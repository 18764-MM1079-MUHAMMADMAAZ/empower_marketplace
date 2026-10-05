<?php

use App\Enums\BillingCycle;
use App\Models\Package;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?string $package = null;

    /** Display-only: shows what's about to be purchased, same package the eventual checkout
     *  (Step 1) will pre-select — never used to charge anything from this component. */
    #[Computed]
    public function selectedPackage(): ?Package
    {
        $slug = $this->package ?: session('intended_package');

        return $slug ? Package::where('slug', $slug)->where('is_active', true)->first() : null;
    }

    #[Computed]
    public function billingCycle(): BillingCycle
    {
        $raw = request()->query('billing_cycle') ?? session('intended_billing_cycle');

        return $raw === 'monthly' ? BillingCycle::Monthly : BillingCycle::Annual;
    }

    /** Where "Create an account" sends the visitor — the account itself is created as part of
     *  paying in Step 1 (⚡portal.blade.php's pay()/payFreeTrial()), not here. */
    #[Computed]
    public function portalUrl(): string
    {
        return route('portal', array_filter([
            'package' => $this->selectedPackage?->slug,
            'billing_cycle' => $this->billingCycle->value,
        ]));
    }
};
?>

<div>
    @if($this->selectedPackage)
    <div class="mb-5 rounded-xl bg-[#f2f8fd] border border-[#d4e5f1] px-4 py-3 flex items-center justify-between gap-3">
        <div>
            <p class="text-[10px] font-extrabold uppercase tracking-widest text-[#5c778d] mb-0.5">Selected package</p>
            <p class="text-sm font-bold text-[#173a59]">{{ $this->selectedPackage->name }}</p>
            <p class="text-xs text-[#5c778d]">${{ number_format($this->selectedPackage->priceForCycle($this->billingCycle) ?? 0, 2) }}
                per provider / {{ $this->billingCycle->period() }}</p>
        </div>
        <a href="{{ route('home') }}#pricing" wire:navigate
            class="text-xs font-semibold text-[#0b9ed0] hover:text-[#087fa9] transition-colors whitespace-nowrap">Change
            package</a>
    </div>
    @endif
    <a href="{{ $this->portalUrl }}" wire:navigate
        class="block w-full rounded bg-[#2299dd] px-5 py-2.5 text-center text-sm font-bold text-white hover:bg-[#087fa9] transition-colors">Create
        an account &rarr;</a>

    <p class="mt-6 text-center text-sm text-[#5c778d]">
        Already have an account?
        <a href="{{ route('login') }}" wire:navigate class="font-semibold text-[#0e3a61] hover:text-[#0b9ed0] transition-colors">Log in</a>
    </p>
</div>
