<?php

use App\Models\ActivityLog;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Component;

/**
 * My Account › Sign-in & security › Two-step verification (Laravel Fortify's actions, no Fortify
 * routes). Turning it on, showing or regenerating recovery codes and turning it off all ask for the
 * password first; a confirmed password is trusted for {@see PASSWORD_TRUST_SECONDS}.
 */
new class extends Component
{
    public const PASSWORD_TRUST_SECONDS = 900;

    private const ACTIONS = ['enable', 'codes', 'regenerate', 'disable'];

    /** The action waiting for the password ('enable' | 'codes' | 'regenerate' | 'disable'). */
    public ?string $pendingAction = null;

    public string $password = '';

    /** The 6-digit code from the authenticator app, confirming setup. */
    public string $code = '';

    public bool $showingRecoveryCodes = false;

    private function passwordRecentlyConfirmed(): bool
    {
        return now()->getTimestamp() - (int) session('auth.password_confirmed_at', 0) < self::PASSWORD_TRUST_SECONDS;
    }

    /** Starts an action, asking for the password first unless it was confirmed in the last 15 minutes. */
    public function start(string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return;
        }

        $this->resetErrorBag();

        if ($this->passwordRecentlyConfirmed()) {
            $this->run($action);

            return;
        }

        $this->pendingAction = $action;
        $this->password = '';
    }

    public function confirmPassword(): void
    {
        $this->validate(['password' => 'required|current_password'], [
            'password.current_password' => 'That password is incorrect.',
        ]);

        session(['auth.password_confirmed_at' => now()->getTimestamp()]);

        $action = $this->pendingAction;
        $this->pendingAction = null;
        $this->password = '';

        if ($action) {
            $this->run($action);
        }
    }

    public function cancelPassword(): void
    {
        $this->pendingAction = null;
        $this->password = '';
        $this->resetErrorBag();
    }

    private function run(string $action): void
    {
        $user = auth()->user();

        match ($action) {
            'enable' => app(EnableTwoFactorAuthentication::class)($user),
            'codes' => $this->showingRecoveryCodes = $user->hasEnabledTwoFactorAuthentication(),
            'regenerate' => $this->regenerate(),
            'disable' => $this->disable(),
        };

        if ($action === 'enable') {
            $this->code = '';
            $this->showingRecoveryCodes = false;
        }
    }

    public function confirmSetup(): void
    {
        $this->resetErrorBag();
        $user = auth()->user();
        $this->code = preg_replace('/\D/', '', $this->code);

        try {
            app(ConfirmTwoFactorAuthentication::class)($user, $this->code);
        } catch (ValidationException) {
            $this->addError('code', "That code didn't work. Check the 6 digits in your app and try again.");

            return;
        }

        ActivityLog::record('account.two_factor_enabled', 'Turned on two-step verification.', user: $user);

        $this->code = '';
        $this->showingRecoveryCodes = true;
        $this->dispatch('toast', message: 'Two-step verification is on. Save your recovery codes.', type: 'success');
    }

    /** Abandons a setup that was never confirmed (no password needed: nothing was protecting the account yet). */
    public function cancelSetup(): void
    {
        $user = auth()->user();

        if ($user->two_factor_secret && ! $user->two_factor_confirmed_at) {
            app(DisableTwoFactorAuthentication::class)($user);
        }

        $this->code = '';
        $this->resetErrorBag();
    }

    public function hideRecoveryCodes(): void
    {
        $this->showingRecoveryCodes = false;
    }

    private function regenerate(): void
    {
        $user = auth()->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        app(GenerateNewRecoveryCodes::class)($user);
        ActivityLog::record('account.two_factor_recovery_codes_regenerated', 'Made new two-step recovery codes.', user: $user);

        $this->showingRecoveryCodes = true;
        $this->dispatch('toast', message: 'New recovery codes made. The old ones no longer work.', type: 'success');
    }

    private function disable(): void
    {
        $user = auth()->user();

        app(DisableTwoFactorAuthentication::class)($user);
        ActivityLog::record('account.two_factor_disabled', 'Turned off two-step verification.', user: $user);

        $this->showingRecoveryCodes = false;
        $this->dispatch('toast', message: 'Two-step verification is off.', type: 'success');
    }
};
?>

