<?php

use App\Enums\IntakeSubmissionStatus;
use App\Services\ClientAccount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function account(): ClientAccount
    {
        return ClientAccount::for(auth()->user());
    }

    #[On('account-updated')]
    public function refreshAccount(): void
    {
        unset($this->account);
    }

    /** The banner's "Regenerate documents" button: every out-of-date document, in one go. */
    public function regenerateAll(): void
    {
        $count = $this->account->staleDocuments()
            ->filter(fn ($document) => $this->account->regenerate($document))
            ->count();

        $this->dispatch('toast', message: $count > 0
            ? "{$count} ".str('document')->plural($count).' queued to regenerate. They need a quick review by our team before you can download them.'
            : 'There are no documents to regenerate.', type: $count > 0 ? 'success' : 'error');
        $this->dispatch('account-updated');
    }
};
?>

@php
$user = auth()->user();
$account = $this->account;
$order = $account->primaryOrder();
$practice = $user->practice;
$submission = $order?->intakeSubmission;
$state = $order ? $account->billingState($order) : null;
$nextCharge = $order ? $account->nextCharge($order) : null;
$providers = $practice?->billable_providers_count;
$memberSince = $account->orders()->pluck('paid_at')->filter()->min() ?? $user->created_at;
$documents = $account->documents();
$currentDocs = $documents->filter(fn ($d) => $d->clientStatus()[0] === 'Current')->count();
$stale = $account->staleDocuments();
$attention = $documents->filter(fn ($d) => in_array($d->clientStatus()[0], ['Needs update', 'Failed'], true))->count();
$staleSince = $stale->max('updated_at');

[$reviewLabel, $reviewTone] = match ($submission?->status) {
    IntakeSubmissionStatus::Approved => ['Approved', 'good'],
    IntakeSubmissionStatus::Rejected => ['Changes requested', 'bad'],
    IntakeSubmissionStatus::UnderReview => ['In review', 'info'],
    IntakeSubmissionStatus::Submitted, IntakeSubmissionStatus::Pending => ['Submitted', 'info'],
    null => ['Not started', 'muted'],
    default => ['Not submitted', 'muted'],
};
$reviewWhen = match ($submission?->status) {
    IntakeSubmissionStatus::Approved, IntakeSubmissionStatus::Rejected => $submission->reviewed_at ? 'On '.$submission->reviewed_at->format('M j, Y') : null,
    IntakeSubmissionStatus::UnderReview => $submission->under_review_started_at ? 'Since '.$submission->under_review_started_at->format('M j, Y') : null,
    IntakeSubmissionStatus::Submitted, IntakeSubmissionStatus::Pending => $submission->submitted_at ? 'Sent '.$submission->submitted_at->format('M j, Y') : null,
    default => 'Finish your intake in My Portal',
};
$money = fn (float $amount) => '$'.number_format($amount, $amount == floor($amount) ? 0 : 2);
@endphp

