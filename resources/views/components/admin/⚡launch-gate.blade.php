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

<div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
    @php $launchAt = $this->launchAt !== '' ? \Illuminate\Support\Carbon::parse($this->launchAt) : null; @endphp
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Purchase Gating</h2>
            <p class="mt-1 text-sm text-empower-muted max-w-xl">Until this date, clients who haven't paid see "Sign up for updates" instead of the payment form.</p>
            <p class="mt-2 text-sm font-semibold">
                @if($launchAt === null)
                    <span class="text-[#117a51]">No date set — purchases are open.</span>
                @elseif(now()->lt($launchAt))
                    <span class="text-[#9a6700]">Closed until {{ $launchAt->format('M j, Y g:i A') }}</span>
                @else
                    <span class="text-[#117a51]">Open since {{ $launchAt->format('M j, Y g:i A') }}</span>
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <input type="datetime-local" wire:model="launchAt"
                class="rounded-lg border border-empower-border bg-white px-3 py-2 text-sm text-navy">
            <button type="button" wire:click="save"
                class="rounded-lg bg-navy px-4 py-2 text-sm font-semibold text-white hover:opacity-90">Save date</button>
            <button type="button" wire:click="openNow"
                class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-navy hover:bg-page">Open now</button>
        </div>
    </div>
    @error('launchAt') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
</div>
