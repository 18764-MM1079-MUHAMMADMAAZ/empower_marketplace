{{--
    Shared "Section X of Y" chapter header for the practice intake wizard — mirrors the client
    prototype's topHTML()/qz-sections dropdown. Rendered via @include with explicit data (not
    $this) so it works the same whether the parent Volt component binds $this into included views
    or not.

    Expects: $chapters (array<{key,label,total,done}>), $currentKey (string),
    $reachedScreens (array<string>), $doneItems, $totalItems, $minutesLeft (int), $skippedCount (int),
    $lastSavedAt (?Carbon) — mirrors the prototype's qz-saved indicator. Every mutating wizard
    action already updates the submission row, so its own updated_at is the save timestamp; no new
    state to track.
--}}
@php
    $currentIndex = collect($chapters)->search(fn ($c) => $c['key'] === $currentKey);
    $currentIndex = $currentIndex === false ? 0 : $currentIndex;
    $currentLabel = $chapters[$currentIndex]['label'] ?? '';
@endphp
<div class="relative border-b border-[#eef2f6] px-5 py-3.5 flex items-center justify-between gap-3 flex-wrap"
    x-data="{ sectionsOpen: false }">
    <button type="button" x-on:click="sectionsOpen = !sectionsOpen" x-on:click.outside="sectionsOpen = false"
        class="inline-flex items-center gap-1.5 text-[13.5px] font-bold text-[#12304f] hover:bg-[#f2f8fd] rounded-lg px-2 py-1.5 -mx-2 -my-1.5">
        Section {{ $currentIndex + 1 }} of {{ count($chapters) }} &middot; {{ $currentLabel }}
        <svg width="14" height="14" viewBox="0 0 24 24" x-bind:class="sectionsOpen ? 'rotate-180' : ''"
            class="transition-transform">
            <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                stroke-linejoin="round" />
        </svg>
    </button>

    <span class="inline-flex items-center gap-3">
        @if($lastSavedAt)
        <span class="inline-flex items-center gap-1 text-[12px] text-[#1f9d6b]">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Saved &middot; {{ $lastSavedAt->diffInSeconds(now()) < 60 ? 'just now' : $lastSavedAt->diffForHumans() }}
        </span>
        @endif
        <span class="text-[12.5px] text-[#5d6e7f]">
            {{ $doneItems }} of {{ $totalItems }} done
            @if($doneItems < $totalItems)
            &middot; about {{ $minutesLeft }} min left
            @endif
            @if($skippedCount > 0)
            &middot; <strong class="text-[#b7791f]">{{ $skippedCount }} skipped</strong>
            @endif
        </span>
    </span>

    <div x-show="sectionsOpen" x-cloak x-transition
        class="absolute top-full left-3 mt-1 z-20 bg-white border border-[#dbe4ee] rounded-2xl shadow-[0_18px_50px_rgba(10,32,55,0.16)] p-1.5 w-[min(360px,calc(100vw-56px))] max-h-[60vh] overflow-auto">
        @foreach($chapters as $chapter)
        @php
            $isChCurrent = $chapter['key'] === $currentKey;
            $isChReached = in_array($chapter['key'], $reachedScreens, true) || $isChCurrent;
        @endphp
        <button type="button" @if($isChReached) wire:click="jumpToScreen('{{ $chapter['key'] }}')" @endif
            @disabled(! $isChReached)
            class="flex items-center justify-between gap-2.5 w-full text-left text-sm px-2.5 py-2 rounded-lg {{ $isChCurrent ? 'bg-[#eaf7fc] font-bold text-[#173045]' : ($isChReached ? 'text-[#173045] hover:bg-[#f2f8fd]' : 'text-[#a7b4c2] cursor-not-allowed') }}">
            <span>{{ $chapter['label'] }}</span>
            <span class="text-xs text-[#5d6e7f] whitespace-nowrap">{{ $chapter['done'] === $chapter['total'] ? '✓ ' : '' }}{{ $chapter['done'] }}/{{ $chapter['total'] }}</span>
        </button>
        @endforeach
        <button type="button" wire:click="viewWhatYouNeed" x-on:click="sectionsOpen = false"
            class="flex items-center justify-between gap-2.5 w-full text-left text-sm px-2.5 py-2 mt-1 rounded-lg border-t border-[#eef2f6] font-bold text-[#1a7aad] hover:bg-[#f2f8fd]">
            <span>What you&rsquo;ll need</span>
            <span class="text-xs whitespace-nowrap">Checklist</span>
        </button>
        <button type="button" wire:click="requestReview" x-on:click="sectionsOpen = false"
            class="flex items-center justify-between gap-2.5 w-full text-left text-sm px-2.5 py-2 rounded-lg font-bold text-[#1a7aad] hover:bg-[#f2f8fd]">
            <span>Review all answers</span>
            <span class="text-xs whitespace-nowrap">&rarr;</span>
        </button>
    </div>
</div>
<div class="h-1 bg-[#e8eef4]">
    <div class="h-full bg-[#0b9ed0] transition-all"
        style="width: {{ $totalItems > 0 ? round($doneItems / $totalItems * 100) : 100 }}%"></div>
</div>
