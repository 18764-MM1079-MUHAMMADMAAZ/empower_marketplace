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
        $this->dispatch('toast', message: $count > 0 ? "Signed out {$count} other ".Str::plural('device', $count).'.' : 'You were not signed in anywhere else.', type: 'success');
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
    <h2 id="h-security" class="text-lg font-semibold text-[#12304f]">Sign-in &amp; security</h2>
    <p class="text-sm text-[#5d6e7f] mb-5">Manage how you sign in to the Empower portal.</p>

    @if($user->must_change_password)
    <div data-temporary-password role="status" class="mb-5 rounded-xl border border-[#f3d18a] bg-[#fff8e6] px-4 py-3 text-sm text-[#7a5200]">
        <strong class="font-bold">Choose your own password.</strong>
        You're using the temporary password from your welcome email. Enter it as your current password below, then choose a new one.
    </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] p-4 sm:p-5">
            <h3 class="text-base font-bold text-[#12304f] mb-3">Change password</h3>
            <livewire:auth.change-password-form />
        </div>

        <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] p-4 sm:p-5">
            <h3 class="text-base font-bold text-[#12304f] mb-3">Account</h3>
            <dl class="divide-y divide-[#eef2f6] text-sm">
                <div class="flex justify-between gap-3 py-2.5">
                    <dt class="text-[#5d6e7f]">Name</dt>
                    <dd class="font-semibold text-[#173045] text-right">{{ $user->name }}</dd>
                </div>
                <div class="flex justify-between gap-3 py-2.5">
                    <dt class="text-[#5d6e7f]">Email</dt>
                    <dd class="font-semibold text-[#173045] text-right break-all">{{ $user->email }}</dd>
                </div>
                <div class="flex justify-between gap-3 py-2.5">
                    <dt class="text-[#5d6e7f]">Role</dt>
                    <dd class="font-semibold text-[#173045] text-right">{{ $user->isAdmin() ? 'Administrator' : ($user->is_practice_admin ? 'Practice administrator' : 'Practice user') }}</dd>
                </div>
                <div class="flex justify-between gap-3 py-2.5">
                    <dt class="text-[#5d6e7f]">Sign-in method</dt>
                    <dd class="font-semibold text-[#173045] text-right">
                        {{ $partner ? "Email and password, or {$partner} sign-in" : 'Email and password' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3 py-2.5">
                    <dt class="text-[#5d6e7f]">Two-step verification</dt>
                    <dd class="font-semibold text-right {{ $user->hasEnabledTwoFactorAuthentication() ? 'text-[#0f7a4f]' : 'text-[#173045]' }}">{{ $user->hasEnabledTwoFactorAuthentication() ? 'On' : 'Off' }}</dd>
                </div>
                <div class="flex justify-between gap-3 py-2.5">
                    <dt class="text-[#5d6e7f]">Member since</dt>
                    <dd class="font-semibold text-[#173045] text-right">{{ $user->created_at?->format('M j, Y') ?? '—' }}</dd>
                </div>
            </dl>
            @if($user->isAdmin())
            <p class="mt-3 text-xs text-[#5d6e7f]">To change your name or email, edit your user in <a href="{{ route('admin.users.edit', $user) }}" class="font-semibold text-[#087fa9] hover:underline" wire:navigate>Admin › Users</a>.</p>
            @endif
        </div>

        <livewire:account.two-factor />

        {{-- Signed-in devices --}}
        <div class="md:col-span-2 rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] p-4 sm:p-5" data-signed-in-devices>
            <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                <div>
                    <h3 class="text-base font-bold text-[#12304f]">Signed-in devices</h3>
                    <p class="text-xs text-[#5d6e7f]">Places your account is signed in now. Sign out any you don't recognise, then change your password.</p>
                </div>
                @if($others > 0)
                <button type="button" wire:click="signOutOtherDevices" data-sign-out-others
                    wire:confirm="Sign out of every other device? You'll stay signed in here."
                    class="rounded-lg border border-[#d4e5f1] bg-white px-3 py-1.5 text-xs font-bold text-[#b42318] hover:bg-[#fff5f5] transition-colors"
                    wire:loading.attr="disabled" wire:target="signOutOtherDevices">
                    Sign out all other devices
                </button>
                @endif
            </div>

            @if(! UserSessions::available())
            <p class="text-sm text-[#5d6e7f]">The device list isn't available on this server yet.</p>
            @else
            <ul class="divide-y divide-[#eef2f6]">
                @forelse($this->devices as $device)
                <li class="flex items-center gap-3 py-3" wire:key="device-{{ $device['id'] }}" data-device @if($device['current']) data-current-device @endif>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white border border-[#dbe4ee] text-[#087fa9]" aria-hidden="true">
                        @if($device['mobile'])
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>
                        @else
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-[#173045]">
                            {{ $device['browser'] }} on {{ $device['platform'] }}
                            @if($device['current'])
                            <span class="ml-1 rounded-full bg-[#eef8f3] border border-[#bfe3d2] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#0f7a4f]">This device</span>
                            @endif
                        </p>
                        <p class="text-xs text-[#5d6e7f]">
                            {{ $device['ip'] ?? 'Unknown IP address' }} ·
                            {{ $device['current'] ? 'Active now' : 'Last active '.$device['last_active']->diffForHumans() }}
                        </p>
                    </div>
                    @unless($device['current'])
                    <button type="button" wire:click="signOutDevice(@js($device['id']))"
                        class="shrink-0 rounded-lg border border-[#d4e5f1] bg-white px-3 py-1.5 text-xs font-bold text-[#173a59] hover:bg-[#eef8fd] transition-colors"
                        wire:loading.attr="disabled" wire:target="signOutDevice">
                        Sign out
                    </button>
                    @endunless
                </li>
                @empty
                <li class="py-3 text-sm text-[#5d6e7f]">No devices to show.</li>
                @endforelse
            </ul>
            @endif
        </div>
    </div>
</section>
