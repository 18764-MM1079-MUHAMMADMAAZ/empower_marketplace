<?php

use App\Models\ActivityLog;
use App\Models\Practice;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My Account › Practice Information (v5): read-only, as shown in the compliance manuals. For
 * talkEHR / CareCloud practices it's synced from the partner; fields the partner doesn't send yet
 * (practice type, Tax ID, fax, time zone, partner plan) show "—" (my-account-plan.md).
 */
new class extends Component
{
    #[Computed]
    public function practice(): Practice
    {
        return auth()->user()->practice;
    }
};
?>

@php
$p = $this->practice;
$user = auth()->user();
$partner = match ($p->source_system) {
    'talkehr' => 'talkEHR',
    'cch' => 'CareCloud',
    default => null,
};
$providers = (int) $p->billable_providers_count;
$otherLocations = collect($p->practice_locations ?? [])
    ->map(fn ($l) => trim((string) $l))
    ->filter()
    ->reject(fn ($l) => strcasecmp($l, trim((string) $p->address)) === 0)
    ->implode('; ');
$lastSynced = $partner
    ? ActivityLog::where('user_id', $user->id)->whereIn('event_type', ['sso.empower_sso_api_login', 'sso.login_completed'])->latest('id')->value('created_at')
    : null;
$groups = array_filter([
    'Practice details' => [
        ['Legal name', $p->legal_practice_name ?: $p->name],
        ['DBA', $p->dba_name],
        ['Specialty', $p->specialty],
        ['Practice type', null],
        ['Number of providers', $providers > 0 ? $providers.' billable '.Str::plural('provider', $providers) : null],
    ],
    'Identifiers' => array_values(array_filter([
        ['Group NPI', $p->npi_number],
        ['Tax ID (EIN)', null],
        $partner ? ["{$partner} practice ID", $p->external_practice_id] : null,
    ])),
    'Contact' => [
        ['Main phone', $p->main_phone ?: $p->phone],
        ['Fax', null],
        ['Main email', $p->main_email],
    ],
    'Locations' => [
        ['Primary address', $p->address],
        ['Other locations', $otherLocations],
        ['Time zone', null],
    ],
    'Account' => array_values(array_filter([
        ['Admin name', $user->name],
        $partner ? ["{$partner} plan", null] : null,
    ])),
]);
@endphp

<section id="practice" data-account-section aria-labelledby="h-practice"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex-1 min-w-[16rem]">
            <h2 id="h-practice" class="text-lg font-semibold text-[#12304f]">Practice Information</h2>
            <p class="text-sm text-[#5d6e7f] mt-0.5">
                @if($partner)
                Synced from your {{ $partner }} account and shown in your compliance manuals. To update these details, change them in {{ $partner }}.
                @else
                Shown in your compliance manuals. To update these details, use your portal dashboard.
                @endif
            </p>
        </div>
        @if($partner)
        @include('account._synced', ['partner' => $partner])
        @endif
    </div>

    @foreach($groups as $heading => $rows)
    <h3 class="text-sm font-semibold text-[#173045] {{ $loop->first ? 'mt-5' : 'mt-6 pt-5 border-t border-[#eef2f6]' }}">{{ $heading }}</h3>
    @include('account._fields', ['rows' => $rows])
    @endforeach

    @if($lastSynced)
    <p class="mt-5 text-xs text-[#5d6e7f]">Last synced from {{ $partner }}: {{ $lastSynced->format('M j, Y, g:i A') }}</p>
    @endif
</section>
