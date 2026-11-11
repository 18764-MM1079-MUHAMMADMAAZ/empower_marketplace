<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\OidcTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OidcTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'empower-lms';

    private const SECRET = 'test-secret';

    private const REDIRECT = 'https://lms.test/auth/iomadoidc/';

    private string $keyDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyDir = sys_get_temp_dir().'/oidc-test-'.uniqid();
        mkdir($this->keyDir);
        $keys = OidcTokenIssuer::generateKeyPair();
        file_put_contents($this->keyDir.'/private.key', $keys['private']);
        file_put_contents($this->keyDir.'/public.key', $keys['public']);

        config([
            'oidc.issuer' => 'https://marketplace.test',
            'oidc.private_key_path' => $this->keyDir.'/private.key',
            'oidc.public_key_path' => $this->keyDir.'/public.key',
            'oidc.moodle.client_id' => self::CLIENT_ID,
            'oidc.moodle.client_secret' => self::SECRET,
            'oidc.moodle.base_url' => 'https://lms.test',
            'oidc.moodle.redirect_uri' => self::REDIRECT,
            'oidc.moodle.frontchannel_logout_uri' => 'https://lms.test/auth/iomadoidc/logout.php',
            'oidc.moodle.launch_url' => 'https://lms.test/my/',
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->keyDir.'/*'));
        rmdir($this->keyDir);

        parent::tearDown();
    }

    private function clientWith(string $slug, PaymentStatus $payment = PaymentStatus::Paid, OrderStatus $status = OrderStatus::Paid): User
    {
        $user = User::factory()->create(['name' => 'Jane Q Provider', 'email' => 'Jane'.uniqid().'@Practice.com']);
        $package = Package::where('slug', $slug)->first() ?? Package::factory()->create(['slug' => $slug, 'is_active' => true]);
        Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id, 'payment_status' => $payment, 'status' => $status]);

        return $user;
    }

    /** @param  array<string, string>  $overrides */
    private function authorizeUrl(array $overrides = []): string
    {
        return route('oidc.authorize', $overrides + [
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'xyz',
            'nonce' => 'n-123',
        ]);
    }

    private function codeFor(User $user): string
    {
        $location = $this->actingAs($user)->get($this->authorizeUrl())->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['code'];
    }

    /** @return array<string, mixed> */
    private function decodeAndVerify(string $jwt): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), file_get_contents($this->keyDir.'/public.key'), OPENSSL_ALGO_SHA256));

        return json_decode($decode($payload), true);
    }

    public function test_access_follows_paid_lms_packages_only(): void
    {
        $this->assertTrue($this->clientWith('professional')->hasLmsAccess());
        $this->assertTrue($this->clientWith('advanced', PaymentStatus::Trialing)->hasLmsAccess());
        $this->assertFalse($this->clientWith('essential')->hasLmsAccess());
        $this->assertFalse($this->clientWith('complete', PaymentStatus::Pending, OrderStatus::PendingPayment)->hasLmsAccess());
        $this->assertFalse($this->clientWith('professional', PaymentStatus::Paid, OrderStatus::Cancelled)->hasLmsAccess());
        $this->assertFalse(User::factory()->create(['role' => UserRole::Admin])->hasLmsAccess());
    }

    public function test_discovery_and_jwks_describe_the_provider(): void
    {
        $this->getJson('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('issuer', 'https://marketplace.test')
            ->assertJsonPath('authorization_endpoint', route('oidc.authorize'))
            ->assertJsonPath('token_endpoint', route('oidc.token'));

        $this->getJson(route('oidc.jwks'))->assertOk()->assertJsonPath('keys.0.alg', 'RS256');
    }

    public function test_an_unknown_client_or_redirect_uri_is_refused_without_redirecting(): void
    {
        $user = $this->clientWith('professional');

        $this->actingAs($user)->get($this->authorizeUrl(['client_id' => 'someone-else']))->assertStatus(400);
        $this->actingAs($user)->get($this->authorizeUrl(['redirect_uri' => 'https://evil.test/cb']))->assertStatus(400);
    }

    public function test_a_guest_is_sent_to_login_and_resumed_afterwards(): void
    {
        $this->get($this->authorizeUrl())->assertRedirect(route('login'));
        $resumeUrl = session('oidc_authorize_url');
        $this->assertStringStartsWith(route('oidc.authorize').'?', $resumeUrl);
        $this->assertStringContainsString('nonce=n-123', $resumeUrl);

        $user = $this->clientWith('professional');
        $user->update(['password' => 'secret-password']);

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'secret-password')
            ->call('login')
            ->assertRedirect($resumeUrl);
    }

    public function test_a_client_without_an_lms_package_is_sent_to_the_packages(): void
    {
        $this->actingAs($this->clientWith('essential'))
            ->get($this->authorizeUrl())
            ->assertRedirect(route('home').'#pricing');
    }

    public function test_full_code_flow_issues_a_signed_id_token_keyed_on_the_marketplace_id(): void
    {
        $user = $this->clientWith('professional');
        $location = $this->actingAs($user)->get($this->authorizeUrl())->headers->get('Location');

        $this->assertStringStartsWith(self::REDIRECT.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);

        $response = $this->post(route('oidc.token'), [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => self::REDIRECT,
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::SECRET,
        ])->assertOk()->assertJsonPath('token_type', 'Bearer');

        $claims = $this->decodeAndVerify($response->json('id_token'));

        $this->assertSame('https://marketplace.test', $claims['iss']);
        $this->assertSame(self::CLIENT_ID, $claims['aud']);
        $this->assertSame('n-123', $claims['nonce']);
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('mp_'.$user->id, $claims['preferred_username']);
        $this->assertSame(strtolower($user->email), $claims['email']);
        $this->assertSame('Jane', $claims['given_name']);
        $this->assertSame('Q Provider', $claims['family_name']);
        $this->assertGreaterThan(time(), $claims['exp']);

        $this->withToken($response->json('access_token'))->getJson(route('oidc.userinfo'))
            ->assertOk()->assertJsonPath('sub', (string) $user->id);
    }

    public function test_client_secret_basic_auth_is_accepted(): void
    {
        $code = $this->codeFor($this->clientWith('advanced'));

        $this->withHeader('Authorization', 'Basic '.base64_encode(self::CLIENT_ID.':'.self::SECRET))
            ->post(route('oidc.token'), ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT])
            ->assertOk();
    }

    public function test_a_code_works_once_only(): void
    {
        $code = $this->codeFor($this->clientWith('professional'));
        $request = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'client_id' => self::CLIENT_ID, 'client_secret' => self::SECRET];

        $this->post(route('oidc.token'), $request)->assertOk();
        $this->post(route('oidc.token'), $request)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_wrong_secret_or_redirect_uri_gets_no_token(): void
    {
        $code = $this->codeFor($this->clientWith('professional'));
        $request = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'client_id' => self::CLIENT_ID];

        $this->post(route('oidc.token'), $request + ['client_secret' => 'wrong'])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->post(route('oidc.token'), ['redirect_uri' => 'https://lms.test/other'] + $request + ['client_secret' => self::SECRET])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_access_lost_between_authorize_and_token_gets_no_token(): void
    {
        $user = $this->clientWith('professional');
        $code = $this->codeFor($user);
        $user->orders()->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

        $this->post(route('oidc.token'), ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'client_id' => self::CLIENT_ID, 'client_secret' => self::SECRET])
            ->assertStatus(400);
    }

    public function test_header_shows_access_lms_to_paid_clients_and_a_locked_button_to_others(): void
    {
        $this->actingAs($this->clientWith('professional'))->get(route('portal'))
            ->assertSee('data-lms-button', false)
            ->assertSee(route('lms.launch'), false);

        $this->actingAs($this->clientWith('essential'))->get(route('portal'))
            ->assertSee('data-lms-button-locked', false)
            ->assertSee('Buy a package to unlock training');
    }

    public function test_launch_goes_to_moodle_only_with_access(): void
    {
        $this->actingAs($this->clientWith('professional'))->get(route('lms.launch'))->assertRedirect('https://lms.test/my/');
        $this->actingAs($this->clientWith('essential'))->get(route('lms.launch'))->assertRedirect(route('home').'#pricing');
    }

    public function test_marketplace_logout_also_ends_the_lms_session(): void
    {
        $this->actingAs($this->clientWith('professional'))->post(route('logout'))
            ->assertOk()
            ->assertSee('https://lms.test/auth/iomadoidc/logout.php', false);

        $this->assertGuest();
    }

    public function test_lms_logout_signs_out_of_marketplace_and_only_returns_to_the_lms(): void
    {
        $user = $this->clientWith('professional');

        $this->actingAs($user)->get(route('oidc.logout', ['post_logout_redirect_uri' => 'https://lms.test/login/index.php']))
            ->assertRedirect('https://lms.test/login/index.php');
        $this->assertGuest();

        $this->actingAs($user)->get(route('oidc.logout', ['post_logout_redirect_uri' => 'https://lms.test.evil.com/']))
            ->assertRedirect(route('home'));
    }
}
