<?php

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Livewire\Component;

new class extends Component
{
    public string $launchAt = '';

    public function mount(): void
    {
        $this->launchAt = $this->currentLaunchAt()?->format('Y-m-d\TH:i') ?? '';
    }

    /** The dashboard-set date wins over the PUBLIC_LAUNCH_AT env default. */
    private function currentLaunchAt(): ?Carbon
    {
        $value = Setting::read('public_launch_at') ?? config('app.public_launch_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function save(): void
    {
        $this->validate(['launchAt' => 'required|date']);

        Setting::write('public_launch_at', Carbon::parse($this->launchAt)->toDateTimeString());

        $this->dispatch('toast', message: 'Public launch date saved.', type: 'success');
    }

    public function openNow(): void
    {
        Setting::write('public_launch_at', now()->toDateTimeString());
        $this->launchAt = now()->format('Y-m-d\TH:i');

        $this->dispatch('toast', message: 'Purchases are now open to everyone.', type: 'success');
    }
};
?>

<div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 h-full flex flex-col">
    @php
        $launchAt = $this->launchAt !== '' ? \Illuminate\Support\Carbon::parse($this->launchAt) : null;
        $isClosed = $launchAt !== null && now()->lt($launchAt);
    @endphp
    <div class="flex items-start justify-between gap-3">
        <h2 class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Purchase Gating</h2>
        <span class="{{ $isClosed ? 'inline-flex items-center gap-1.5 rounded-full bg-[#fff3cd] px-2.5 py-1 text-[0.68rem] font-extrabold uppercase tracking-wider text-[#9a6700]' : 'inline-flex items-center gap-1.5 rounded-full bg-[#dff7f0] px-2.5 py-1 text-[0.68rem] font-extrabold uppercase tracking-wider text-[#0f7a4f]' }}">
            <span class="h-1.5 w-1.5 rounded-full {{ $isClosed ? 'bg-[#9a6700]' : 'bg-[#0f7a4f]' }}"></span>
            {{ $isClosed ? 'Closed' : 'Open' }}
        </span>
    </div>

    <p class="mt-3 text-lg font-bold text-navy">
        @if($launchAt === null)
            Purchases are open to everyone
        @elseif($isClosed)
            Opens {{ $launchAt->format('M j, Y') }}
        @else
            Open since {{ $launchAt->format('M j, Y') }}
        @endif
    </p>
    <p class="mt-1 text-sm text-empower-muted">
        @if($isClosed)
            Until {{ $launchAt->format('g:i A') }} on that day, clients who haven't paid see "Sign up for updates" instead of the payment form.
        @else
            Set a future date to hold back purchases; unpaid clients then see "Sign up for updates" instead of the payment form.
        @endif
    </p>

    <div class="mt-auto pt-4">
        <div class="border-t border-empower-border pt-4 flex flex-wrap items-center gap-2">
            <input type="datetime-local" wire:model="launchAt" aria-label="Public launch date and time"
                class="rounded-lg border border-empower-border bg-white px-3 py-2 text-sm text-navy focus:outline-none focus:ring-2 focus:ring-accent">
            <button type="button" wire:click="save"
                class="rounded-lg bg-navy px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition-opacity">Save date</button>
            <button type="button" wire:click="openNow"
                class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-navy hover:bg-page transition-colors">Open now</button>
        </div>
        @error('launchAt') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
