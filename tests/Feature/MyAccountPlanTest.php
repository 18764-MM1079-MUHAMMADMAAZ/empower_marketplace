<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\PackageTier;
use App\Enums\PaymentStatus;
use App\Mail\ClientPlanChangeReceiptMail;
use App\Mail\ClientSubscriptionCancellationMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Practice;
use App\Models\User;
use App\Services\MtbcCardCipher;
use App\Services\PlanChangeService;
use App\Services\TrialBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** My Account Phase 3: provider count and billing period changes, and cancelling at term end. */
class MyAccountPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** A client on a paid annual Professional plan, 3 providers, renewing in ~6 months. */
    private function client(array $orderAttributes = [], array $packageAttributes = []): array
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 3]);
        $package = Package::factory()->create([
            'slug' => PackageTier::Professional->value, 'name' => 'Professional Compliance', 'annual_price' => 1200, 'monthly_price' => 120,
            ...$packageAttributes,
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Paid,
            'billing_cycle' => BillingCycle::Annual,
            'next_bill_date' => now()->addDays(146)->startOfDay(),
            'clover_card_token' => 'tok_saved',
            'card_last_four' => '4242',
            'card_expiry_month' => 12,
            'card_expiry_year' => (int) now()->addYears(3)->format('Y'),
            'billing_address' => ['name' => 'Jane Provider', 'address1' => '7 Clyde Road', 'city' => 'Somerset', 'state' => 'NJ', 'zip' => '08873'],
            ...$orderAttributes,
        ]);

        return [$user, $order, $practice];
    }

    private function fakeCharge(bool $succeeds = true): void
    {
        $cipher = new MtbcCardCipher;

        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/detokenize' => Http::response([
                'status' => true,
                'data' => ['value' => $cipher->encrypt('4111111111111111'), 'cvv' => $cipher->encrypt('123'), 'referenceNumber' => 'REF123'],
            ]),
            '*/api/payment/Create_Charge' => $succeeds
                ? Http::response(['status' => true, 'message' => 'Payment Successful', 'data' => ['id' => 'TXN_PLAN']])
                : Http::response(['status' => false, 'message' => 'Card declined']),
        ]);
    }

    // --- Adding providers ---

    public function test_adding_providers_charges_the_prorated_difference_after_confirmation(): void
    {
        $this->fakeCharge();
        [$user, $order, $practice] = $this->client();
        $expected = app(PlanChangeService::class)->prorated($order, 2);
        $this->assertGreaterThan(0, $expected);
        // About 146 of 365 days left: 2 providers × $1,200 × 0.4.
        $this->assertEqualsWithDelta(2 * 1200 * 146 / 365, $expected, 5);

        $component = Livewire::actingAs($user)->test('account.plan-billing')
            ->call('changeProviders', 1)
            ->call('changeProviders', 1)
            ->assertSee('adds a prorated charge of $'.number_format($expected, 2))
            ->call('savePlan')
            ->assertSet('confirmingCharge', true)
            ->assertSee('Confirm and pay');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'Create_Charge'));
        $this->assertSame(3, $practice->fresh()->billable_providers_count);

        $component->call('savePlan')->assertSet('confirmingCharge', false)->assertDispatched('toast');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'Create_Charge') && (float) $request['amount'] === $expected);
        $this->assertSame(5, $practice->fresh()->billable_providers_count);
        $payment = PaymentLog::where('order_id', $order->id)->where('success', true)->sole();
        $this->assertEquals($expected, (float) $payment->amount);
        $this->assertStringContainsString('Added 2 providers (3 → 5)', $payment->message);
        Mail::assertQueued(ClientPlanChangeReceiptMail::class, fn ($mail) => $mail->payment->is($payment) && $mail->providers === 5);
        // The next renewal is billed for the new count.
        $this->assertEquals(5 * 1200, app(TrialBillingService::class)->renewalAmount($order->fresh()));
    }

    public function test_a_declined_charge_leaves_the_plan_unchanged(): void
    {
        $this->fakeCharge(succeeds: false);
        [$user, $order, $practice] = $this->client();

        Livewire::actingAs($user)->test('account.plan-billing')
            ->call('changeProviders', 1)
            ->call('savePlan')
            ->call('savePlan')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(3, $practice->fresh()->billable_providers_count);
        $this->assertDatabaseHas('payment_logs', ['order_id' => $order->id, 'success' => false]);
        Mail::assertNotQueued(ClientPlanChangeReceiptMail::class);
    }

    // --- Fewer providers and billing period: at the next renewal ---

    public function test_fewer_providers_waits_for_the_next_renewal(): void
    {
        Http::fake();
        [$user, $order, $practice] = $this->client();

        Livewire::actingAs($user)->test('account.plan-billing')
            ->call('changeProviders', -1)
            ->assertSee('Fewer providers takes effect at your next renewal')
            ->call('savePlan')
            ->assertSet('providers', 2)
            ->assertSeeHtml('data-scheduled-change');

        Http::assertNothingSent();
        $practice->refresh();
        $this->assertSame(3, $practice->billable_providers_count);
        $this->assertSame(2, $practice->scheduled_billable_providers);
        $this->assertEquals(2 * 1200, app(TrialBillingService::class)->renewalAmount($order->fresh()));
    }

    public function test_switching_to_monthly_waits_for_the_next_renewal(): void
    {
        Http::fake();
        [$user, $order] = $this->client();

        Livewire::actingAs($user)->test('account.plan-billing')
            ->call('setCycle', 'monthly')
            ->assertSee('Monthly billing starts at your next renewal')
            ->call('savePlan');

        Http::assertNothingSent();
        $order->refresh();
        $this->assertSame(BillingCycle::Annual, $order->billing_cycle);
        $this->assertSame(BillingCycle::Monthly, $order->scheduled_billing_cycle);
        $this->assertEquals(3 * 120, app(TrialBillingService::class)->renewalAmount($order));
    }

    public function test_choosing_the_current_plan_again_removes_a_scheduled_change(): void
    {
        [$user, $order, $practice] = $this->client(['scheduled_billing_cycle' => BillingCycle::Monthly]);
        $practice->update(['scheduled_billable_providers' => 2]);

        Livewire::actingAs($user)->test('account.plan-billing')
            ->assertSet('providers', 2)
            ->assertSet('cycle', 'monthly')
            ->call('changeProviders', 1)
            ->call('setCycle', 'annual')
            ->call('savePlan');

        $this->assertNull($order->fresh()->scheduled_billing_cycle);
        $this->assertNull($practice->fresh()->scheduled_billable_providers);
        $this->assertSame(3, $practice->fresh()->billable_providers_count);
    }

    public function test_the_renewal_applies_scheduled_changes_before_charging(): void
    {
        $this->fakeCharge();
        $due = now()->startOfDay();
        [, $order, $practice] = $this->client(['next_bill_date' => $due, 'scheduled_billing_cycle' => BillingCycle::Monthly]);
        $practice->update(['scheduled_billable_providers' => 2]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $practice->refresh();
        $this->assertSame(BillingCycle::Monthly, $order->billing_cycle);
        $this->assertNull($order->scheduled_billing_cycle);
        $this->assertSame(2, $practice->billable_providers_count);
        $this->assertNull($practice->scheduled_billable_providers);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'Create_Charge') && (float) $request['amount'] === 240.0);
        $this->assertTrue($order->next_bill_date->isSameDay($due->copy()->addMonth()));
    }

    public function test_monthly_billing_is_not_offered_without_a_monthly_price(): void
    {
        [$user] = $this->client(packageAttributes: ['monthly_price' => null]);

        Livewire::actingAs($user)->test('account.plan-billing')
            ->assertDontSeeHtml("wire:click=\"setCycle('monthly')\"")
            ->call('setCycle', 'monthly')
            ->assertSee('Monthly billing isn\'t available for your package.');
    }

    public function test_plan_changes_are_refused_while_a_payment_is_past_due(): void
    {
        Http::fake();
        [$user, , $practice] = $this->client(['payment_status' => PaymentStatus::PastDue, 'next_bill_date' => now()->subDays(2)]);

        Livewire::actingAs($user)->test('account.plan-billing')
            ->call('changeProviders', 1)
            ->assertSee('Your last payment didn')
            ->call('savePlan')
            ->call('savePlan');

        Http::assertNothingSent();
        $this->assertSame(3, $practice->fresh()->billable_providers_count);
    }

    // --- Free trials ---

    public function test_trial_changes_apply_straight_away_without_charging(): void
    {
        Http::fake();
        [$user, $order, $practice] = $this->client([
            'payment_status' => PaymentStatus::Trialing, 'trial_ends_at' => now()->addDays(10), 'next_bill_date' => null, 'original_price' => 3600,
        ]);

        Livewire::actingAs($user)->test('account.plan-billing')
            ->call('changeProviders', 1)
            ->call('setCycle', 'monthly')
            ->assertSee('Your first payment after the free trial will be $480.00 per month.')
            ->call('savePlan')
            ->assertSet('confirmingCharge', false);

        Http::assertNothingSent();
        $order->refresh();
        $this->assertSame(4, $practice->fresh()->billable_providers_count);
        $this->assertSame(BillingCycle::Monthly, $order->billing_cycle);
        $this->assertEquals(480, (float) $order->original_price);
    }

    // --- Cancelling ---

    public function test_cancelling_a_paid_plan_keeps_it_active_until_the_term_ends(): void
    {
        [$user, $order] = $this->client();

        Livewire::actingAs($user)->test('account.cancel-subscription')
            ->call('confirm', $order->id)
            ->assertSee('Yes, cancel at term end')
            ->call('cancel', $order->id)
            ->assertSee('Keep my plan')
            ->assertSeeInOrder(['ends on', $order->next_bill_date->format('M j, Y')]);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertTrue($order->cancels_at->isSameDay($order->next_bill_date));
        $this->assertTrue($user->fresh()->orders->first()->isPaid());
        Mail::assertQueued(ClientSubscriptionCancellationMail::class, fn ($mail) => $mail->ended === false);

        Livewire::actingAs($user)->test('account.plan-billing')
            ->assertSee('Ends '.$order->cancels_at->format('M j, Y'))
            ->assertDontSeeHtml('data-plan-editor')
            ->assertDontSeeHtml('data-billing-row="Scheduled"');
    }

    public function test_keep_my_plan_undoes_the_cancellation(): void
    {
        [$user, $order] = $this->client(['cancels_at' => now()->addDays(146)]);

        Livewire::actingAs($user)->test('account.cancel-subscription')->call('keep', $order->id);

        $this->assertNull($order->fresh()->cancels_at);
    }

    public function test_a_cancelled_plan_ends_on_its_date_instead_of_renewing(): void
    {
        Http::fake();
        $end = now()->startOfDay();
        [, $order] = $this->client(['next_bill_date' => $end, 'cancels_at' => $end]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNull($order->cancels_at);
        Http::assertNothingSent();
        Mail::assertQueued(ClientSubscriptionCancellationMail::class, fn ($mail) => $mail->ended === true);
    }

    public function test_a_plan_cancelled_for_later_is_never_renewed_early(): void
    {
        Http::fake();
        [, $order] = $this->client(['next_bill_date' => now()->subDay(), 'cancels_at' => now()->addDays(5)]);

        $this->artisan('subscriptions:process-billing');

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_cancelling_a_trial_ends_it_straight_away(): void
    {
        [$user, $order] = $this->client(['payment_status' => PaymentStatus::Trialing, 'trial_ends_at' => now()->addDays(10), 'next_bill_date' => null]);

        Livewire::actingAs($user)->test('account.cancel-subscription')
            ->call('confirm', $order->id)
            ->assertSee('Yes, cancel now')
            ->call('cancel', $order->id);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_another_clients_subscription_cannot_be_cancelled(): void
    {
        [$user] = $this->client();
        $other = Order::factory()->create(['payment_status' => PaymentStatus::Paid]);

        Livewire::actingAs($user)->test('account.cancel-subscription')->call('cancel', $other->id)->assertNotFound();

        $this->assertNull($other->fresh()->cancels_at);
        $this->assertNotSame(OrderStatus::Cancelled, $other->fresh()->status);
    }
}
