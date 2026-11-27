<?php

use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\Practice;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My Account › Compliance Hotline (v5): the number staff call to report compliance concerns.
 * Shows what was chosen at sign-up (the intake wizard's hotline step): the Empower Compliance
 * Hotline (uses_ehcp_hotline) or the practice's own number. It's printed in the compliance
 * manuals, so changing it marks the practice's documents as needing an update (the same flag a
 * practice-details change sets) for the compliance team to regenerate.
 */
new class extends Component
{
    public bool $editing = false;

    public string $draft = '';

    public bool $saved = false;

    #[Computed]
    public function practice(): Practice
    {
        return auth()->user()->practice;
    }

    /** The 10 digits of a US number ("1" country code dropped), or null when it isn't one. */
    public static function digits(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    public static function format(string $digits): string
    {
        return '('.substr($digits, 0, 3).') '.substr($digits, 3, 3).'-'.substr($digits, 6, 4);
    }

    public function edit(): void
    {
        // On the Empower hotline, "Use our own number" starts from an empty field.
        $own = $this->practice->uses_ehcp_hotline ? '' : (string) $this->practice->compliance_hotline_number;
        $current = self::digits($own);
        $this->draft = $current ? self::format($current) : $own;
        $this->saved = false;
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->draft = '';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $digits = self::digits($this->draft);

        if ($digits === null) {
            $this->addError('draft', 'Enter a 10-digit US phone number.');

            return;
        }

        $practice = $this->practice;
        $number = self::format($digits);
        $changed = $practice->uses_ehcp_hotline || $number !== $practice->compliance_hotline_number;

        if ($changed) {
            $practice->update(['uses_ehcp_hotline' => false, 'compliance_hotline_number' => $number]);
            $this->flagDocuments($practice, "Compliance hotline number set to {$number}");
        }

        $this->finish($changed);
    }

    /** Switches back to the shared Empower Compliance Hotline, the other choice the sign-up wizard offers. */
    public function useEmpowerHotline(): void
    {
        $practice = $this->practice;
        $changed = ! $practice->uses_ehcp_hotline;

        if ($changed) {
            $practice->update(['uses_ehcp_hotline' => true, 'compliance_hotline_number' => null]);
            $this->flagDocuments($practice, 'Compliance hotline set to the Empower Compliance Hotline');
        }

        $this->finish($changed);
    }

    private function flagDocuments(Practice $practice, string $activity): void
    {
        GeneratedDocument::whereIn('order_id', $practice->user->orders()->pluck('id'))
            ->where('is_stale', false)
            ->update(['is_stale' => true, 'stale_reason' => 'hotline_updated']);

        ActivityLog::record('practice.hotline_updated', $activity, user: auth()->user(), subject: $practice);
    }

    private function finish(bool $changed): void
    {
        $this->editing = false;
        $this->draft = '';
        $this->resetErrorBag();
        $this->saved = $changed;
        unset($this->practice);
    }
};
?>

@php
$usesEmpower = (bool) $this->practice->uses_ehcp_hotline;
// What's shown: the Empower hotline's number, or the practice's own (as entered at sign-up, which
// may also be an email).
$number = $usesEmpower ? Practice::EMPOWER_HOTLINE_PHONE : $this->practice->compliance_hotline_number;
$button ='inline-flex h-10 items-center rounded-lg border px-4 text-[13px] font-semibold transition-colors';
@endphp

<section id="hotline" data-account-section aria-labelledby="h-hotline"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex-1 min-w-[16rem]">
            <h2 id="h-hotline" class="text-lg font-semibold text-[#12304f]">Compliance Hotline</h2>
            <p class="text-sm text-[#5d6e7f] mt-0.5">The number your staff can call to report compliance concerns. Shown in your compliance manuals. Changing it marks those documents for regeneration.</p>
        </div>
        @unless($editing)
        <button type="button" wire:click="edit" data-hotline-edit class="{{ $button }} border-[#cbd5e1] bg-white text-[#173045] hover:bg-[#f4f7fb]">{{ match (true) { $usesEmpower => 'Use our own number', (bool) $number => 'Edit', default => 'Add hotline number' } }}</button>
        @endunless
    </div>

    @if($editing)
    <form wire:submit="save" novalidate class="mt-4.5" data-hotline-form
        x-data="{ draft: $wire.entangle('draft'), get digits() { let d = (this.draft || '').replace(/\D/g, ''); if (d.length === 11 && d[0] === '1') d = d.slice(1); return d }, get valid() { return this.digits.length === 10 }, get bad() { return (this.draft || '').length > 0 && ! this.valid && this.digits.length >= 10 } }">
        <label class="flex max-w-xs flex-col gap-1.5 text-[13px] font-medium text-[#173045]">Hotline number
            <input type="tel" inputmode="tel" autocomplete="tel" placeholder="(555) 555-0100" x-model="draft" aria-describedby="hotline-help"
                x-bind:class="bad || {{ $errors->has('draft') ? 'true' : 'false' }} ? 'border-[#c62828]' : 'border-[#cbd5e1]'"
                class="h-10 rounded-lg border bg-white px-3 text-sm text-[#0f2a44] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0]">
        </label>
        <p id="hotline-help" class="mt-1.5 text-xs" x-bind:class="bad || {{ $errors->has('draft') ? 'true' : 'false' }} ? 'text-[#b42318]' : 'text-[#5d6e7f]'"
            x-text="bad || {{ $errors->has('draft') ? 'true' : 'false' }} ? 'Enter a 10-digit US phone number.' : 'US number, 10 digits.'">US number, 10 digits.</p>
        <div class="mt-3.5 flex flex-wrap gap-2">
            <button type="submit" x-bind:disabled="! valid" x-bind:class="valid ? '' : 'opacity-50 cursor-not-allowed'"
                class="{{ $button }} border-[#2299dd] bg-[#2299dd] text-white hover:bg-[#087fa9]" wire:loading.attr="disabled" wire:target="save">Save</button>
            <button type="button" wire:click="cancel" class="{{ $button }} border-[#cbd5e1] bg-white text-[#173045] hover:bg-[#f4f7fb]">Cancel</button>
        </div>
        @unless($usesEmpower)
        <p class="mt-3 text-[13px] text-[#5d6e7f]">Don't have a hotline of your own?
            <button type="button" wire:click="useEmpowerHotline" data-hotline-use-empower
                class="font-semibold text-[#0b7fb3] hover:underline">Use the Empower Compliance Hotline</button>
            (included in your package).</p>
        @endunless
    </form>
    @else
    <div class="mt-4.5 flex flex-wrap items-center gap-3" data-hotline-number="{{ $number }}">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] {{ $number ? 'bg-[#e7f5ee] text-[#146c43]' : 'bg-[#f1f5f9] text-[#5d6e7f]' }}" aria-hidden="true">
            <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z" /></svg>
        </span>
        <div>
            <p class="text-xs text-[#5d6e7f]">{{ $usesEmpower ? 'Empower Compliance Hotline' : 'Hotline number' }}</p>
            <p class="text-lg font-semibold break-all {{ $number ? 'text-[#0f2a44]' : 'text-[#5d6e7f]' }}">{{ $number ?: 'No number added' }}</p>
        </div>
        @if($usesEmpower)
        <span class="rounded-full bg-[#e6f3fb] px-2.5 py-0.5 text-xs font-semibold text-[#0b5f86]">Included in your package</span>
        @elseif(! $number)
        <span class="rounded-full bg-[#fdf0d8] px-2.5 py-0.5 text-xs font-semibold text-[#7a4a00]">Not added yet</span>
        @endif
    </div>
    {{-- The poster prints the Empower hotline number, so it's offered to practices using it (no approval needed). --}}
    @if($usesEmpower)
    <div class="mt-4.5 flex flex-wrap items-center gap-3 rounded-xl border border-[#e3e8ef] bg-[#f8fafc] p-3.5" data-hotline-poster>
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] bg-white text-[#145a93] border border-[#e3e8ef]" aria-hidden="true">
            <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6" /></svg>
        </span>
        <div class="flex-1 min-w-[12rem]">
            <p class="text-sm font-semibold text-[#173045]">Compliance Hotline Poster</p>
            <p class="text-xs text-[#5d6e7f]">Print and post where staff can see it.</p>
        </div>
        <a href="{{ asset('compliance%20hotline%20poster_empower.pdf') }}" download="compliance-hotline-poster.pdf"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#cbd5e1] bg-white px-3.5 text-[13px] font-semibold text-[#173045] hover:bg-[#f4f7fb] transition-colors">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
            Download
        </a>
    </div>
    @endif
    @if($saved)
    <p role="status" class="mt-3 text-[13px] text-[#146c43]">Hotline saved. Your compliance manuals will be regenerated with the new details.</p>
    @endif
    @endif
</section>
