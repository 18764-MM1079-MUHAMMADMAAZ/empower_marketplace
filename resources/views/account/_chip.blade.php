{{-- Status badge for My Account. Tones: good, warn, bad, info, muted. --}}
@php
$chipTone = match ($tone ?? 'muted') {
    'good' => 'bg-[#e6f6ef] text-[#1f9d6b]',
    'warn' => 'bg-[#fdf3e0] text-[#b7791f]',
    'bad' => 'bg-[#fdecea] text-[#c2362b]',
    'info' => 'bg-[#e7f5fb] text-[#0a6f96]',
    default => 'bg-[#edf2f7] text-[#5d6e7f]',
};
@endphp
<span class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold {{ $chipTone }}">{{ $label }}</span>
