<?php

namespace Tests\Feature;

use App\Enums\AdminEmail;
use App\Enums\UserRole;
use App\Mail\NewLeadNotificationMail;
use App\Models\User;
use App\Services\UserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** My Account › Sign-in & security (devices, password rules) and the admin Notifications section. */
class MyAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin User', ...$attributes]);
    }

    /** Switches to database sessions and records this test's session plus one other device. */
    private function devices(User $user): string
    {
        config(['session.driver' => 'database']);

        DB::table('sessions')->insert([
            ['id' => session()->getId(), 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => self::CHROME_WINDOWS, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'other-device-session', 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => self::SAFARI_IPHONE, 'payload' => '', 'last_activity' => now()->subHours(3)->timestamp],
        ]);

        return 'other-device-session';
    }

    // --- The page ---

    public function test_an_admin_sees_sign_in_security_and_notifications_only(): void
    {
        $this->actingAs($this->admin())
            ->get(route('account'))
            ->assertOk()
            ->assertSee('data-account-nav="security"', false)
            ->assertSee('data-account-nav="notifications"', false)
            ->assertDontSee('data-account-nav="overview"', false)
            ->assertDontSee('data-account-nav="plan"', false)
            ->assertSee("Where you're signed in", false)
            ->assertSee('Sign-in method')
            ->assertSee(AdminEmail::Payments->label())
            ->assertSee(AdminEmail::NewLeads->label());
    }

    public function test_a_client_does_not_get_the_admin_notifications_section(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->assertSee('data-account-nav="security"', false)
            ->assertDontSee('data-account-nav="notifications"', false)
            ->assertDontSee(AdminEmail::Payments->label());
    }

    public function test_the_notifications_component_is_admin_only(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('account.notifications')
            ->assertForbidden();
    }

    // --- Notifications ---

    public function test_admin_emails_are_on_until_turned_off(): void
    {
        $admin = $this->admin();

        foreach (AdminEmail::cases() as $email) {
            $this->assertTrue($admin->wantsEmail($email));
        }
    }

    public function test_an_admin_can_turn_an_email_off_and_back_on(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('account.notifications')
            ->call('toggle', AdminEmail::NewLeads->value)
            ->assertDispatched('toast')
            ->assertSeeHtml('aria-checked="false"');

        $this->assertFalse($admin->fresh()->wantsEmail(AdminEmail::NewLeads));
        $this->assertTrue($admin->fresh()->wantsEmail(AdminEmail::Payments));

        Livewire::actingAs($admin->fresh())
            ->test('account.notifications')
            ->call('toggle', AdminEmail::NewLeads->value);

        $this->assertTrue($admin->fresh()->wantsEmail(AdminEmail::NewLeads));
    }

    public function test_an_unknown_preference_is_ignored(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('account.notifications')
            ->call('toggle', 'not-a-preference');

        $this->assertNull($admin->fresh()->notification_preferences);
    }

    public function test_an_admin_who_turned_off_new_leads_does_not_get_the_email(): void
    {
        Mail::fake();
        $optedOut = $this->admin(['email' => 'quiet@empower.test', 'notification_preferences' => [AdminEmail::NewLeads->value => false]]);
        $optedIn = $this->admin(['email' => 'loud@empower.test']);

        Livewire::test('contact-dialog')
            ->call('openWith', 'general')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->set('practiceName', 'Provider Family Medicine')
            ->set('billableProviders', 3)
            ->set('message', 'Looking for compliance help.')
            ->call('submit')
            ->assertSet('submitted', true);

        Mail::assertSent(NewLeadNotificationMail::class, fn ($mail) => $mail->hasTo($optedIn->email));
        Mail::assertNotSent(NewLeadNotificationMail::class, fn ($mail) => $mail->hasTo($optedOut->email));
    }

    // --- Password rules ---

    public function test_a_weak_new_password_is_rejected_with_each_rule(): void
    {
        $user = User::factory()->create(['password' => 'Old-Secret-2025!']);

        Livewire::actingAs($user)
            ->test('auth.change-password-form')
            ->set('currentPassword', 'Old-Secret-2025!')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        $this->assertTrue(Hash::check('Old-Secret-2025!', $user->fresh()->password));
    }

    public function test_the_change_password_form_shows_the_strength_meter(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('auth.change-password-form')
            ->assertSeeHtml('data-password-meter')
            ->assertSee('At least 12 characters');
    }

    // --- Signed-in devices ---

    public function test_the_device_list_shows_this_device_first_and_the_others(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->devices($admin);

        Livewire::test('account.security')
            ->assertSeeHtmlInOrder(['data-current-device', 'Chrome on Windows', 'This device', 'Safari on iPhone', '203.0.113.9'])
            ->assertSee('Sign out of all other devices');
    }

    public function test_signing_out_another_device_removes_its_session_and_keeps_this_one(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $other = $this->devices($admin);
        $token = $admin->fresh()->remember_token;

        Livewire::test('account.security')
            ->call('signOutDevice', $other)
            ->assertDispatched('toast')
            ->assertDontSee('Safari on iPhone');

        $this->assertDatabaseMissing('sessions', ['id' => $other]);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId()]);
        $this->assertNotSame($token, $admin->fresh()->remember_token);
    }

    public function test_this_device_cannot_be_signed_out_from_the_list(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->devices($admin);

        Livewire::test('account.security')->call('signOutDevice', session()->getId());

        $this->assertDatabaseHas('sessions', ['id' => session()->getId()]);
    }

    public function test_another_users_session_cannot_be_signed_out(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->devices($admin);
        DB::table('sessions')->insert(['id' => 'someone-else', 'user_id' => User::factory()->create()->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp]);

        Livewire::test('account.security')->call('signOutDevice', 'someone-else');

        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);
    }

    public function test_sign_out_all_other_devices(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->devices($admin);

        Livewire::test('account.security')->call('signOutOtherDevices');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => session()->getId()]);
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        $admin = $this->admin(['password' => 'Old-Secret-2025!']);
        $this->actingAs($admin);
        $other = $this->devices($admin);

        Livewire::test('auth.change-password-form')
            ->set('currentPassword', 'Old-Secret-2025!')
            ->set('password', 'New-Secret-2026!')
            ->set('password_confirmation', 'New-Secret-2026!')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertDispatched('password-updated')
            ->assertSee('We signed you out on 1 other device.');

        $this->assertDatabaseMissing('sessions', ['id' => $other]);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId()]);
    }

    public function test_without_database_sessions_the_page_says_the_list_is_unavailable(): void
    {
        config(['session.driver' => 'array']);

        Livewire::actingAs($this->admin())
            ->test('account.security')
            ->assertSee("The device list isn't available on this server yet.", false);
    }

    public function test_user_agents_are_described_in_plain_words(): void
    {
        $this->assertSame(['browser' => 'Chrome', 'platform' => 'Windows', 'mobile' => false], UserSessions::describe(self::CHROME_WINDOWS));
        $this->assertSame(['browser' => 'Safari', 'platform' => 'iPhone', 'mobile' => true], UserSessions::describe(self::SAFARI_IPHONE));
        $this->assertSame('Edge', UserSessions::describe('Mozilla/5.0 (Windows NT 10.0) Chrome/131.0 Safari/537.36 Edg/131.0')['browser']);
        $this->assertSame('Firefox', UserSessions::describe('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.1; rv:132.0) Gecko/20100101 Firefox/132.0')['browser']);
        $this->assertSame(['browser' => 'Unknown browser', 'platform' => 'Unknown system', 'mobile' => false], UserSessions::describe(null));
    }
}
