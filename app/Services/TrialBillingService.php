<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\EmpowerPaymentApiException;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\ClientPaymentReceiptMail;
use App\Mail\ClientRenewalFailedMail;
use App\Mail\ClientRenewalReceiptMail;
use App\Mail\ClientTrialCancelledMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\User;
use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Orchestrates free-trial → paid conversion and recurring annual renewals, shared by the portal's
 * client-initiated "Proceed with Payment" action and the daily ProcessSubscriptionBilling command
 * so the charge/update/notify logic never has two copies.
 */
class TrialBillingService
{
    /** Renewal attempts per billing cycle before giving up and cancelling — see plan.md's
     *  2026-09-19 update for the reasoning (0/3/7-day retry spacing, driven by the scheduled
     *  command re-running daily and only acting once this count is reached). */
    private const MAX_RENEWAL_ATTEMPTS = 3;

    public function __construct(private EmpowerPaymentApiClient $client) {}

    /** The client's first paid charge, converting a Trialing order to Paid. */
    public function convertTrialToPaid(Order $order): ChargeResult
    {
        $amount = (float) $order->original_price;
        $result = $this->chargeStoredCard($order, $amount);

        if (! $result->success) {
            PaymentLog::record(
                success: false,
                amount: $amount,
                user: $order->user,
                package: $order->package,
                order: $order,
                transactionId: $result->transactionId,
                message: $result->declineMessage,
            );

            return $result;
        }

        $order->update([
            'payment_status' => PaymentStatus::Paid,
            'payment_reference' => $result->transactionId,
            'amount_paid' => $amount,
            'paid_at' => now(),
            'trial_confirmed_at' => now(),
            'next_bill_date' => $order->billing_cycle === BillingCycle::Monthly ? now()->addMonth() : now()->addYear(),
        ]);

        ActivityLog::record(
            'trial.converted',
            "Free trial converted to a paid subscription for {$order->package->name} (\${$amount}).",
            user: $order->user,
            order: $order,
        );

        PaymentLog::record(
            success: true,
            amount: $amount,
            user: $order->user,
            package: $order->package,
            order: $order,
            transactionId: $result->transactionId,
        );

        $this->sendQuietly(fn () => Mail::to($order->user->email)->queue(new ClientPaymentReceiptMail($order)));
        $this->notifyAdmins($order);

        return $result;
    }

    /** An automatic year-2+ renewal charge. Advances next_bill_date on success; tracks a retry
     *  count and cancels after MAX_RENEWAL_ATTEMPTS consecutive failures on failure. */
    public function renew(Order $order): ChargeResult
    {
        $cycle = $order->billing_cycle ?? BillingCycle::Annual;
        // Re-derives from the practice's *current* provider count, not the frozen count at
        // purchase time — a provider count change should take effect at the next renewal, same
        // as a package price change already does below.
        $providers = max(1, $order->user->practice?->billable_providers_count ?? 1);
        $perProviderPrice = $order->package->priceForCycle($cycle);
        // Falls back to the order's own frozen original_price if the package's price for this
        // cycle has since been unset (e.g. an admin cleared monthly_price after monthly
        // subscribers already exist on it) — a renewal must never silently charge $0. The frozen
        // value already has the provider count baked in from when it was first charged, so it's
        // not multiplied again here.
        $amount = $perProviderPrice !== null ? $perProviderPrice * $providers : (float) $order->original_price;
        $result = $this->chargeStoredCard($order, $amount);

        if (! $result->success) {
            $order->update([
                'payment_status' => PaymentStatus::PastDue,
                'renewal_attempts' => $order->renewal_attempts + 1,
                'last_renewal_attempt_at' => now(),
                'last_renewal_error' => $result->declineMessage,
            ]);

            PaymentLog::record(
                success: false,
                amount: $amount,
                user: $order->user,
                package: $order->package,
                order: $order,
                transactionId: $result->transactionId,
                message: $result->declineMessage,
            );

            if ($order->renewal_attempts >= self::MAX_RENEWAL_ATTEMPTS) {
                $this->cancel($order, 'renewal_failed');
            } else {
                $this->sendQuietly(fn () => Mail::to($order->user->email)->queue(new ClientRenewalFailedMail($order, cancelled: false)));
            }

            return $result;
        }

        // Advanced from the order's own scheduled date, not now() — a late retry never drifts the
        // billing anniversary forward.
        $nextBillDate = $cycle === BillingCycle::Monthly
            ? ($order->next_bill_date ?? now())->copy()->addMonth()
            : ($order->next_bill_date ?? now())->copy()->addYear();

        $order->update([
            'payment_status' => PaymentStatus::Paid,
            'payment_reference' => $result->transactionId,
            'amount_paid' => $amount,
            'paid_at' => now(),
            'renewal_attempts' => 0,
            'last_renewal_error' => null,
            'next_bill_date' => $nextBillDate,
        ]);

        ActivityLog::record(
            'order.renewed',
            "Subscription renewed for {$order->package->name} (\${$amount}).",
            user: $order->user,
            order: $order,
        );

        PaymentLog::record(
            success: true,
            amount: $amount,
            user: $order->user,
            package: $order->package,
            order: $order,
            transactionId: $result->transactionId,
        );

        $this->sendQuietly(fn () => Mail::to($order->user->email)->queue(new ClientRenewalReceiptMail($order)));
        $this->notifyAdmins($order);

        return $result;
    }

