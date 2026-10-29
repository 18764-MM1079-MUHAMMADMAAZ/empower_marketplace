<?php

use App\Models\Setting;
use App\Services\SpecialistCallSettings;
use Livewire\Component;

new class extends Component
{
    public bool $enabled = true;

    public string $topics = '';

    public function mount(): void
    {
        $this->enabled = SpecialistCallSettings::enabled();
        $this->topics = implode("\n", SpecialistCallSettings::topics());
    }

    public function save(): void
    {
        $this->validate([
            'topics' => 'required|string',
        ]);

        Setting::write('calls_enabled', $this->enabled ? '1' : '0');
        Setting::write('call_topics', json_encode($this->lines($this->topics)));

        $this->dispatch('toast', message: 'Call booking settings saved.', type: 'success');
    }

    /** @return array<int, string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));
    }
};
?>

<div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 h-full flex flex-col" x-data="{ open: false }">
    <h2 class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Specialist Call Booking</h2>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <p class="text-lg font-bold text-navy">{{ $enabled ? 'Clients can book calls' : 'Booking is switched off' }}</p>
        <span class="{{ $enabled ? 'inline-flex items-center gap-1.5 rounded-full bg-[#dff7f0] px-2.5 py-1 text-[0.68rem] font-extrabold uppercase tracking-wider text-[#0f7a4f]' : 'inline-flex items-center gap-1.5 rounded-full bg-[#fff3cd] px-2.5 py-1 text-[0.68rem] font-extrabold uppercase tracking-wider text-[#9a6700]' }}">
            <span class="h-1.5 w-1.5 rounded-full {{ $enabled ? 'bg-[#0f7a4f]' : 'bg-[#9a6700]' }}"></span>
            {{ $enabled ? 'Open' : 'Off' }}
        </span>
    </div>

    <p class="mt-1 text-sm text-empower-muted">
        {{ \App\Models\SpecialistCallRequest::where('status', \App\Enums\SpecialistCallStatus::Pending)->count() }} pending call requests.
    </p>

    <div class="mt-auto pt-4">
        <div class="border-t border-empower-border pt-4 flex flex-wrap items-center justify-end gap-2">
            <button type="button" x-on:click="open = !open"
                class="rounded-lg bg-navy px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition-opacity">
                <span x-text="open ? 'Hide settings' : 'Edit settings'"></span>
            </button>
            <a href="{{ route('admin.specialist-calls') }}" wire:navigate
                class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-navy hover:bg-page transition-colors">View requests</a>
        </div>

    <div x-show="open" x-cloak
        x-transition:enter="transition-all duration-300 ease-in-out"
        x-transition:enter-start="max-h-0 -translate-y-2 opacity-0"
        x-transition:enter-end="max-h-[500px] translate-y-0 opacity-100"
        x-transition:leave="transition-all duration-200 ease-in-out"
        x-transition:leave-start="max-h-[500px] translate-y-0 opacity-100"
        x-transition:leave-end="max-h-0 -translate-y-2 opacity-0"
        class="mt-4 space-y-4 overflow-hidden">
        <label class="flex items-center gap-2 text-sm font-semibold text-navy">
            <input type="checkbox" wire:model.live="enabled" class="rounded border-empower-border"> Allow clients to book calls
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-navy mb-1">Topics (one per line)</label>
                <textarea wire:model="topics" rows="6"
                    class="w-full rounded-xl border border-empower-border px-3 py-2 text-sm"></textarea>
                @error('topics') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <button type="button" wire:click="save"
            class="rounded-lg bg-navy px-4 py-2 text-sm font-semibold text-white hover:opacity-90">Save settings</button>
    </div>
    </div>
</div>
