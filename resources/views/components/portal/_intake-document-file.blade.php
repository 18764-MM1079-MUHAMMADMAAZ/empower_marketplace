{{--
    One saved document on the intake's documents screen: name, size, a type button and remove.
    Expects: $doc (IntakeUpload), $action ('Change type' inside a checklist row, 'Choose type' under
    "Needs a type").
--}}
<li wire:key="doc-file-{{ $doc->id }}" data-document-file="{{ $doc->id }}"
    class="flex items-center gap-2.5 rounded-lg border border-[#e6edf4] bg-white px-2.5 py-1.5">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="shrink-0 text-[#8592a1]" aria-hidden="true">
        <path d="M6 2h9l5 5v15H6V2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
        <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
    </svg>
    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm font-medium text-[#173045]" title="{{ $doc->original_filename }}">{{ $doc->original_filename }}</span>
        <span class="block text-[11px] text-[#8592a1]">{{ $doc->fileSizeForHumans() }}</span>
    </span>
    <button type="button" wire:click="openDocumentTypeDialog({{ $doc->id }})"
        class="shrink-0 text-xs font-semibold transition-colors {{ $action === 'Choose type'
            ? 'rounded-md bg-[#12304f] px-2.5 py-1 text-white hover:bg-[#0c233b]'
            : 'text-[#1a7aad] hover:underline' }}">{{ $action }}</button>
    <button type="button" wire:click="deleteExistingDocument({{ $doc->id }})" wire:confirm="Remove this document?"
        aria-label="Remove {{ $doc->original_filename }}"
        class="shrink-0 inline-flex h-7 w-7 items-center justify-center rounded-md border border-[#dbe4ee] text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
    </button>
</li>
