<?php

use App\Models\ActivityLog;
use App\Services\UserSessions;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * My Account › Sign-in & security, for clients and admins: change password (with the
 * strength meter), account details, and the devices the user is signed in on (database sessions).
 */
new class extends Component
{
    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    #[Computed]
    public function devices(): \Illuminate\Support\Collection
    {
        return UserSessions::for(auth()->user())->all();
    }

    /** A password change signs out other devices, so refresh the list. */
    #[On('password-updated')]
    public function refreshDevices(): void
    {
        unset($this->devices);
    }

    public function signOutDevice(string $sessionId): void
    {
        $device = $this->devices->firstWhere('id', $sessionId);

        if (! $device || $device['current'] || ! UserSessions::for(auth()->user())->signOut($sessionId)) {
            $this->dispatch('toast', message: 'That device is already signed out.', type: 'error');
            unset($this->devices);

            return;
        }

        ActivityLog::record('account.device_signed_out', "Signed out {$device['browser']} on {$device['platform']} from My Account.", user: auth()->user());

        unset($this->devices);
        $this->dispatch('toast', message: "Signed out {$device['browser']} on {$device['platform']}.", type: 'success');
    }

    public function signOutOtherDevices(): void
    {
        $count = UserSessions::for(auth()->user())->signOutOthers();

        ActivityLog::record('account.devices_signed_out', 'Signed out all other devices from My Account.', user: auth()->user());

        unset($this->devices);
        $this->dispatch('toast', message: $count > 0 ? 'Signed out of all other devices. This is now your only active session.' : 'You were not signed in anywhere else.', type: 'success');
    }
};
?>

@php
$user = auth()->user();
$partner = in_array($user->practice?->source_system, ['cch', 'talkehr'], true)
    ? ($user->practice->source_system === 'cch' ? 'CareCloud' : 'talkEHR')
    : null;
$others = $this->devices->where('current', false)->count();
@endphp

