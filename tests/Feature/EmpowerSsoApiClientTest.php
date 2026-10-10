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
            config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login' => Http::response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => ['external_user_id' => '544109', 'email' => 'jane@practice.com', 'first_name' => 'Jane', 'last_name' => 'Provider'],
            ]),
        ]);

        app(EmpowerSsoApiClient::class)->login('1163testing', 't@lkTest@1234');

        Http::assertSent(function ($request) {
            return $request->url() === config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login'
                && $request['UserName'] === '1163testing'
                && $request['Password'] === hash('sha512', 't@lkTest@1234')
                && $request['Password'] !== 't@lkTest@1234';
        });
    }

    public function test_successful_login_returns_the_identity_fields(): void
    {
        Http::fake([
            config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login' => Http::response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'external_user_id' => '544109',
                    'email' => 'jane@practice.com',
                    'first_name' => 'Jane',
                    'last_name' => 'Provider',
                    'is_practice_admin' => true,
                    'practices' => [
                        ['id' => '1011163', 'name' => 'Riverside Family Medicine', 'user_name' => 'JANE'],
                        ['id' => '1011164', 'name' => 'Riverside Pediatrics', 'user_name' => 'JANE'],
                    ],
                    'selected_practice_id' => '1011163',
                    'practice' => [
                        'id' => '1011163',
                        'name' => 'Riverside Family Medicine',
                        'phone' => '5555551010',
                        'address' => ['street' => '742 Evergreen Terrace', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62704'],
                        'admin_count' => 1,
                        'admins' => [['user_name' => 'owner', 'first_name' => 'Olive', 'last_name' => 'Owner', 'email' => 'olive@practice.com']],
                    ],
                ],
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
        $this->assertSame('5555551010', $result->practicePhone);
        $this->assertTrue($result->isPracticeAdmin);
        $this->assertCount(2, $result->practices);
        $this->assertSame('1011163', $result->selectedPracticeId);
        $this->assertSame('olive@practice.com', $result->practiceAdmins[0]['email']);
    }

    public function test_invalid_credentials_are_treated_as_a_decline(): void
    {
        Http::fake([
            config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login' => Http::response([
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
        config(['services.empower_sso_api.providers.talkehr' => null]);

        $result = app(EmpowerSsoApiClient::class)->login('1163testing', 't@lkTest@1234');

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }
}
