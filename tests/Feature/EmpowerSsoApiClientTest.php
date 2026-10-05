<?php

namespace Tests\Feature;

use App\Services\EmpowerSsoApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmpowerSsoApiClientTest extends TestCase
{
    public function test_login_sends_the_password_as_a_sha512_hash(): void
    {
        Http::fake([
            config('services.empower_sso_api.base_url').'/api/Auth/Login' => Http::response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [['userId' => '544109', 'email' => 'jane@practice.com', 'firstName' => 'Jane', 'lastName' => 'Provider']],
            ]),
        ]);

        app(EmpowerSsoApiClient::class)->login('1163testing', 't@lkTest@1234');

        Http::assertSent(function ($request) {
            return $request->url() === config('services.empower_sso_api.base_url').'/api/Auth/Login'
                && $request['UserName'] === '1163testing'
                && $request['Password'] === hash('sha512', 't@lkTest@1234')
                && $request['Password'] !== 't@lkTest@1234';
        });
    }

    public function test_successful_login_returns_the_identity_fields(): void
    {
        Http::fake([
            config('services.empower_sso_api.base_url').'/api/Auth/Login' => Http::response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [[
                    'userId' => '544109',
                    'email' => 'jane@practice.com',
                    'firstName' => 'Jane',
                    'lastName' => 'Provider',
                    'practiceName' => 'Riverside Family Medicine',
                    'prac_Address' => '742 Evergreen Terrace',
                    'prac_city' => 'Springfield',
                    'prac_State' => 'IL',
                    'zip' => '62704',
                ]],
            ]),
        ]);

        $result = app(EmpowerSsoApiClient::class)->login('1163testing', 't@lkTest@1234');

        $this->assertTrue($result->success);
        $this->assertSame('544109', $result->externalUserId);
        $this->assertSame('jane@practice.com', $result->email);
        $this->assertSame('Jane', $result->firstName);
        $this->assertSame('Provider', $result->lastName);
        $this->assertSame('Riverside Family Medicine', $result->practiceName);
        $this->assertSame('742 Evergreen Terrace', $result->practiceAddress);
        $this->assertSame('Springfield', $result->practiceCity);
        $this->assertSame('IL', $result->practiceState);
        $this->assertSame('62704', $result->practiceZip);
    }

    public function test_invalid_credentials_are_treated_as_a_decline(): void
    {
        Http::fake([
            config('services.empower_sso_api.base_url').'/api/Auth/Login' => Http::response([
                'success' => false,
                'message' => 'Invalid username or password.',
                'data' => null,
            ], 401),
        ]);

        $result = app(EmpowerSsoApiClient::class)->login('1163testing', 'wrong-password');

        $this->assertFalse($result->success);
        $this->assertSame('Invalid username or password.', $result->declineMessage);
    }

    public function test_network_failure_is_treated_as_a_decline_not_an_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(EmpowerSsoApiClient::class)->login('1163testing', 't@lkTest@1234');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->declineMessage);
    }

    public function test_missing_base_url_configuration_fails_gracefully(): void
    {
        Http::fake();
        config(['services.empower_sso_api.base_url' => null]);

        $result = app(EmpowerSsoApiClient::class)->login('1163testing', 't@lkTest@1234');

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }
}
