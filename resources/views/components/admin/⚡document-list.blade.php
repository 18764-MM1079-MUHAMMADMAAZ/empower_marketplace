<?php

use App\Enums\DocumentStatus;
use App\Jobs\GenerateComplianceDocument;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        return GeneratedDocument::query()
            ->with(['order.package', 'order.user.practice', 'order.intakeSubmission', 'oshaLocation'])
            ->when($this->status === 'stale', fn ($q) => $q->where('is_stale', true))
            ->when($this->status !== 'all' && $this->status !== 'stale', fn ($q) => $q->where('status', $this->status))
            ->latest('generated_at')
            ->paginate(10);
    }

    public function regenerate(int $documentId): void
    {
        $document = GeneratedDocument::with('order')->findOrFail($documentId);

        GenerateComplianceDocument::dispatch($document->order, $document->document_type, $document->oshaLocation);

        ActivityLog::record(
            'document.regenerate_requested',
            "Regeneration requested for {$document->document_type->label()} (order #{$document->order_id}).",
            user: auth()->user(),
            order: $document->order,
            subject: $document,
        );

        unset($this->documents);

        $this->dispatch('toast', message: "{$document->document_type->label()} regeneration requested.", type: 'success');
    }
};
?>

<div class="space-y-4" x-data="{ confirmId: null }">
    <div class="flex flex-wrap items-center gap-2 justify-between">
        <h1 data-tour="page-title" class="text-2xl font-bold text-navy">Documents</h1>
        <div data-tour="filter" class="flex flex-wrap items-center gap-2">
        @foreach([
            'all' => 'All',
            DocumentStatus::Completed->value => 'Completed',
            DocumentStatus::Generating->value => 'Generating',
            DocumentStatus::Failed->value => 'Failed',
            'stale' => 'Stale',
        ] as $value => $label)
            <button type="button" wire:click="$set('status', '{{ $value }}')" wire:target="$set('status', '{{ $value }}')"
                wire:loading.attr="disabled" wire:target="$set('status', '{{ $value }}')"
                class="inline-flex items-center gap-1 rounded-full px-3.5 py-1.5 text-xs font-bold transition-colors {{ $status === $value ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted hover:border-navy/40' }}">
                <span wire:loading.remove wire:target="$set('status', '{{ $value }}')">{{ $label }}</span>
                <span wire:loading wire:target="$set('status', '{{ $value }}')"><x-spinner class="h-3 w-3" /></span>
            </button>
        @endforeach
        </div>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table data-tour="table" class="w-full min-w-[780px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Document</th>
                    <th class="px-5 py-3">Practice</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Generated</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->documents as $doc)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-semibold text-navy">{{ $doc->document_type->label() }}</div>
                            @if($doc->oshaLocation)
                                <div class="text-xs text-empower-muted">{{ $doc->oshaLocation->name }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $doc->order?->user?->practice?->name ?: '—' }}</td>
                        <td class="px-5 py-3.5">
                            @php
                                $badgeLabel = match(true) {
                                    $doc->is_stale => 'Stale',
                                    $doc->isApproved() => 'Approved',
                                    $doc->status === DocumentStatus::Completed => 'Pending Review',
                                    default => $doc->status->value,
                                };
                                $badgeClasses = match(true) {
                                    $doc->is_stale => 'bg-[#fde2e2] text-[#a53b3b]',
                                    $doc->isApproved() => 'bg-[#dff7f0] text-[#0f7a4f]',
                                    $doc->status === DocumentStatus::Completed => 'bg-[#eef6fb] text-empower-muted',
                                    $doc->status === DocumentStatus::Failed => 'bg-[#fde2e2] text-[#a53b3b]',
                                    default => 'bg-[#fff3cd] text-[#9a6700]',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider {{ $badgeClasses }}">
                                {{ $badgeLabel }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-empower-muted text-xs">{{ $doc->generated_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-right">
                            @if($doc->order?->intakeSubmission)
                                <a href="{{ route('admin.submissions.show', $doc->order->intakeSubmission) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline mr-3">Review</a>
                            @endif
                            <button type="button" x-on:click="confirmId = {{ $doc->id }}"
                                class="text-xs font-bold text-[#0b9ed0] hover:underline">Regenerate</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-empower-muted italic">No documents match this filter.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    <div>{{ $this->documents->links() }}</div>

    <div x-show="confirmId !== null" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmId = null">
            <h3 class="text-base font-semibold text-navy mb-2">Regenerate this document?</h3>
            <p class="text-sm text-empower-muted mb-5">This creates a new version of the document using the practice's latest details.</p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmId = null"
                    class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                    Cancel
                </button>
                <button type="button" wire:target="regenerate"
                    x-on:click="$wire.regenerate(confirmId).then(() => confirmId = null).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="regenerate"
                    class="inline-flex items-center gap-1 rounded px-5 py-2 text-sm font-bold transition-colors bg-accent text-navy-dark hover:bg-accent-dark">
                    <span wire:loading.remove wire:target="regenerate">Regenerate</span>
                    <span wire:loading.inline-flex wire:target="regenerate" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Regenerating…</span>
                </button>
            </div>
        </div>
    </div>
</div>
