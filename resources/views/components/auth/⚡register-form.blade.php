<?php

use App\Enums\BillingCycle;
use App\Models\Package;
use App\Services\EmpowerSsoApiClient;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?string $package = null;

    /** Remembers the package being bought, so signing in instead of registering (including via
     *  talkEHR) can carry on to that package's checkout. */
    public function mount(): void
    {
        $package = $this->selectedPackage;

        if ($package) {
            session(['intended_package' => $package->slug, 'intended_billing_cycle' => $this->billingCycle->value]);
        }
    }

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

    /** talkEHR / CareCloud users already have an account through their platform: these buttons send
     *  them to sign in with it instead of creating a duplicate Empower account. The package chosen
     *  above stays in the session, so signing in carries on to its checkout.
     *
     *  @return array<int, string> */
    #[Computed]
    public function ssoProviders(): array
    {
        return app(EmpowerSsoApiClient::class)->enabledProviders();
    }

    /** Where "Create an account" sends the visitor — the account itself is created as part of
     *  paying in Step 1 (⚡portal.blade.php's pay()/payFreeTrial()), not here. */
    #[Computed]
    public function portalUrl(): string
    {
        return route('portal', array_filter([
            'package' => $this->selectedPackage?->slug,
            'billing_cycle' => $this->billingCycle->value,
            'billable_providers' => session('intended_billable_providers'),
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
            <p class="text-xs text-[#5c778d]">${{
                number_format($this->selectedPackage->priceForCycle($this->billingCycle) ?? 0, 2) }}
                per provider / {{ $this->billingCycle->period() }}</p>
        </div>
        <a href="{{ route('home') }}#pricing" wire:navigate
            class="text-xs font-semibold text-[#0b9ed0] hover:text-[#087fa9] transition-colors whitespace-nowrap">Change
            package</a>
    </div>
    @endif

    @if($this->ssoProviders)
    @php
    $ssoLogos = ['talkehr' => 'talk-logo.png', 'carecloud' => 'carcloud-logo.png'];
    $ssoLabels = ['talkehr' => 'talkEHR', 'carecloud' => 'CareCloud'];
    @endphp
    <div class="mb-5 rounded-xl border border-[#d4e5f1] bg-[#f9fcff] px-4 py-4" data-sso-signup-options>
        <p class="text-sm font-bold text-[#173a59]">Already use {{ collect($this->ssoProviders)->map(fn ($p) => $ssoLabels[$p])->join(' or ') }}?</p>
        <p class="mt-0.5 mb-3 text-xs text-[#5c778d]">Sign in with that account instead &mdash; there's no need to
            create a new one.</p>
        <div class="grid {{ count($this->ssoProviders) > 1 ? 'grid-cols-2' : 'grid-cols-1' }} gap-2.5">
            @foreach($this->ssoProviders as $provider)
            <a href="{{ route('login', ['sso' => $provider]) }}" wire:navigate wire:key="signup-sso-{{ $provider }}"
                aria-label="Sign in with {{ $ssoLabels[$provider] }}"
                class="flex items-center justify-center rounded-xl border border-[#d4e5f1] bg-white px-3 py-2.5 hover:border-[#0b9ed0] hover:bg-[#f4f9fc] transition-colors">
                <img src="{{ asset('images/'.$ssoLogos[$provider]) }}" alt="" class="h-8 w-auto max-w-full object-contain">
            </a>
            @endforeach
        </div>
    </div>

    <div class="flex items-center gap-3 mb-5">
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
        <span class="text-xs font-semibold text-[#5c778d] uppercase tracking-wide">New to Empower?</span>
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
    </div>
    @endif

    <a href="{{ $this->portalUrl }}" wire:navigate
        class="block w-full rounded bg-[#2299dd] px-5 py-2.5 text-center text-sm font-bold text-white hover:bg-[#087fa9] transition-colors">Create
        an account &rarr;</a>

    <p class="mt-6 text-center text-sm text-[#5c778d]">
        Already have an account?
        <a href="{{ route('login') }}" wire:navigate
            class="font-semibold text-[#0e3a61] hover:text-[#0b9ed0] transition-colors">Log in</a>
    </p>
</div>