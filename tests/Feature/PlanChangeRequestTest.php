<?php

namespace Tests\Feature;

use App\Enums\AdminEmail;
use App\Enums\BillingCycle;
use App\Enums\PackageTier;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Mail\PlanChangeRequestMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** My Account › Plan › Request a change: the client asks, an admin changes it in the admin panel. */
class PlanChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
    }

    private function client(array $orderAttributes = [], array $practiceAttributes = []): array
    {
        $user = User::factory()->create(['name' => 'Jane Provider']);
        $practice = Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 3, ...$practiceAttributes]);
        $package = Package::factory()->create(['slug' => PackageTier::Professional->value, 'name' => 'Professional Compliance', 'annual_price' => 1200, 'monthly_price' => 120]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::Paid,
            'billing_cycle' => BillingCycle::Annual,
            'next_bill_date' => now()->addMonths(6),
            ...$orderAttributes,
        ]);

        return [$user, $order, $practice];
    }

    public function test_a_card_client_sends_a_request_that_emails_and_notifies_the_admins(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $optedOut = User::factory()->create(['role' => UserRole::Admin, 'notification_preferences' => [AdminEmail::PlanChangeRequests->value => false]]);
        [$user, $order, $practice] = $this->client();

        Livewire::actingAs($user)->test('account.plan')
            ->assertSeeHtml('data-request-plan-change')
            ->call('startRequest')
            ->assertSet('requestedProviders', 3)
            ->assertSet('requestedCycle', 'annual')
            ->set('requestedProviders', 5)
            ->set('requestedCycle', 'monthly')
            ->set('note', 'From next month please')
            ->call('sendRequest')
            ->assertHasNoErrors()
            ->assertSet('requesting', false)
            ->assertDispatched('toast')
            ->assertSee('Change requested on');

        // Nothing changes or is charged until an admin does it.
        $this->assertSame(3, $practice->fresh()->billable_providers_count);
        $this->assertSame(BillingCycle::Annual, $order->fresh()->billing_cycle);
        Http::assertNothingSent();

        Mail::assertSent(PlanChangeRequestMail::class, fn ($mail) => $mail->hasTo($admin->email)
            && $mail->change['providers'] === 5 && $mail->change['cycle'] === 'monthly' && $mail->change['note'] === 'From next month please');
        Mail::assertNotSent(PlanChangeRequestMail::class, fn ($mail) => $mail->hasTo($optedOut->email));
        $this->assertSame('Plan change requested', $admin->notifications()->first()?->data['title']);
        $this->assertSame(1, $optedOut->notifications()->count(), 'Opting out of the email keeps the bell.');
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'plan.change_requested', 'user_id' => $user->id, 'description' => 'Requested a plan change: 3 → 5 billable providers, billed monthly, note added']);
    }

    public function test_an_unchanged_request_with_no_note_is_refused(): void
    {
        [$user] = $this->client();

        Livewire::actingAs($user)->test('account.plan')
            ->call('startRequest')
            ->call('sendRequest')
            ->assertHasErrors(['requestedProviders']);

        Mail::assertNothingSent();
    }

    public function test_monthly_cannot_be_requested_without_a_monthly_price(): void
    {
        [$user, $order] = $this->client();
        $order->package->update(['monthly_price' => null]);

        Livewire::actingAs($user)->test('account.plan')
            ->call('startRequest')
            ->assertDontSeeHtml('<option value="monthly">')
            ->set('requestedCycle', 'monthly')
            ->call('sendRequest')
            ->assertHasErrors(['requestedCycle']);
    }

    public function test_requests_are_limited_to_three_an_hour(): void
    {
        [$user] = $this->client();
        $component = Livewire::actingAs($user)->test('account.plan');

        foreach ([4, 5, 6] as $providers) {
            $component->call('startRequest')->set('requestedProviders', $providers)->call('sendRequest')->assertHasNoErrors();
        }

        $component->call('startRequest')->set('requestedProviders', 7)->call('sendRequest')->assertHasErrors(['requestedProviders']);
        $this->assertSame(3, ActivityLog::where('event_type', 'plan.change_requested')->count());
    }

    public function test_partner_billed_clients_change_their_plan_with_the_partner(): void
    {
        [$user] = $this->client(['payment_status' => PaymentStatus::ExternallyBilled], ['source_system' => 'talkehr']);

        Livewire::actingAs($user)->test('account.plan')
            ->assertDontSeeHtml('data-request-plan-change')
            ->assertSee('To change your plan, contact talkEHR.')
            ->call('startRequest')
            ->assertForbidden();
    }

    public function test_admins_apply_the_billing_period_on_the_order_and_the_client_sees_it(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$user, $order] = $this->client(['scheduled_billing_cycle' => BillingCycle::Monthly]);
        $nextBill = $order->next_bill_date->toDateString();

        Livewire::actingAs($admin)->test('admin.order-form', ['order' => $order])
            ->assertSet('billingCycle', 'annual')
            ->set('billingCycle', 'monthly')
            ->call('save')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(BillingCycle::Monthly, $order->billing_cycle);
        $this->assertNull($order->scheduled_billing_cycle);
        $this->assertSame($nextBill, $order->next_bill_date->toDateString());

        Livewire::actingAs($user)->test('account.activity-log')
            ->assertSee('Billing period changed to monthly by the Empower team')
            ->assertSee('Empower team');
    }

    public function test_admins_apply_the_provider_count_and_the_client_sees_it(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$user, , $practice] = $this->client();

        Livewire::actingAs($admin)->test('admin.user-form', ['user' => $user])
            ->set('practiceBillableProvidersCount', 5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(5, $practice->fresh()->billable_providers_count);

        Livewire::actingAs($user)->test('account.activity-log')
            ->assertSee('Billable providers changed to 5 by the Empower team');
    }
}
