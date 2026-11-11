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
// The portal page's Step 1 grid gives its right-hand sidebar a fixed 320px column — on the
// standard max-w-7xl container that leaves the main content (forms, cards) visibly squeezed, so
// this route alone gets a wider container to compensate; every other client page is unaffected.
$isPortal = request()->routeIs('portal');
$containerClass = $isAdmin ? 'max-w-full' : ($isPortal ? 'max-w-[96rem]' : 'max-w-7xl');
@endphp

<body class="min-h-screen flex flex-col bg-page font-sans antialiased @unless($isAdmin) client-portal @endunless"
    @if($isAdmin) data-admin-page="{{ request()->route()?->getName() }}" @endif
    @if($isAdmin) x-data="{ adminSidebarOpen: false }" @endif>

    <nav class="sticky top-0 z-50 bg-white/96 backdrop-blur border-b border-empower-border shadow-sm">
        <div class="mx-auto {{ $containerClass }} px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center gap-2 sm:gap-3">
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
                            class="hidden md:block text-[0.6rem] font-extrabold tracking-widest uppercase text-empower-muted">Marketplace</span>
                    </a>
                </div>

                <div class="flex items-center gap-3">
                    @auth
                    @if(! $isAdmin && ! auth()->user()->isAdmin())
                    {{-- Empower LMS single sign-on (Marketplace → Moodle via OpenID Connect). Paid clients
                    get the filled button; everyone else sees it greyed out, linking to the packages. --}}
                    @if(auth()->user()->hasLmsAccess())
                    <a href="{{ route('lms.launch') }}" target="_blank" rel="noopener" data-lms-button
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-navy px-3 py-1.5 text-xs font-bold text-white hover:opacity-90 transition-opacity">
                        Access LMS
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                    </a>
                    @else
                    <a href="{{ route('home') }}#pricing" title="Buy a package to unlock training" aria-label="Access LMS (buy a package to unlock training)" data-lms-button-locked
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-empower-border bg-page px-3 py-1.5 text-xs font-bold text-empower-muted opacity-70 hover:opacity-100 transition-opacity">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                        Access LMS
                    </a>
                    @endif
                    @endif
                    @endauth
                    @if($isAdmin)
                    <button type="button" data-tour-button onclick="window.startAdminTour?.()" aria-label="Take a tour of this page"
                        class="tour-attention inline-flex h-9 w-9 md:h-auto md:w-auto items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-empower-border bg-white md:px-3 md:py-1.5 text-xs font-bold text-navy hover:bg-page transition-colors">
                        <svg class="tour-bolt h-3.5 w-3.5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        <span class="hidden md:inline">Take a tour</span>
                    </button>
                    @endif
                    @auth
                    <div data-tour="bell"><livewire:notification-bell /></div>
                    @endauth
                    <div data-tour="account"><livewire:header-account-menu /></div>
                </div>
            </div>
        </div>
    </nav>

    @if($isAdmin)
    {{-- Mobile nav drawer --}}
    <div x-show="adminSidebarOpen" x-cloak class="lg:hidden fixed inset-0 z-50 flex" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" x-on:click="adminSidebarOpen = false"></div>
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
        <aside data-tour="sidebar"
            class="hidden lg:block w-56 shrink-0 border-r border-empower-border px-3 py-6 bg-white border border-empower-border shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
            @include('admin._nav')
        </aside>
        <main class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">
            @unless(request()->routeIs('admin.dashboard') || ($inlineHeading ?? false))
            <h1 class="text-2xl font-bold text-navy mb-4">{{ $title }}</h1>
            @endunless
            {{ $slot }}
        </main>
    </div>
    @else
    <main class="mx-auto w-full {{ $containerClass }} flex-1 px-4 sm:px-6 lg:px-8 py-6">
        {{ $slot }}
    </main>
    @endif

    <x-site-footer footer-class="py-4" :container-class="$containerClass" />

    {{-- Persistent offline banner — ported from the prototype's #netBanner (fixed bottom-center,
    same copy/colors), backed by the `connectivity` Alpine store in resources/js/app.js. Unlike a
    one-off toast, this stays visible for the whole time the connection is down, on every page. --}}
    <div x-data x-show="!$store.connectivity.online" x-cloak role="status" aria-live="polite"
        class="fixed left-1/2 bottom-[18px] -translate-x-1/2 z-[9000] flex items-start gap-2.5 w-[min(560px,calc(100vw-32px))] px-3.5 py-3 rounded-2xl bg-[#0e1b30] text-[#e8eef5] text-[13.5px] leading-relaxed shadow-[0_16px_40px_rgba(10,32,55,0.35)]">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="flex-shrink-0 mt-0.5 text-[#f3b74f]">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
            <path d="M12 8v5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            <circle cx="12" cy="16" r="1" fill="currentColor" />
        </svg>
        <span><strong class="text-white">You're offline.</strong> Your answers are saved on this device. Uploads, payment and submitting will work again once you reconnect.</span>
    </div>

    <x-toast />

    @livewireScripts
</body>

</html>