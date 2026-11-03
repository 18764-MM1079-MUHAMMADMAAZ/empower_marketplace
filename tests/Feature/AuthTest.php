<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\ResetPasswordMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\MtbcCardCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // --- Login page ---

    public function test_login_page_renders(): void
    {
        $this->withoutVite()
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Welcome back');
    }

    public function test_guest_is_redirected_home_from_auth_protected_pages(): void
    {
        $this->get(route('password.edit'))
            ->assertRedirect(route('home'));
    }

    public function test_expired_session_csrf_failure_redirects_home(): void
    {
        Route::get('/__test-session-expired', fn () => throw new TokenMismatchException);

        $this->get('/__test-session-expired')
            ->assertRedirect(route('home'))
            ->assertSessionHas('toast', 'Your session has expired. Please sign in again.')
            ->assertSessionHas('toast_type', 'error');
    }

    public function test_login_with_valid_credentials_and_no_orders_goes_to_the_pricing_section_to_choose_a_package(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('home').'#pricing');

        $this->assertSame('Login successful - Please continue with purchasing the package', session('toast'));
    }

    public function test_login_redirects_a_client_with_an_existing_order_to_portal(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        Order::factory()->create(['user_id' => $user->id]);

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('portal'));
    }

    public function test_login_with_admin_credentials_redirects_to_admin_dashboard(): void
    {
        $admin = User::factory()->create(['password' => 'secret123', 'role' => UserRole::Admin]);

        Livewire::test('auth.login-form')
            ->set('email', $admin->email)
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_login_with_wrong_password_shows_error(): void
    {
        $user = User::factory()->create();

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email']);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret123', 'is_active' => false]);

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_login_requires_email_and_password(): void
    {
        Livewire::test('auth.login-form')
            ->call('login')
            ->assertHasErrors(['email', 'password']);
    }

    // --- CareCloud/talkEHR SSO login (EmpowerSSOAPI — sso.md §1a) ---

    private function fakeEmpowerSsoApi(array $data): void
    {
        Http::fake([
            config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login' => Http::response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => $data,
            ]),
        ]);
    }

    public function test_selecting_a_provider_shows_the_inline_sso_form(): void
    {
        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->assertSet('ssoProvider', 'talkehr')
            ->assertSee('Username');
    }

    public function test_sso_login_creates_a_new_user_and_practice_from_the_returned_identity(): void
    {
        $this->fakeEmpowerSsoApi([
            'external_user_id' => '544109',
            'email' => 'jane@practice.com',
            'first_name' => 'Jane',
            'last_name' => 'Provider',
            'specialization' => 'cardiology',
            'is_practice_admin' => true,
            'practices' => [['id' => '1011163', 'name' => 'Riverside Family Medicine', 'user_name' => 'JANE']],
            'selected_practice_id' => '1011163',
            'practice' => [
                'id' => '1011163',
                'name' => 'Riverside Family Medicine',
                'phone' => '5555551010',
                'address' => ['street' => '742 Evergreen Terrace', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62704'],
                'admins' => [],
            ],
        ]);

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertHasNoErrors()
            ->assertRedirect(route('home').'#pricing');

        $user = User::where('email', 'jane@practice.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Jane Provider', $user->name);
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'name' => 'Riverside Family Medicine',
            'address' => '742 Evergreen Terrace, Springfield, IL, 62704',
        ]);
    }

    public function test_sso_login_signs_in_an_existing_user_matched_by_email(): void
    {
        $existing = User::factory()->create(['email' => 'jane@practice.com']);

        $this->fakeEmpowerSsoApi(['email' => 'jane@practice.com', 'first_name' => 'Jane', 'last_name' => 'Provider']);

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'jane@practice.com')->count());
    }

    public function test_sso_login_shows_an_error_on_invalid_credentials(): void
    {
        Http::fake([
            config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login' => Http::response([
                'success' => false,
                'message' => 'Invalid username or password.',
                'data' => null,
            ], 401),
        ]);

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 'wrong-password')
            ->call('loginViaSso')
            ->assertHasErrors(['ssoPassword']);

        $this->assertGuest();
    }

    public function test_back_from_sso_returns_to_the_provider_buttons(): void
    {
        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->assertSet('ssoProvider', 'talkehr')
            ->call('backFromSso')
            ->assertSet('ssoProvider', null)
            ->assertSee('Sign in with');
    }

    // --- Register page ---

    public function test_register_page_renders(): void
    {
        $this->withoutVite()
            ->get(route('register'))
            ->assertOk()
            ->assertSee('Sign Up');
    }

    public function test_visiting_register_with_a_package_stores_it_in_session(): void
    {
        $this->withoutVite()->get(route('register', ['package' => 'essential']))->assertOk();

        $this->assertSame('essential', session('intended_package'));
    }

    public function test_visiting_register_with_provider_count_stores_it_in_session(): void
    {
        $this->withoutVite()->get(route('register', ['billable_providers' => 4]))->assertOk();

        $this->assertSame(4, session('intended_billable_providers'));
    }

    public function test_register_page_shows_the_selected_package_and_its_price(): void
    {
        Package::factory()->create([
            'slug' => 'essential',
            'name' => 'Essential Compliance',
            'is_active' => true,
            'monthly_price' => 99,
            'annual_price' => 999,
        ]);

        $response = $this->withoutVite()->get(route('register', [
            'package' => 'essential',
            'billing_cycle' => 'monthly',
        ]));

        $response->assertOk();
        $response->assertSeeText('Selected package');
        $response->assertSeeText('Essential Compliance');
        $response->assertSeeText('$99.00 per provider / month');
    }

    public function test_register_page_shows_no_package_card_when_none_was_selected(): void
    {
        $response = $this->withoutVite()->get(route('register'));

        $response->assertOk();
        $response->assertDontSee('Selected package');
    }

    public function test_visiting_register_with_a_billing_cycle_stores_it_in_session(): void
    {
        $this->withoutVite()->get(route('register', ['billing_cycle' => 'monthly']))->assertOk();

        $this->assertSame('monthly', session('intended_billing_cycle'));
    }

    /**
     * The Sign Up screen no longer creates an account itself — "Create an account" just carries
     * the package/billing cycle through to Step 1's checkout, which is what actually creates the
     * guest account as part of paying (⚡portal.blade.php's pay()/payFreeTrial()).
     */
    public function test_create_an_account_link_carries_the_package_and_billing_cycle_to_checkout(): void
    {
        Package::factory()->create(['slug' => 'essential', 'is_active' => true]);

        $response = $this->withoutVite()->get(route('register', [
            'package' => 'essential',
            'billing_cycle' => 'monthly',
            'billable_providers' => 4,
        ]));

        $response->assertOk();
        $response->assertSee(route('portal', [
            'package' => 'essential',
            'billing_cycle' => 'monthly',
            'billable_providers' => 4,
        ]));
    }

    public function test_create_an_account_link_falls_back_to_the_session_intended_package(): void
    {
        Package::factory()->create(['slug' => 'essential', 'is_active' => true]);
        session(['intended_package' => 'essential', 'intended_billing_cycle' => 'monthly']);

        $response = $this->withoutVite()->get(route('register'));

        $response->assertOk();
        $response->assertSee(route('portal', ['package' => 'essential', 'billing_cycle' => 'monthly']));
    }

    public function test_create_an_account_link_defaults_to_annual_billing_with_no_package(): void
    {
        $response = $this->withoutVite()->get(route('register'));

        $response->assertOk();
        $response->assertSee(route('portal', ['billing_cycle' => 'annual']));
    }

    // --- Forgot / reset password ---

    public function test_forgot_password_page_renders(): void
    {
        $this->withoutVite()
            ->get(route('password.request'))
            ->assertOk()
            ->assertSee('Forgot your password?');
    }

    public function test_forgot_password_sends_reset_link_for_existing_user(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        Livewire::test('auth.forgot-password-form')
            ->set('email', $user->email)
            ->call('sendResetLink')
            ->assertHasNoErrors();

        Mail::assertSent(ResetPasswordMail::class, fn ($mail) => $mail->hasTo($user->email));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_shows_error_for_unknown_email(): void
    {
        Mail::fake();

        Livewire::test('auth.forgot-password-form')
            ->set('email', 'nobody@example.com')
            ->call('sendResetLink')
            ->assertHasErrors(['email']);

        Mail::assertNotSent(ResetPasswordMail::class);
    }

    public function test_reset_password_page_renders(): void
    {
        $this->withoutVite()
            ->get(route('password.reset', ['token' => 'a-token']))
            ->assertOk()
            ->assertSee('Set a New Password');
    }

    public function test_reset_password_with_valid_token_updates_password_and_allows_login(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        Livewire::test('auth.reset-password-form', ['token' => $token, 'email' => $user->email])
            ->set('password', 'new-secret-123')
            ->set('password_confirmation', 'new-secret-123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'new-secret-123')
            ->call('login')
            ->assertRedirect(route('home').'#pricing');
    }

    public function test_reset_password_with_invalid_token_shows_error(): void
    {
        $user = User::factory()->create();

        Livewire::test('auth.reset-password-form', ['token' => 'invalid-token', 'email' => $user->email])
            ->set('password', 'new-secret-123')
            ->set('password_confirmation', 'new-secret-123')
            ->call('resetPassword')
            ->assertHasErrors(['email']);
    }

    public function test_reset_password_requires_matching_confirmation(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        Livewire::test('auth.reset-password-form', ['token' => $token, 'email' => $user->email])
            ->set('password', 'new-secret-123')
            ->set('password_confirmation', 'different')
            ->call('resetPassword')
            ->assertHasErrors(['password']);
    }

    public function test_reset_password_rejects_reusing_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-secret-1']);
        $token = Password::createToken($user);

        Livewire::test('auth.reset-password-form', ['token' => $token, 'email' => $user->email])
            ->set('password', 'old-secret-1')
            ->set('password_confirmation', 'old-secret-1')
            ->call('resetPassword')
            ->assertHasErrors(['password']);

        $this->assertTrue(Hash::check('old-secret-1', $user->fresh()->password));
    }

    // --- Change password (authenticated) ---

    public function test_change_password_page_requires_authentication(): void
    {
        $this->withoutVite()
            ->get(route('password.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_change_password_page_renders(): void
    {
        $user = User::factory()->create();

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('password.edit'))
            ->assertOk()
            ->assertSee('Change Password');
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::factory()->create(['password' => 'old-secret-1']);

        Livewire::actingAs($user)
            ->test('auth.change-password-form')
            ->set('currentPassword', 'old-secret-1')
            ->set('password', 'new-secret-2')
            ->set('password_confirmation', 'new-secret-2')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-secret-2', $user->fresh()->password));
    }

    public function test_changing_password_requires_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-secret-1']);

        Livewire::actingAs($user)
            ->test('auth.change-password-form')
            ->set('currentPassword', 'wrong-password')
            ->set('password', 'new-secret-2')
            ->set('password_confirmation', 'new-secret-2')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword']);

        $this->assertTrue(Hash::check('old-secret-1', $user->fresh()->password));
    }

    public function test_changing_password_rejects_reusing_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-secret-1']);

        Livewire::actingAs($user)
            ->test('auth.change-password-form')
            ->set('currentPassword', 'old-secret-1')
            ->set('password', 'old-secret-1')
            ->set('password_confirmation', 'old-secret-1')
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        $this->assertTrue(Hash::check('old-secret-1', $user->fresh()->password));
    }

    public function test_changing_password_requires_matching_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'old-secret-1']);

        Livewire::actingAs($user)
            ->test('auth.change-password-form')
            ->set('currentPassword', 'old-secret-1')
            ->set('password', 'new-secret-2')
            ->set('password_confirmation', 'different')
            ->call('updatePassword')
            ->assertHasErrors(['password']);
    }

    // --- Logout ---

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->withoutVite()
            ->actingAs($user)
            ->post(route('logout'));

        $response->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_guest_can_access_portal_to_sign_up_and_pay(): void
    {
        $this->withoutVite()
            ->get(route('portal'))
            ->assertOk();
    }

    private function ssoPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'external_user_id' => '544109',
            'email' => 'jane@practice.com',
            'first_name' => 'Jane',
            'last_name' => 'Provider',
            'specialization' => 'cardiology',
            'is_practice_admin' => true,
            'practices' => [['id' => '1011163', 'name' => 'Riverside Family Medicine', 'user_name' => 'JANE']],
            'selected_practice_id' => '1011163',
            'practice' => [
                'id' => '1011163',
                'name' => 'Riverside Family Medicine',
                'phone' => '5555551010',
                'address' => ['street' => '742 Evergreen Terrace', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62704'],
                'admins' => [],
            ],
        ], $overrides);
    }

    private function ssoLogin(string $provider = 'talkehr'): void
    {
        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', $provider)
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso');
    }

    public function test_sso_login_stores_the_partner_identity_and_practice_link(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        $this->ssoLogin('talkehr');

        $user = User::where('email', 'jane@practice.com')->first();
        $this->assertSame('544109', $user->external_id);
        $this->assertTrue($user->is_practice_admin);
        $this->assertSame('talkehr', $user->practice->source_system);
        $this->assertSame('1011163', $user->practice->external_practice_id);
        $this->assertSame('5555551010', $user->practice->phone);
    }

    public function test_only_talkehr_is_offered_as_an_sso_provider(): void
    {
        Livewire::test('auth.login-form')
            ->assertSee('talk-logo.png')
            ->assertDontSee('carcloud-logo.png')
            ->call('selectSsoProvider', 'carecloud')
            ->assertSet('ssoProvider', null);
    }

    public function test_a_returning_user_is_matched_by_the_partner_id_even_if_their_email_changed(): void
    {
        $existing = User::factory()->create(['email' => 'old@practice.com', 'external_id' => '544109']);
        $this->fakeEmpowerSsoApi($this->ssoPayload(['email' => 'new@practice.com']));

        $this->ssoLogin();

        $this->assertAuthenticatedAs($existing);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_existing_email_account_gets_linked_to_the_partner_id_on_first_sso_login(): void
    {
        $existing = User::factory()->create(['email' => 'jane@practice.com', 'external_id' => null, 'is_practice_admin' => false]);
        Practice::factory()->create(['user_id' => $existing->id, 'source_system' => null, 'external_practice_id' => null]);
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        $this->ssoLogin();

        $existing->refresh();
        $this->assertAuthenticatedAs($existing);
        $this->assertSame('544109', $existing->external_id);
        $this->assertTrue($existing->is_practice_admin);
        $this->assertSame('1011163', $existing->practice->external_practice_id);
        $this->assertSame('talkehr', $existing->practice->source_system);
    }

    private function multiPracticePayload(): array
    {
        return $this->ssoPayload([
            'practices' => [
                ['id' => '1011163', 'name' => 'Riverside Family Medicine', 'user_name' => 'JANE'],
                ['id' => '1011164', 'name' => 'Riverside Pediatrics', 'user_name' => 'JANE2'],
            ],
        ]);
    }

    public function test_a_user_with_several_practices_is_asked_which_one_before_signing_in(): void
    {
        $this->fakeEmpowerSsoApi($this->multiPracticePayload());

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertSet('choosingPractice', true)
            ->assertSet('pickedPracticeId', '1011163')
            ->assertSee('Which practice are you working in?')
            ->assertSee('Riverside Pediatrics');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_returning_to_the_practice_picker_keeps_the_pending_selection_alive(): void
    {
        $this->fakeEmpowerSsoApi($this->multiPracticePayload());

        $component = Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso');

        $this->assertNotNull(session('sso_pending_key'));

        $component->call('backToSsoCredentials')
            ->assertSet('choosingPractice', false)
            ->assertSet('pickedPracticeId', '1011163');

        $this->assertNotNull(session('sso_pending_key'));
    }

    public function test_choosing_the_default_practice_signs_in_with_its_full_details(): void
    {
        $this->fakeEmpowerSsoApi($this->multiPracticePayload());

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->call('choosePractice');

        $user = User::where('email', 'jane@practice.com')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Riverside Family Medicine', $user->practice->name);
        $this->assertSame('1011163', $user->practice->external_practice_id);
        $this->assertStringContainsString('742 Evergreen Terrace', $user->practice->address);
        $this->assertSame('1011163', session('sso_active_practice.id'));
    }

    public function test_choosing_another_practice_uses_its_name_but_has_no_address_to_prefill(): void
    {
        $this->fakeEmpowerSsoApi($this->multiPracticePayload());

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->set('pickedPracticeId', '1011164')
            ->call('choosePractice');

        $user = User::where('email', 'jane@practice.com')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Riverside Pediatrics', $user->practice->name);
        $this->assertSame('1011164', $user->practice->external_practice_id);
        $this->assertNull($user->practice->address);
        $this->assertNull($user->practice->phone);
        $this->assertSame('1011164', session('sso_active_practice.id'));
        $this->assertSame('Riverside Pediatrics', session('sso_active_practice.name'));
    }

    public function test_picking_a_practice_that_is_not_in_the_list_is_refused(): void
    {
        $this->fakeEmpowerSsoApi($this->multiPracticePayload());

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->set('pickedPracticeId', '999')
            ->call('choosePractice')
            ->assertHasErrors(['pickedPracticeId']);

        $this->assertGuest();
    }

    public function test_choosing_a_practice_without_a_verified_login_does_nothing(): void
    {
        Livewire::test('auth.login-form')
            ->set('pickedPracticeId', '1011163')
            ->call('choosePractice')
            ->assertHasErrors(['pickedPracticeId']);

        $this->assertGuest();
    }

    public function test_a_single_practice_user_skips_the_picker(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertSet('choosingPractice', false);

        $this->assertAuthenticated();
    }

    public function test_orders_can_record_the_partner_practice_they_belong_to(): void
    {
        $order = Order::factory()->create(['external_practice_id' => '1011164']);

        $this->assertSame('1011164', $order->fresh()->external_practice_id);
    }

    public function test_carecloud_appears_once_its_own_endpoint_is_configured(): void
    {
        config(['services.empower_sso_api.providers.carecloud' => 'https://cch.example.test/EmpowerSSOAPI']);

        Livewire::test('auth.login-form')
            ->assertSee('talk-logo.png')
            ->assertSee('carcloud-logo.png')
            ->call('selectSsoProvider', 'carecloud')
            ->assertSet('ssoProvider', 'carecloud');
    }

    private function enableCarecloud(): void
    {
        config([
            'services.empower_sso_api.providers.carecloud' => 'https://cch.example.test/empower-marketplace/login',
            'services.empower_sso_api.cch_aes_key' => '6810369413101191',
        ]);
    }

    private function carecloudPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'external_user_id' => '18759d01-226c-4355-89a9-b49b2892d502',
            'first_name' => 'TALHA',
            'last_name' => 'AHMED',
            'email' => 'talha@practice.com',
            'is_practice_admin' => false,
            'selected_practice_id' => '77b81aa8',
            'specialty' => 'Ophthalmology',
            'practices' => [['id' => '77b81aa8', 'name' => 'CareCloud Clinic', 'user_name' => 'u1']],
            'practice' => [
                'id' => '77b81aa8',
                'name' => 'CareCloud Clinic',
                'address' => ['street' => '786 BOSTON POST ROAD', 'city' => 'MADISON', 'state' => 'CT', 'zip' => '064433036'],
                'phone' => '3052654200',
                'admins' => [],
                'account_status' => true,
                'subscription_account_id' => 'ACCT-1',
                'preferred_billing_option' => 'carecloud_subscription',
            ],
        ], $overrides);
    }

    private function fakeCarecloud(array $data): void
    {
        Http::fake(['cch.example.test/*' => Http::response(['success' => true, 'message' => 'Login successful', 'data' => $data])]);
    }

    private function carecloudLogin(string $email = 'talha@practice.com'): Testable
    {
        return Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'carecloud')
            ->set('ssoUsername', $email)
            ->set('ssoPassword', 'Secret-Pass-1!')
            ->call('loginViaSso');
    }

    public function test_a_carecloud_login_posts_the_email_and_an_aes_encrypted_password_and_is_recorded_as_cch(): void
    {
        $this->enableCarecloud();
        $this->fakeCarecloud($this->carecloudPayload());

        $this->carecloudLogin();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://cch.example.test/empower-marketplace/login'
                && $request['email'] === 'talha@practice.com'
                && $request['password'] !== 'Secret-Pass-1!'
                && (new MtbcCardCipher('6810369413101191'))->decrypt($request['password']) === 'Secret-Pass-1!';
        });
        $user = User::where('email', 'talha@practice.com')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('cch', $user->practice->source_system);
        $this->assertSame('Ophthalmology', $user->practice->specialty);
        $this->assertSame('CareCloud Clinic', $user->practice->name);
        $this->assertTrue($user->practice->account_status);
        $this->assertSame('ACCT-1', $user->practice->subscription_account_id);
        $this->assertSame('carecloud_subscription', $user->practice->preferred_billing_option);
    }

    public function test_carecloud_billing_fields_that_are_null_stay_empty(): void
    {
        $this->enableCarecloud();
        $this->fakeCarecloud($this->carecloudPayload(['practice' => ['subscription_account_id' => null, 'preferred_billing_option' => null]]));

        $this->carecloudLogin();

        $practice = User::where('email', 'talha@practice.com')->first()->practice;
        $this->assertNull($practice->subscription_account_id);
        $this->assertNull($practice->preferred_billing_option);
        $this->assertTrue($practice->account_status);
    }

    public function test_carecloud_billing_standing_is_refreshed_on_a_later_login(): void
    {
        $this->enableCarecloud();
        Http::fake(['cch.example.test/*' => Http::sequence()
            ->push(['success' => true, 'message' => 'ok', 'data' => $this->carecloudPayload()])
            ->push(['success' => true, 'message' => 'ok', 'data' => $this->carecloudPayload(['practice' => ['account_status' => false, 'subscription_account_id' => 'ACCT-2']])]),
        ]);

        $this->carecloudLogin();
        auth()->logout();
        $this->carecloudLogin();

        $practice = User::where('email', 'talha@practice.com')->first()->practice;
        $this->assertFalse($practice->account_status);
        $this->assertSame('ACCT-2', $practice->subscription_account_id);
    }

    public function test_the_carecloud_form_asks_for_an_email_and_rejects_a_non_email(): void
    {
        $this->enableCarecloud();

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'carecloud')
            ->assertSee('Email')
            ->set('ssoUsername', 'not-an-email')
            ->set('ssoPassword', 'x')
            ->call('loginViaSso')
            ->assertHasErrors(['ssoUsername'])
            ->assertDispatched('toast', message: 'Please enter your email and password.', type: 'error');
    }

    public function test_a_wrong_carecloud_password_shows_their_message_as_an_error_toast(): void
    {
        $this->enableCarecloud();
        Http::fake(['cch.example.test/*' => Http::response(['success' => false, 'message' => 'Invalid email or password.', 'data' => null], 401)]);

        $this->carecloudLogin()
            ->assertHasErrors(['ssoPassword'])
            ->assertDispatched('toast', message: 'Invalid email or password.', type: 'error');

        $this->assertGuest();
    }

    public function test_a_carecloud_server_error_gets_a_friendly_message(): void
    {
        $this->enableCarecloud();
        Http::fake(['cch.example.test/*' => Http::response(['error' => ['error_code' => 500]], 500)]);

        $this->carecloudLogin()
            ->assertDispatched('toast', message: 'The sign-in service is having trouble right now. Please try again shortly.', type: 'error');
    }

    public function test_a_carecloud_user_with_thousands_of_practices_gets_a_searchable_capped_picker(): void
    {
        $this->enableCarecloud();
        $practices = collect(range(1, 2605))->map(fn ($i) => ['id' => "p{$i}", 'name' => $i === 1500 ? 'Unique Needle Clinic' : "Practice Number {$i}", 'user_name' => 'u'])->all();
        $this->fakeCarecloud($this->carecloudPayload(['practices' => $practices, 'selected_practice_id' => 'p7', 'practice' => ['id' => 'p7', 'name' => 'Practice Number 7']]));

        $component = $this->carecloudLogin()
            ->assertSet('choosingPractice', true)
            ->assertSet('pickedPracticeId', 'p7');

        $this->assertSame(2605, $component->get('practiceChoices')['total']);
        $this->assertLessThanOrEqual(41, count($component->get('practiceChoices')['items']));
        $this->assertGreaterThan(41, $component->get('practiceChoices')['matched']);

        $component->set('practiceSearch', 'needle');
        $this->assertSame(['p1500'], array_column($component->get('practiceChoices')['items'], 'id'));

        $component->set('pickedPracticeId', 'p1500')->call('choosePractice');

        $user = User::where('email', 'talha@practice.com')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Unique Needle Clinic', $user->practice->name);
        $this->assertSame('p1500', $user->practice->external_practice_id);
    }

    public function test_the_pending_multi_practice_login_is_not_kept_in_the_session(): void
    {
        $this->enableCarecloud();
        $practices = collect(range(1, 50))->map(fn ($i) => ['id' => "p{$i}", 'name' => "Practice {$i}", 'user_name' => 'u'])->all();
        $this->fakeCarecloud($this->carecloudPayload(['practices' => $practices, 'selected_practice_id' => 'p1']));

        $this->carecloudLogin();

        $this->assertNull(session('sso_pending'));
        $this->assertNotNull(session('sso_pending_key'));
        $this->assertStringStartsWith('sso_pending:', session('sso_pending_key'));
        // Stored as plain data, because the real cache stores won't unserialize objects.
        $this->assertIsArray(Cache::get(session('sso_pending_key'))['result']);
        $this->assertGuest();
    }

    public function test_a_talkehr_login_never_hits_the_carecloud_endpoint(): void
    {
        config(['services.empower_sso_api.providers.carecloud' => 'https://cch.example.test/EmpowerSSOAPI']);
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        $this->ssoLogin('talkehr');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cch.example.test'));
        $this->assertSame('talkehr', User::where('email', 'jane@practice.com')->first()->practice->source_system);
    }

    public function test_a_successful_talkehr_login_flashes_a_success_toast(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        $this->ssoLogin('talkehr');

        $this->assertSame('Login successful - Please continue with purchasing the package', session('toast'));
        $this->assertSame('success', session('toast_type'));
    }

    public function test_a_talkehr_login_carries_on_to_the_package_being_purchased(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload());
        session([
            'intended_package' => 'professional',
            'intended_billing_cycle' => 'monthly',
            'intended_billable_providers' => 4,
        ]);

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertRedirect(route('portal', [
                'package' => 'professional',
                'billing_cycle' => 'monthly',
                'billable_providers' => 4,
            ]));
    }

    public function test_without_a_chosen_package_a_new_talkehr_client_lands_on_the_pricing_section(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertRedirect(route('home').'#pricing');
    }

    public function test_a_failed_talkehr_login_shows_an_error_toast_and_the_inline_error(): void
    {
        Http::fake([
            config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login' => Http::response(['success' => false, 'message' => 'Invalid username or password.'], 401),
        ]);

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 'wrong')
            ->call('loginViaSso')
            ->assertHasErrors(['ssoPassword'])
            ->assertDispatched('toast', message: 'Invalid username or password.', type: 'error');

        $this->assertGuest();
        $this->assertNull(session('toast'));
    }

    public function test_missing_talkehr_fields_show_an_error_toast(): void
    {
        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->call('loginViaSso')
            ->assertHasErrors(['ssoUsername', 'ssoPassword'])
            ->assertDispatched('toast', message: 'Please enter your username and password.', type: 'error');
    }

    public function test_an_unreachable_talkehr_service_shows_an_error_toast(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertDispatched('toast', message: 'Could not reach the sign-in service. Please try again.', type: 'error');
    }

    public function test_a_normal_email_login_failure_shows_an_error_toast(): void
    {
        Livewire::test('auth.login-form')
            ->set('email', 'nobody@practice.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertDispatched('toast', message: 'These credentials do not match our records.', type: 'error');
    }

    public function test_the_toast_is_present_on_the_sign_in_page_so_flashes_can_show(): void
    {
        foreach (['login', 'home', 'register'] as $route) {
            $html = $this->withoutVite()->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'x-on:toast.window'), "{$route} should have exactly one toast, or every message would show twice.");
        }
    }

    public function test_the_register_page_remembers_the_package_for_the_sign_in_detour(): void
    {
        Package::factory()->create(['slug' => 'advanced', 'annual_price' => 1699, 'is_active' => true]);

        $this->withoutVite()->get(route('register', ['package' => 'advanced', 'billing_cycle' => 'monthly']))->assertOk();

        $this->assertSame('advanced', session('intended_package'));
        $this->assertSame('monthly', session('intended_billing_cycle'));
    }

    public function test_the_partner_specialization_fills_the_practice_specialty(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        $this->ssoLogin();

        $this->assertSame('Cardiology', User::where('email', 'jane@practice.com')->first()->practice->specialty);
    }

    public function test_a_specialization_outside_our_list_is_kept_as_sent(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload(['specialization' => 'Exercise Physiology']));

        $this->ssoLogin();

        $this->assertSame('Exercise Physiology', User::where('email', 'jane@practice.com')->first()->practice->specialty);
    }

    public function test_an_existing_specialty_is_not_overwritten_but_a_blank_one_is_filled(): void
    {
        $withSpecialty = User::factory()->create(['email' => 'jane@practice.com', 'external_id' => '544109']);
        Practice::factory()->create(['user_id' => $withSpecialty->id, 'specialty' => 'Pediatrics']);
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        $this->ssoLogin();

        $this->assertSame('Pediatrics', $withSpecialty->practice->fresh()->specialty);

        $withSpecialty->practice->update(['specialty' => null]);
        auth()->logout();

        $this->ssoLogin();

        $this->assertSame('Cardiology', $withSpecialty->practice->fresh()->specialty);
    }

    public function test_a_client_who_chose_a_package_gets_the_purchasing_toast_too(): void
    {
        $this->fakeEmpowerSsoApi($this->ssoPayload());
        session(['intended_package' => 'advanced']);

        $this->ssoLogin();

        $this->assertSame('Login successful - Please continue with purchasing the package', session('toast'));
    }

    public function test_a_returning_client_with_orders_gets_the_plain_toast_and_the_portal(): void
    {
        $user = User::factory()->create(['email' => 'jane@practice.com', 'external_id' => '544109']);
        Order::factory()->create(['user_id' => $user->id]);
        $this->fakeEmpowerSsoApi($this->ssoPayload());

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertRedirect(route('portal'));

        $this->assertSame('Login successful', session('toast'));
    }
}
