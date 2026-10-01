<?php

namespace Tests\Feature;

use App\Services\CloverChargeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloverChargeServiceTest extends TestCase
{
    /** Fakes the shared EmpowerPaymentApiClient auth-token endpoint CloverChargeService now
     *  calls before every charge, so each test only has to additionally fake the charge URL. */
    private function fakeAccessToken(): array
    {
        return [
            config('services.empower_payment_api.base_url').'/api/auth/token' => Http::response([
                'status' => true,
                'data' => ['accessToken' => 'fake-access-token'],
            ]),
        ];
    }

    /** @return array{name: string, address1: string, city: string, state: string, zip: string, product_Name: string, amount: float, cardNumber: string, expMonth: int, expYear: int, cvv: string} */
    private function chargeParams(): array
    {
        return [
            'name' => 'Jane Provider',
            'address1' => '7 Clyde Road',
            'city' => 'Somerset',
            'state' => 'NJ',
            'zip' => '08873',
            'product_Name' => 'Essential Compliance',
            'amount' => 999.0,
            'cardNumber' => '4111111111111111',
            'expMonth' => 9,
            'expYear' => 2029,
            'cvv' => '123',
        ];
    }

    public function test_successful_charge_returns_the_transaction_id(): void
    {
        Http::fake($this->fakeAccessToken() + [
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => '59VT21YY0WR2M', 'amount' => 99900, 'paid' => true, 'status' => 'succeeded'],
            ]),
        ]);

        $result = app(CloverChargeService::class)->charge($this->chargeParams());

        $this->assertTrue($result->success);
        $this->assertSame('59VT21YY0WR2M', $result->transactionId);
        $this->assertNull($result->declineMessage);
    }

    public function test_charge_sends_credentials_and_params_to_the_configured_url(): void
    {
        Http::fake($this->fakeAccessToken() + [
            config('services.clover_mtbc.base_url') => Http::response(['status' => true, 'data' => ['id' => 'TXN1']]),
        ]);

        app(CloverChargeService::class)->charge($this->chargeParams());

        Http::assertSent(function ($request) {
            return $request->url() === config('services.clover_mtbc.base_url')
                && $request->hasHeader('Authorization', 'Bearer fake-access-token')
                && $request['username'] === config('services.clover_mtbc.username')
                && $request['password'] === config('services.clover_mtbc.password')
                && $request['cardNumber'] === '4111111111111111'
                && $request['amount'] === 999.0;
        });
    }

    public function test_charge_clears_the_cached_token_and_retries_once_on_a_gateway_401(): void
    {
        Http::fakeSequence('*/api/auth/token')
            ->push(['status' => true, 'data' => ['accessToken' => 'stale-token']])
            ->push(['status' => true, 'data' => ['accessToken' => 'fresh-token']]);

        Http::fake([
            config('services.clover_mtbc.base_url') => Http::sequence()
                ->push('', 401) // the exact failure mode observed live: empty body, bare 401
                ->push(['status' => true, 'data' => ['id' => 'TXN1']]),
        ]);

        $result = app(CloverChargeService::class)->charge($this->chargeParams());

        $this->assertTrue($result->success);

        Http::assertSent(fn ($request) => $request->url() === config('services.clover_mtbc.base_url')
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_wrong_credentials_response_is_treated_as_a_decline(): void
    {
        Http::fake($this->fakeAccessToken() + [
            config('services.clover_mtbc.base_url') => Http::response(['status' => false, 'message' => 'Invalid username or password', 'data' => null], 400),
        ]);

        $result = app(CloverChargeService::class)->charge($this->chargeParams());

        $this->assertFalse($result->success);
        $this->assertSame('Invalid username or password', $result->declineMessage);
    }

    public function test_nested_json_string_error_message_is_unwrapped(): void
    {
        // The real API sometimes returns "message" as a JSON-encoded string containing the
        // actual processor error, rather than a plain string.
        Http::fake($this->fakeAccessToken() + [
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => false,
                'message' => '{"message":"400 Bad Request","error":{"type":"invalid_request_error","code":"invalid_number","message":"Please provide valid card number."}}',
                'data' => null,
            ], 400),
        ]);

        $result = app(CloverChargeService::class)->charge($this->chargeParams());

        $this->assertFalse($result->success);
        $this->assertSame('Please provide valid card number.', $result->declineMessage);
    }

    public function test_aspnet_model_validation_error_shape_is_handled(): void
    {
        // A completely different shape from the API's own {status,message,data} envelope —
        // the framework's own validation middleware, e.g. when a required field is missing.
        Http::fake($this->fakeAccessToken() + [
            config('services.clover_mtbc.base_url') => Http::response([
                'type' => 'https://tools.ietf.org/html/rfc9110#section-15.5.1',
                'title' => 'One or more validation errors occurred.',
                'status' => 400,
                'errors' => ['CardNumber' => ['The CardNumber field is required.']],
            ], 400),
        ]);

        $result = app(CloverChargeService::class)->charge($this->chargeParams());

        $this->assertFalse($result->success);
        $this->assertSame('The CardNumber field is required.', $result->declineMessage);
    }

    public function test_network_failure_is_treated_as_a_decline_not_an_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(CloverChargeService::class)->charge($this->chargeParams());

        $this->assertFalse($result->success);
        $this->assertNotNull($result->declineMessage);
    }

    public function test_missing_base_url_configuration_fails_gracefully(): void
    {
        Http::fake(); // defensive — asserts below prove this is never even reached
        config(['services.clover_mtbc.base_url' => null]);

        $result = app(CloverChargeService::class)->charge($this->chargeParams());

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }
}
