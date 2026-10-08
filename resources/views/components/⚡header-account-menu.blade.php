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
            <button @click="open = !open" aria-label="Account menu" class="flex items-center gap-2 whitespace-nowrap rounded-lg border border-[#9ed3e9] bg-white px-3 sm:px-4 py-2 text-sm font-medium text-[#087fa9] hover:bg-[#eef8fd] transition-colors">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <span class="hidden sm:inline max-w-[10rem] truncate">{{ auth()->user()->name ?: 'Account' }}</span>
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div x-show="open" @click.outside="open = false" x-transition
                class="absolute right-0 mt-2 w-48 rounded-lg bg-white shadow-lg ring-1 ring-black/5 py-1 z-50">
                <a href="{{ route('home') }}#pricing" class="block px-4 py-2 text-sm text-empower-text hover:bg-page">Back to Packages</a>
                <a href="{{ route('password.edit') }}" wire:navigate class="block px-4 py-2 text-sm text-empower-text hover:bg-page">Change Password</a>
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" wire:navigate class="block px-4 py-2 text-sm text-empower-text hover:bg-page">Admin Panel</a>
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
