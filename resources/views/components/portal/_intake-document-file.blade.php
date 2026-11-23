{{--
    One saved document on the intake's documents screen: name, size, a type button and remove.
    Expects: $doc (IntakeUpload), $action ('Change type' inside a checklist row, 'Choose type' under
    "Needs a type"). Optional $compact: a small chip shown on the same line as its type's name.
--}}
@php
$compact = $compact ?? false;
$isPdf = strtolower(pathinfo((string) $doc->original_filename, PATHINFO_EXTENSION)) === 'pdf';
@endphp
<li wire:key="doc-file-{{ $doc->id }}" data-document-file="{{ $doc->id }}"
    class="flex items-center rounded-lg border border-[#e6edf4] bg-[#f6f9fc] {{ $compact ? 'w-full min-w-0 gap-1.5 py-1 pl-2 pr-1' : 'gap-2.5 px-2.5 py-1.5' }}">
    {{-- File icon matching the type: red for PDF, blue for Word (.docx). --}}
    <svg width="{{ $compact ? 18 : 20 }}" height="{{ $compact ? 18 : 20 }}" viewBox="0 0 24 24" fill="none" aria-hidden="true"
        class="shrink-0 {{ $isPdf ? 'text-[#d93025]' : 'text-[#2b579a]' }}" data-file-icon="{{ $isPdf ? 'pdf' : 'docx' }}">
        <path d="M6 2h9l5 5v15H6V2z" fill="#ffffff" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
        <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
        <rect x="2" y="11.5" width="15" height="7.5" rx="1.5" fill="currentColor" />
        <text x="9.5" y="17.2" text-anchor="middle" fill="#ffffff" font-size="5.6" font-weight="800"
            font-family="Arial, Helvetica, sans-serif">{{ $isPdf ? 'PDF' : 'DOC' }}</text>
    </svg>
    @if($compact)
    <span class="min-w-0 flex-1 truncate text-xs font-medium text-[#173045]" title="{{ $doc->original_filename }} ({{ $doc->fileSizeForHumans() }})">{{ $doc->original_filename }}</span>
    @else
    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm font-medium text-[#173045]" title="{{ $doc->original_filename }}">{{ $doc->original_filename }}</span>
        <span class="block text-[11px] text-[#8592a1]">{{ $doc->fileSizeForHumans() }}</span>
    </span>
    @endif
    <button type="button" wire:click="openDocumentTypeDialog({{ $doc->id }})"
        class="shrink-0 text-xs font-semibold transition-colors {{ $action === 'Choose type'
            ? 'rounded-md bg-[#12304f] px-2.5 py-1 text-white hover:bg-[#0c233b]'
            : 'text-[#1a7aad] hover:underline' }}">{{ $action }}</button>
    {{-- Asks first, in the "Remove this document?" popup. --}}
    <button type="button" wire:click="askToRemoveDocument({{ $doc->id }})" data-remove-document="{{ $doc->id }}"
        aria-label="Remove {{ $doc->original_filename }}" title="Remove"
        class="shrink-0 inline-flex items-center justify-center rounded-md text-[#d93025] hover:bg-[#fdecea] hover:text-[#b3261e] transition-colors {{ $compact ? 'h-6 w-6' : 'h-7 w-7' }}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>
</li>
