<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|email:rfc,filter')]
    public string $email = '';

    public bool $sent = false;

    public function sendResetLink(): void
    {
        $this->validate();

        $status = Password::sendResetLink(['email' => $this->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        $this->sent = true;
    }
};
?>

<div>
    @if($sent)
        <div class="text-center py-6">
            <div class="w-14 h-14 rounded-full bg-[#eef8f3] text-[#117a51] inline-flex items-center justify-center text-2xl mb-3 mx-auto">&#10003;</div>
            <h4 class="text-base font-semibold text-[#173a59] mb-1">Check your email</h4>
            <p class="text-sm text-[#5c778d]">We've sent a password reset link to {{ $email }}.</p>
        </div>
    @else
        <form wire:submit="sendResetLink" novalidate>
            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="fpf-email">Email address</label>
                <input wire:model="email" id="fpf-email" type="email" autocomplete="email" autofocus
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                    placeholder="you@practice.com">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
                <span wire:loading.remove>Send Reset Link &rarr;</span>
                <span wire:loading.inline-flex class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Sending…</span>
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-[#5c778d]">
            Remembered your password?
            <a href="{{ route('login') }}" wire:navigate class="font-semibold text-[#0e3a61] hover:text-[#0b9ed0] transition-colors">Log in</a>
        </p>
    @endif
</div>
