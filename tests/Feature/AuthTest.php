<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\ResetPasswordMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
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

    public function test_login_with_valid_credentials_and_no_orders_redirects_to_home(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('home'));
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
            config('services.empower_sso_api.base_url').'/api/Auth/Login' => Http::response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [$data],
            ]),
        ]);
    }

    public function test_selecting_a_provider_shows_the_inline_sso_form(): void
    {
        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'carecloud')
            ->assertSet('ssoProvider', 'carecloud')
            ->assertSee('Username');
    }

    public function test_sso_login_creates_a_new_user_and_practice_from_the_returned_identity(): void
    {
        $this->fakeEmpowerSsoApi([
            'userId' => '544109',
            'email' => 'jane@practice.com',
            'firstName' => 'Jane',
            'lastName' => 'Provider',
            'practiceName' => 'Riverside Family Medicine',
            'prac_Address' => '742 Evergreen Terrace',
            'prac_city' => 'Springfield',
            'prac_State' => 'IL',
            'zip' => '62704',
        ]);

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'carecloud')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

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

        $this->fakeEmpowerSsoApi(['email' => 'jane@practice.com', 'firstName' => 'Jane', 'lastName' => 'Provider']);

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
            config('services.empower_sso_api.base_url').'/api/Auth/Login' => Http::response([
                'success' => false,
                'message' => 'Invalid username or password.',
                'data' => null,
            ], 401),
        ]);

        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'carecloud')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 'wrong-password')
            ->call('loginViaSso')
            ->assertHasErrors(['ssoPassword']);

        $this->assertGuest();
    }

    public function test_back_from_sso_returns_to_the_provider_buttons(): void
    {
        Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'carecloud')
            ->assertSet('ssoProvider', 'carecloud')
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
        ]));

        $response->assertOk();
        $response->assertSee(route('portal', ['package' => 'essential', 'billing_cycle' => 'monthly']));
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
            ->assertRedirect(route('home'));
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
}
