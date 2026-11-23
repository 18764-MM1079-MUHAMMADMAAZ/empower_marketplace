{{-- My Account — layout from changes/empower-my-account-v5.html, styled like the client portal.
Each section is its own Livewire component in resources/views/components/account. --}}
@php
$user = auth()->user();
$isClient = ! $user->isAdmin();
$account = \App\Services\ClientAccount::for($user);
$hasOrders = $isClient && $account->orders()->isNotEmpty();
$practice = $isClient ? $user->practice : null;
$twoFactorOff = ! $user->hasEnabledTwoFactorAuthentication();
$openQuestions = $hasOrders
    ? $account->orders()->map->intakeSubmission->filter()->unique('id')
        ->flatMap->reviewerQuestions->reject->isAnswered()->count()
    : 0;

$sections = array_filter([
    'overview' => $isClient ? 'Overview' : null,
    'profile' => $isClient ? 'My Profile' : null,
    'plan' => $hasOrders ? 'Plan' : null,
    'documents' => $hasOrders ? 'Documents' : null,
    'practice' => $practice ? 'Practice Information' : null,
    'hotline' => $practice ? 'Compliance Hotline' : null,
    'security' => 'Sign-in & Security',
    'notifications' => $isClient ? null : 'Notifications',
    'activity' => $isClient ? 'Activity Log' : null,
]);
$sectionIcons = [
    'overview' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
    'profile' => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
    'plan' => 'M3 6h18v12H3zM3 10h18',
    'documents' => 'M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5',
    'practice' => 'M3 21h18M5 21V8l7-4 7 4v13M10 21v-5h4v5',
    'hotline' => 'M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z',
    'security' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
    'notifications' => 'M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0',
    'activity' => 'M3 12a9 9 0 103-6.7L3 8M3 3v5h5M12 7v5l4 2',
];
@endphp
<x-layouts.app title="My Account">
    @unless($isClient)
    <a href="{{ route('admin.dashboard') }}" wire:navigate data-back-to-dashboard
        class="mb-4 inline-flex items-center gap-1.5 rounded-lg px-2 py-1 -ml-2 lg:ml-1 text-sm font-semibold text-[#087fa9] hover:text-[#12304f] hover:bg-white/70 transition-colors">
        <span aria-hidden="true">&larr;</span> Back to dashboard
    </a>
    @endunless
    <div class="lg:grid lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8"
        x-data="{
            current: @js(array_key_first($sections)),
            go(id) { document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); this.current = id },
            init() {
                const io = new IntersectionObserver((entries) => {
                    const visible = entries.filter(e => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
                    if (visible) this.current = visible.target.id;
                }, { rootMargin: '-120px 0px -60% 0px' });
                document.querySelectorAll('[data-account-section]').forEach(el => io.observe(el));
            },
        }">

        {{-- Section menu: a sticky column on desktop, a scrolling row of chips on phones --}}
        <nav aria-label="My account sections"
            class="sticky top-16 z-30 -mx-4 sm:-mx-6 lg:mx-0 mb-4 lg:mb-0 lg:self-start bg-page/95 backdrop-blur lg:bg-transparent px-4 sm:px-6 lg:px-0 py-2 lg:py-0 border-b border-empower-border lg:border-0">
            <p class="hidden lg:block text-[11px] font-bold uppercase tracking-widest text-[#5d6e7f] px-3 mb-2.5 mt-1">My account</p>
            <div class="flex lg:flex-col gap-1.5 lg:gap-0.5 overflow-x-auto lg:overflow-visible">
                @foreach($sections as $id => $label)
                <button type="button" x-on:click="go(@js($id))" data-account-nav="{{ $id }}"
                    x-bind:aria-current="current === @js($id)"
                    x-bind:class="current === @js($id) ? 'bg-white text-[#12304f] border-[#dbe4ee] lg:shadow-sm' : 'text-[#5d6e7f] border-transparent hover:text-[#12304f] hover:bg-white/70'"
                    class="flex items-center gap-2.5 whitespace-nowrap rounded-lg border px-3 py-1.5 lg:py-2.5 text-sm font-semibold text-left transition-colors">
                    <svg class="hidden lg:block h-4 w-4 shrink-0" x-bind:class="current === @js($id) ? 'text-[#0b9ed0]' : 'text-[#8592a1]'" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $sectionIcons[$id] }}" /></svg>
                    <span>{{ $label }}</span>
                    @if($id === 'security' && $twoFactorOff)
                    <span class="ml-auto rounded-full bg-[#fdf0d8] px-1.5 text-[11px] font-bold text-[#7a4a00]" aria-label="1 item needs attention" data-security-attention>1</span>
                    @endif
                </button>
                @endforeach
            </div>
        </nav>

        <div class="min-w-0 space-y-5">
            @if($isClient)
            <div>
                <h1 class="text-2xl sm:text-[1.75rem] font-bold text-[#12304f] leading-tight">Welcome back, {{ str($user->name)->before(' ')->value() ?: $user->name }}</h1>
                @if($practice?->name || $user->is_practice_admin)
                <p class="text-sm text-[#5d6e7f] mt-1">{{ collect([$practice?->name, $user->is_practice_admin ? 'Practice administrator' : null])->filter()->implode(' · ') }}</p>
                @endif
            </div>

            @if($twoFactorOff)
            <div role="status" data-alert-two-factor class="flex flex-wrap items-center gap-3 rounded-xl border border-[#f3d9a4] bg-[#fff8eb] px-4 py-3.5">
                <svg class="h-5 w-5 shrink-0 text-[#7a4a00]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path d="M12 8v4M12 16h.01" /></svg>
                <p class="flex-1 min-w-[16rem] text-sm text-[#5c3a00]"><strong class="font-semibold">Two-step verification is off.</strong> Turn it on to protect your practice's compliance documents.</p>
                <button type="button" x-on:click="go('security')" class="rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors">Turn on</button>
            </div>
            @endif

            @if($openQuestions > 0)
            <div role="status" data-alert-questions class="flex flex-wrap items-center gap-3 rounded-xl border border-[#f3d9a4] bg-[#fff8eb] px-4 py-3.5">
                <svg class="h-5 w-5 shrink-0 text-[#7a4a00]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" /><path d="M12 8v3M12 14h.01" /></svg>
                <p class="flex-1 min-w-[16rem] text-sm text-[#5c3a00]"><strong class="font-semibold">The compliance team has {{ $openQuestions }} {{ Str::plural('question', $openQuestions) }} for you.</strong> Your review is paused until you respond.</p>
                <a href="{{ route('portal') }}" class="rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-semibold text-white hover:bg-[#087fa9] transition-colors">Answer questions</a>
            </div>
            @endif

            <livewire:account.overview />
            <livewire:account.profile />
            @if($hasOrders)
            <livewire:account.plan />
            <livewire:account.documents />
            @endif
            @if($practice)
            <livewire:account.practice-info />
            <livewire:account.hotline />
            @endif
            @endif

            <livewire:account.security />
            @unless($isClient)<livewire:account.notifications />@endunless
            @if($isClient)<livewire:account.activity-log />@endif
        </div>
    </div>
</x-layouts.app>
