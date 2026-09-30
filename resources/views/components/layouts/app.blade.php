<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}: Empower Marketplace</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('images/favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
@php
$isAdmin = request()->routeIs('admin.*');
$containerClass = $isAdmin ? 'max-w-[112rem]' : 'max-w-7xl';
@endphp

<body class="min-h-screen flex flex-col bg-page font-sans antialiased @unless($isAdmin) client-portal @endunless"
    @if($isAdmin) x-data="{ adminSidebarOpen: false }" @endif>

    <nav class="sticky top-0 z-50 bg-white/96 backdrop-blur border-b border-empower-border shadow-sm">
        <div class="mx-auto {{ $containerClass }} px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center gap-3">
                    @if($isAdmin)
                    <button type="button" x-on:click="adminSidebarOpen = true" aria-label="Open menu"
                        class="lg:hidden inline-flex items-center justify-center rounded-lg border border-empower-border p-2 text-empower-muted hover:bg-page transition-colors">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    @endif
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                        <span class="inline-flex items-center rounded-lg bg-white px-2.5 py-1.5">
                            <img src="{{ asset('images/logo.webp') }}" alt="Empower" class="h-[28px] sm:h-[45px] w-auto"
                                onerror="this.parentElement.innerHTML='<span class=\'font-bold text-navy text-sm\'>EMPOWER</span>'">
                        </span>
                        <span
                            class="hidden sm:block text-[0.6rem] font-extrabold tracking-widest uppercase text-empower-muted">Marketplace</span>
                    </a>
                </div>

                <livewire:header-account-menu />
            </div>
        </div>
    </nav>

    @if($isAdmin)
    {{-- Mobile nav drawer --}}
    <div x-show="adminSidebarOpen" x-cloak class="lg:hidden fixed inset-0 z-50 flex" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-black/40" x-on:click="adminSidebarOpen = false"></div>
        <div class="relative w-64 max-w-[80vw] bg-white h-full overflow-y-auto p-4 shadow-xl"
            x-on:click.outside="adminSidebarOpen = false">
            <div class="flex items-center justify-between mb-4">
                <span class="text-xs font-extrabold uppercase tracking-widest text-empower-muted">Admin Menu</span>
                <button type="button" x-on:click="adminSidebarOpen = false" aria-label="Close menu"
                    class="text-empower-muted hover:text-navy transition-colors">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            @include('admin._nav', ['mobile' => true])
        </div>
    </div>

    <div class="flex-1 flex mx-auto w-full {{ $containerClass }}">
        <aside
            class="hidden lg:block w-56 shrink-0 border-r border-empower-border px-3 py-6 bg-white border border-empower-border shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
            @include('admin._nav')
        </aside>
        <main class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">
            {{ $slot }}
        </main>
    </div>
    @else
    <main class="mx-auto w-full {{ $containerClass }} flex-1 px-4 sm:px-6 lg:px-8 py-6">
        {{ $slot }}
    </main>
    @endif

    <x-site-footer footer-class="py-4" />

    @if($isAdmin)
    {{-- Global admin toast — any admin Livewire component can trigger this from anywhere with
    $this->dispatch('toast', message: '...', type: 'success' | 'error'). An action that
    redirects (e.g. after a wire:navigate) instead flashes session('toast'/'toast_type'),
    which this same component fires on load — a live dispatch can't survive the page swap. --}}
    <div x-data="{ show: false, message: '', type: 'success', hideTimer: null }" @if(session('toast')) x-init="
            message = @js(session('toast'));
            type = @js(session('toast_type', 'success'));
            show = true;
            clearTimeout(hideTimer);
            hideTimer = setTimeout(() => show = false, 3000)
        " @endif x-on:toast.window="
            message = $event.detail.message;
            type = $event.detail.type ?? 'success';
            show = true;
            clearTimeout(hideTimer);
            hideTimer = setTimeout(() => show = false, 3000)
        " x-show="show" x-transition x-cloak class="fixed top-6 right-6 z-[100]">
        <div x-on:click="show = false; clearTimeout(hideTimer)"
            class="flex items-center gap-2 rounded-xl pl-4 pr-5 py-3 shadow-[0_18px_50px_rgba(10,32,55,0.25)] text-white cursor-pointer"
            x-bind:class="type === 'error' ? 'bg-red-600' : 'bg-green-600'">
            <span class="font-bold" x-text="type === 'error' ? '&#9888;' : '&#9432;'"
                x-bind:class="type === 'error' ? 'text-red-200' : 'text-green-200'"></span>
            <span class="text-sm font-semibold" x-text="message"></span>
        </div>
    </div>
    @endif

    @livewireScripts
</body>

</html>