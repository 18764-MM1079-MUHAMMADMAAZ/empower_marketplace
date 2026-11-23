{{--
    "Go to LMS" on the order confirmation (Step 1 after paying, or after a CCH/talkEHR order form):
    gap 13 in lms-enrollment-plan.md, spec §7. Navy for clients with LMS access (Professional,
    Advanced, Complete); greyed out with the upgrade message for Essential, linking to the packages.
    Expects: $orders (the batch's orders).
--}}
@php
$lmsUser = auth()->user();
$lmsOpen = $lmsUser?->hasLmsAccess() ?? false;
$lmsSettingUp = $lmsOpen && collect($orders)->contains(fn ($o) => $o->package?->includesLmsAccess() && $o->lms_provisioned_at === null);
@endphp
@if($lmsUser)
<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5 mt-3 {{ $lmsOpen ? 'border-[#d4e5f1] bg-[#f2f8fd]' : 'border-empower-border bg-page' }}"
    data-order-lms="{{ $lmsOpen ? 'open' : 'locked' }}">
    <div class="min-w-0">
        <p class="text-sm font-semibold text-navy">Your training (Empower LMS)</p>
        <p class="text-xs text-empower-muted mt-0.5">
            @if(! $lmsOpen)
            {{ $lmsUser->lmsLockedMessage() }}.
            @elseif($lmsSettingUp)
            Your courses are being set up. This takes about a minute.
            @else
            Your courses are ready. Same login as the Marketplace.
            @endif
        </p>
    </div>
    @if($lmsOpen)
    <a href="{{ route('lms.launch') }}" target="_blank" rel="noopener" data-order-lms-button
        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-navy px-4 py-2 text-xs font-bold text-white hover:opacity-90 transition-opacity">
        Go to LMS
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
    </a>
    @else
    <a href="{{ route('home') }}#pricing" title="{{ $lmsUser->lmsLockedMessage() }}" data-order-lms-button-locked
        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-empower-border bg-white px-4 py-2 text-xs font-bold text-empower-muted opacity-70 hover:opacity-100 transition-opacity">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
        Go to LMS
    </a>
    @endif
</div>
@endif
