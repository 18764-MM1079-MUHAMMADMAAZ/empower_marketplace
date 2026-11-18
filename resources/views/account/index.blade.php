{{-- My Account — layout from changes/Empower Account Management.html, styled like the client
portal. Each section is its own Livewire component in resources/views/components/account. --}}
@php
$user = auth()->user();
$isClient = ! $user->isAdmin();
$account = \App\Services\ClientAccount::for($user);
$hasOrders = $isClient && $account->orders()->isNotEmpty();
$hasSubmission = $hasOrders && $account->primaryOrder()?->intakeSubmission !== null;
$staleCount = $hasOrders ? $account->staleDocuments()->count() : 0;

$sections = array_filter([
    'overview' => $isClient ? 'Overview' : null,
    'review' => $hasSubmission ? 'Review status' : null,
    'plan' => $hasOrders ? 'Plan & billing' : null,
    'documents' => $hasOrders ? 'Documents' : null,
    'practice' => $isClient && $user->practice ? 'Practice info' : null,
    'security' => 'Sign-in & security',
    'notifications' => $isClient ? null : 'Notifications',
]);
@endphp
<x-layouts.app title="My Account">
    @unless($isClient)
    <a href="{{ route('admin.dashboard') }}" wire:navigate data-back-to-dashboard
        class="mb-4 inline-flex items-center gap-1.5 rounded-lg px-2 py-1 -ml-2 lg:ml-1 text-sm font-semibold text-[#087fa9] hover:text-[#12304f] hover:bg-white/70 transition-colors">
        <span aria-hidden="true">&larr;</span> Back to dashboard
    </a>
    @endunless
    <div class="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-6"
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
        <nav aria-label="Account sections"
            class="sticky top-16 z-30 -mx-4 sm:-mx-6 lg:mx-0 mb-4 lg:mb-0 lg:self-start bg-page/95 backdrop-blur lg:bg-transparent px-4 sm:px-6 lg:px-0 py-2 lg:py-0 border-b border-empower-border lg:border-0">
            <p class="hidden lg:block text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] px-3 mb-2 mt-1">My account</p>
            <div class="flex lg:flex-col gap-1.5 lg:gap-0.5 overflow-x-auto lg:overflow-visible">
                @foreach($sections as $id => $label)
                <button type="button" x-on:click="go(@js($id))" data-account-nav="{{ $id }}"
                    x-bind:aria-current="current === @js($id)"
                    x-bind:class="current === @js($id) ? 'bg-white text-[#12304f] border-[#dbe4ee] lg:shadow-sm' : 'text-[#5d6e7f] border-transparent hover:text-[#12304f] hover:bg-white/70'"
                    class="flex items-center justify-between gap-2 whitespace-nowrap rounded-lg border px-3 py-1.5 lg:py-2 text-sm font-semibold text-left transition-colors">
                    {{ $label }}
                    @if($id === 'documents' && $staleCount > 0)
                    <span class="h-2 w-2 rounded-full bg-[#b7791f]" title="{{ $staleCount }} {{ Str::plural('document', $staleCount) }} need{{ $staleCount === 1 ? 's' : '' }} updating"></span>
                    @endif
                </button>
                @endforeach
            </div>
        </nav>

        <div class="min-w-0 space-y-5">
            @if($isClient)
            <livewire:account.overview />
            @if($hasSubmission)<livewire:account.review-status />@endif
            @if($hasOrders)
            <livewire:account.plan-billing />
            <livewire:account.documents />
            @endif
            @if($user->practice)<livewire:account.practice-info />@endif
            @endif

            <livewire:account.security />
            @unless($isClient)<livewire:account.notifications />@endunless

            @if($hasOrders)<livewire:account.cancel-subscription />@endif
        </div>
    </div>
</x-layouts.app>
