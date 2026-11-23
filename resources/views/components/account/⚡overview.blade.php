<?php

use App\Enums\BillingCycle;
use App\Enums\IntakeSubmissionStatus;
use App\Services\ClientAccount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** My Account › Overview (v5): account status, next billed date, subscription type, payment type. */
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
};
?>

@php
$account = $this->account;
$order = $account->primaryOrder();
$submission = $order?->intakeSubmission;
$state = $order ? $account->billingState($order) : null;
$partner = $account->user->practice?->source_system === 'cch' ? 'CareCloud' : 'talkEHR';
$openQuestions = $submission?->reviewerQuestions->reject->isAnswered()->count() ?? 0;
$planName = $order?->package ? str($order->package->name)->replaceLast(' Compliance', '')->value() : null;
$annual = ($order?->billing_cycle ?? BillingCycle::Annual) === BillingCycle::Annual;

// Account status, as the design's four states (plus "Not submitted" before the intake is sent).
[$statusLabel, $statusLine, $tone] = match (true) {
    $submission?->status === IntakeSubmissionStatus::Approved => ['Approved', 'Approved'.($submission->reviewed_at ? ' on '.$submission->reviewed_at->format('M j, Y') : '').' by the Empower compliance team', 'good'],
    $submission?->status === IntakeSubmissionStatus::Rejected => ['Action needed', 'The Empower compliance team sent your intake back for changes', 'warn'],
    $openQuestions > 0 => ['Action needed', "The Empower compliance team has {$openQuestions} ".Str::plural('question', $openQuestions).' for you', 'warn'],
    $submission?->status === IntakeSubmissionStatus::UnderReview => ['In progress', 'Being reviewed by the Empower compliance team', 'info'],
    in_array($submission?->status, [IntakeSubmissionStatus::Submitted, IntakeSubmissionStatus::Pending], true) => ['Submitted', 'Submitted'.($submission->submitted_at ? ' on '.$submission->submitted_at->format('M j, Y') : '').' · awaiting the Empower compliance team', 'muted'],
    default => ['Not submitted', 'Finish your practice intake in My Portal', 'muted'],
};
$toneClasses = [
    'good' => ['tile' => 'border-[#bfe3cf] bg-[#f3faf6]', 'chip' => 'bg-[#e7f5ee] text-[#146c43]', 'dot' => 'bg-[#1f9d61]', 'icon' => 'text-[#146c43]'],
    'warn' => ['tile' => 'border-[#f3d9a4] bg-[#fff8eb]', 'chip' => 'bg-[#fdf0d8] text-[#7a4a00]', 'dot' => 'bg-[#d98a00]', 'icon' => 'text-[#7a4a00]'],
    'info' => ['tile' => 'border-[#bdd6ee] bg-[#f5f9fd]', 'chip' => 'bg-[#e8f1fa] text-[#145a93]', 'dot' => 'bg-[#1a6fb5]', 'icon' => 'text-[#145a93]'],
    'muted' => ['tile' => 'border-[#cbd5e1] bg-[#f8fafc]', 'chip' => 'bg-[#e2e8f0] text-[#334155]', 'dot' => 'bg-[#64748b]', 'icon' => 'text-[#334155]'],
][$tone];

[$nextBilled, $nextBilledLine] = match ($state) {
    'partner' => [$order->next_bill_date?->format('M j, Y') ?? '—', "Billed with your {$partner} Plan"],
    'trial' => [$order->trial_ends_at?->format('M j, Y') ?? '—', 'Free trial ends, then billed to your card'],
    'ending' => ['—', 'Plan ends '.$order->cancels_at->format('M j, Y')],
    'cancelled' => ['—', 'Subscription cancelled'],
    null => ['—', ''],
    default => [$order->next_bill_date?->format('M j, Y') ?? '—', $order->card_last_four ? 'Billed to card ending '.$order->card_last_four : 'Billed to your card'],
};
$label = 'text-[13px] font-semibold text-[#334155]';
$iconBox = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg';
@endphp