@php
$user = auth()->user()->fresh();
$enabled = $user->hasEnabledTwoFactorAuthentication();
$settingUp = ! $enabled && $user->two_factor_secret !== null;
$recoveryCodes = $enabled && $showingRecoveryCodes ? $user->recoveryCodes() : [];
$inputClass = 'w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition';
@endphp

<div class="py-4.5 border-t border-[#eef2f6]" data-two-factor data-two-factor-state="{{ $enabled ? 'on' : ($settingUp ? 'setup' : 'off') }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex-1 min-w-[16rem]">
            <h3 class="text-sm font-semibold text-[#173045] flex items-center gap-2">
                Two-step verification
                @if($enabled)
                <span class="rounded-full bg-[#e7f5ee] px-2.5 py-0.5 text-xs font-semibold text-[#146c43]">On</span>
                @else
                <span class="rounded-full bg-[#fdf0d8] px-2.5 py-0.5 text-xs font-semibold text-[#7a4a00]">Off</span>
                @endif
            </h3>
            <p class="mt-0.5 text-[13px] text-[#5d6e7f] max-w-xl">
                @if($enabled)
                Signing in asks for a code from your authenticator app as well as your password. On since {{ $user->two_factor_confirmed_at->format('M j, Y') }}.
                @else
                After your password, enter a 6-digit code from an authenticator app such as Google Authenticator, Microsoft Authenticator or 1Password.
                @endif
            </p>
        </div>

        @if($pendingAction === null && ! $settingUp)
        <div class="flex flex-wrap gap-2">
            @if($enabled)
            <button type="button" wire:click="start('codes')" class="rounded-lg border border-[#d4e5f1] bg-white px-3 py-1.5 text-xs font-bold text-[#173a59] hover:bg-[#eef8fd] transition-colors" @if($showingRecoveryCodes) hidden @endif>Show recovery codes</button>
            <button type="button" wire:click="start('disable')" class="rounded-lg border border-[#d4e5f1] bg-white px-3 py-1.5 text-xs font-bold text-[#b42318] hover:bg-[#fff5f5] transition-colors" data-two-factor-disable>Turn off</button>
            @else
            <button type="button" wire:click="start('enable')" class="inline-flex h-10 items-center rounded-lg bg-[#2299dd] px-4 text-[13px] font-semibold text-white hover:bg-[#087fa9] transition-colors" data-two-factor-enable>Turn on</button>
            @endif
        </div>
        @endif
    </div>

    {{-- Password check before a sensitive change --}}
    @if($pendingAction !== null)
    <form wire:submit="confirmPassword" novalidate class="mt-4 max-w-md" data-two-factor-password>
        <label for="tf-password" class="block text-sm font-medium text-[#173a59] mb-1.5">
            {{ match ($pendingAction) {
                'enable' => 'Enter your password to turn on two-step verification',
                'disable' => 'Enter your password to turn off two-step verification',
                'regenerate' => 'Enter your password to make new recovery codes',
                default => 'Enter your password to see your recovery codes',
            } }}
        </label>
        <x-password-input wire:model="password" id="tf-password" autocomplete="current-password" class="{{ $inputClass }}" placeholder="••••••••" x-init="$el.focus()" />
        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        <div class="mt-3 flex gap-2">
            <button type="submit" class="rounded px-4 py-2 text-sm font-bold text-white transition-colors {{ $pendingAction === 'disable' ? 'bg-[#c03a3a] hover:bg-[#a12f2f]' : 'bg-[#2299dd] hover:bg-[#087fa9]' }}"
                wire:loading.attr="disabled" wire:target="confirmPassword">{{ $pendingAction === 'disable' ? 'Turn off' : 'Continue' }}</button>
            <button type="button" wire:click="cancelPassword" class="rounded px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:text-[#12304f]">Cancel</button>
        </div>
    </form>
    @endif

    {{-- Setup: scan the QR code, then confirm with a code --}}
    @if($settingUp)
    <div class="mt-4 grid gap-5 sm:grid-cols-[auto_minmax(0,1fr)] items-start" data-two-factor-setup>
        <div class="justify-self-center sm:justify-self-start rounded-xl border border-[#dbe4ee] bg-white p-3" aria-label="QR code for your authenticator app" role="img">
            {!! $user->twoFactorQrCodeSvg() !!}
        </div>
        <div class="min-w-0">
            <ol class="list-decimal pl-5 space-y-1 text-sm text-[#173045]">
                <li>Open your authenticator app and add an account.</li>
                <li>Scan this QR code. Can't scan it? Enter this setup key instead:
                    <span class="mt-1 block font-mono text-xs font-semibold tracking-wider text-[#12304f] break-all bg-white border border-[#dbe4ee] rounded px-2 py-1" data-two-factor-key>{{ trim(chunk_split(\Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 4, ' ')) }}</span>
                </li>
                <li>Enter the 6-digit code the app shows.</li>
            </ol>
            <form wire:submit="confirmSetup" novalidate class="mt-3 max-w-xs">
                <label for="tf-code" class="sr-only">6-digit code</label>
                <input wire:model="code" id="tf-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456"
                    class="{{ $inputClass }} font-mono tracking-[0.3em]" x-init="$el.focus()">
                @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <div class="mt-3 flex gap-2">
                    <button type="submit" class="rounded bg-[#2299dd] px-4 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                        wire:loading.attr="disabled" wire:target="confirmSetup">Confirm and turn on</button>
                    <button type="button" wire:click="cancelSetup" class="rounded px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:text-[#12304f]">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Recovery codes --}}
    @if($recoveryCodes)
    <div class="mt-4 rounded-xl border border-[#f0d9a8] bg-[#fffaf0] p-4" data-recovery-codes
        x-data="{ copied: false, codes: @js(implode("\n", $recoveryCodes)) }">
        <p class="text-sm font-bold text-[#7a4b00]">Save your recovery codes</p>
        <p class="text-xs text-[#7a5a1f] mb-3">If you lose your phone, each code lets you sign in once. Keep them somewhere safe, like a password manager.</p>
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 font-mono text-sm text-[#12304f]">
            @foreach($recoveryCodes as $recoveryCode)
            <li class="rounded bg-white border border-[#f0e2c4] px-2 py-1 text-center">{{ $recoveryCode }}</li>
            @endforeach
        </ul>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" x-on:click="navigator.clipboard?.writeText(codes); copied = true; setTimeout(() => copied = false, 2000)"
                class="rounded-lg border border-[#d4e5f1] bg-white px-3 py-1.5 text-xs font-bold text-[#173a59] hover:bg-[#eef8fd]"
                x-text="copied ? 'Copied ✓' : 'Copy'">Copy</button>
            <button type="button" x-on:click="empowerDownloadText('empower-recovery-codes.txt', 'Empower portal recovery codes\n\n' + codes + '\n', 'text/plain')"
                class="rounded-lg border border-[#d4e5f1] bg-white px-3 py-1.5 text-xs font-bold text-[#173a59] hover:bg-[#eef8fd]">Download</button>
            <button type="button" wire:click="start('regenerate')" wire:confirm="Make new recovery codes? The old ones will stop working."
                class="rounded-lg border border-[#d4e5f1] bg-white px-3 py-1.5 text-xs font-bold text-[#173a59] hover:bg-[#eef8fd]">Make new codes</button>
            <button type="button" wire:click="hideRecoveryCodes" class="rounded-lg px-3 py-1.5 text-xs font-bold text-[#5d6e7f] hover:text-[#12304f]">Done</button>
        </div>
    </div>
    @endif
</div>
