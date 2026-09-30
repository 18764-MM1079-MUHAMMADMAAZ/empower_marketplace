<?php

use App\Enums\UserRole;
use App\Mail\LeadConfirmationMail;
use App\Mail\NewLeadNotificationMail;
use App\Models\Lead;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|string|max:100|regex:/^[\p{L}\s.\'-]+$/u')]
    public string $name = '';

    #[Validate('required|email:rfc,filter|max:150')]
    public string $email = '';

    #[Validate('required|regex:/^\+?[1-9]\d{7,14}$/')]
    public string $phone = '';

    #[Validate('required|string|max:2000')]
    public string $message = '';

    public string $packageInterest = '';

    public bool $submitted = false;

    #[Computed]
    public function packages()
    {
        return Package::where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Please enter a valid name using letters only.',
            'phone.regex' => 'Please enter a valid international phone number, digits only (e.g. +15551234567).',
        ];
    }

    public function mount(): void
    {
        $this->packageInterest = request()->query('package', '');

        if ($user = auth()->user()) {
            $this->name = $user->name;
            $this->email = $user->email;
        }
    }

    public function submit(): void
    {
        $this->validate();

        $lead = Lead::create([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'message' => $this->message,
            'package_interest' => $this->packageInterest ?: null,
        ]);

        try {
            Mail::to($lead->email)->send(new LeadConfirmationMail($lead));
        } catch (\Throwable $e) {
            report($e);
        }

        User::where('role', UserRole::Admin)->pluck('email')->each(
            function (string $adminEmail) use ($lead) {
                try {
                    Mail::to($adminEmail)->send(new NewLeadNotificationMail($lead));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        );

        $this->submitted = true;
    }
};
?>

<div>
    @if($submitted)
        <div class="text-center py-10">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-[#e9f7fc]">
                <svg class="h-8 w-8 text-[#0b9ed0]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-[#0e3a61] mb-2">Thanks, {{ $name }}!</h3>
            <p class="text-sm text-[#5c778d] mb-6">We've received your message and will be in touch shortly about Complete Compliance.</p>
            <a href="{{ route('home') }}#pricing" class="inline-block rounded-xl border border-[#d4e5f1] px-6 py-2.5 text-sm font-semibold text-[#0e3a61] hover:bg-[#f2f8fd] transition-colors">Back to Packages</a>
        </div>
    @else
        <h3 class="text-lg font-semibold text-[#0e3a61] mb-1">Request a Quote</h3>
        <p class="text-sm text-[#5c778d] mb-6">Fields marked required help our team follow up quickly.</p>

        <form wire:submit="submit" novalidate>
            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cf-name">Name</label>
                <input wire:model="name" id="cf-name" type="text" placeholder="Jane Provider"
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cf-email">Email</label>
                <input wire:model="email" id="cf-email" type="email" placeholder="jane@practice.com"
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cf-phone">Phone Number</label>
                <input wire:model="phone" id="cf-phone" type="tel" inputmode="tel" placeholder="+15551234567" maxlength="16"
                    pattern="[+]?[1-9][0-9]{7,14}" title="A valid international phone number, e.g. +15551234567"
                    x-on:input="$el.value = $el.value.replace(/[^0-9+]/g, '')"
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cf-package">Package</label>
                <select wire:model="packageInterest" id="cf-package"
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    <option value="">Select a package…</option>
                    @if($packageInterest !== '' && ! $this->packages->contains('slug', $packageInterest))
                        <option value="{{ $packageInterest }}">{{ $packageInterest }}</option>
                    @endif
                    @foreach($this->packages as $package)
                        <option value="{{ $package->slug }}">{{ $package->name }}</option>
                    @endforeach
                </select>
                @error('packageInterest') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cf-message">Message</label>
                <textarea wire:model="message" id="cf-message" rows="4"
                    placeholder="Tell us a bit about your practice and what you're looking for."
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition resize-none"></textarea>
                @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                class="inline-flex items-center gap-2 rounded-xl bg-[#2299dd] px-6 py-3 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
                <span wire:loading.remove>Send Message</span>
                <span wire:loading.inline-flex class="items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Sending…</span>
                <svg wire:loading.remove class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </button>
        </form>
    @endif
</div>
