<?php

use App\Models\ActivityLog;
use Livewire\Component;

/**
 * My Account › My Profile (v5). Read-only: partner (talkEHR / CareCloud) users' details come from
 * the partner. Fields the partner doesn't send yet (credentials, role, NPI, phone) show "—" until
 * they do (my-account-plan.md, "Data needed from talkEHR"). User type is set by Empower admins.
 */
new class extends Component {};
?>

@php
$user = auth()->user();
$practice = $user->practice;
$partner = match ($practice?->source_system) {
    'talkehr' => 'talkEHR',
    'cch' => 'CareCloud',
    default => null,
};
$parts = preg_split('/\s+/', trim($user->name)) ?: [];
$first = $parts[0] ?? '';
$last = count($parts) > 1 ? end($parts) : '';
$middle = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : '';
$initials = Str::upper(Str::substr($first, 0, 1).Str::substr($last, 0, 1));
$role = $user->is_practice_admin ? 'Practice administrator' : null;
$lastSynced = $partner
    ? ActivityLog::where('user_id', $user->id)->whereIn('event_type', ['sso.empower_sso_api_login', 'sso.login_completed'])->latest('id')->value('created_at')
    : null;
$dummy = ($user->user_type ?? 'actual') === 'dummy';
$fields = [
    'Personal details' => [['First name', $first], ['Middle name', $middle], ['Last name', $last], ['Title', null]],
    'Professional details' => [[$partner ? "Role ({$partner})" : 'Role', $role], ['NPI', null], ['Specialty', $practice?->specialty]],
    'Contact' => [['Email', $user->email], ['Phone', null]],
];
@endphp

<section id="profile" data-account-section aria-labelledby="h-profile"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex-1 min-w-[16rem]">
            <h2 id="h-profile" class="text-lg font-semibold text-[#12304f]">My Profile</h2>
            <p class="text-sm text-[#5d6e7f] mt-0.5">
                {{ $partner ? "Your details come from {$partner}. To update them, change them in {$partner}." : 'Your account details. To change your name or email, contact support.' }}
            </p>
        </div>
        @if($partner)
        @include('account._synced', ['partner' => $partner])
        @endif
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3.5">
        <span class="flex h-13 w-13 shrink-0 items-center justify-center rounded-full bg-[#12304f] text-lg font-bold text-white" aria-hidden="true">{{ $initials }}</span>
        <div>
            <p class="text-base font-semibold text-[#173045]">{{ $user->name }}</p>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-[13px] text-[#5d6e7f]">
                @if($role)<span class="rounded-full bg-[#eef2f6] px-2 py-px text-xs font-semibold text-[#334155]">{{ $role }}</span>@endif
                @if($practice?->name)<span>{{ $practice->name }}</span>@endif
            </p>
        </div>
    </div>

    @foreach($fields as $heading => $rows)
    <h3 class="mt-6 text-sm font-semibold text-[#173045] {{ $loop->first ? '' : 'pt-5 border-t border-[#eef2f6]' }}">{{ $heading }}</h3>
    @include('account._fields', ['rows' => $rows])
    @endforeach

    {{-- User type: set by the Empower team (Admin › Users), never synced from the partner. --}}
    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 {{ $dummy ? 'border-[#d9cdf3] bg-[#faf8fe]' : 'border-[#e3e8ef] bg-[#f8fafc]' }}" data-user-type="{{ $user->user_type ?? 'actual' }}">
        <div class="flex-1 min-w-[16rem]">
            <p class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-[#173045]">User type</span>
                <span class="inline-flex items-center gap-1 rounded-full border border-[#cbd5e1] bg-white px-2 py-px text-[11px] font-semibold text-[#334155]">
                    <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 018 0v4" /></svg>
                    Empower admins only
                </span>
            </p>
            <p class="mt-1 text-[13px] text-[#5d6e7f]">Set by the Empower team.{{ $partner ? " Not synced from {$partner}." : '' }}</p>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold {{ $dummy ? 'bg-[#f4f0fc] text-[#5b3fa6]' : 'bg-[#e7f5ee] text-[#146c43]' }}">
            <span class="h-2 w-2 rounded-full {{ $dummy ? 'bg-[#6d4bc3]' : 'bg-[#1f9d61]' }}" aria-hidden="true"></span>{{ $user->userTypeLabel() }}
        </span>
    </div>

    @if($lastSynced)
    <p class="mt-4 text-xs text-[#5d6e7f]">Last synced from {{ $partner }}: {{ $lastSynced->format('M j, Y, g:i A') }}</p>
    @endif
</section>
