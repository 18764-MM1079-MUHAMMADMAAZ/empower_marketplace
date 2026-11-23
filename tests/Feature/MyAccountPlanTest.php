<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\PackageTier;
use App\Enums\PaymentStatus;
use App\Mail\ClientSubscriptionCancellationMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\MtbcCardCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Billing behaviour behind the plan changes and term-end cancellations (built in My Account Phase 3).
 * The v5 My Account page no longer has the plan editor or the Cancel subscription section, but the
 * renewal job still applies scheduled changes and ends cancelled plans, and the portal dashboard
 * still cancels subscriptions.
 */
class MyAccountPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** A client on a paid annual Professional plan, 3 providers. */
    private function client(array $orderAttributes = []): array
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 3]);
        $package = Package::factory()->create([
            'slug' => PackageTier::Professional->value, 'name' => 'Professional Compliance', 'annual_price' => 1200, 'monthly_price' => 120,
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
            ...$orderAttributes,
        ]);

        return [$user, $order, $practice];
    }

    public function test_the_renewal_applies_scheduled_changes_before_charging(): void
    {
        $cipher = new MtbcCardCipher;
        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/detokenize' => Http::response([
                'status' => true,
                'data' => ['value' => $cipher->encrypt('4111111111111111'), 'cvv' => $cipher->encrypt('123'), 'referenceNumber' => 'REF123'],
            ]),
            '*/api/payment/Create_Charge' => Http::response(['status' => true, 'message' => 'Payment Successful', 'data' => ['id' => 'TXN_PLAN']]),
        ]);
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
}
