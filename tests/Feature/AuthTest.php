<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\AdminNewSignupMail;
use App\Mail\ResetPasswordMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_registration_creates_user_and_practice_and_logs_in(): void
    {
        Mail::fake();

        Livewire::test('auth.register-form')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->call('register')
            ->assertRedirect(route('portal'));

        $this->assertDatabaseHas('users', ['email' => 'jane@practice.com']);

        $user = User::where('email', 'jane@practice.com')->first();
        $this->assertNotNull($user->practice);
    }

    public function test_registration_completes_even_when_an_admin_notification_email_fails(): void
    {
        // Simulates a bad/reserved admin email address rejecting delivery at the SMTP
        // level — this used to crash the whole registration request with a 500 before
        // the new user's account had a chance to finish being logged in and redirected.
        User::factory()->create(['role' => UserRole::Admin]);

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP rejected the recipient.'));

        Livewire::test('auth.register-form')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->call('register')
            ->assertRedirect(route('portal'));

        $this->assertDatabaseHas('users', ['email' => 'jane@practice.com']);
        $this->assertAuthenticatedAs(User::where('email', 'jane@practice.com')->first());
    }

    public function test_registration_notifies_every_admin_by_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::test('auth.register-form')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->call('register');

        Mail::assertSent(AdminNewSignupMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertSent(AdminNewSignupMail::class, fn ($mail) => $mail->hasTo($otherAdmin->email));
        Mail::assertNotSent(AdminNewSignupMail::class, fn ($mail) => $mail->hasTo('jane@practice.com'));
    }

    public function test_registration_also_creates_an_in_app_notification_for_every_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::test('auth.register-form')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->call('register');

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $otherAdmin->fresh()->unreadNotifications()->count());

        $data = $admin->fresh()->notifications()->first()->data;
        $this->assertSame('New account created', $data['title']);
        $this->assertStringContainsString('Jane Provider', $data['message']);
    }

    public function test_registration_emails_the_generated_password_and_it_works_for_login(): void
    {
        Mail::fake();

        Livewire::test('auth.register-form')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->call('register');

        $capturedPassword = null;

        Mail::assertQueued(WelcomeCredentialsMail::class, function ($mail) use (&$capturedPassword) {
            $capturedPassword = $mail->password;

            return $mail->hasTo('jane@practice.com') && strlen($mail->password) >= 16;
        });

        $user = User::where('email', 'jane@practice.com')->first();

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', $capturedPassword)
            ->call('login')
            ->assertRedirect(route('home'));
    }

    public function test_registration_with_package_redirects_to_portal_with_package(): void
    {
        Mail::fake();

        Livewire::test('auth.register-form', ['package' => 'essential'])
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->call('register')
            ->assertRedirect(route('portal', ['package' => 'essential']));
    }

    public function test_registration_falls_back_to_session_intended_package(): void
    {
        Mail::fake();

        session(['intended_package' => 'essential']);

        Livewire::test('auth.register-form')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->call('register')
            ->assertRedirect(route('portal', ['package' => 'essential']));
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test('auth.register-form')
            ->set('name', 'Jane Provider')
            ->set('email', 'taken@example.com')
            ->call('register')
            ->assertHasErrors(['email']);

        Mail::assertNothingSent();
    }

    public function test_registration_requires_all_fields(): void
    {
        Livewire::test('auth.register-form')
            ->call('register')
            ->assertHasErrors(['name', 'email']);
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