    /** @param  'client_requested'|'trial_expired'|'renewal_failed'  $reason */
    public function cancel(Order $order, string $reason): void
    {
        $order->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        ActivityLog::record(
            'trial.cancelled',
            "Subscription cancelled ({$reason}) for {$order->package->name}.",
            user: $order->user,
            order: $order,
        );

        if ($reason === 'renewal_failed') {
            $this->sendQuietly(fn () => Mail::to($order->user->email)->queue(new ClientRenewalFailedMail($order, cancelled: true)));

            return;
        }

        $this->sendQuietly(fn () => Mail::to($order->user->email)->queue(new ClientTrialCancelledMail($order, $reason)));
    }

    /**
     * The CVV-discipline chokepoint: the only place the recovered raw card number and CVV briefly
     * exist. They're read once to build the charge request and go out of scope the moment this
     * method returns — never logged, never assigned to $order, never persisted.
     */
    private function chargeStoredCard(Order $order, float $amount): ChargeResult
    {
        if (! $order->clover_card_token) {
            return new ChargeResult(success: false, declineMessage: 'No payment method is on file for this subscription.');
        }

        if ($this->cardIsExpired($order)) {
            return new ChargeResult(success: false, declineMessage: 'The card on file has expired. Please update your payment details.');
        }

        try {
            $card = $this->client->detokenize($order->clover_card_token);
        } catch (EmpowerPaymentApiException $e) {
            report($e);

            return new ChargeResult(success: false, declineMessage: 'Could not retrieve the saved card. Please try again or update your payment details.');
        }

        $billingAddress = $order->billing_address ?? [];

        $result = $this->client->charge([
            'name' => $billingAddress['name'] ?? $order->user->name,
            'address1' => $billingAddress['address1'] ?? '',
            'city' => $billingAddress['city'] ?? '',
            'state' => $billingAddress['state'] ?? '',
            'zip' => $billingAddress['zip'] ?? '',
            'product_Name' => $order->package->name,
            'amount' => $amount,
            'cardNumber' => $card['cardNumber'],
            'expMonth' => $order->card_expiry_month,
            'expYear' => $order->card_expiry_year,
            'cvv' => $card['cvv'],
        ]);

        unset($card);

        return $result;
    }

    private function cardIsExpired(Order $order): bool
    {
        if (! $order->card_expiry_month || ! $order->card_expiry_year) {
            return true;
        }

        $currentYear = (int) now()->format('Y');
        $currentMonth = (int) now()->format('n');

        return $order->card_expiry_year < $currentYear
            || ($order->card_expiry_year === $currentYear && $order->card_expiry_month < $currentMonth);
    }

    private function notifyAdmins(Order $order): void
    {
        $admins = User::where('role', UserRole::Admin)->get();

        $admins->each(
            fn (User $admin) => $this->sendQuietly(fn () => Mail::to($admin->email)->queue(new AdminPaymentReceivedMail($order)))
        );

        $this->sendQuietly(fn () => Notification::send($admins, new PaymentReceivedNotification($order)));
    }

    private function sendQuietly(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
