<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\PreLaunchSignupMail;
use App\Models\Package;
use App\Models\Practice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class LaunchGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_shows_the_purchase_gating_widget(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Purchase Gating');
    }

    public function test_purchase_gating_controls_follow_the_updated_layout_order(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Setting::write('public_launch_at', now()->subDay()->toDateTimeString());

        Livewire::actingAs($admin)
            ->test('admin.launch-gate')
            ->assertSeeInOrder(['Open', 'Open since', 'type="datetime-local"', 'Save date', 'Open now']);
    }

    public function test_admin_can_save_a_launch_date_that_closes_purchases(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test('admin.launch-gate')
            ->set('launchAt', now()->addMonth()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull(Setting::read('public_launch_at'));

        Livewire::actingAs(User::factory()->create())->test('portal')
            ->set('selectedPackageId', $package->id)
            ->assertSee('not quite open to the public yet');
    }

    public function test_open_now_reopens_purchases_even_when_the_env_date_is_in_the_future(): void
    {
        config(['app.public_launch_at' => now()->addMonth()]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($admin)->test('admin.launch-gate')->call('openNow');

        Livewire::actingAs(User::factory()->create())->test('portal')
            ->set('selectedPackageId', $package->id)
            ->assertDontSee('not quite open to the public yet');
    }

    public function test_launch_date_is_required(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.launch-gate')
            ->set('launchAt', '')
            ->call('save')
            ->assertHasErrors(['launchAt']);
    }

    public function test_signing_up_for_updates_emails_the_subscriber_the_details(): void
    {
        Mail::fake();
        Setting::write('public_launch_at', '2026-11-11 00:00:00');
        $package = Package::factory()->create(['name' => 'Essential Compliance', 'slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('updatesEmail', 'interested@practice.com')
            ->call('signUpForUpdates')
            ->assertHasNoErrors();

        Mail::assertSent(PreLaunchSignupMail::class, function (PreLaunchSignupMail $mail) {
            return $mail->hasTo('interested@practice.com')
                && $mail->packageName === 'Essential Compliance'
                && str_contains($mail->render(), 'November 11, 2026');
        });
    }

    public function test_pre_launch_sign_ups_are_tagged_as_subscribers(): void
    {
        Mail::fake();
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('updatesEmail', 'interested@practice.com')
            ->call('signUpForUpdates');

        $this->assertDatabaseHas('leads', ['email' => 'interested@practice.com', 'source' => 'subscriber']);
    }

    public function test_sso_clients_can_purchase_before_the_public_launch(): void
    {
        Setting::write('public_launch_at', now()->addMonth()->toDateTimeString());
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        foreach (['cch', 'talkehr'] as $source) {
            $user = User::factory()->create();
            Practice::factory()->create(['user_id' => $user->id, 'source_system' => $source]);

            Livewire::actingAs($user)->test('portal')
                ->set('selectedPackageId', $package->id)
                ->assertDontSee('not quite open to the public yet');
        }
    }

    public function test_public_signups_cannot_pay_or_start_a_trial_before_launch_even_by_calling_directly(): void
    {
        Setting::write('public_launch_at', now()->addMonth()->toDateTimeString());
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'source_system' => null]);

        $component = Livewire::actingAs($user)->test('portal')->set('selectedPackageId', $package->id);

        $component->call('validatePayment', 'A B', '4242424242424242', '12/30', '123')
            ->assertDispatched('toast', type: 'error');
        $component->call('pay', 'A B', '4242424242424242', '12/30', '123', true)
            ->assertDispatched('toast', type: 'error');
        $component->call('payFreeTrial', 'A B', '4242424242424242', '12/30', '123', true)
            ->assertDispatched('toast', type: 'error');

        $this->assertDatabaseCount('orders', 0);
    }
}
