<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SpecialistCallStatus;
use App\Enums\UserRole;
use App\Mail\ClientSpecialistCallMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\Setting;
use App\Models\SpecialistCallRequest;
use App\Models\User;
use App\Services\SpecialistCallSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class SpecialistCallAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function makeCall(array $attributes = []): SpecialistCallRequest
    {
        return SpecialistCallRequest::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'requested_date' => now()->addDay()->toDateString(),
            'requested_time' => '9:00 AM',
            'phone' => '+15551234567',
            'topic' => 'Pricing or billing',
        ], $attributes));
    }

    public function test_admin_sees_call_requests_and_clients_cannot(): void
    {
        $call = $this->makeCall();

        $this->withoutVite()->actingAs($this->admin())->get(route('admin.specialist-calls'))->assertOk();

        $this->actingAs(User::factory()->create())->get(route('admin.specialist-calls'))->assertRedirect();

        Livewire::actingAs($this->admin())
            ->test('admin.specialist-call-list')
            ->assertSee($call->user->email)
            ->assertSee('Pricing or billing');
    }

    public function test_new_requests_default_to_pending_and_admin_can_change_status(): void
    {
        $call = $this->makeCall();
        $this->assertSame(SpecialistCallStatus::Pending, $call->fresh()->status);

        Livewire::actingAs($this->admin())
            ->test('admin.specialist-call-list')
            ->call('setStatus', $call->id, 'scheduled')
            ->call('setStatus', $call->id, 'bogus');

        $this->assertSame(SpecialistCallStatus::Scheduled, $call->fresh()->status);
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        $pending = $this->makeCall();
        $done = $this->makeCall(['status' => SpecialistCallStatus::Completed]);

        Livewire::actingAs($this->admin())
            ->test('admin.specialist-call-list')
            ->set('status', 'completed')
            ->assertSee($done->user->email)
            ->assertDontSee($pending->user->email);
    }

    public function test_admin_can_export_the_calls_to_excel(): void
    {
        $this->makeCall();

        Livewire::actingAs($this->admin())
            ->test('admin.specialist-call-list')
            ->call('export')
            ->assertFileDownloaded();
    }

    public function test_settings_default_until_an_admin_saves_them(): void
    {
        $this->assertTrue(SpecialistCallSettings::enabled());
        $this->assertSame(SpecialistCallSettings::DEFAULT_TIME_SLOTS, SpecialistCallSettings::timeSlots());
        $this->assertSame(5, SpecialistCallSettings::daysAhead());
    }

    public function test_admin_can_save_call_settings(): void
    {
        Livewire::actingAs($this->admin())
            ->test('admin.call-settings')
            ->set('timeSlots', "8:00 AM\n\n2:00 PM\n")
            ->set('topics', "Onboarding\nBilling")
            ->set('daysAhead', 3)
            ->set('enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['8:00 AM', '2:00 PM'], SpecialistCallSettings::timeSlots());
        $this->assertSame(['Onboarding', 'Billing'], SpecialistCallSettings::topics());
        $this->assertSame(3, SpecialistCallSettings::daysAhead());
        $this->assertFalse(SpecialistCallSettings::enabled());
    }

    public function test_call_settings_validate_their_input(): void
    {
        Livewire::actingAs($this->admin())
            ->test('admin.call-settings')
            ->set('timeSlots', '')
            ->set('daysAhead', 0)
            ->call('save')
            ->assertHasErrors(['timeSlots', 'daysAhead']);
    }

    public function test_wizard_uses_the_saved_slots_and_refuses_bookings_when_switched_off(): void
    {
        Setting::write('call_time_slots', json_encode(['8:00 AM']));
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $component = Livewire::actingAs($user)->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]]);

        $this->assertSame(['8:00 AM'], $component->instance()->availableCallTimes());

        Setting::write('calls_enabled', '0');

        $component->call('openCallDialog')
            ->set('callPhone', '+15551234567')
            ->call('bookSpecialistCall');

        $this->assertDatabaseCount('specialist_call_requests', 0);
    }

    public function test_client_is_emailed_when_a_call_is_scheduled_or_cancelled_but_not_completed(): void
    {
        Mail::fake();
        $call = $this->makeCall();
        $component = Livewire::actingAs($this->admin())->test('admin.specialist-call-list');

        $component->call('setStatus', $call->id, 'scheduled');
        Mail::assertSent(ClientSpecialistCallMail::class, fn ($m) => $m->event === 'scheduled' && $m->hasTo($call->user->email)
            && str_contains($m->render(), 'confirmed'));

        $component->call('setStatus', $call->id, 'cancelled');
        Mail::assertSent(ClientSpecialistCallMail::class, fn ($m) => $m->event === 'cancelled');

        $component->call('setStatus', $call->id, 'completed');
        Mail::assertSent(ClientSpecialistCallMail::class, 2);
    }

    public function test_client_gets_a_confirmation_email_when_booking_a_call(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('openCallDialog')
            ->set('callPhone', '+15551234567')
            ->call('bookSpecialistCall')
            ->assertHasNoErrors();

        Mail::assertSent(ClientSpecialistCallMail::class, fn ($m) => $m->event === 'requested' && $m->hasTo($user->email));
    }
}
