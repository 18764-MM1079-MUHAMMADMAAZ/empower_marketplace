<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\ClientTrialEndingReminderMail;
use App\Models\Order;
use App\Services\TrialBillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Daily pass over every free-trial and converted-subscription order: sends the trial-ending
 * reminder, auto-cancels trials no one responded to, and drives automatic annual renewals
 * (including the retry/cancellation policy for failed renewal charges) — see plan.md's
 * 2026-09-19 update for the reasoning behind the retry spacing below.
 */
#[Signature('subscriptions:process-billing')]
#[Description('Sends trial-ending reminders, cancels lapsed trials, and processes automatic subscription renewals')]
class ProcessSubscriptionBilling extends Command
{
    /** Renewal attempts happen this many days after next_bill_date: on the due date itself, then
     *  +3 days, then +7 days — matching TrialBillingService::MAX_RENEWAL_ATTEMPTS (3). */
    private const RETRY_OFFSETS_DAYS = [0, 3, 7];

    public function __construct(private TrialBillingService $billing)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $reminders = $this->sendTrialEndingReminders();
        $cancelled = $this->cancelLapsedTrials();
        $ended = $this->endCancelledSubscriptions();
        $renewals = $this->processRenewals();

        $this->components->info(
            "Sent {$reminders} trial reminder(s), cancelled {$cancelled} lapsed trial(s), ended {$ended} cancelled subscription(s), processed {$renewals} renewal attempt(s)."
        );

        return self::SUCCESS;
    }

    private function sendTrialEndingReminders(): int
    {
        $reminderDays = (int) config('services.empower_payment_api.trial_reminder_days_before', 3);
        $count = 0;

        Order::query()
            ->where('payment_status', PaymentStatus::Trialing)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereNull('trial_reminder_sent_at')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays($reminderDays)])
            ->chunkById(50, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    try {
                        Mail::to($order->user->email)->queue(new ClientTrialEndingReminderMail($order));
                        $order->update(['trial_reminder_sent_at' => now()]);
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    private function cancelLapsedTrials(): int
    {
        $count = 0;

        Order::query()
            ->where('payment_status', PaymentStatus::Trialing)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->chunkById(50, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    try {
                        $this->billing->cancel($order, 'trial_expired');
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    /** Subscriptions the client cancelled from My Account end on their cancels_at date (the end
     *  of the paid term) instead of renewing. */
    private function endCancelledSubscriptions(): int
    {
        $count = 0;

        Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereNotNull('cancels_at')
            ->where('cancels_at', '<=', now())
            ->chunkById(50, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    try {
                        $this->billing->cancel($order, 'term_ended');
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    private function processRenewals(): int
    {
        $count = 0;

        Order::query()
            ->whereIn('payment_status', [PaymentStatus::Paid, PaymentStatus::PastDue])
            ->where('status', '!=', OrderStatus::Cancelled)
            // Never renew a subscription the client has cancelled, whatever its end date.
            ->whereNull('cancels_at')
            ->whereNotNull('next_bill_date')
            ->whereDate('next_bill_date', '<=', now())
            ->where('renewal_attempts', '<', count(self::RETRY_OFFSETS_DAYS))
            ->chunkById(50, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    $offsetDays = self::RETRY_OFFSETS_DAYS[$order->renewal_attempts] ?? null;

                    if ($offsetDays === null || now()->lt($order->next_bill_date->copy()->addDays($offsetDays))) {
                        continue;
                    }

                    try {
                        $this->billing->renew($order);
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }
}