<section id="overview" data-account-section aria-labelledby="h-overview"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <h2 id="h-overview" class="text-lg font-semibold text-[#12304f]">Overview</h2>

    @if($order)
    <ul aria-label="Account overview" class="mt-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3.5">
        <li class="flex flex-col gap-2 rounded-xl border p-4 {{ $toneClasses['tile'] }}" data-account-status="{{ $statusLabel }}">
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-white {{ $toneClasses['icon'] }}" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0116 0" /></svg></span>
                <span class="{{ $label }}">Account status</span>
            </div>
            <div><span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[15px] font-bold {{ $toneClasses['chip'] }}"><span class="h-2 w-2 rounded-full {{ $toneClasses['dot'] }}" aria-hidden="true"></span>{{ $statusLabel }}</span></div>
            <p class="text-xs text-[#5d6e7f]">{{ $statusLine }}</p>
        </li>

        <li class="flex flex-col gap-2 rounded-xl border border-[#e3e8ef] bg-white p-4" data-next-billed>
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-[#eef2f6] text-[#334155]" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg></span>
                <span class="{{ $label }}">Next billed date</span>
            </div>
            <p class="text-xl font-bold leading-tight text-[#0f2a44]">{{ $nextBilled }}</p>
            <p class="text-xs text-[#5d6e7f]">{{ $nextBilledLine }}</p>
        </li>

        <li class="flex flex-col gap-2 rounded-xl border border-[#e3e8ef] bg-white p-4">
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-[#eef2f6] text-[#334155]" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20" /></svg></span>
                <span class="{{ $label }}">Plan Subscription Type</span>
            </div>
            <p class="text-xl font-bold leading-tight text-[#0f2a44]">{{ $annual ? 'Annual' : 'Monthly' }}</p>
            <p class="text-xs text-[#5d6e7f]">Plan: {{ $planName ?? '—' }}</p>
        </li>

        <li class="flex flex-col gap-2 rounded-xl border p-4 {{ $annual ? 'border-[#d9cdf3] bg-[#f4f0fc]' : 'border-[#bdd6ee] bg-[#eef5fc]' }}" data-payment-type="{{ $annual ? 'Annually' : 'Monthly' }}">
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-white {{ $annual ? 'text-[#6d4bc3]' : 'text-[#1a6fb5]' }}" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 2l4 4-4 4M3 11v-1a4 4 0 014-4h14M7 22l-4-4 4-4M21 13v1a4 4 0 01-4 4H3" /></svg></span>
                <span class="{{ $label }}">Payment type</span>
            </div>
            <div><span class="inline-flex rounded-full px-3 py-1 text-[15px] font-bold text-white {{ $annual ? 'bg-[#6d4bc3]' : 'bg-[#1a6fb5]' }}">{{ $annual ? 'Annually' : 'Monthly' }}</span></div>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-[#334155]">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-[#1a6fb5]" aria-hidden="true"></span>Monthly</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-[#6d4bc3]" aria-hidden="true"></span>Annually</span>
            </div>
        </li>
    </ul>

    @if($submission?->status === IntakeSubmissionStatus::Approved && $submission->reviewed_at)
    <p class="mt-4 pt-3.5 border-t border-[#eef2f6] flex items-center gap-2 text-[13px] text-[#334155]" data-next-policy-review>
        <svg class="h-4 w-4 shrink-0 text-[#5d6e7f]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
        <span>Next annual policy review: <strong class="font-semibold text-[#0f2a44]">{{ $submission->reviewed_at->copy()->addYear()->format('M j, Y') }}</strong></span>
    </p>
    @endif
    @else
    <div class="mt-4 rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-5 py-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-[#5d6e7f]">You don't have a compliance package yet. Choose one to start your practice intake.</p>
        <a href="{{ route('home') }}#pricing" class="rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors">View packages</a>
    </div>
    @endif
</section>
