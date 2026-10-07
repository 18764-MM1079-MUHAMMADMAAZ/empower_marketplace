<x-layouts.marketing title="Proactive Compliance by Empower: Healthcare Compliance Portal" :on-home-page="true">

    {{-- "Welcome back" resume banner --}}
    @if($resumeOrder)
    @php $resumePercent = $resumeOrder->intakePercentComplete(); @endphp
    <div class="bg-[#eef8f3] border-b border-[#bfe3d2]">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-3" x-data="{
                dismissed: false,
                init() {
                    try { this.dismissed = localStorage.getItem('resume-banner-dismissed-{{ $resumeOrder->id }}') === '1'; } catch (e) {}
                },
                dismiss() {
                    this.dismissed = true;
                    try { localStorage.setItem('resume-banner-dismissed-{{ $resumeOrder->id }}', '1'); } catch (e) {}
                },
            }" x-show="!dismissed" x-cloak>
            <div class="flex items-center gap-4">
                <span
                    class="flex-shrink-0 h-9 w-9 rounded-full bg-[#1f9d6b] text-white flex items-center justify-center font-bold">&#10003;</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-[#0e1b30]">Welcome back, {{ auth()->user()->name }}</p>
                    <p class="text-xs text-[#4a5563]">Your {{ $resumeOrder->package?->name }} intake is
                        {{ $resumePercent }}% complete.</p>
                    <div class="mt-1.5 h-1.5 w-full max-w-xs rounded-full bg-white overflow-hidden">
                        <div class="h-full bg-[#1f9d6b] rounded-full" style="width: {{ $resumePercent }}%"></div>
                    </div>
                </div>
                <a href="{{ route('portal') }}"
                    class="flex-shrink-0 rounded-full bg-[#1c3457] px-4 py-2 text-xs font-semibold text-white hover:bg-[#162a46] transition-colors whitespace-nowrap">Continue
                    where you left off</a>
                <button type="button" @click="dismiss()" aria-label="Dismiss"
                    class="flex-shrink-0 text-[#4a5563] hover:text-[#0e1b30] transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
    @endif

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
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" x-data="{
                cycle: 'annual',
                quizOpen: false,
                quizStep: 1,
                quizDocs: null,
                quizSra: null,
                quizProviders: 1,
                {{-- toBase(): EloquentCollection::only() filters by primary key, not array key —
                     without this every slug lookup below would silently miss and return []. --}}
                quizPackages: @js($packages->toBase()->only(['essential', 'professional', 'advanced'])->map(fn ($p) => [
                    'name' => $p->name,
                    'monthly' => $p->monthly_price,
                    'annual' => $p->annual_price,
                ])),
                get quizRecommendation() {
                    if (this.quizSra === 'yes') return 'advanced';
                    if (this.quizDocs === 'current') return 'essential';
                    return 'professional';
                },
                // Alpine evaluates a freshly-inserted x-if template's bindings once before this
                // getter's dependencies (quizSra/quizDocs) have settled, so this can transiently
                // run with defaults — always falls back to a real package rather than throwing.
                get quizResultPackage() {
                    return this.quizPackages[this.quizRecommendation] ?? Object.values(this.quizPackages)[0] ?? { name: '', monthly: 0, annual: 0 };
                },
                get quizReason() {
                    if (this.quizRecommendation === 'advanced') return 'It adds the Security Risk Assessment and the coding and documentation audit, on top of everything in Professional.';
                    if (this.quizRecommendation === 'essential') return 'Your policies are current, so Essential reviews and updates what you already have.';
                    return 'Empower creates any missing or outdated documents for you, and your staff get the Empower training platform.';
                },
                // Mirrors the client prototype's quizResult().extra array — 0, 1, or both tips
                // can apply at once, so this is a list, not a single conditional paragraph.
                get quizExtraTips() {
                    const tips = [];
                    if (this.quizSra === 'unsure' && this.quizRecommendation !== 'advanced') {
                        tips.push('Not sure about a risk assessment? HIPAA requires a security <span class=\'underline decoration-dotted decoration-[#3a9bd5] underline-offset-2 cursor-help\' title=\'A formal review of risks to the confidentiality, integrity, and availability of patient data — required under the HIPAA Security Rule.\'>risk analysis</span>, and Advanced includes one.');
                    }
                    if (this.quizRecommendation === 'essential') {
                        tips.push('If a policy turns out to be missing, Professional creates it for you.');
                    }
                    return tips;
                },
                quizPrice() {
                    const perProvider = this.cycle === 'monthly' ? this.quizResultPackage.monthly : this.quizResultPackage.annual;
                    return (perProvider ?? 0) * Math.max(1, this.quizProviders);
                },
                quizReset() {
                    this.quizStep = 1;
                    this.quizDocs = null;
                    this.quizSra = null;
                    this.quizProviders = 1;
                    this.quizPicked = null;
                },
                quizPicked: null,
                // Matches the prototype's .qz-opt.picked state: briefly highlight the chosen
                // option before advancing, instead of jumping to the next question instantly.
                quizPick(field, value, nextStep) {
                    this.quizPicked = value;
                    this[field] = value;
                    setTimeout(() => {
                        this.quizStep = nextStep;
                        this.quizPicked = null;
                    }, 220);
                },
                quizPulse(slug) {
                    this.quizOpen = false;
                    this.$nextTick(() => {
                        document.getElementById('pricing').scrollIntoView({ behavior: 'smooth', block: 'start' });
                        const card = document.querySelector('[data-package-card=' + slug + ']');
                        if (card) {
                            card.classList.remove('quiz-pick');
                            void card.offsetWidth;
                            card.classList.add('quiz-pick');
                            setTimeout(() => card.classList.remove('quiz-pick'), 3200);
                        }
                    });
                },
            }">
            <div class="text-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5]">Pricing</span>
                <h2 class="mt-3 text-[28px] sm:text-[34px] font-extrabold text-[#0e1b30]">Choose Your Compliance Package</h2>
                <p class="mt-4 text-[#4a5563] max-w-2xl mx-auto leading-relaxed">
                    Every package is billed per billable provider, per year (or monthly), and includes annual
                    renewal.
                </p>
            </div>

            <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
                <div class="inline-flex self-start gap-1 rounded-full border border-[#dde3ea] bg-[#eef1f5] p-1">
                    <button type="button" @click="cycle = 'monthly'"
                        :class="cycle === 'monthly' ? 'bg-white text-[#1c3457] shadow-sm' : 'text-[#4a5563] hover:text-[#1c3457]'"
                        class="rounded-full px-4 sm:px-[22px] py-2.5 text-sm font-bold transition-colors">Billed monthly</button>
                    <button type="button" @click="cycle = 'annual'"
                        :class="cycle === 'annual' ? 'bg-white text-[#1c3457] shadow-sm' : 'text-[#4a5563] hover:text-[#1c3457]'"
                        class="rounded-full px-4 sm:px-[22px] py-2.5 text-sm font-bold transition-colors">Billed annually
                        <span class="ml-1.5 text-xs font-extrabold text-[#1f9d6b]">Save ~16%</span></button>
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="w-full sm:w-auto text-sm text-[#4a5563]">Not sure what package is right?</span>
                    <button type="button" @click="quizOpen = true; quizStep = 1"
                        class="inline-block whitespace-nowrap rounded-full bg-[#3a9bd5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors">Take
                        the 3-question quiz</button>
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-contact', { detail: { topic: 'package' } }))"
                        class="inline-block whitespace-nowrap rounded-full border border-[#9ed3e9] bg-white px-4 py-2 text-sm font-semibold text-[#2b82b8] hover:bg-[#eaf5fb] transition-colors">Contact
                        us</button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 items-stretch">

                {{-- Essential --}}
                <div data-package-card="essential"
                    class="relative rounded-2xl border border-[#dde3ea] bg-[#f5f7fa] p-7 flex flex-col transition-shadow">
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
                    <a :href="`{{ auth()->check() ? route('portal', ['package' => 'essential']) : route('register', ['package' => 'essential']) }}&billing_cycle=${cycle}`"
                        class="block w-full rounded-full bg-[#1c3457] py-3 text-center text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Select
                        Package</a>
                </div>

                {{-- Professional --}}
                <div data-package-card="professional"
                    class="relative rounded-2xl border border-[#dde3ea] bg-[#f5f7fa] p-7 flex flex-col transition-shadow">
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
                    <a :href="`{{ auth()->check() ? route('portal', ['package' => 'professional']) : route('register', ['package' => 'professional']) }}&billing_cycle=${cycle}`"
                        class="block w-full rounded-full bg-[#1c3457] py-3 text-center text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Select
                        Package</a>
                </div>

                {{-- Advanced (Popular) --}}
                <div data-package-card="advanced"
                    class="rounded-2xl border-2 border-[#3a9bd5] bg-[#1c3457] p-7 flex flex-col relative transition-shadow">
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
                    <a :href="`{{ auth()->check() ? route('portal', ['package' => 'advanced']) : route('register', ['package' => 'advanced']) }}&billing_cycle=${cycle}`"
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
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-contact', { detail: { topic: 'quote' } }))"
                        class="block w-full rounded-full bg-[#1c3457] py-3 text-center text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Request
                        a Quote</button>
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
                        <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-contact', { detail: { topic: 'legal' } }))"
                            class="inline-block rounded-full bg-[#1c3457] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#162a46] transition-colors">Contact
                            us about this add-on</button>
                    </div>
                </div>
            </div>

            {{-- Package-picker quiz --}}
            <div x-show="quizOpen" x-cloak x-on:keydown.escape.window="quizOpen = false"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
                <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl overflow-hidden"
                    x-on:click.outside="quizOpen = false">

                    {{-- Header --}}
                    <div class="flex items-start justify-between px-7 pt-6 pb-4">
                        <div>
                            <h3 class="text-xl font-extrabold text-[#0e1b30]">Find the right package</h3>
                            <p class="text-sm text-[#8a94a3] mt-1">
                                <span x-show="quizStep <= 3">Question <span x-text="quizStep"></span> of 3</span>
                                <span x-show="quizStep === 4" x-cloak>Based on your answers</span>
                            </p>
                        </div>
                        <button type="button" x-on:click="quizOpen = false" aria-label="Close"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full border border-[#e2e8f0] text-[#5c778d] hover:bg-[#f5f7fa] hover:text-[#0e3a61] transition-colors">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="border-t border-[#eef1f5]"></div>

                    {{-- Segmented progress bar: matches the client prototype's 3-state dots —
                         done (#0b9ed0), current (#7cc8e8), not-yet-reached (#e3eef6). --}}
                    <div class="flex gap-1.5 px-7 pt-5">
                        <template x-for="segment in [1, 2, 3]" :key="segment">
                            <div class="h-[5px] flex-1 rounded-full"
                                :class="segment < quizStep ? 'bg-[#0b9ed0]' : segment === quizStep ? 'bg-[#7cc8e8]' : 'bg-[#e3eef6]'">
                            </div>
                        </template>
                    </div>

                    <div class="px-7 pt-5 pb-7">
                        {{-- Q1: documentation state --}}
                        <template x-if="quizStep === 1">
                            <div>
                                <h4 class="text-lg font-extrabold text-[#0e1b30] mb-4">Do you have written compliance
                                    and HIPAA policies today?</h4>
                                <div class="space-y-3">
                                    <button type="button" @click="quizPick('quizDocs', 'current', 2)"
                                        :class="quizPicked === 'current' ? 'border-[#12304f] bg-[#f2f6fa] scale-[0.99]' : 'border-[#e2e8f0] hover:border-[#3a9bd5] hover:bg-[#f5fafd]'"
                                        class="w-full flex items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition-all">
                                        <span class="h-5 w-5 rounded-full border-2 flex-shrink-0 flex items-center justify-center"
                                            :class="quizPicked === 'current' ? 'border-[#12304f]' : 'border-[#cbd5e1]'">
                                            <span x-show="quizPicked === 'current'" class="h-2.5 w-2.5 rounded-full bg-[#12304f]"></span>
                                        </span>
                                        <span class="text-sm font-semibold text-[#173045]">Yes, and they're reasonably up to date</span>
                                    </button>
                                    <button type="button" @click="quizPick('quizDocs', 'outdated', 2)"
                                        :class="quizPicked === 'outdated' ? 'border-[#12304f] bg-[#f2f6fa] scale-[0.99]' : 'border-[#e2e8f0] hover:border-[#3a9bd5] hover:bg-[#f5fafd]'"
                                        class="w-full flex items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition-all">
                                        <span class="h-5 w-5 rounded-full border-2 flex-shrink-0 flex items-center justify-center"
                                            :class="quizPicked === 'outdated' ? 'border-[#12304f]' : 'border-[#cbd5e1]'">
                                            <span x-show="quizPicked === 'outdated'" class="h-2.5 w-2.5 rounded-full bg-[#12304f]"></span>
                                        </span>
                                        <span class="text-sm font-semibold text-[#173045]">Yes, but they're outdated or incomplete</span>
                                    </button>
                                    <button type="button" @click="quizPick('quizDocs', 'none', 2)"
                                        :class="quizPicked === 'none' ? 'border-[#12304f] bg-[#f2f6fa] scale-[0.99]' : 'border-[#e2e8f0] hover:border-[#3a9bd5] hover:bg-[#f5fafd]'"
                                        class="w-full flex items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition-all">
                                        <span class="h-5 w-5 rounded-full border-2 flex-shrink-0 flex items-center justify-center"
                                            :class="quizPicked === 'none' ? 'border-[#12304f]' : 'border-[#cbd5e1]'">
                                            <span x-show="quizPicked === 'none'" class="h-2.5 w-2.5 rounded-full bg-[#12304f]"></span>
                                        </span>
                                        <span class="text-sm font-semibold text-[#173045]">No, or we're not sure</span>
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{-- Q2: SRA / audit need --}}
                        <template x-if="quizStep === 2">
                            <div>
                                <h4 class="text-lg font-extrabold text-[#0e1b30] mb-2">Do you also need a <span
                                        class="underline decoration-dotted decoration-[#3a9bd5] underline-offset-2 cursor-help"
                                        title="A formal review of risks to the confidentiality, integrity, and availability of patient data — required under the HIPAA Security Rule.">Security
                                        Risk Assessment</span> or a coding audit this year?</h4>
                                <p class="text-sm text-[#5f6b7a] mb-4">HIPAA requires a security <span
                                        class="underline decoration-dotted decoration-[#3a9bd5] underline-offset-2 cursor-help"
                                        title="A formal review of risks to the confidentiality, integrity, and availability of patient data — required under the HIPAA Security Rule.">risk
                                        analysis</span>. Many practices also audit their coding each year.</p>
                                <div class="space-y-3">
                                    <button type="button" @click="quizPick('quizSra', 'yes', 3)"
                                        :class="quizPicked === 'yes' ? 'border-[#12304f] bg-[#f2f6fa] scale-[0.99]' : 'border-[#e2e8f0] hover:border-[#3a9bd5] hover:bg-[#f5fafd]'"
                                        class="w-full flex items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition-all">
                                        <span class="h-5 w-5 rounded-full border-2 flex-shrink-0 flex items-center justify-center"
                                            :class="quizPicked === 'yes' ? 'border-[#12304f]' : 'border-[#cbd5e1]'">
                                            <span x-show="quizPicked === 'yes'" class="h-2.5 w-2.5 rounded-full bg-[#12304f]"></span>
                                        </span>
                                        <span class="text-sm font-semibold text-[#173045]">Yes, we need one or both</span>
                                    </button>
                                    <button type="button" @click="quizPick('quizSra', 'no', 3)"
                                        :class="quizPicked === 'no' ? 'border-[#12304f] bg-[#f2f6fa] scale-[0.99]' : 'border-[#e2e8f0] hover:border-[#3a9bd5] hover:bg-[#f5fafd]'"
                                        class="w-full flex items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition-all">
                                        <span class="h-5 w-5 rounded-full border-2 flex-shrink-0 flex items-center justify-center"
                                            :class="quizPicked === 'no' ? 'border-[#12304f]' : 'border-[#cbd5e1]'">
                                            <span x-show="quizPicked === 'no'" class="h-2.5 w-2.5 rounded-full bg-[#12304f]"></span>
                                        </span>
                                        <span class="text-sm font-semibold text-[#173045]">No, we're already covered</span>
                                    </button>
                                    <button type="button" @click="quizPick('quizSra', 'unsure', 3)"
                                        :class="quizPicked === 'unsure' ? 'border-[#12304f] bg-[#f2f6fa] scale-[0.99]' : 'border-[#e2e8f0] hover:border-[#3a9bd5] hover:bg-[#f5fafd]'"
                                        class="w-full flex items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition-all">
                                        <span class="h-5 w-5 rounded-full border-2 flex-shrink-0 flex items-center justify-center"
                                            :class="quizPicked === 'unsure' ? 'border-[#12304f]' : 'border-[#cbd5e1]'">
                                            <span x-show="quizPicked === 'unsure'" class="h-2.5 w-2.5 rounded-full bg-[#12304f]"></span>
                                        </span>
                                        <span class="text-sm font-semibold text-[#173045]">Not sure</span>
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{-- Q3: provider count --}}
                        <template x-if="quizStep === 3">
                            <div>
                                <h4 class="text-lg font-extrabold text-[#0e1b30] mb-2">How many billable providers do
                                    you have?</h4>
                                <p class="text-sm text-[#5f6b7a] mb-4">Physicians and non-physician practitioners
                                    billing under your group NPI. We use this to estimate your price.</p>
                                {{-- Sized to match the prototype's .qz-num (larger than the
                                     base .stepper-num used elsewhere): 48×52 buttons, 84×52 input. --}}
                                <div class="inline-flex items-stretch rounded-[10px] border border-[#dde3ea] bg-[#f5f7fa] overflow-hidden">
                                    <button type="button" @click="quizProviders = Math.max(1, quizProviders - 1)"
                                        class="w-12 h-13 flex items-center justify-center text-[22px] text-[#1c3457] font-bold hover:bg-[#eef1f5] transition-colors">&minus;</button>
                                    <input x-model.number="quizProviders" type="number" min="1" max="9999"
                                        class="w-21 h-13 text-center bg-white border-x border-[#dde3ea] text-xl font-extrabold text-[#173045] focus:outline-none">
                                    <button type="button" @click="quizProviders = Math.min(9999, quizProviders + 1)"
                                        class="w-12 h-13 flex items-center justify-center text-[22px] text-[#1c3457] font-bold hover:bg-[#eef1f5] transition-colors">&plus;</button>
                                </div>
                            </div>
                        </template>

                        {{-- Result --}}
                        <template x-if="quizStep === 4">
                            <div class="text-center">
                                <p class="text-xs font-bold tracking-widest uppercase text-[#3a9bd5] mb-2">We
                                    recommend</p>
                                <h3 class="text-2xl font-extrabold text-[#0e1b30] mb-1"
                                    x-text="quizResultPackage.name"></h3>
                                <p class="text-sm text-[#5f6b7a] mb-4">
                                    $<span x-text="quizPrice().toLocaleString()"></span><span
                                        x-text="cycle === 'monthly' ? '/month' : '/year'"></span>
                                    &middot; $<span x-text="(cycle === 'monthly' ? quizResultPackage.monthly : quizResultPackage.annual).toLocaleString()"></span>
                                    per provider &times; <span x-text="quizProviders"></span>
                                </p>
                                <p class="text-sm text-[#5f6b7a] leading-relaxed max-w-sm mx-auto" x-text="quizReason"></p>
                                <ul class="max-w-sm mx-auto mt-2 text-left space-y-1">
                                    <template x-for="tip in quizExtraTips" :key="tip">
                                        <li class="flex items-start gap-1.5 text-xs text-[#5f6b7a] leading-relaxed">
                                            <span class="mt-1.5 h-1 w-1 rounded-full bg-[#5f6b7a] flex-shrink-0"></span>
                                            <span x-html="tip"></span>
                                        </li>
                                    </template>
                                </ul>
                                <p class="text-sm font-semibold mt-4">
                                    <button type="button" @click="quizPulse(quizRecommendation)"
                                        class="text-[#3a9bd5] hover:text-[#2b82b8] transition-colors">Compare all
                                        packages</button>
                                    <span class="text-[#c3ccd4] mx-1.5">&middot;</span>
                                    <button type="button" @click="quizReset()"
                                        class="text-[#3a9bd5] hover:text-[#2b82b8] transition-colors">Start over</button>
                                </p>
                            </div>
                        </template>
                    </div>

                    {{-- Footer: Q1/Q2 show a back link + hint; Q3 shows Back and the continue
                         action as a pair of buttons instead, since it needs an explicit submit. --}}
                    <template x-if="quizStep === 1 || quizStep === 2">
                        <div class="flex items-center justify-between border-t border-[#eef1f5] bg-[#f8fafc] px-7 py-3">
                            <button type="button" x-show="quizStep > 1" @click="quizStep = quizStep - 1"
                                class="text-sm font-semibold text-[#5f6b7a] hover:text-[#1c3457] transition-colors">&larr;
                                Back</button>
                            <span x-show="quizStep <= 1"></span>
                            <span class="text-sm font-semibold text-[#3a9bd5]">Pick one to continue</span>
                        </div>
                    </template>
                    <template x-if="quizStep === 3">
                        <div class="flex items-center justify-between border-t border-[#eef1f5] bg-[#f8fafc] px-7 py-3">
                            <button type="button" @click="quizStep = 2"
                                class="rounded-full border border-[#dde3ea] bg-white px-4 py-2 text-sm font-semibold text-[#1c3457] hover:bg-[#f5f7fa] transition-colors">&larr;
                                Back</button>
                            <button type="button" @click="quizStep = 4"
                                class="rounded-full bg-[#3a9bd5] px-5 py-2 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors">See
                                my recommendation &rarr;</button>
                        </div>
                    </template>
                    <template x-if="quizStep === 4">
                        <div class="flex items-center justify-between border-t border-[#eef1f5] bg-[#f8fafc] px-7 py-3">
                            <button type="button" @click="quizStep = 3"
                                class="rounded-full border border-[#dde3ea] bg-white px-4 py-2 text-sm font-semibold text-[#1c3457] hover:bg-[#f5f7fa] transition-colors">&larr;
                                Back</button>
                            <a :href="`{{ auth()->check() ? route('portal') : route('register') }}?package=${quizRecommendation}&billing_cycle=${cycle}`"
                                class="rounded-full bg-[#3a9bd5] px-5 py-2 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors">Choose
                                <span x-text="quizResultPackage.name.split(' ')[0]"></span> &rarr;</a>
                        </div>
                    </template>
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
                        <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-contact', { detail: { topic: 'general' } }))"
                            class="inline-block rounded-full border border-[#9ed3e9] bg-white px-6 py-3 text-sm font-semibold text-[#2b82b8] hover:bg-[#eaf5fb] transition-colors">Talk
                            to the team</button>
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
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-contact', { detail: { topic: 'general' } }))"
                        class="inline-block rounded-full bg-[#3a9bd5] px-8 py-3.5 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors shadow-lg">Contact
                        Us</button>
                    <a href="#pricing"
                        class="inline-block rounded-full border border-white/30 px-8 py-3.5 text-sm font-semibold text-white hover:bg-white/10 transition-colors">Start
                        onboarding</a>
                </div>
            </div>
        </div>
    </section>

    <div x-data="{ show: false, message: '' }"
        x-on:toast.window="message = $event.detail.message; show = true; clearTimeout(hideTimer); hideTimer = setTimeout(() => show = false, 3000)"
        x-init="hideTimer = null" x-show="show" x-transition x-cloak class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[100]">
        <div
            class="flex items-center gap-2 rounded-xl bg-[#1c3457] text-white pl-4 pr-5 py-3 shadow-[0_18px_50px_rgba(10,32,55,0.25)]">
            <span class="text-[#74bfe4] font-bold">&#10003;</span>
            <span class="text-sm font-semibold" x-text="message"></span>
        </div>
    </div>
</x-layouts.marketing>
