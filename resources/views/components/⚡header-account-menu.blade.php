<?php

use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * Guest checkout (see pay() in ⚡portal.blade.php) can log a user in mid-request without any
     * page navigation — this component lives in the layout, outside <livewire:portal />, so it
     * never learns about that without an explicit nudge to re-render.
     */
    #[On('user-logged-in')]
    public function refreshAuthState(): void
    {
        //
    }
};
?>

<div class="flex items-center gap-3">
    @auth
        <div class="relative" x-data="{ open: false }">
            @php
            $menuName = auth()->user()->name ?: 'Account';
            $menuParts = preg_split('/\s+/', trim($menuName)) ?: [];
            $menuInitials = Str::upper(Str::substr($menuParts[0] ?? '', 0, 1).(count($menuParts) > 1 ? Str::substr(end($menuParts), 0, 1) : ''));
            @endphp
            <button @click="open = !open" aria-label="Account menu for {{ $menuName }}" aria-haspopup="menu" x-bind:aria-expanded="open"
                class="flex h-11 items-center gap-2 whitespace-nowrap rounded-[9px] border border-[#cbd5e1] bg-white px-2 sm:px-3 text-[13px] font-semibold text-[#0f2a44] hover:bg-page transition-colors">
                <span aria-hidden="true" class="flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full bg-[#12385c] text-[11px] font-bold text-white">{{ $menuInitials }}</span>
                <span class="hidden sm:inline max-w-[10rem] truncate">{{ $menuName }}</span>
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </button>

            <div x-show="open" @click.outside="open = false" x-transition
                class="absolute right-0 mt-2 w-48 rounded-lg bg-white shadow-lg ring-1 ring-black/5 py-1 z-50">
                <a href="{{ route('account') }}" class="block px-4 py-2 text-sm font-semibold text-empower-text hover:bg-page" data-account-link>My Account</a>
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" wire:navigate class="block px-4 py-2 text-sm text-empower-text hover:bg-page">Admin Panel</a>
                @else
                    <a href="{{ route('home') }}#pricing" class="block px-4 py-2 text-sm text-empower-text hover:bg-page" data-upgrade-package-link>Upgrade Your Package</a>
                @endif
                <div class="my-1 border-t border-empower-border"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-page">Log out</button>
                </form>
            </div>
        </div>
    @else
        <a href="{{ route('home') }}#pricing" class="rounded-lg border border-[#9ed3e9] bg-white px-4 py-2 text-sm font-medium text-[#087fa9] hover:bg-[#eef8fd] transition-colors">Back to Packages</a>
        <a href="{{ route('login') }}" class="rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors">Login</a>
    @endauth
</div>
