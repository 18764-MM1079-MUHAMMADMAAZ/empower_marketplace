<?php

use App\Models\Setting;
use App\Services\SpecialistCallSettings;
use Livewire\Component;

new class extends Component
{
    public bool $enabled = true;

    public string $timeSlots = '';

    public string $topics = '';

    public int $daysAhead = SpecialistCallSettings::DEFAULT_DAYS_AHEAD;

    public function mount(): void
    {
        $this->enabled = SpecialistCallSettings::enabled();
        $this->timeSlots = implode("\n", SpecialistCallSettings::timeSlots());
        $this->topics = implode("\n", SpecialistCallSettings::topics());
        $this->daysAhead = SpecialistCallSettings::daysAhead();
    }

    public function save(): void
    {
        $this->validate([
            'timeSlots' => 'required|string',
            'topics' => 'required|string',
            'daysAhead' => 'required|integer|min:1|max:30',
        ]);

        Setting::write('calls_enabled', $this->enabled ? '1' : '0');
        Setting::write('call_time_slots', json_encode($this->lines($this->timeSlots)));
        Setting::write('call_topics', json_encode($this->lines($this->topics)));
        Setting::write('call_days_ahead', (string) $this->daysAhead);

        $this->dispatch('toast', message: 'Call booking settings saved.', type: 'success');
    }

    /** @return array<int, string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));
    }
};
?>

<div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5" x-data="{ open: false }">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Specialist Call Booking</h2>
            <p class="mt-1 text-sm font-semibold {{ $enabled ? 'text-[#117a51]' : 'text-[#9a6700]' }}">
                {{ $enabled ? 'Open for booking' : 'Booking is switched off' }}
                <a href="{{ route('admin.specialist-calls') }}" wire:navigate class="ml-2 font-semibold text-[#1a7aad] hover:underline">View requests</a>
            </p>
        </div>
        <button type="button" x-on:click="open = !open"
            class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-navy hover:bg-page">
            <span x-text="open ? 'Hide settings' : 'Edit settings'"></span>
        </button>
    </div>

    <div x-show="open" x-cloak class="mt-4 space-y-4 border-t border-empower-border pt-4">
        <label class="flex items-center gap-2 text-sm font-semibold text-navy">
            <input type="checkbox" wire:model.live="enabled" class="rounded border-empower-border"> Allow clients to book calls
        </label>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-bold text-navy mb-1">Time slots (Eastern, one per line)</label>
                <textarea wire:model="timeSlots" rows="6"
                    class="w-full rounded-xl border border-empower-border px-3 py-2 text-sm"></textarea>
                @error('timeSlots') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-bold text-navy mb-1">Topics (one per line)</label>
                <textarea wire:model="topics" rows="6"
                    class="w-full rounded-xl border border-empower-border px-3 py-2 text-sm"></textarea>
                @error('topics') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-bold text-navy mb-1">Weekdays offered (from tomorrow)</label>
                <input type="number" wire:model="daysAhead" min="1" max="30"
                    class="w-full rounded-xl border border-empower-border px-3 py-2 text-sm">
                @error('daysAhead') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <button type="button" wire:click="save"
            class="rounded-lg bg-navy px-4 py-2 text-sm font-semibold text-white hover:opacity-90">Save settings</button>
    </div>
</div>
