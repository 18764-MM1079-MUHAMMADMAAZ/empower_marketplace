<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Two-step verification: My Account setup (Fortify actions) and the login form's code step. */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Old-Secret-2025!';

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret));
    }

    /** A user with two-step verification already on. */
    private function userWithTwoFactor(array $attributes = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD, ...$attributes]);
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    // --- My Account ---

    public function test_the_security_section_shows_two_step_verification_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('account'))
            ->assertOk()
            ->assertSee('data-two-factor-state="off"', false)
            ->assertSee('Two-step verification');
    }

    public function test_turning_it_on_asks_for_the_password_first(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        Livewire::actingAs($user)
            ->test('account.two-factor')
            ->call('start', 'enable')
            ->assertSet('pendingAction', 'enable')
            ->set('password', 'wrong-password')
            ->call('confirmPassword')
            ->assertHasErrors(['password']);

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_setup_shows_a_qr_code_and_is_confirmed_with_a_code(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $component = Livewire::actingAs($user)
            ->test('account.two-factor')
            ->call('start', 'enable')
            ->set('password', self::PASSWORD)
            ->call('confirmPassword')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-two-factor-state="setup"')
            ->assertSeeHtml('<svg')
            ->assertSeeHtml('data-two-factor-key');

        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());

        $component->set('code', '000000')->call('confirmSetup')->assertHasErrors(['code']);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());

        $component->set('code', $this->currentCode($user))
            ->call('confirmSetup')
            ->assertHasNoErrors()
            ->assertSet('showingRecoveryCodes', true)
            ->assertSeeHtml('data-recovery-codes')
            ->assertSee($user->fresh()->recoveryCodes()[0]);

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'account.two_factor_enabled', 'user_id' => $user->id]);
    }

    public function test_cancelling_setup_removes_the_unconfirmed_secret(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        Livewire::actingAs($user)
            ->test('account.two-factor')
            ->call('start', 'enable')
            ->set('password', self::PASSWORD)
            ->call('confirmPassword')
            ->call('cancelSetup')
            ->assertSeeHtml('data-two-factor-state="off"');

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_cancel_setup_does_not_turn_off_a_confirmed_setup(): void
    {
        $user = $this->userWithTwoFactor();

        Livewire::actingAs($user)->test('account.two-factor')->call('cancelSetup');

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_recovery_codes_are_shown_only_after_the_password(): void
    {
        $user = $this->userWithTwoFactor();

        Livewire::actingAs($user)
            ->test('account.two-factor')
            ->assertSeeHtml('data-two-factor-state="on"')
            ->assertDontSeeHtml('data-recovery-codes')
            ->call('start', 'codes')
            ->assertDontSeeHtml('data-recovery-codes')
            ->set('password', self::PASSWORD)
            ->call('confirmPassword')
            ->assertSeeHtml('data-recovery-codes')
            ->assertSee($user->recoveryCodes()[0]);
    }

    public function test_making_new_recovery_codes_replaces_the_old_ones(): void
    {
        $user = $this->userWithTwoFactor();
        $old = $user->recoveryCodes();

        Livewire::actingAs($user)
            ->test('account.two-factor')
            ->call('start', 'regenerate')
            ->set('password', self::PASSWORD)
            ->call('confirmPassword');

        $this->assertNotEquals($old, $user->fresh()->recoveryCodes());
        $this->assertCount(8, $user->fresh()->recoveryCodes());
    }

    public function test_turning_it_off_needs_the_password(): void
    {
        $user = $this->userWithTwoFactor();

        Livewire::actingAs($user)
            ->test('account.two-factor')
            ->call('start', 'disable')
            ->set('password', self::PASSWORD)
            ->call('confirmPassword')
            ->assertSeeHtml('data-two-factor-state="off"');

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'account.two_factor_disabled']);
    }

    public function test_a_recently_confirmed_password_is_not_asked_again(): void
    {
        $user = $this->userWithTwoFactor();

        session(['auth.password_confirmed_at' => now()->getTimestamp() - 60]);

        Livewire::actingAs($user)
            ->test('account.two-factor')
            ->call('start', 'disable')
            ->assertSet('pendingAction', null);

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_an_old_password_confirmation_is_asked_again(): void
    {
        $user = $this->userWithTwoFactor();

        session(['auth.password_confirmed_at' => now()->getTimestamp() - 3600]);

        Livewire::actingAs($user)
            ->test('account.two-factor')
            ->call('start', 'disable')
            ->assertSet('pendingAction', 'disable');

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_the_secret_and_recovery_codes_are_never_serialised(): void
    {
        $user = $this->userWithTwoFactor();

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }

    public function test_fortify_routes_are_not_registered(): void
    {
        foreach (['two-factor.login', 'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.recovery-codes', 'login.store'] as $name) {
            $this->assertFalse(Route::has($name), "Fortify route {$name} should not be registered.");
        }
    }

    // --- Signing in ---

    public function test_signing_in_without_two_step_is_unchanged(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertSet('twoFactorChallenge', false)
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_correct_password_then_asks_for_the_code(): void
    {
        $user = $this->userWithTwoFactor();

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertSet('twoFactorChallenge', true)
            ->assertSet('password', '')
            ->assertSeeHtml('data-two-factor-challenge')
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_a_wrong_password_never_reaches_the_code_step(): void
    {
        $user = $this->userWithTwoFactor();

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', 'Wrong-Password-1!')
            ->call('login')
            ->assertHasErrors(['email'])
            ->assertSet('twoFactorChallenge', false);

        $this->assertGuest();
    }

    public function test_the_right_code_signs_in_and_a_wrong_one_does_not(): void
    {
        $user = $this->userWithTwoFactor();

        $component = Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->set('remember', true)
            ->call('login');

        $component->set('twoFactorCode', '000000')->call('verifyTwoFactor')->assertHasErrors(['twoFactorCode']);
        $this->assertGuest();

        $component->set('twoFactorCode', $this->currentCode($user))
            ->call('verifyTwoFactor')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('login.two_factor'));
        $this->assertNotNull($user->fresh()->remember_token, 'Remember me carries through the code step.');
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $user = $this->userWithTwoFactor();
        $recovery = $user->recoveryCodes()[0];

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->call('toggleRecoveryCode')
            ->assertSet('useRecoveryCode', true)
            ->set('twoFactorCode', $recovery)
            ->call('verifyTwoFactor')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($recovery, $user->fresh()->recoveryCodes());
        $this->assertCount(8, $user->fresh()->recoveryCodes());
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'auth.two_factor_recovery_code_used']);
    }

    public function test_the_code_step_times_out(): void
    {
        $user = $this->userWithTwoFactor();

        $component = Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login');

        $this->travel(11)->minutes();

        $component->set('twoFactorCode', $this->currentCode($user))
            ->call('verifyTwoFactor')
            ->assertSet('twoFactorChallenge', false)
            ->assertHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_the_code_step_cannot_be_used_without_a_password_first(): void
    {
        Livewire::test('auth.login-form')
            ->set('twoFactorCode', '123456')
            ->call('verifyTwoFactor')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_too_many_wrong_codes_are_throttled(): void
    {
        $user = $this->userWithTwoFactor();

        $component = Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login');

        foreach (range(1, 5) as $attempt) {
            $component->set('twoFactorCode', '000000')->call('verifyTwoFactor');
        }

        $component->set('twoFactorCode', $this->currentCode($user))->call('verifyTwoFactor');

        $this->assertGuest();
        $this->assertStringContainsString('Too many tries', $component->errors()->first('twoFactorCode'));
    }

    public function test_back_leaves_the_code_step(): void
    {
        $user = $this->userWithTwoFactor();

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->call('cancelTwoFactor')
            ->assertSet('twoFactorChallenge', false)
            ->assertSeeHtml('wire:submit="login"');

        $this->assertNull(session('login.two_factor'));
    }

    public function test_a_partner_sign_in_also_asks_for_the_code(): void
    {
        $user = $this->userWithTwoFactor(['email' => 'jane@practice.com']);
        Http::fake([
            config('services.empower_sso_api.providers.talkehr').'/api/Auth/Login' => Http::response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => ['email' => 'jane@practice.com', 'first_name' => 'Jane', 'last_name' => 'Provider'],
            ]),
        ]);

        $component = Livewire::test('auth.login-form')
            ->call('selectSsoProvider', 'talkehr')
            ->set('ssoUsername', '1163testing')
            ->set('ssoPassword', 't@lkTest@1234')
            ->call('loginViaSso')
            ->assertSet('twoFactorChallenge', true);

        $this->assertGuest();

        $component->set('twoFactorCode', $this->currentCode($user))
            ->call('verifyTwoFactor')
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'sso.empower_sso_api_login', 'user_id' => $user->id]);
    }

    public function test_confirm_action_rejects_a_bad_code(): void
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);

        $this->expectException(ValidationException::class);
        app(ConfirmTwoFactorAuthentication::class)($user->fresh(), '000000');
    }
}