<div>
    <section id="overview" data-account-section aria-labelledby="h-overview" class="scroll-mt-32 lg:scroll-mt-24">
        <p class="text-[10px] font-extrabold uppercase tracking-wide text-accent-dark">My account</p>
        <h1 id="h-overview" class="text-2xl font-bold text-navy mt-0.5">Welcome back, {{ str($user->name)->before(' ')->value() ?: $user->name }}</h1>
        <p class="text-sm text-[#5d6e7f] mt-1">
            {{ collect([$practice?->name, $order?->package?->name, 'Member since '.$memberSince->format('M j, Y')])->filter()->implode(' · ') }}
        </p>

        @if($order)
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mt-4">
            <div class="rounded-xl border border-[#dbe4ee] bg-white px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Plan</p>
                <p class="text-base font-bold text-[#173045]">{{ $order->package?->name ?? '—' }}</p>
                <p class="text-xs text-[#5d6e7f] mt-0.5">
                    @if($providers){{ $providers }} {{ Str::plural('provider', $providers) }} · @endif
                    @if($state === 'partner') billed through your partner
                    @elseif($state === 'trial') free trial
                    @else billed {{ ($order->billing_cycle?->label() ?? 'Annual') === 'Annual' ? 'annually' : 'monthly' }}
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-white px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">{{ $state === 'trial' ? 'First payment' : 'Next payment' }}</p>
                @if($nextCharge)
                <p class="text-base font-bold text-[#173045]">{{ $money($nextCharge['amount']) }}</p>
                <p class="text-xs text-[#5d6e7f] mt-0.5">{{ $nextCharge['date']->format('M j, Y') }}@if($order->card_last_four) · {{ $order->card_brand?->label() ?? 'Card' }} ••{{ $order->card_last_four }}@endif</p>
                @elseif($state === 'partner')
                <p class="text-base font-bold text-[#173045]">Partner billed</p>
                <p class="text-xs text-[#5d6e7f] mt-0.5">Invoiced by your partner</p>
                @elseif($state === 'ending')
                <p class="text-base font-bold text-[#173045]">None</p>
                <p class="text-xs text-[#b7791f] font-semibold mt-0.5">Plan ends {{ $order->cancels_at->format('M j, Y') }}</p>
                @elseif($state === 'cancelled')
                <p class="text-base font-bold text-[#173045]">None</p>
                <p class="text-xs text-[#5d6e7f] mt-0.5">Subscription cancelled</p>
                @else
                <p class="text-base font-bold text-[#173045]">—</p>
                @endif
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-white px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Review</p>
                <p class="mb-0.5">@include('account._chip', ['label' => $reviewLabel, 'tone' => $reviewTone])</p>
                @if($reviewWhen)<p class="text-xs text-[#5d6e7f] mt-1">{{ $reviewWhen }}</p>@endif
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-white px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Documents</p>
                <p class="text-base font-bold text-[#173045]">{{ $currentDocs }} current</p>
                <p class="text-xs mt-0.5 {{ $attention ? 'text-[#b7791f] font-semibold' : 'text-[#5d6e7f]' }}">
                    @if($attention){{ $attention }} {{ $attention === 1 ? 'needs' : 'need' }} your attention @else Nothing needs your attention @endif
                </p>
            </div>
        </div>
        @else
        <div class="mt-4 rounded-xl border border-[#dbe4ee] bg-white px-5 py-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-[#5d6e7f]">You don't have a compliance package yet. Choose one to start your practice intake.</p>
            <a href="{{ route('home') }}#pricing" class="rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors">View packages</a>
        </div>
        @endif
    </section>

    @if($stale->isNotEmpty())
    <div role="status" data-stale-banner
        class="mt-5 flex flex-wrap sm:flex-nowrap items-start gap-3 rounded-xl border border-[#f1d9a8] bg-[#fdf3e0] px-4 py-3.5 text-sm text-[#7a5212]">
        <svg class="h-5 w-5 shrink-0 text-[#b7791f]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" /></svg>
        <div class="flex-1 min-w-0">
            <strong class="text-[#5c3d0c]">Your practice details changed{{ $staleSince ? ' on '.$staleSince->format('M j') : '' }}.</strong>
            {{ $stale->count() }} {{ Str::plural('document', $stale->count()) }} still {{ $stale->count() === 1 ? 'shows' : 'show' }} the old details. Regenerate {{ $stale->count() === 1 ? 'it' : 'them' }} so your manuals stay current.
        </div>
        <button type="button" wire:click="regenerateAll" wire:loading.attr="disabled" wire:target="regenerateAll"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-[#e2c58c] bg-white px-3.5 py-1.5 text-xs font-bold text-[#7a5212] hover:bg-[#fff9ee] transition-colors">
            <span wire:loading.remove wire:target="regenerateAll">Regenerate documents</span>
            <span wire:loading.inline-flex wire:target="regenerateAll" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Queueing…</span>
        </button>
    </div>
    @endif
</div>
