<?php

namespace Tests\Feature;

use App\Http\Middleware\RequirePasswordChange;
use App\Mail\WelcomeCredentialsMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/** Gap 3 in lms-enrollment-plan.md: the emailed welcome password must be changed at first login. */
class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = 'Temp-Password-123!';

    private const NEW = 'My-Own-Password-456!';

    private function clientWithTemporaryPassword(): User
    {
        $user = User::factory()->create(['password' => self::TEMP, 'must_change_password' => true]);
        Order::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function logIn(User $user): void
    {
        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::TEMP)
            ->call('login')
            ->assertRedirect(route('account').'#security');
    }

    public function test_a_guest_who_pays_gets_a_temporary_password_and_the_email_says_so(): void
    {
        Mail::fake();
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response(['status' => true, 'message' => 'Payment Successful', 'data' => ['id' => 'TXN', 'amount' => 1, 'paid' => true, 'status' => 'succeeded']]),
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'jwt']]),
            '*/api/payment/tokenize' => Http::response(['status' => true, 'data' => ['token' => '4111114281501111', 'firstSix' => '411111', 'lastFour' => '1111', 'referenceNumber' => 'REF']]),
        ]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 1)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);

        $user = User::where('email', 'jane@practice.com')->firstOrFail();
        $this->assertTrue($user->must_change_password);

        Mail::assertQueued(WelcomeCredentialsMail::class, fn (WelcomeCredentialsMail $mail) => str_contains($mail->render(), 'This is a temporary password'));

        // The automatic sign-in right after payment is not interrupted: they carry on to the intake.
        $this->get(route('portal'))->assertOk();
    }

    public function test_logging_in_with_the_temporary_password_goes_to_my_account_first(): void
    {
        $user = $this->clientWithTemporaryPassword();

        $this->logIn($user);

        $this->assertTrue(session(RequirePasswordChange::SESSION_KEY));
        $this->get(route('account'))->assertOk()->assertSee('data-temporary-password', false);
    }

    public function test_every_other_page_sends_them_back_until_the_password_is_changed(): void
    {
        $user = $this->clientWithTemporaryPassword();
        $this->logIn($user);

        $this->get(route('portal'))->assertRedirect(route('account').'#security');
        $this->get(route('home'))->assertRedirect(route('account').'#security');
        $this->get(route('lms.launch'))->assertRedirect(route('account').'#security');
        $this->get(route('password.edit'))->assertRedirect(route('account').'#security');

        // Logging out still works.
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_an_lms_handoff_waits_for_the_password_change_then_continues(): void
    {
        $user = $this->clientWithTemporaryPassword();
        $this->logIn($user);

        $authorize = route('oidc.authorize', ['client_id' => 'empower-lms', 'state' => 'abc']);
        $this->get($authorize)->assertRedirect(route('account').'#security');
        $this->assertSame($authorize, session('oidc_authorize_url'));

        Livewire::actingAs($user)
            ->test('auth.change-password-form')
            ->set('currentPassword', self::TEMP)
            ->set('password', self::NEW)
            ->set('password_confirmation', self::NEW)
            ->call('updatePassword')
            ->assertRedirect($authorize);
    }

    public function test_changing_the_password_clears_the_requirement_and_continues_to_the_portal(): void
    {
        $user = $this->clientWithTemporaryPassword();
        $this->logIn($user);

        Livewire::actingAs($user)
            ->test('auth.change-password-form')
            ->set('currentPassword', self::TEMP)
            ->set('password', self::NEW)
            ->set('password_confirmation', self::NEW)
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('portal'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNull(session(RequirePasswordChange::SESSION_KEY));
        $this->get(route('portal'))->assertOk();
    }

    public function test_resetting_the_password_by_email_also_clears_the_requirement(): void
    {
        $user = $this->clientWithTemporaryPassword();
        $token = Password::createToken($user);

        Livewire::test('auth.reset-password-form', ['token' => $token, 'email' => $user->email])
            ->set('password', self::NEW)
            ->set('password_confirmation', self::NEW)
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_users_with_their_own_password_are_never_redirected(): void
    {
        $user = User::factory()->create(['password' => self::TEMP]);
        Order::factory()->create(['user_id' => $user->id]);

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', self::TEMP)
            ->call('login')
            ->assertRedirect(route('portal'));

        $this->get(route('portal'))->assertOk();
    }
}
