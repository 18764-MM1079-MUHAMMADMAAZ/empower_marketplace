<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\GenerateComplianceDocument;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read model behind the My Account page (resources/views/account). Each section component builds
 * one of these for the signed-in client, so they all agree on which subscription is "the" plan.
 */
class ClientAccount
{
    private ?Collection $orders = null;

    public function __construct(public readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    /** Every order, newest first, with what the account sections read from it. */
    public function orders(): Collection
    {
        return $this->orders ??= $this->user->orders()
            ->with(['package', 'intakeSubmission.reviewerQuestions', 'intakeSubmission.intakeUploads', 'generatedDocuments.oshaLocation', 'generatedDocuments.intakeUpload'])
            ->latest('id')
            ->get();
    }

    /** Paid (or trialing / partner-billed) orders that haven't been cancelled. */
    public function activeOrders(): Collection
    {
        return $this->orders()
            ->filter(fn (Order $order) => $order->isPaid() && $order->status !== OrderStatus::Cancelled)
            ->values();
    }

    /** The subscription the overview talks about: the newest active one, else the newest order. */
    public function primaryOrder(): ?Order
    {
        return $this->activeOrders()->first() ?? $this->orders()->first();
    }

    /** Billed through talkEHR / CareCloud rather than a card on file with us. */
    public function isPartnerBilled(?Order $order = null): bool
    {
        if ($order?->payment_status === PaymentStatus::ExternallyBilled) {
            return true;
        }

        return in_array($this->user->practice?->source_system, ['cch', 'talkehr'], true);
    }

    /**
     * 'partner' | 'trial' | 'past_due' | 'cancelled' | 'ending' | 'unpaid' | 'active'
     * ('ending': cancelled by the client, still active until cancels_at)
     */
    public function billingState(Order $order): string
    {
        return match (true) {
            $order->status === OrderStatus::Cancelled => 'cancelled',
            $order->cancels_at !== null => 'ending',
            $this->isPartnerBilled($order) => 'partner',
            $order->payment_status === PaymentStatus::Trialing => 'trial',
            $order->payment_status === PaymentStatus::PastDue => 'past_due',
            ! $order->isPaid() => 'unpaid',
            default => 'active',
        };
    }

    /** The date and amount of the next automatic charge, or null when nothing will be charged. */
    public function nextCharge(Order $order): ?array
    {
        $state = $this->billingState($order);

        if ($state === 'trial' && $order->trial_ends_at) {
            return ['date' => $order->trial_ends_at, 'amount' => (float) $order->original_price];
        }

        if (in_array($state, ['active', 'past_due'], true) && $order->next_bill_date) {
            return ['date' => $order->next_bill_date, 'amount' => app(TrialBillingService::class)->renewalAmount($order)];
        }

        return null;
    }

    /** Documents across every order, in a stable order for the documents table. */
    public function documents(): Collection
    {
        return $this->orders()
            ->flatMap(fn (Order $order) => $order->generatedDocuments->each->setRelation('order', $order))
            ->sortBy(fn (GeneratedDocument $doc) => [$doc->order_id, $doc->document_type->label(), $doc->id])
            ->values();
    }

    public function staleDocuments(): Collection
    {
        return $this->documents()->filter(fn (GeneratedDocument $doc) => $doc->is_stale && $doc->canBeRegeneratedByClient())->values();
    }

    /** Queues a new generation of one document, exactly as the dashboard's Regenerate button does. */
    public function regenerate(GeneratedDocument $document): bool
    {
        if ($document->order->user_id !== $this->user->id || ! $document->canBeRegeneratedByClient()) {
            return false;
        }

        // Marked Pending before queueing so the table shows "Generating" straight away and a second
        // click can't queue it twice; the job moves it on to Generating / Completed / Failed.
        $document->update(['status' => DocumentStatus::Pending]);

        GenerateComplianceDocument::dispatch($document->order, $document->document_type, $document->oshaLocation, $document->intakeUpload);

        ActivityLog::record(
            'document.regenerate_requested',
            "Regeneration requested for {$document->document_type->label()} from My Account.",
            user: $this->user,
            order: $document->order,
            subject: $document,
        );

        return true;
    }
}
