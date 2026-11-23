{{-- "Synced from talkEHR" pill for My Account sections whose data comes from the partner. --}}
<span class="inline-flex items-center gap-1.5 rounded-full bg-[#e8f1fa] px-2.5 py-0.5 text-xs font-semibold text-[#145a93]" data-synced-from="{{ $partner }}">
    <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 01-15.5 6.2L3 16M3 12a9 9 0 0115.5-6.2L21 8M21 3v5h-5M3 21v-5h5" /></svg>
    Synced from {{ $partner }}
</span>
