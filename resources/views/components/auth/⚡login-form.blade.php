<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    #[Validate('required|email:rfc,filter')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function login(): void
    {
        $this->validate();

        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->addError('email', "Too many login attempts. Please try again in {$seconds} seconds.");

            return;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey(), self::LOGIN_DECAY_SECONDS);
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($this->throttleKey());

        if (! Auth::user()->is_active) {
            Auth::logout();
            $this->addError('email', 'This account has been deactivated.');

            return;
        }

        session()->regenerate();

        $user = Auth::user();

        $destination = match (true) {
            $user->isAdmin() => route('admin.dashboard'),
            // A fresh client who has never chosen a package or completed a payment (orders
            // are only ever created after a successful charge — see pay() in the portal
            // component) has nothing to do in the portal yet, so send them to the home page.
            $user->orders()->doesntExist() => route('home'),
            default => route('portal'),
        };

        $this->redirect($destination, navigate: true);
    }
};
?>

<div>
    {{-- SSO sign-in options (UI only for now — these link out to CCH's/talkEHR's own "launch
    Empower" URL once that's built; see sso.md §5 "Sign-in screen" and open question 1). --}}
    <div class="grid grid-cols-2 gap-2.5 mb-5">
        <a href="#"
            class="flex items-center justify-center gap-1.5 rounded-xl border border-[#d4e5f1] bg-white px-3 py-2.5 text-xs font-semibold text-[#173a59] hover:border-[#0b9ed0] hover:bg-[#f4f9fc] transition-colors">
            <img src="{{ asset('images/carcloud-logo.png') }}" alt="" class="h-3 w-auto flex-shrink-0">
            <span class="sr-only min-[534px]:not-sr-only">Sign in with CareCloud</span>
        </a>
        <a href="#"
            class="flex items-center justify-center gap-1.5 rounded-xl border border-[#d4e5f1] bg-white px-3 py-2.5 text-xs font-semibold text-[#173a59] hover:border-[#0b9ed0] hover:bg-[#f4f9fc] transition-colors">
            <img src="{{ asset('images/talk-logo.png') }}" alt="" class="h-3 w-auto flex-shrink-0">
            <span class="sr-only min-[534px]:not-sr-only">Sign in with talkEHR</span>
        </a>
    </div>
    <div class="space-y-2.5 mb-5">
        <a href="{{ route('register') }}" wire:navigate
            class="flex items-center justify-center gap-2 w-full rounded-xl border border-dashed border-[#d4e5f1] px-4 py-2.5 text-sm font-semibold text-[#0e3a61] hover:border-[#0b9ed0] hover:bg-[#f4f9fc] transition-colors">
            Create an account
        </a>
    </div>

    <div class="flex items-center gap-3 mb-5">
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
        <span class="text-xs font-semibold text-[#5c778d] uppercase tracking-wide">Or sign in with email</span>
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
    </div>

    <form wire:submit="login" novalidate>
        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="lf-email">Email address</label>
            <input wire:model="email" id="lf-email" type="email" autocomplete="email" autofocus
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                placeholder="you@practice.com">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <div class="flex items-center justify-between mb-1.5">
                <label class="block text-sm font-medium text-[#173a59]" for="lf-password">Password</label>
                <a href="{{ route('password.request') }}" wire:navigate
                    class="text-xs font-semibold text-[#0b9ed0] hover:text-[#0e3a61] transition-colors">Forgot
                    password?</a>
            </div>
            <input wire:model="password" id="lf-password" type="password" autocomplete="current-password"
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                placeholder="••••••••">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="remember" type="checkbox"
                    class="h-4 w-4 rounded border-[#d4e5f1] text-[#0b9ed0] focus:ring-[#0b9ed0]">
                <span class="text-sm text-[#5c778d]">Remember me</span>
            </label>
        </div>

        <button type="submit"
            class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
            wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
            <span wire:loading.remove>Log In &rarr;</span>
            <span wire:loading.inline-flex class="inline-flex items-center gap-1.5">
                <x-spinner class="h-3.5 w-3.5" /> Signing in…
            </span>
        </button>
    </form>
</div>