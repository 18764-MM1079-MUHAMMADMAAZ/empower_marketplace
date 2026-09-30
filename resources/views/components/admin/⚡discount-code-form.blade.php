<?php

use App\Enums\DiscountType;
use App\Models\ActivityLog;
use App\Models\DiscountCode;
use Illuminate\Support\Carbon;
use Livewire\Component;

new class extends Component
{
    public ?int $discountCodeId = null;

    public string $code = '';

    public string $type = 'percentage';

    public ?string $percentage = null;

    public ?string $trialDays = null;

    public string $startsAt = '';

    public string $expiresAt = '';

    public ?string $maxUses = null;

    public bool $isActive = true;

    public function mount(?DiscountCode $discountCode = null): void
    {
        if (! $discountCode) {
            return;
        }

        $this->discountCodeId = $discountCode->id;
        $this->code = $discountCode->code;
        $this->type = $discountCode->type->value;
        $this->percentage = $discountCode->percentage !== null ? (string) $discountCode->percentage : null;
        $this->trialDays = $discountCode->trial_days !== null ? (string) $discountCode->trial_days : null;
        $this->startsAt = $discountCode->starts_at?->format('Y-m-d') ?? '';
        $this->expiresAt = $discountCode->expires_at?->format('Y-m-d') ?? '';
        $this->maxUses = $discountCode->max_uses !== null ? (string) $discountCode->max_uses : null;
        $this->isActive = $discountCode->is_active;
    }

    /** Keeps the code's own validity window in step with the trial length, so an admin doesn't
     *  have to hand-calculate an expiry date — still editable afterward if a different window is wanted. */
    public function updatedTrialDays(): void
    {
        if ($this->type !== DiscountType::FreeTrial->value || ! $this->trialDays) {
            return;
        }

        $start = $this->startsAt ? Carbon::parse($this->startsAt) : now();
        $this->startsAt = $start->format('Y-m-d');
        $this->expiresAt = $start->copy()->addDays((int) $this->trialDays)->format('Y-m-d');
    }

    public function save(): void
    {
        $this->code = strtoupper(trim($this->code));

        $this->validate([
            'code' => 'required|string|max:50|unique:discount_codes,code'.($this->discountCodeId ? ",{$this->discountCodeId}" : ''),
            'type' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, DiscountType::cases())),
            'percentage' => 'required_if:type,'.DiscountType::Percentage->value.'|nullable|integer|min:1|max:100',
            'trialDays' => 'required_if:type,'.DiscountType::FreeTrial->value.'|nullable|integer|min:1|max:365',
            'startsAt' => 'nullable|date',
            'expiresAt' => 'nullable|date|after_or_equal:startsAt',
            'maxUses' => 'nullable|integer|min:1',
        ]);

        $isPercentage = $this->type === DiscountType::Percentage->value;

        $data = [
            'code' => $this->code,
            'type' => $this->type,
            'percentage' => $isPercentage ? $this->percentage : null,
            'trial_days' => $isPercentage ? null : $this->trialDays,
            'starts_at' => $this->startsAt ?: null,
            'expires_at' => $this->expiresAt ?: null,
            'max_uses' => $this->maxUses !== null && $this->maxUses !== '' ? $this->maxUses : null,
            'is_active' => $this->isActive,
        ];

        if ($this->discountCodeId) {
            $discountCode = DiscountCode::findOrFail($this->discountCodeId);
            $discountCode->update($data);

            ActivityLog::record('discount_code.updated', "{$discountCode->code} was updated.", user: auth()->user(), subject: $discountCode);
            session()->flash('toast', "{$discountCode->code} updated.");
        } else {
            $discountCode = DiscountCode::create($data);

            ActivityLog::record('discount_code.created', "{$discountCode->code} was created.", user: auth()->user(), subject: $discountCode);
            session()->flash('toast', "{$discountCode->code} created.");
        }

        $this->redirect(route('admin.discount-codes'), navigate: true);
    }
};
?>

<div class="space-y-4">
    <a href="{{ route('admin.discount-codes') }}" wire:navigate class="text-sm font-semibold text-[#0b9ed0] hover:underline">&larr; Back to discount codes</a>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h2 class="text-lg font-semibold text-navy mb-4">{{ $discountCodeId ? 'Edit Discount Code' : 'New Discount Code' }}</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Code</label>
                <input wire:model="code" type="text" placeholder="SAVE20"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm font-mono uppercase text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Type</label>
                <select wire:model.live="type"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @foreach(DiscountType::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
                @error('type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if($type === DiscountType::Percentage->value)
                <div>
                    <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Percentage Off (%)</label>
                    <input wire:model="percentage" type="number" min="1" max="100" placeholder="20"
                        class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @error('percentage') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @else
                <div>
                    <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Trial Length (days)</label>
                    <input wire:model.live.debounce.500ms="trialDays" type="number" min="1" max="365" placeholder="30"
                        class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    <p class="mt-1 text-xs text-empower-muted">Valid From / Expires On below are auto-filled from this — feel free to adjust them afterward.</p>
                    @error('trialDays') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Max Uses</label>
                <input wire:model="maxUses" type="number" min="1" placeholder="Leave blank for unlimited"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('maxUses') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Valid From</label>
                <input wire:model="startsAt" type="date"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('startsAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Expires On</label>
                <input wire:model="expiresAt" type="date"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                <p class="mt-1 text-xs text-empower-muted">Leave blank for a code that never expires.</p>
                @error('expiresAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="inline-flex items-center gap-2">
                    <input wire:model="isActive" type="checkbox" class="rounded border-empower-border text-navy focus:ring-accent">
                    <span class="text-sm font-semibold text-[#173a59]">Active</span>
                </label>
            </div>
        </div>

        <div class="mt-5 flex justify-end">
            <button wire:click="save" wire:target="save"
                class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $discountCodeId ? 'Save Changes' : 'Create Discount Code' }} &rarr;</span>
                <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
            </button>
        </div>
    </div>
</div>