<section id="security" data-account-section aria-labelledby="h-security"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <h2 id="h-security" class="text-lg font-semibold text-[#12304f]">Sign-in &amp; Security</h2>
    <p class="text-sm text-[#5d6e7f] mt-0.5">Member since {{ $user->created_at?->format('M j, Y') ?? '—' }}</p>

    @if($user->must_change_password)
    <div data-temporary-password role="status" class="mt-4 rounded-xl border border-[#f3d18a] bg-[#fff8e6] px-4 py-3 text-sm text-[#7a5200]">
        <strong class="font-bold">Choose your own password.</strong>
        You're using the temporary password from your welcome email. Enter it as your current password below, then choose a new one.
    </div>
    @endif

    <h3 class="mt-5 text-base font-semibold text-[#173045]">Sign-in</h3>

    <div class="py-4.5">
        <h4 class="text-sm font-semibold text-[#173045]">Sign-in method</h4>
        <p class="mt-0.5 text-[13px] text-[#5d6e7f]">{{ $partner ? "Email and password, or {$partner} sign-in" : 'Email and password' }}</p>
        @if($user->isAdmin())
        <p class="mt-1 text-xs text-[#5d6e7f]">To change your name or email, edit your user in <a href="{{ route('admin.users.edit', $user) }}" class="font-semibold text-[#087fa9] hover:underline" wire:navigate>Admin › Users</a>.</p>
        @endif
    </div>

    {{-- Password: the form opens from "Change password" (open straight away for a temporary password). --}}
    <div class="border-t border-[#eef2f6]" x-data="{ open: @js((bool) $user->must_change_password) }" data-password-row>
        <div class="flex flex-wrap items-center justify-between gap-3 py-4.5">
            <div class="flex-1 min-w-[16rem]">
                <h4 class="text-sm font-semibold text-[#173045]">Password</h4>
                <p class="mt-0.5 text-[13px] text-[#5d6e7f]">At least 12 characters, with upper and lower case letters, a number and a symbol.</p>
            </div>
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="pw-panel" data-password-toggle
                class="inline-flex h-10 items-center rounded-lg border border-[#cbd5e1] bg-white px-4 text-[13px] font-semibold text-[#173045] hover:bg-[#f4f7fb] transition-colors"
                x-text="open ? 'Close' : 'Change password'">Change password</button>
        </div>
        <div id="pw-panel" x-show="open" x-cloak class="max-w-3xl pb-4.5">
            <livewire:auth.change-password-form />
        </div>
    </div>

    <livewire:account.two-factor />

    {{-- Where you're signed in --}}
    <div class="mt-2 pt-6 border-t border-[#e3e8ef]" data-signed-in-devices>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex-1 min-w-[16rem]">
                <h3 class="text-base font-semibold text-[#173045]">Where you're signed in</h3>
                <p class="mt-1 text-[13px] text-[#5d6e7f]">Devices currently signed in to your Empower account. If you don't recognize one, sign it out and change your password.</p>
            </div>
            @if($others > 0)
            <button type="button" wire:click="signOutOtherDevices" data-sign-out-others
                wire:confirm="Sign out of every other device? You'll stay signed in here."
                class="inline-flex h-10 items-center rounded-lg border border-[#cbd5e1] bg-white px-4 text-[13px] font-semibold text-[#c62828] hover:bg-[#fff5f5] transition-colors"
                wire:loading.attr="disabled" wire:target="signOutOtherDevices">
                Sign out of all other devices
            </button>
            @endif
        </div>

        @if(! UserSessions::available())
        <p class="mt-4 text-sm text-[#5d6e7f]">The device list isn't available on this server yet.</p>
        @else
        <ul aria-label="Signed-in devices" class="mt-4 overflow-hidden rounded-xl border border-[#e3e8ef] divide-y divide-[#eef2f6]">
            @forelse($this->devices as $device)
            @php
            $method = UserSessions::signInMethodLabel($device['sign_in_method']);
            @endphp
            <li class="flex flex-wrap items-center justify-between gap-3 p-4" wire:key="device-{{ $device['id'] }}" data-device @if($device['current']) data-current-device @endif>
                <div class="flex items-start gap-3 min-w-0">
                    <span class="flex h-9.5 w-9.5 shrink-0 items-center justify-center rounded-lg bg-[#f1f5f9] text-[#334155]" aria-hidden="true">
                        @if($device['mobile'])
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>
                        @else
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        @endif
                    </span>
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-[#173045]">
                            {{ $device['browser'] }} on {{ $device['platform'] }}
                            @if($device['current'])
                            <span class="rounded-full bg-[#e7f5ee] px-2 py-px text-xs font-semibold text-[#146c43]">This device</span>
                            @endif
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[13px] text-[#334155]">
                            @if($method)
                            <span class="rounded-full px-2 py-px text-xs font-semibold {{ $device['sign_in_method'] === 'password' ? 'bg-[#eef2f6] text-[#334155]' : 'bg-[#e8f1fa] text-[#145a93]' }}" data-sign-in-method>{{ $method }}</span>
                            <span class="text-[#cbd5e1]" aria-hidden="true">•</span>
                            @endif
                            @if($device['current'])
                            <span class="font-medium text-[#146c43]">Active now</span>
                            @else
                            <span class="text-[#5d6e7f]">Last active {{ $device['last_active']->diffForHumans() }}</span>
                            @endif
                        </p>
                        <p class="mt-0.5 text-xs text-[#5d6e7f]">IP {{ $device['ip'] ?? 'unknown' }}</p>
                    </div>
                </div>
                @if($device['current'])
                <span class="text-xs text-[#5d6e7f]">Current session</span>
                @else
                <button type="button" wire:click="signOutDevice(@js($device['id']))"
                    class="inline-flex h-9 items-center rounded-lg border border-[#cbd5e1] bg-white px-3.5 text-xs font-semibold text-[#173045] hover:bg-[#f4f7fb] transition-colors"
                    wire:loading.attr="disabled" wire:target="signOutDevice">
                    Sign out
                </button>
                @endif
            </li>
            @empty
            <li class="p-4 text-sm text-[#5d6e7f]">No devices to show.</li>
            @endforelse
        </ul>
        @endif
    </div>
</section>
