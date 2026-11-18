<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Mail\ClientPlanChangeReceiptMail;
use App\Mail\ClientSubscriptionCancellationMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * My Account's plan changes (see my-account-plan.md, Phase 3):
 *
 * - More providers: charged today, prorated over what's left of each paid term, then applied.
 * - Fewer providers: saved on the practice and applied at the next renewal.
 * - Monthly ↔ annual: saved on each subscription and applied at its next renewal.
 * - During a free trial (or on a subscription that's never charged a card) every change applies
 *   straight away, with no charge; the trial's first payment is recalculated.
 * - Cancelling a paid subscription keeps it active to the end of the term (cancels_at); a trial
 *   or a past-due subscription is cancelled straight away, as on the dashboard.
 */
class PlanChangeService
{
    public function __construct(private TrialBillingService $billing) {}

    /** Subscriptions whose plan the client manages here: active and not billed by a partner. */
    public function orders(User $user): Collection
    {
        $account = ClientAccount::for($user);

        return $account->activeOrders()->reject(fn (Order $order) => $account->isPartnerBilled($order))->values();
    }

    /** A subscription in a paid term that can be charged: the only kind that prorates or waits for renewal. */
    public function isBilledTerm(Order $order): bool
    {
        // A past-due subscription's renewal date has already passed, but it's still a paid
        // subscription: plan changes are refused until it's paid (see preview()).
        if ($order->payment_status === PaymentStatus::PastDue) {
            return true;
        }

        return $order->payment_status === PaymentStatus::Paid
            && $order->next_bill_date !== null
            && $order->next_bill_date->isFuture();
    }

    /** The provider count and billing period the client has chosen, including scheduled changes. */
    public function current(User $user): array
    {
        $practice = $user->practice;
        $order = $this->orders($user)->first();

        return [
            'providers' => max(1, (int) ($practice?->scheduled_billable_providers ?? $practice?->billable_providers_count ?? 1)),
            'cycle' => $order?->scheduled_billing_cycle ?? $order?->billing_cycle ?? BillingCycle::Annual,
        ];
    }

    /** Share of the current paid term still to come, from 0 to 1. */
    public function remainingFraction(Order $order): float
    {
        if ($order->next_bill_date === null || ! $order->next_bill_date->isFuture()) {
            return 0.0;
        }

        $end = $order->next_bill_date->copy()->startOfDay();
        $start = ($order->billing_cycle ?? BillingCycle::Annual) === BillingCycle::Monthly ? $end->copy()->subMonth() : $end->copy()->subYear();
        $total = max(1, (int) round($start->diffInDays($end)));
        $left = max(0, (int) round(now()->startOfDay()->diffInDays($end, false)));

        return min(1, $left / $total);
    }

    /**
     * What saving these choices would do, for the confirmation step.
     *
     * @return array{changed: bool, charge: float, notes: list<string>, error: ?string, next: ?array{amount: float, date: mixed}}
     */
    public function preview(User $user, int $providers, BillingCycle $cycle): array
    {
        $orders = $this->orders($user);
        $current = $this->current($user);
        $billedNow = (int) ($user->practice?->billable_providers_count ?? 1);
        $notes = [];
        $error = null;
        $charge = 0.0;

        $billed = $orders->filter(fn (Order $order) => $this->isBilledTerm($order));
        $renewOn = $billed->pluck('next_bill_date')->filter()->min()?->format('M j, Y') ?? 'your next renewal';
        $changed = $providers !== $current['providers'] || $cycle !== $current['cycle'];

        if ($orders->isEmpty()) {
            return ['changed' => false, 'charge' => 0.0, 'notes' => [], 'error' => 'There is no subscription to change.', 'next' => null];
        }

        if ($orders->contains(fn (Order $order) => $order->cancels_at !== null)) {
            $error = 'Your plan is set to end. Keep your plan first to change it.';
        }

        if ($cycle === BillingCycle::Monthly && $orders->contains(fn (Order $order) => ! $order->package?->hasMonthlyPricing())) {
            $error = 'Monthly billing isn\'t available for your package.';
        }

        if ($billed->contains(fn (Order $order) => $order->payment_status === PaymentStatus::PastDue)) {
            $error ??= 'Your last payment didn\'t go through. Update your payment method, and once that payment is made you can change your plan.';
        }

        if ($providers > $billedNow && $billed->isNotEmpty()) {
            $added = $providers - $billedNow;
            foreach ($billed as $order) {
                $charge += $this->prorated($order, $added);
            }
            $charge = round($charge, 2);

            $notes[] = 'Adding '.$added.' '.str('provider')->plural($added).' adds a prorated charge of $'.number_format($charge, 2)
                .' today, covering the rest of your current term (to '.$renewOn.').';
        } elseif ($providers < $billedNow && $billed->isNotEmpty()) {
            $notes[] = 'Fewer providers takes effect at your next renewal on '.$renewOn.'. Nothing is refunded for the current term.';
        }

        if ($cycle !== $current['cycle'] && $billed->isNotEmpty()) {
            $package = $billed->first()->package;
            $saving = $package?->monthly_price && $package?->annual_price
                ? (int) round((1 - (float) $package->annual_price / ((float) $package->monthly_price * 12)) * 100)
                : 0;
            $notes[] = $cycle === BillingCycle::Monthly
                ? 'Monthly billing starts at your next renewal on '.$renewOn.'.'
                : 'Annual billing starts at your next renewal on '.$renewOn.($saving > 0 ? ", and saves about {$saving}%." : '.');
        }

        $trial = $orders->first(fn (Order $order) => ! $this->isBilledTerm($order));
        if ($changed && $trial && $trial->payment_status === PaymentStatus::Trialing) {
            $price = $trial->package?->priceForCycle($cycle);
            if ($price !== null) {
                $notes[] = 'Your first payment after the free trial will be $'.number_format($price * $providers, 2).' per '.$cycle->period().'.';
            }
        }

        if ($changed && $providers !== $current['providers']) {
            $notes[] = 'Your documents list your provider count, so they\'ll be marked for regeneration once the new count applies.';
        }

        $first = $billed->first();
        $next = null;
        if ($first) {
            $price = $first->package?->priceForCycle($cycle);
            $next = ['amount' => $price !== null ? $price * $providers : $this->billing->renewalAmount($first), 'date' => $first->next_bill_date];
        }

        return ['changed' => $changed, 'charge' => $charge, 'notes' => $notes, 'error' => $changed ? $error : null, 'next' => $next];
    }

    /**
     * Saves the client's choices. Charges first when providers are added, and changes nothing if
     * that charge is declined.
     *
     * @return array{ok: bool, message: string}
     */
    public function apply(User $user, int $providers, BillingCycle $cycle): array
    {
        $providers = max(1, min(9999, $providers));
        $preview = $this->preview($user, $providers, $cycle);

        if ($preview['error']) {
            return ['ok' => false, 'message' => $preview['error']];
        }

        if (! $preview['changed']) {
            return ['ok' => true, 'message' => 'No changes to save.'];
        }

        $orders = $this->orders($user);
        $practice = $user->practice;
        $billedNow = (int) ($practice?->billable_providers_count ?? 1);
        $billed = $orders->filter(fn (Order $order) => $this->isBilledTerm($order));
        $renewOn = $billed->pluck('next_bill_date')->filter()->min()?->format('M j, Y') ?? 'your next renewal';

        // 1. Charge for added providers before anything changes.
        if ($preview['charge'] > 0) {
            $order = $billed->first();
            $added = $providers - $billedNow;
            $description = "Added {$added} ".str('provider')->plural($added)." ({$billedNow} → {$providers}), prorated to ".$renewOn;
            $result = $this->billing->chargeForPlanChange($order, $preview['charge'], $description);

            if (! $result->success) {
                return ['ok' => false, 'message' => 'Your card was declined, so your plan wasn\'t changed. '.($result->declineMessage ?? '')];
            }

            $payment = PaymentLog::where('order_id', $order->id)->where('success', true)->latest('id')->first();
            try {
                Mail::to($user->email)->queue(new ClientPlanChangeReceiptMail($payment, $providers));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // 2. Providers: straight away unless it's a reduction during a paid term.
        if ($providers !== $billedNow || $practice->scheduled_billable_providers !== null) {
            if ($providers < $billedNow && $billed->isNotEmpty()) {
                $practice->update(['scheduled_billable_providers' => $providers]);
                ActivityLog::record('plan.providers_scheduled', "Billable providers will change from {$billedNow} to {$providers} at the next renewal.", user: $user, subject: $practice);
            } else {
                $practice->update(['billable_providers_count' => $providers, 'scheduled_billable_providers' => null]);
                if ($providers !== $billedNow) {
                    ActivityLog::record('plan.providers_changed', "Billable providers changed from {$billedNow} to {$providers} from My Account.", user: $user, subject: $practice);
                }
            }
        }

        // 3. Billing period, per subscription.
        foreach ($orders as $order) {
            $currentCycle = $order->billing_cycle ?? BillingCycle::Annual;

            if ($this->isBilledTerm($order)) {
                $scheduled = $cycle === $currentCycle ? null : $cycle;
                if ($order->scheduled_billing_cycle !== $scheduled) {
                    $order->update(['scheduled_billing_cycle' => $scheduled]);
                    ActivityLog::record('plan.billing_cycle_scheduled', $scheduled
                        ? "Billing will change to {$scheduled->label()} at the next renewal."
                        : 'Scheduled billing period change removed.', user: $user, order: $order);
                }

                continue;
            }

            // Trial (or never-charged) subscriptions: everything applies now, nothing is charged.
            $updates = ['billing_cycle' => $cycle, 'scheduled_billing_cycle' => null];
            if ($order->payment_status === PaymentStatus::Trialing && ($price = $order->package?->priceForCycle($cycle)) !== null) {
                $updates['original_price'] = $price * $providers;
                $updates['discount_amount'] = $price * $providers;
            }
            $order->update($updates);

            if ($cycle !== $currentCycle) {
                ActivityLog::record('plan.billing_cycle_changed', "Billing changed from {$currentCycle->label()} to {$cycle->label()} from My Account.", user: $user, order: $order);
            }
        }

        $message = $preview['charge'] > 0
            ? 'Plan updated. We charged $'.number_format($preview['charge'], 2).' to your card.'
            : 'Plan updated.';

        return ['ok' => true, 'message' => $message];
    }

    /** Price for $added more providers over what's left of this order's current term. */
    public function prorated(Order $order, int $added): float
    {
        $price = $order->package?->priceForCycle($order->billing_cycle ?? BillingCycle::Annual) ?? 0.0;

        return round($price * $added * $this->remainingFraction($order), 2);
    }

    /**
     * Cancels from My Account. A paid term runs to its end (and can be kept until then); a trial,
     * a past-due subscription, or one with no renewal date ends straight away.
     *
     * @return array{ok: bool, message: string}
     */
    public function cancel(User $user, Order $order): array
    {
        if ($order->user_id !== $user->id || ! $this->orders($user)->contains('id', $order->id)) {
            return ['ok' => false, 'message' => 'This subscription can\'t be cancelled here.'];
        }

        if ($order->payment_status === PaymentStatus::Paid && $this->isBilledTerm($order)) {
            $order->update(['cancels_at' => $order->next_bill_date->copy()->startOfDay(), 'scheduled_billing_cycle' => null]);
            ActivityLog::record('subscription.cancellation_scheduled', "Cancellation scheduled for {$order->package?->name}; the plan ends on {$order->cancels_at->format('M j, Y')}.", user: $user, order: $order);

            try {
                Mail::to($user->email)->queue(new ClientSubscriptionCancellationMail($order));
            } catch (\Throwable $e) {
                report($e);
            }

            return ['ok' => true, 'message' => 'Cancellation scheduled. Your plan stays active until '.$order->cancels_at->format('M j, Y').'.'];
        }

        $this->billing->cancel($order, 'client_requested');

        return ['ok' => true, 'message' => 'Your subscription has been cancelled.'];
    }

    /** Undoes a scheduled cancellation. */
    public function keep(User $user, Order $order): array
    {
        if ($order->user_id !== $user->id || $order->cancels_at === null || ! $this->orders($user)->contains('id', $order->id)) {
            return ['ok' => false, 'message' => 'There is no cancellation to undo.'];
        }

        $order->update(['cancels_at' => null]);
        ActivityLog::record('subscription.cancellation_withdrawn', "Cancellation withdrawn for {$order->package?->name}.", user: $user, order: $order);

        return ['ok' => true, 'message' => 'Your plan will keep renewing as normal.'];
    }
}
