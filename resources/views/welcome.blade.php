<x-layouts.marketing title="Proactive Compliance by Empower: Healthcare Compliance Portal" :on-home-page="true">

    {{-- Hero --}}
    <section id="home" class="py-16 lg:py-20"
        style="background: radial-gradient(circle at 84% 18%, rgba(11, 158, 208, 0.36), transparent 34%), radial-gradient(circle at 8% 0%, rgba(34, 153, 221, 0.20), transparent 30%), linear-gradient(115deg, #f5f7fa 0%, #dff1fb 44%, #c7e7f6 100%);">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="w-full">
                <span
                    class="inline-block rounded-full border border-[#a3d5ee] bg-white/75 px-[18px] py-2 text-[13px] font-bold text-[#1c3457] tracking-wide mb-[26px]">
                    Proactive Compliance
                </span>
                <h1 class="text-[36px] lg:text-[52px] font-extrabold text-[#0e1b30] mb-4 leading-[1.05]">
                    Proactive Compliance<br class="hidden sm:block">
                    by Empower
                </h1>
                <p class="w-full max-w-3xl text-[19px] text-[#4a5563] mt-[22px] leading-[1.6]">
                    Empower helps healthcare practices build and maintain a compliance program structured on the
                    seven elements described in OIG guidance, with the policies, training, and ongoing support to
                    stay audit-ready. Support scales from reviewing your current program to a fully custom one.
                </p>
                <div class="flex flex-wrap gap-[14px] mt-[34px]">
                    <a href="#pricing"
                        class="rounded-full bg-[#3a9bd5] px-6 py-3 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors shadow-lg">Explore
                        Packages</a>
                </div>
            </div>
        </div>
    </section>

    {{-- Why now / Who is this for --}}
    <section id="services" class="py-14 lg:py-16 bg-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-14 items-start">
                <div>
                    <span class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5]">Why Now</span>
                    <h2 class="mt-3 text-[34px] font-extrabold text-[#0e1b30] leading-tight">
                        Documentation and coding remain among the most common subjects of payor and regulatory
                        review.
                    </h2>
                    <p class="mt-4 text-[#4a5563] leading-relaxed">
                        Payors and regulators expect a documented, active program: written policies, real training,
                        exclusions screening, a reporting channel, and evidence that someone owns it. Gaps in any one
                        of these can expose a practice to audits, overpayment demands, and penalties.
                    </p>
                </div>

                <div class="rounded-2xl border border-[#dde3ea] bg-[#f9fcff] p-7">
                    <h3 class="font-semibold text-[#1c3457] mb-3">Who is this for?</h3>
                    <p class="text-sm text-[#4a5563] leading-relaxed mb-4">
                        Compliance programs are required by regulation for certain entity types and are expected of
                        all providers under OIG guidance; many payor participation agreements require one.
                    </p>
                    <ul class="space-y-2.5 text-sm text-[#0e1b30]">
                        @foreach([
                        'Practices that bill federal healthcare programs.',
                        'Commonly required in payor participation agreements.',
                        'Expected under OIG guidance; required by regulation for certain entity types.',
                        ] as $point)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#3a9bd5]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $point }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- How your program works: the seven elements --}}
    <section id="how-it-works" class="py-14 lg:py-16 bg-gradient-to-br from-[#162a46] via-[#1c3457] to-[#16638e]">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase text-[#74bfe4]">How Your Program Works</span>
                <h2 class="mt-3 text-3xl font-bold text-white">The seven elements, mapped</h2>
            </div>

            <div class="rounded-2xl border border-white/15 bg-white/5 overflow-hidden">
                <div
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 px-6 py-4 border-b border-white/10">
                    <span class="text-sm font-semibold text-white">Structured on the seven elements described in OIG
                        guidance</span>
                    <span class="text-xs font-semibold text-[#74bfe4] uppercase tracking-wide">Structured on OIG
                        guidance</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-white/10">
                    <div class="p-7">
                        <span class="text-xs font-bold tracking-widest uppercase text-[#74bfe4]">Your Program
                            Documents</span>
                        <ul class="mt-4 space-y-3 text-sm text-white/85">
                            @foreach([
                            ['Written standards & policies', 'tailored to your practice'],
                            ['Compliance oversight structure', 'and designated roles'],
                            ['Training & education', 'across required topics'],
                            ['Reporting channels', 'including a compliance hotline'],
                            ] as [$lead, $rest])
                            <li class="flex items-start gap-2">
                                <svg class="h-4 w-4 mt-0.5 shrink-0 text-[#74bfe4]" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                        d="M5 13l4 4L19 7" />
                                </svg>
                                <span><span class="font-semibold text-white">{{ $lead }}</span> {{ $rest }}</span>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="p-7">
                        <span class="text-xs font-bold tracking-widest uppercase text-[#74bfe4]">Your Practice
                            Operates, With Our Support</span>
                        <ul class="mt-4 space-y-3 text-sm text-white/85">
                            @foreach([
                            ['Ongoing monitoring & auditing', 'of day-to-day operations'],
                            ['Enforcement & discipline', 'through your own policies'],
                            ['Corrective action', 'when a review surfaces a finding'],
                            ] as [$lead, $rest])
                            <li class="flex items-start gap-2">
                                <svg class="h-4 w-4 mt-0.5 shrink-0 text-[#74bfe4]" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                        d="M5 13l4 4L19 7" />
                                </svg>
                                <span><span class="font-semibold text-white">{{ $lead }}</span> {{ $rest }}</span>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Pricing --}}
    <section id="pricing" class="py-14 lg:py-16 bg-white">
        @php
            $formatPrice = fn (?float $price) => number_format($price ?? 0, ((int) ($price ?? 0)) == ($price ?? 0) ? 0 : 2);

            $featureGroups = [
                'essential' => [
                    'provides' => ['sublabel' => null, 'items' => ['Exclusions Screening', 'Compliance Hotline']],
                    'second' => ['label' => 'You Provide (We Review & Update)', 'items' => ['Compliance & Ethics Program', 'HIPAA Privacy & Security Policies', 'Trainings†']],
                ],
                'professional' => [
                    'provides' => ['sublabel' => 'Includes Essential, plus', 'items' => ['Exclusions Screening', 'Compliance Hotline']],
                    'second' => ['label' => 'Empower Reviews, Updates, or Creates', 'items' => ['Compliance & Ethics Program', 'HIPAA Privacy & Security', 'Trainings']],
                ],
                'advanced' => [
                    'provides' => ['sublabel' => 'Includes Professional, plus', 'items' => ['Coding & Documentation Mini Audit‡ (10 encounters/provider)', 'Security Risk Assessment (SRA)', 'Guidance of Compliance Structure', 'Periodic Compliance Meeting']],
                    'second' => ['label' => 'You Provide (We Review & Update)', 'items' => ['Your employee manual']],
                ],
                'complete' => [
                    'provides' => ['sublabel' => 'Includes Advanced, plus', 'items' => ['Customized Compliance Program', 'Compliance Officer Needs: Co-Sourced, Fractional, or Outsourced']],
                    'second' => null,
                ],
            ];
        @endphp
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" x-data="{ cycle: 'annual' }">
            <div class="text-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5]">Pricing</span>
                <h2 class="mt-3 text-[34px] font-extrabold text-[#0e1b30]">Choose Your Compliance Package</h2>
                <p class="mt-4 text-[#4a5563] max-w-2xl mx-auto leading-relaxed">
                    Every package is billed per billable provider, per year (or monthly), and includes annual
                    renewal.
                </p>
            </div>

            <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="inline-flex self-start gap-1 rounded-full border border-[#dde3ea] bg-[#eef1f5] p-1">
                    <button type="button" @click="cycle = 'monthly'"
                        :class="cycle === 'monthly' ? 'bg-white text-[#1c3457] shadow-sm' : 'text-[#4a5563] hover:text-[#1c3457]'"
                        class="rounded-full px-[22px] py-2.5 text-sm font-bold transition-colors">Billed monthly</button>
                    <button type="button" @click="cycle = 'annual'"
                        :class="cycle === 'annual' ? 'bg-white text-[#1c3457] shadow-sm' : 'text-[#4a5563] hover:text-[#1c3457]'"
                        class="rounded-full px-[22px] py-2.5 text-sm font-bold transition-colors">Billed annually
                        <span class="ml-1.5 text-xs font-extrabold text-[#1f9d6b]">Save ~16%</span></button>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-[#4a5563]">Not sure what package is right?</span>
                    <a href="{{ route('contact') }}"
                        class="inline-block whitespace-nowrap rounded-full border border-[#9ed3e9] bg-white px-4 py-2 text-sm font-semibold text-[#2b82b8] hover:bg-[#eaf5fb] transition-colors">Contact
                        us</a>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 items-stretch">

                {{-- Essential --}}
                <div class="relative rounded-2xl border border-[#dde3ea] bg-[#f5f7fa] p-7 flex flex-col">
                    <div class="absolute top-4 right-4" x-data="{ open: false }">
                        <button type="button" @mouseenter="open = true" @mouseleave="open = false"
                            @click="open = !open"
                            class="flex h-6 w-6 items-center justify-center rounded-full text-[#4a5563] hover:text-[#2b82b8] hover:bg-white transition-colors"
                            aria-label="Package details">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                        </button>
                        <div x-show="open" x-cloak x-transition
                            class="absolute right-0 top-7 z-20 w-64 rounded-xl border border-[#dde3ea] bg-white p-3 text-xs leading-relaxed text-[#4a5563] shadow-lg whitespace-pre-line">
                            {{ $packages['essential']->description ?? '' }}</div>
                    </div>
                    <div class="flex items-center gap-1.5 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-[#4a5563]">Essential</span>
                    </div>
                    <div class="flex items-baseline gap-1">
                        <span class="text-4xl font-extrabold text-[#1c3457]" x-show="cycle === 'monthly'">${{
                            $formatPrice($packages['essential']->monthly_price ?? null) }}</span>
                        <span class="text-4xl font-extrabold text-[#1c3457]" x-show="cycle === 'annual'" x-cloak>${{
                            $formatPrice($packages['essential']->annual_price ?? null) }}</span>
                        <span class="text-sm text-[#4a5563]" x-text="cycle === 'monthly' ? '/month' : '/year'"></span>
                    </div>
                    <div class="text-sm text-[#4a5563] mt-1 mb-6">per billable provider</div>
                    @php $groups = $featureGroups['essential']; @endphp
                    <p class="text-xs font-bold tracking-widest uppercase text-[#4a5563] mb-2">Empower Provides</p>
                    @if($groups['provides']['sublabel'])
                    <p class="text-xs text-[#4a5563] mb-2">{{ $groups['provides']['sublabel'] }}</p>
                    @endif
                    <ul class="space-y-2.5 text-sm text-[#0e1b30] {{ $groups['second'] ? 'mb-4' : 'mb-8 grow' }}">
                        @foreach($groups['provides']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#3a9bd5]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @if($groups['second'])
                    <p class="text-xs font-bold tracking-widest uppercase text-[#4a5563] mb-2">{{ $groups['second']['label'] }}</p>
                    <ul class="space-y-2.5 text-sm text-[#0e1b30] mb-8 grow">
                        @foreach($groups['second']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#3a9bd5]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @endif
                    <a :href="`{{ route('portal', ['package' => 'essential']) }}&billing_cycle=${cycle}`"
                        class="block w-full rounded-full bg-[#1c3457] py-3 text-center text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Select
                        Package</a>
                </div>

                {{-- Professional --}}
                <div class="relative rounded-2xl border border-[#dde3ea] bg-[#f5f7fa] p-7 flex flex-col">
                    <div class="absolute top-4 right-4" x-data="{ open: false }">
                        <button type="button" @mouseenter="open = true" @mouseleave="open = false"
                            @click="open = !open"
                            class="flex h-6 w-6 items-center justify-center rounded-full text-[#4a5563] hover:text-[#2b82b8] hover:bg-white transition-colors"
                            aria-label="Package details">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                        </button>
                        <div x-show="open" x-cloak x-transition
                            class="absolute right-0 top-7 z-20 w-64 rounded-xl border border-[#dde3ea] bg-white p-3 text-xs leading-relaxed text-[#4a5563] shadow-lg whitespace-pre-line">
                            {{ $packages['professional']->description ?? '' }}</div>
                    </div>
                    <div class="flex items-center gap-1.5 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-[#4a5563]">Professional</span>
                    </div>
                    <div class="flex items-baseline gap-1">
                        <span class="text-4xl font-extrabold text-[#1c3457]" x-show="cycle === 'monthly'">${{
                            $formatPrice($packages['professional']->monthly_price ?? null) }}</span>
                        <span class="text-4xl font-extrabold text-[#1c3457]" x-show="cycle === 'annual'" x-cloak>${{
                            $formatPrice($packages['professional']->annual_price ?? null) }}</span>
                        <span class="text-sm text-[#4a5563]" x-text="cycle === 'monthly' ? '/month' : '/year'"></span>
                    </div>
                    <div class="text-sm text-[#4a5563] mt-1 mb-6">per billable provider</div>
                    @php $groups = $featureGroups['professional']; @endphp
                    <p class="text-xs font-bold tracking-widest uppercase text-[#4a5563] mb-2">Empower Provides</p>
                    @if($groups['provides']['sublabel'])
                    <p class="text-xs text-[#4a5563] mb-2">{{ $groups['provides']['sublabel'] }}</p>
                    @endif
                    <ul class="space-y-2.5 text-sm text-[#0e1b30] {{ $groups['second'] ? 'mb-4' : 'mb-8 grow' }}">
                        @foreach($groups['provides']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#3a9bd5]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @if($groups['second'])
                    <p class="text-xs font-bold tracking-widest uppercase text-[#4a5563] mb-2">{{ $groups['second']['label'] }}</p>
                    <ul class="space-y-2.5 text-sm text-[#0e1b30] mb-8 grow">
                        @foreach($groups['second']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#3a9bd5]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @endif
                    <a :href="`{{ route('portal', ['package' => 'professional']) }}&billing_cycle=${cycle}`"
                        class="block w-full rounded-full bg-[#1c3457] py-3 text-center text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Select
                        Package</a>
                </div>

                {{-- Advanced (Popular) --}}
                <div class="rounded-2xl border-2 border-[#3a9bd5] bg-[#1c3457] p-7 flex flex-col relative">
                    <div class="absolute -top-3.5 left-1/2 -translate-x-1/2">
                        <span
                            class="rounded-full bg-[#3a9bd5] px-4 py-1 text-xs font-bold text-white shadow">Popular</span>
                    </div>
                    <div class="absolute top-4 right-4" x-data="{ open: false }">
                        <button type="button" @mouseenter="open = true" @mouseleave="open = false"
                            @click="open = !open"
                            class="flex h-6 w-6 items-center justify-center rounded-full text-white/80 hover:text-white hover:bg-white/10 transition-colors"
                            aria-label="Package details">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                        </button>
                        <div x-show="open" x-cloak x-transition
                            class="absolute right-0 top-7 z-20 w-64 rounded-xl border border-[#dde3ea] bg-white p-3 text-xs leading-relaxed text-[#4a5563] shadow-lg whitespace-pre-line">
                            {{ $packages['advanced']->description ?? '' }}</div>
                    </div>
                    <div class="flex items-center gap-1.5 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-[#74bfe4]">Advanced</span>
                    </div>
                    <div class="flex items-baseline gap-1">
                        <span class="text-4xl font-extrabold text-white" x-show="cycle === 'monthly'">${{
                            $formatPrice($packages['advanced']->monthly_price ?? null) }}</span>
                        <span class="text-4xl font-extrabold text-white" x-show="cycle === 'annual'" x-cloak>${{
                            $formatPrice($packages['advanced']->annual_price ?? null) }}</span>
                        <span class="text-sm text-white/60" x-text="cycle === 'monthly' ? '/month' : '/year'"></span>
                    </div>
                    <div class="text-sm text-white/60 mt-1 mb-6">per billable provider</div>
                    @php $groups = $featureGroups['advanced']; @endphp
                    <p class="text-xs font-bold tracking-widest uppercase text-[#74bfe4] mb-2">Empower Provides</p>
                    @if($groups['provides']['sublabel'])
                    <p class="text-xs text-white/60 mb-2">{{ $groups['provides']['sublabel'] }}</p>
                    @endif
                    <ul class="space-y-2.5 text-sm text-white/85 {{ $groups['second'] ? 'mb-4' : 'mb-8 grow' }}">
                        @foreach($groups['provides']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#74bfe4]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @if($groups['second'])
                    <p class="text-xs font-bold tracking-widest uppercase text-[#74bfe4] mb-2">{{ $groups['second']['label'] }}</p>
                    <ul class="space-y-2.5 text-sm text-white/85 mb-8 grow">
                        @foreach($groups['second']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#74bfe4]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @endif
                    <a :href="`{{ route('portal', ['package' => 'advanced']) }}&billing_cycle=${cycle}`"
                        class="block w-full rounded-full bg-[#3a9bd5] py-3 text-center text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors">Select
                        Package</a>
                </div>

                {{-- Complete --}}
                <div class="relative rounded-2xl border border-[#dde3ea] bg-[#f5f7fa] p-7 flex flex-col">
                    <div class="absolute top-4 right-4" x-data="{ open: false }">
                        <button type="button" @mouseenter="open = true" @mouseleave="open = false"
                            @click="open = !open"
                            class="flex h-6 w-6 items-center justify-center rounded-full text-[#4a5563] hover:text-[#2b82b8] hover:bg-white transition-colors"
                            aria-label="Package details">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                        </button>
                        <div x-show="open" x-cloak x-transition
                            class="absolute right-0 top-7 z-20 w-64 rounded-xl border border-[#dde3ea] bg-white p-3 text-xs leading-relaxed text-[#4a5563] shadow-lg whitespace-pre-line">
                            {{ $packages['complete']->description ?? '' }}</div>
                    </div>
                    <div class="flex items-center gap-1.5 mb-3">
                        <span class="text-xs font-bold tracking-widest uppercase text-[#4a5563]">Complete</span>
                    </div>
                    <div class="text-2xl font-extrabold text-[#1c3457] whitespace-nowrap mb-6">Custom Quote</div>
                    @php $groups = $featureGroups['complete']; @endphp
                    <p class="text-xs font-bold tracking-widest uppercase text-[#4a5563] mb-2">Empower Provides</p>
                    @if($groups['provides']['sublabel'])
                    <p class="text-xs text-[#4a5563] mb-2">{{ $groups['provides']['sublabel'] }}</p>
                    @endif
                    <ul class="space-y-2.5 text-sm text-[#0e1b30] {{ $groups['second'] ? 'mb-4' : 'mb-8 grow' }}">
                        @foreach($groups['provides']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#3a9bd5]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @if($groups['second'])
                    <p class="text-xs font-bold tracking-widest uppercase text-[#4a5563] mb-2">{{ $groups['second']['label'] }}</p>
                    <ul class="space-y-2.5 text-sm text-[#0e1b30] mb-8 grow">
                        @foreach($groups['second']['items'] as $f)
                        <li class="flex items-start gap-2"><svg class="h-4 w-4 mt-0.5 shrink-0 text-[#3a9bd5]"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M5 13l4 4L19 7" />
                            </svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                    @endif
                    <a href="{{ route('contact') }}?package=complete"
                        class="block w-full rounded-full bg-[#1c3457] py-3 text-center text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Request
                        a Quote</a>
                </div>

            </div>

            <p class="mt-6 text-xs text-[#5f6b7a] leading-relaxed max-w-5xl">
                <strong class="text-[#5f6b7a]">Priced per billable provider:</strong> counts physicians and
                non-physician practitioners who bill under the group NPI; mid-term joiners are prorated and trued up
                at renewal. The full definition is published on the pricing page and order form.<br>
                <strong class="text-[#5f6b7a]">† Harassment prevention (general):</strong> does not substitute for
                state-mandated harassment training where specific content or frequency is required.<br>
                <strong class="text-[#5f6b7a]">‡ Coding &amp; Documentation Mini Audit:</strong> if a review
                identifies a potential overpayment, identified overpayments must be reported and returned within 60
                days under federal law. Your program includes a defined escalation path, and we will recommend
                independent legal counsel where findings suggest material exposure.
            </p>

            {{-- Legal Add-on --}}
            <div class="mt-6 rounded-2xl border border-[#dde3ea] bg-[#eaf5fb] p-7 shadow-sm">
                <div class="flex flex-col lg:flex-row lg:items-start gap-6">
                    <div class="flex items-start gap-4 flex-1">
                        <span
                            class="flex-shrink-0 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-[#1c3457] text-white">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </span>
                        <div>
                            <span class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5]">Add-on &middot;
                                Available for Any Package</span>
                            <h3 class="mt-1 font-semibold text-[#1c3457]">Legal Review &amp; Risk Assessment, by Frier
                                Levitt (or comparable independent counsel)</h3>
                            <p class="mt-2 text-sm text-[#4a5563] leading-relaxed">Conducted at the direction of Frier
                                Levitt (or comparable independent counsel), structured with the intent that counsel's
                                analysis be protected by attorney-client privilege. Privilege is fact-specific and
                                cannot be guaranteed. Underlying records, claims data, and codes submitted are not
                                privileged. The Advanced-tier mini audit is not conducted under privilege. Includes an
                                initial risk assessment call, a coding &amp; documentation review conducted at the
                                direction of counsel, a legal analysis letter, a post-report implementation call, and
                                Business Associate Agreements in place before any work begins.
                            </p>
                            <p class="mt-2 text-xs text-[#4a5563]">Coverage varies by carrier and policy; we can
                                confirm what applies to your practice during scoping.
                            </p>
                        </div>
                    </div>
                    <div class="lg:text-right shrink-0">
                        <a href="{{ route('contact') }}?addon=legal-review"
                            class="inline-block rounded-full bg-[#1c3457] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Contact
                            us about this add-on</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Process --}}
    <section id="process" class="py-14 lg:py-16 bg-[#f5f7fa]">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 items-center">
                <div>
                    <span class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5]">Process</span>
                    <h2 class="mt-3 text-[34px] font-extrabold text-[#0e1b30]">A 5-step flow from billing to compliance
                        documents.</h2>
                    <p class="mt-4 text-[#4a5563] leading-relaxed">
                        Your portal walks each practice through a fixed sequence: billing setup, profile lock, intake
                        uploads with AI extraction, admin review, and dashboard-based delivery.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="#pricing"
                            class="inline-block rounded-full bg-[#3a9bd5] px-6 py-3 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors shadow-lg">Explore
                            Packages</a>
                        <a href="{{ route('contact') }}"
                            class="inline-block rounded-full border border-[#9ed3e9] bg-white px-6 py-3 text-sm font-semibold text-[#2b82b8] hover:bg-[#eaf5fb] transition-colors">Talk
                            to the team</a>
                    </div>
                </div>

                <div class="rounded-2xl bg-white border border-[#dde3ea] shadow-sm divide-y divide-[#dde3ea]">
                    @foreach([
                    ['1', 'Billing & Activation', 'Select your package and complete billing setup to activate
                    onboarding immediately.'],
                    ['2', 'Practice Profile', 'Submit practice details and OSHA locations; core profile fields lock
                    after submission for document consistency.'],
                    ['3', 'Intake Upload', 'Upload package-required forms and handbook inputs; AI extracts structured
                    data from files for drafting.'],
                    ['4', 'Review Status', 'Your submission moves through submitted and under-review states until
                    admin approval or requested changes.'],
                    ['5', 'Dashboard & Documents', 'Access history, payments, and generated files from your
                    dashboard, with stale indicators when profile data changes.'],
                    ] as [$num, $title, $desc])
                    <div class="flex items-start gap-4 p-5">
                        <span
                            class="flex-shrink-0 inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#3a9bd5] text-xs font-bold text-white">{{
                            $num }}</span>
                        <div>
                            <h3 class="text-sm font-semibold text-[#1c3457]">{{ $title }}</h3>
                            <p class="mt-1 text-xs text-[#4a5563] leading-relaxed">{{ $desc }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Compare Packages --}}
    <section id="compare" class="py-14 lg:py-16 bg-[#f5f7fa]">
        @php
            $comparisonTiers = ['Essential', 'Professional', 'Advanced', 'Complete'];
            $comparisonGroups = [
                [
                    'label' => 'Program & Policies',
                    'rows' => [
                        ['feature' => 'Review & update of your existing Compliance & Ethics Program', 'tiers' => [true, true, true, true]],
                        ['feature' => 'Review & update of your existing HIPAA Privacy & Security Policies', 'tiers' => [true, true, true, true]],
                        ['feature' => 'Creation of missing program documents & policies', 'tiers' => [false, true, true, true]],
                        ['feature' => 'Customized Compliance Program', 'tiers' => [false, false, false, true]],
                    ],
                ],
                [
                    'label' => 'Training',
                    'rows' => [
                        ['feature' => 'Review & update of your existing trainings', 'tiers' => [true, true, true, true]],
                        ['feature' => 'Access to Empower LMS', 'tiers' => [false, true, true, true]],
                        ['feature' => 'Custom options', 'tiers' => [false, false, false, true]],
                    ],
                ],
                [
                    'label' => 'Monitoring',
                    'rows' => [
                        ['feature' => 'Exclusions Screening', 'tiers' => [true, true, true, true]],
                        ['feature' => 'Compliance Hotline', 'tiers' => [true, true, true, true]],
                        ['feature' => 'Custom options', 'tiers' => [false, false, false, true]],
                    ],
                ],
                [
                    'label' => 'Audit & Risk',
                    'rows' => [
                        ['feature' => 'Coding & Documentation Mini Audit (10 encounters/provider)', 'tiers' => [false, false, true, true]],
                        ['feature' => 'Security Risk Assessment (SRA)', 'tiers' => [false, false, true, true]],
                        ['feature' => 'Employee manual review & update', 'tiers' => [false, false, true, true]],
                        ['feature' => 'Custom options', 'tiers' => [false, false, false, true]],
                    ],
                ],
                [
                    'label' => 'Oversight',
                    'rows' => [
                        ['feature' => 'Guidance of Compliance Structure', 'tiers' => [false, false, true, true]],
                        ['feature' => 'Periodic Compliance Meeting', 'tiers' => [false, false, true, true]],
                        ['feature' => 'Compliance Officer support: co-sourced, fractional, or outsourced', 'tiers' => [false, false, false, true]],
                    ],
                ],
            ];
        @endphp
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5]">Compare Packages</span>
                <h2 class="mt-3 text-[34px] font-extrabold text-[#0e1b30]">What each tier includes</h2>
            </div>

            <div class="rounded-2xl border border-[#dde3ea] bg-white shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[#dde3ea]">
                                <th scope="col"
                                    class="text-left font-semibold text-[#0e1b30] px-5 py-3 whitespace-nowrap">Feature
                                </th>
                                @foreach($comparisonTiers as $i => $tier)
                                <th scope="col"
                                    class="text-center font-bold text-xs uppercase tracking-wide px-4 py-3 whitespace-nowrap {{ $i === 2 ? 'bg-[#1c3457] text-white' : 'text-[#4a5563]' }}">
                                    {{ $tier }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($comparisonGroups as $group)
                            <tr class="bg-[#f8fbfd]">
                                <td colspan="5"
                                    class="px-5 py-2 text-xs font-bold uppercase tracking-widest text-[#3a9bd5]">
                                    {{ $group['label'] }}</td>
                            </tr>
                            @foreach($group['rows'] as $row)
                            <tr class="border-b border-[#eef2f6] last:border-b-0">
                                <td class="px-5 py-3 text-[#0e1b30]">{{ $row['feature'] }}</td>
                                @foreach($row['tiers'] as $i => $included)
                                <td class="text-center px-4 py-3 {{ $i === 2 ? 'bg-[#eaf5fb]' : '' }}">
                                    @if($included)
                                    <svg class="h-4 w-4 mx-auto text-[#3a9bd5]" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                            d="M5 13l4 4L19 7" />
                                    </svg>
                                    @else
                                    <span class="text-[#c2cad4]">&mdash;</span>
                                    @endif
                                </td>
                                @endforeach
                            </tr>
                            @endforeach
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-[#dde3ea]">
                                <td class="px-5 py-3 text-sm font-semibold text-[#0e1b30]">Legal Review &amp; Risk
                                    Assessment</td>
                                <td colspan="4" class="px-4 py-3 text-xs text-[#4a5563] text-center">Optional add-on
                                    for any package</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <p class="mt-4 text-xs text-[#8598ab] text-center">See each tier's Disclaimer for scope details.</p>
        </div>
    </section>

    {{-- FAQ --}}
    <section id="faq" class="py-14 lg:py-16 bg-white">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5]">FAQ</span>
                <h2 class="mt-3 text-[34px] font-extrabold text-[#0e1b30]">Frequently asked questions</h2>
            </div>

            <div class="space-y-3">
                @foreach([
                ['What is a healthcare compliance program, and does my practice need one?', 'A compliance program is
                a documented set of written standards, training, oversight, monitoring, and reporting that helps a
                practice meet its regulatory obligations. It is required by regulation for certain entity types and
                is expected of all providers under OIG guidance; many payor participation agreements require one as
                well.'],
                ['Is Proactive Compliance built on the OIG\'s seven elements?', 'Yes. Your program is structured on
                the seven elements described in OIG guidance: written standards, oversight, training, and reporting
                channels are documented and operated with our support, while enforcement and corrective action are
                operated by your practice, with our support.'],
                ['How is Empower related to CareCloud?', 'Empower is part of CareCloud. Empower Healthcare &
                Compliance Inc. delivers the compliance programs and audit support described here, and works with the
                systems you already use. Where CareCloud provides revenue cycle services, review of coding and
                billing is performed independently of the teams that deliver them.'],
                ['How much does a compliance program cost?', 'Every package is priced per billable provider, per year
                (or monthly), and includes annual renewal. Pricing for the Essential, Professional, and Advanced
                tiers is available at empowerhci.com. The Complete tier is quoted based on your practice.'],
                ['What happens if a coding or documentation audit finds a problem?', 'Identified overpayments must be
                reported and returned within 60 days under federal law. Your program includes a defined escalation
                path, and we will recommend independent legal counsel where findings suggest material exposure.'],
                ] as [$q, $a])
                <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }"
                    class="rounded-2xl border p-5 transition-colors"
                    :class="open ? 'border-[#3a9bd5] bg-[#eaf5fb]' : 'border-[#dde3ea] bg-white'">
                    <button type="button" @click="open = !open"
                        class="flex w-full items-center justify-between gap-4 text-left cursor-pointer">
                        <span class="text-sm font-semibold text-[#1c3457]">{{ $q }}</span>
                        <svg :class="open ? 'rotate-45' : ''"
                            class="h-4 w-4 shrink-0 text-[#3a9bd5] transition-transform" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                    <p x-show="open" x-cloak x-transition class="mt-3 text-sm text-[#4a5563] leading-relaxed">{{ $a
                        }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section id="contact" class="py-14 lg:py-16 bg-gradient-to-br from-[#162a46] via-[#1c3457] to-[#16638e]">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <span class="text-xs font-bold tracking-widest uppercase text-[#74bfe4]">Ready to get started?</span>
                <h2 class="mt-3 text-3xl font-bold text-white">Proactive Compliance by Empower.</h2>
                <p class="mt-4 text-white/70 leading-relaxed">
                    Select your package and begin the 5-step onboarding flow today, or contact us to discuss the
                    right tier.
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('contact') }}"
                        class="inline-block rounded-full bg-[#3a9bd5] px-8 py-3.5 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors shadow-lg">Contact
                        Us</a>
                    <a href="#pricing"
                        class="inline-block rounded-full border border-white/30 px-8 py-3.5 text-sm font-semibold text-white hover:bg-white/10 transition-colors">Start
                        onboarding</a>
                </div>
            </div>
        </div>
    </section>

    <div x-data="{ show: false, message: '' }"
        x-on:toast.window="message = $event.detail.message; show = true; clearTimeout(hideTimer); hideTimer = setTimeout(() => show = false, 3000)"
        x-init="hideTimer = null" x-show="show" x-transition x-cloak class="fixed bottom-6 right-6 z-[100]">
        <div
            class="flex items-center gap-2 rounded-xl bg-[#1c3457] text-white pl-4 pr-5 py-3 shadow-[0_18px_50px_rgba(10,32,55,0.25)]">
            <span class="text-[#74bfe4] font-bold">&#10003;</span>
            <span class="text-sm font-semibold" x-text="message"></span>
        </div>
    </div>
</x-layouts.marketing>
