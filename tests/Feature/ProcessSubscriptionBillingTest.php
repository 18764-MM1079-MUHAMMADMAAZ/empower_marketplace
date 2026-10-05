<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\ClientRenewalFailedMail;
use App\Mail\ClientTrialCancelledMail;
use App\Mail\ClientTrialEndingReminderMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\MtbcCardCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcessSubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fakeDetokenizeAndCharge(bool $chargeSucceeds): void
    {
        $cipher = new MtbcCardCipher;

        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/detokenize' => Http::response([
                'status' => true,
                'data' => ['value' => $cipher->encrypt('4111111111111111'), 'cvv' => $cipher->encrypt('123'), 'referenceNumber' => 'REF123'],
            ]),
            '*/api/payment/Create_Charge' => $chargeSucceeds
                ? Http::response(['status' => true, 'message' => 'Payment Successful', 'data' => ['id' => 'TXN']])
                : Http::response(['status' => false, 'message' => 'Card declined', 'data' => null], 400),
        ]);
    }

    public function test_reminder_is_sent_once_inside_the_window_and_not_resent(): void
    {
        Mail::fake();

        $package = Package::factory()->create();
        $dueSoon = Order::factory()->trialing()->create(['package_id' => $package->id, 'trial_ends_at' => now()->addDays(2)]);
        $dueLater = Order::factory()->trialing()->create(['package_id' => $package->id, 'trial_ends_at' => now()->addDays(10)]);

        $this->artisan('subscriptions:process-billing');

        $dueSoon->refresh();
        $dueLater->refresh();

        $this->assertNotNull($dueSoon->trial_reminder_sent_at);
        $this->assertNull($dueLater->trial_reminder_sent_at);
        Mail::assertQueued(ClientTrialEndingReminderMail::class, 1);

        // A second run the same day must not resend it.
        $this->artisan('subscriptions:process-billing');
        Mail::assertQueued(ClientTrialEndingReminderMail::class, 1);
    }

    public function test_lapsed_trial_without_a_client_response_is_auto_cancelled(): void
    {
        Mail::fake();

        $order = Order::factory()->trialing()->create(['trial_ends_at' => now()->subDay()]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertTrue($order->blockedFromAiGeneration());
        Mail::assertQueued(ClientTrialCancelledMail::class);
    }

    public function test_successful_renewal_advances_next_bill_date_by_one_year_from_the_original_date(): void
    {
        Mail::fake();
        $this->fakeDetokenizeAndCharge(chargeSucceeds: true);

        $originalNextBillDate = now()->subDays(2)->startOfDay();
        $order = Order::factory()->convertedFromTrial()->create(['next_bill_date' => $originalNextBillDate]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(0, $order->renewal_attempts);
        $this->assertTrue($order->next_bill_date->isSameDay($originalNextBillDate->copy()->addYear()));
    }

    public function test_successful_monthly_renewal_advances_next_bill_date_by_one_month_and_charges_the_monthly_price(): void
    {
        Mail::fake();
        $this->fakeDetokenizeAndCharge(chargeSucceeds: true);

        $package = Package::factory()->create(['annual_price' => 999, 'monthly_price' => 89]);
        $originalNextBillDate = now()->subDays(2)->startOfDay();
        $order = Order::factory()->convertedFromTrial()->create([
            'package_id' => $package->id,
            'billing_cycle' => BillingCycle::Monthly,
            'original_price' => 89,
            'next_bill_date' => $originalNextBillDate,
        ]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(0, $order->renewal_attempts);
        $this->assertEquals(89.0, (float) $order->amount_paid);
        $this->assertTrue($order->next_bill_date->isSameDay($originalNextBillDate->copy()->addMonth()));
    }

    public function test_renewal_charges_the_practices_current_provider_count_not_the_price_at_purchase(): void
    {
        Mail::fake();
        $this->fakeDetokenizeAndCharge(chargeSucceeds: true);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 3]);
        $package = Package::factory()->create(['annual_price' => 100]);
        $order = Order::factory()->convertedFromTrial()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            // Frozen at purchase time with only 1 provider — the practice has since grown.
            'original_price' => 100,
            'next_bill_date' => now()->subDays(2),
        ]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertEquals(300.0, (float) $order->amount_paid);
    }

    public function test_a_direct_pay_order_with_no_trial_history_renews_automatically(): void
    {
        Mail::fake();
        $this->fakeDetokenizeAndCharge(chargeSucceeds: true);

        // Shaped exactly like a direct pay() checkout creates it — no trial_ends_at or
        // trial_confirmed_at anywhere in its history — proving the renewal engine only cares
        // about clover_card_token/next_bill_date, not whether the order ever went through a trial.
        $originalNextBillDate = now()->subDays(2)->startOfDay();
        $order = Order::factory()->create([
            'status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Paid,
            'billing_cycle' => BillingCycle::Annual,
            'clover_card_token' => 'tok_'.fake()->lexify('????????????'),
            'card_expiry_month' => 12,
            'card_expiry_year' => (int) now()->addYears(3)->format('Y'),
            'card_last_four' => '4242',
            'next_bill_date' => $originalNextBillDate,
        ]);

        $this->assertNull($order->trial_ends_at);
        $this->assertNull($order->trial_confirmed_at);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(0, $order->renewal_attempts);
        $this->assertTrue($order->next_bill_date->isSameDay($originalNextBillDate->copy()->addYear()));
    }

    public function test_failed_renewal_sets_past_due_and_only_retries_after_the_configured_delay(): void
    {
        Mail::fake();
        $this->fakeDetokenizeAndCharge(chargeSucceeds: false);

        $dueDate = now()->startOfDay();
        $order = Order::factory()->convertedFromTrial()->create(['next_bill_date' => $dueDate]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(PaymentStatus::PastDue, $order->payment_status);
        $this->assertSame(1, $order->renewal_attempts);
        $this->assertSame($dueDate->toDateString(), $order->next_bill_date->toDateString());
        Mail::assertQueued(ClientRenewalFailedMail::class, fn ($mail) => $mail->cancelled === false);

        // A second run the same day must not re-attempt yet (next retry is +3 days out).
        $this->fakeDetokenizeAndCharge(chargeSucceeds: false);
        $this->artisan('subscriptions:process-billing');
        $order->refresh();
        $this->assertSame(1, $order->renewal_attempts);

        // 3 days later, the second attempt fires.
        Carbon::setTestNow(now()->addDays(3));
        $this->fakeDetokenizeAndCharge(chargeSucceeds: false);
        $this->artisan('subscriptions:process-billing');
        $order->refresh();
        $this->assertSame(2, $order->renewal_attempts);
        $this->assertSame(OrderStatus::Paid, $order->status);
    }

    public function test_third_consecutive_renewal_failure_cancels_the_subscription(): void
    {
        Mail::fake();
        $this->fakeDetokenizeAndCharge(chargeSucceeds: false);

        $order = Order::factory()->pastDue(2)->create(['next_bill_date' => now()->subDays(8)]);

        $this->artisan('subscriptions:process-billing');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(3, $order->renewal_attempts);
        $this->assertTrue($order->blockedFromAiGeneration());
        Mail::assertQueued(ClientRenewalFailedMail::class, fn ($mail) => $mail->cancelled === true);
    }
}
