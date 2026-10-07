@props(['title', 'onHomePage' => false, 'active' => null, 'footerClass' => 'py-6'])
@php
$homeHref = $onHomePage ? '#home' : route('home');
$sectionPrefix = $onHomePage ? '' : route('home');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('images/favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="bg-[#f2f8fd] text-[#173a59] antialiased font-sans">

    {{-- Sticky Nav --}}
    <nav x-data="{ open: false }"
        class="sticky top-0 z-50 bg-white/96 backdrop-blur border-b border-[#d4e5f1] shadow-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <span class="inline-flex items-center rounded-lg bg-white px-2.5 py-1.5">
                        <img src="{{ asset('images/logo.webp') }}" alt="Empower" class="h-[28px] sm:h-[45px] w-auto"
                            onerror="this.parentElement.innerHTML='<span class=\'font-bold text-[#0e3a61] text-sm\'>EMPOWER</span>'">
                    </span>
                    <span
                        class="hidden sm:block text-[0.6rem] font-extrabold tracking-widest uppercase text-[#5c778d]">Marketplace</span>
                </a>

                <div class="hidden md:flex items-center gap-7">
                    <a href="{{ $homeHref }}"
                        class="text-sm font-medium {{ $active === 'home' ? 'text-[#0e3a61] hover:text-[#0b9ed0]' : 'text-[#5c778d] hover:text-[#0e3a61]' }} transition-colors">Home</a>
                    <a href="{{ $sectionPrefix }}#services"
                        class="text-sm font-medium text-[#5c778d] hover:text-[#0e3a61] transition-colors">Services</a>
                    <a href="{{ $sectionPrefix }}#process"
                        class="text-sm font-medium text-[#5c778d] hover:text-[#0e3a61] transition-colors">Process</a>
                    <a href="{{ $sectionPrefix }}#pricing"
                        class="text-sm font-medium text-[#5c778d] hover:text-[#0e3a61] transition-colors">Pricing</a>
                    <a href="{{ $sectionPrefix }}#contact"
                        class="text-sm font-medium text-[#5c778d] hover:text-[#0e3a61] transition-colors">Contact</a>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button"
                        class="inline-flex md:hidden items-center justify-center rounded-lg border border-[#9ed3e9] bg-white p-2 text-[#087fa9] hover:bg-[#eef8fd] transition-colors"
                        x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-controls="mobile-menu"
                        aria-label="Toggle navigation menu">
                        <svg x-show="!open" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg x-show="open" x-cloak class="h-5 w-5" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    @auth
                    <a href="{{ route('portal') }}"
                        class="rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors">My
                        Portal</a>
                    @else
                    <a href="{{ route('login') }}"
                        class="text-sm font-medium text-[#5c778d] hover:text-[#0e3a61] transition-colors">Log in</a>
                    <a href="{{ $sectionPrefix }}#pricing"
                        class="rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors">Get
                        Started</a>
                    @endauth
                </div>
            </div>

            <div id="mobile-menu" x-show="open" x-transition x-cloak class="md:hidden border-t border-[#d4e5f1] py-3">
                <div class="flex flex-col gap-1">
                    <a href="{{ $homeHref }}" x-on:click="open = false"
                        class="rounded-lg px-3 py-2 text-sm font-medium {{ $active === 'home' ? 'text-[#0e3a61]' : 'text-[#5c778d]' }} hover:bg-[#eef8fd] hover:text-[#0e3a61] transition-colors">Home</a>
                    <a href="{{ $sectionPrefix }}#services" x-on:click="open = false"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-[#5c778d] hover:bg-[#eef8fd] hover:text-[#0e3a61] transition-colors">Services</a>
                    <a href="{{ $sectionPrefix }}#process" x-on:click="open = false"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-[#5c778d] hover:bg-[#eef8fd] hover:text-[#0e3a61] transition-colors">Process</a>
                    <a href="{{ $sectionPrefix }}#pricing" x-on:click="open = false"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-[#5c778d] hover:bg-[#eef8fd] hover:text-[#0e3a61] transition-colors">Pricing</a>
                    <a href="{{ $sectionPrefix }}#contact" x-on:click="open = false"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-[#5c778d] hover:bg-[#eef8fd] hover:text-[#0e3a61] transition-colors">Contact</a>
                </div>
            </div>
        </div>
    </nav>

    <main>
        {{ $slot }}
    </main>

    <x-site-footer :footer-class="$footerClass" :contact-link="true" />

    <livewire:contact-dialog />

    @livewireScripts
</body>

</html>