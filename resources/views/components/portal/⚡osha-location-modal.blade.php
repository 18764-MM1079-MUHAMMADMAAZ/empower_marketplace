<?php

use App\Models\OshaLocation;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public int $practiceId;

    public bool $open = false;

    public ?int $locationId = null;

    #[Validate('required|string|max:150')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public string $address = '';

    #[Validate('nullable|string|max:150')]
    public string $oshaOfficer = '';

    #[Validate('nullable|string|max:150')]
    public string $safetyCoordinator = '';

    public bool $usesHazardousDrugs = false;

    public bool $hasOperatingRooms = false;

    #[Validate('nullable|string|max:150')]
    public string $cleaningProvider = '';

    #[Validate('nullable|string|max:100')]
    public string $cleaningFrequency = '';

    public bool $offersHepBVaccination = true;

    public bool $offersTbScreening = false;

    #[Validate('nullable|string|max:20')]
    public string $employeesPerYear = '';

    #[Validate('nullable|string|max:150')]
    public string $wasteHauler = '';

    #[On('open-osha-modal')]
    public function openModal(?int $locationId = null): void
    {
        $this->reset([
            'name', 'address', 'oshaOfficer', 'safetyCoordinator',
            'usesHazardousDrugs', 'hasOperatingRooms', 'cleaningProvider', 'cleaningFrequency',
            'offersHepBVaccination', 'offersTbScreening', 'employeesPerYear', 'wasteHauler',
        ]);
        $this->resetErrorBag();
        $this->locationId = $locationId;

        if ($locationId) {
            $loc = OshaLocation::find($locationId);
            if ($loc && $loc->practice_id === $this->practiceId) {
                $this->name = $loc->name ?? '';
                $this->address = $loc->address ?? '';
                $this->oshaOfficer = $loc->osha_officer ?? '';
                $this->safetyCoordinator = $loc->safety_coordinator ?? '';
                $this->usesHazardousDrugs = (bool) $loc->uses_hazardous_drugs;
                $this->hasOperatingRooms = (bool) $loc->has_operating_rooms;
                $this->cleaningProvider = $loc->cleaning_provider ?? '';
                $this->cleaningFrequency = $loc->cleaning_frequency ?? '';
                $this->offersHepBVaccination = (bool) $loc->offers_hep_b_vaccination;
                $this->offersTbScreening = (bool) $loc->offers_tb_screening;
                $this->employeesPerYear = $loc->employees_per_year ?? '';
                $this->wasteHauler = $loc->waste_hauler ?? '';
            }
        }

        $this->open = true;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'practice_id' => $this->practiceId,
            'name' => $this->name,
            'address' => $this->address ?: null,
            'osha_officer' => $this->oshaOfficer ?: null,
            'safety_coordinator' => $this->safetyCoordinator ?: null,
            'uses_hazardous_drugs' => $this->usesHazardousDrugs,
            'has_operating_rooms' => $this->hasOperatingRooms,
            'cleaning_provider' => $this->cleaningProvider ?: null,
            'cleaning_frequency' => $this->cleaningFrequency ?: null,
            'offers_hep_b_vaccination' => $this->offersHepBVaccination,
            'offers_tb_screening' => $this->offersTbScreening,
            'employees_per_year' => $this->employeesPerYear ?: null,
            'waste_hauler' => $this->wasteHauler ?: null,
        ];

        if ($this->locationId) {
            $loc = OshaLocation::where('id', $this->locationId)
                ->where('practice_id', $this->practiceId)
                ->firstOrFail();
            $loc->update($data);
        } else {
            OshaLocation::create($data);
        }

        $this->open = false;
        $this->dispatch('osha-location-saved');
    }

    public function delete(): void
    {
        if ($this->locationId) {
            OshaLocation::where('id', $this->locationId)
                ->where('practice_id', $this->practiceId)
                ->delete();
        }

        $this->open = false;
        $this->dispatch('osha-location-saved');
    }
};
?>

<div>
@if($open)
<div class="fixed inset-0 z-50 flex items-start justify-center bg-black/60 backdrop-blur-sm overflow-y-auto py-10 px-4">
    <div class="w-full max-w-xl bg-white rounded-[1.25rem] shadow-xl">
        {{-- Modal header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-[#dbe4ee]">
            <h3 class="text-base font-semibold text-[#12304f]">
                {{ $locationId ? 'Edit OSHA Location' : 'Add OSHA Location' }}
            </h3>
            <button wire:click="$set('open', false)" class="text-[#5d6e7f] hover:text-[#12304f] text-xl leading-none">&times;</button>
        </div>

        {{-- Modal body --}}
        <div class="px-6 py-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Location Name <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" placeholder="e.g. Main Office"
                    class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Address</label>
                <input wire:model="address" type="text" placeholder="123 Main St, Springfield, IL"
                    class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">OSHA Officer</label>
                    <input wire:model="oshaOfficer" type="text"
                        class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Safety Coordinator</label>
                    <input wire:model="safetyCoordinator" type="text"
                        class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Does the facility utilize hazardous drugs?</label>
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="usesHazardousDrugs" value="1" class="text-[#009bde] focus:ring-[#009bde]"> Yes
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="usesHazardousDrugs" value="0" class="text-[#009bde] focus:ring-[#009bde]"> No
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Do you have operating rooms?</label>
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="hasOperatingRooms" value="1" class="text-[#009bde] focus:ring-[#009bde]"> Yes
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="hasOperatingRooms" value="0" class="text-[#009bde] focus:ring-[#009bde]"> No
                        </label>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Cleaning service provider</label>
                    <input wire:model="cleaningProvider" type="text"
                        class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">How frequently is the facility cleaned?</label>
                    <input wire:model="cleaningFrequency" type="text"
                        class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Does the facility offer Hepatitis B vaccination to staff?</label>
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="offersHepBVaccination" value="1" class="text-[#009bde] focus:ring-[#009bde]"> Yes
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="offersHepBVaccination" value="0" class="text-[#009bde] focus:ring-[#009bde]"> No
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Does the facility offer TB screening for staff?</label>
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="offersTbScreening" value="1" class="text-[#009bde] focus:ring-[#009bde]"> Yes
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-sm text-[#173045] cursor-pointer">
                            <input type="radio" wire:model="offersTbScreening" value="0" class="text-[#009bde] focus:ring-[#009bde]"> No
                        </label>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">How many employees are employed during a calendar year?</label>
                    <input wire:model="employeesPerYear" type="text"
                        class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Waste hauler</label>
                    <input wire:model="wasteHauler" type="text"
                        class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                </div>
            </div>
        </div>

        {{-- Modal footer --}}
        <div class="flex items-center justify-between px-6 py-4 border-t border-[#dbe4ee]">
            @if($locationId)
                <button wire:click="delete" wire:confirm="Delete this OSHA location?" wire:target="delete"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="delete"
                    class="text-sm font-semibold text-red-600 hover:text-red-700 transition-colors">
                    <span wire:loading.remove wire:target="delete">Delete location</span>
                    <span wire:loading.inline-flex wire:target="delete" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Deleting…</span>
                </button>
            @else
                <span></span>
            @endif
            <div class="flex gap-3">
                <button wire:click="$set('open', false)"
                    class="rounded-lg border border-[#dbe4ee] px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                    Cancel
                </button>
                <button wire:click="save" wire:target="save"
                    class="inline-flex items-center gap-1 rounded bg-[#76c8c0] px-5 py-2 text-sm font-bold text-[#0a2037] hover:bg-[#5bb2aa] transition-colors"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save Location</span>
                    <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif
</div>
