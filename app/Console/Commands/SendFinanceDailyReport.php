<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Mail\FinanceDailyReportMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Daily digest for Finance of every new subscription and cancellation from the previous day
 * (sso.md §10 / the SSO requirements doc §5). Reports every order, not just CareCloud/talkEHR
 * add-on ones — that distinction doesn't exist in the data yet (practices.source_system is always
 * null until the SSO work in sso.md §1-3/§6-9 lands); once it does, narrowing this to add-on-only
 * is a one-line `->whereNotNull('source_system')` addition.
 */
#[Signature('reports:finance-daily {--date= : Report this date (Y-m-d) instead of yesterday, for backfills/testing}')]
#[Description('Emails Finance a daily CSV of new subscriptions and cancellations')]
class SendFinanceDailyReport extends Command
{
    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subDay();

        $rows = $this->newSubscriptionRows($date)->concat($this->reversalRows($date));

        if ($rows->isEmpty()) {
            $this->components->info("No new subscriptions or cancellations on {$date->toDateString()} — skipping the report.");

            return self::SUCCESS;
        }

        $recipients = $this->recipients();

        foreach ($recipients as $email) {
            Mail::to($email)->queue(new FinanceDailyReportMail($date, $rows));
        }

        $this->components->info("Sent the {$date->toDateString()} Finance report ({$rows->count()} row(s)) to {$recipients->implode(', ')}.");

        return self::SUCCESS;
    }

    /** @return Collection<int, string> */
    private function recipients(): Collection
    {
        $configured = config('services.finance.report_email');

        if ($configured) {
            return collect(explode(',', $configured))->map(fn ($email) => trim($email))->filter()->values();
        }

        return User::where('role', UserRole::Admin)->pluck('email');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function newSubscriptionRows(Carbon $date): Collection
    {
        return Order::with(['user', 'package', 'user.practice'])
            ->whereDate('created_at', $date)
            ->get()
            ->map(fn (Order $order) => $this->toRow($order, 'New', $order->created_at));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function reversalRows(Carbon $date): Collection
    {
        return Order::with(['user', 'package', 'user.practice'])
            ->where('status', OrderStatus::Cancelled)
            ->whereDate('cancelled_at', $date)
            // A same-day subscribe-then-cancel already appears once above as "New" — the doc's
            // report is about yesterday's net activity, not double-counting a single order.
            ->whereDate('created_at', '!=', $date)
            ->get()
            ->map(fn (Order $order) => $this->toRow($order, 'Reversed', $order->cancelled_at));
    }

    /** @return array<string, mixed> */
    private function toRow(Order $order, string $status, ?Carbon $eventDate): array
    {
        $practice = $order->user?->practice;

        return [
            'Order ID' => $order->id,
            'Order Date' => $eventDate?->toDateTimeString(),
            'Status' => $status,
            'Source System' => match ($practice?->source_system) {
                'cch' => 'CareCloud',
                'talkehr' => 'talkEHR',
                default => 'Direct',
            },
            'Practice ID' => $practice?->id,
            'Subscription/Account ID' => $practice?->subscription_account_id,
            'Practice Name' => $practice?->name,
            'Practice Address' => $practice?->address,
            'Plan' => $order->package?->name,
            'Price' => $order->original_price !== null ? number_format((float) $order->original_price, 2, '.', '') : null,
            'Term' => $order->billing_cycle?->period(),
            'Start Date' => $order->paid_at?->toDateString(),
            'Preferred Billing Option' => $practice?->preferred_billing_option
                ?? ($order->clover_card_token ? 'Card on file' : null),
            'Subscribed By Name' => $order->user?->name,
            'Subscribed By Email' => $order->user?->email,
        ];
    }
}
