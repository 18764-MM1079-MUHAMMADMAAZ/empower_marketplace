<?php

use App\Enums\IntakeSubmissionStatus;
use App\Models\GeneratedDocument;
use App\Services\ClientAccount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function account(): ClientAccount
    {
        return ClientAccount::for(auth()->user());
    }

    #[On('account-updated')]
    public function refreshAccount(): void
    {
        unset($this->account);
    }

    public function regenerate(int $documentId): void
    {
        $document = $this->account->documents()->firstWhere('id', $documentId);

        abort_unless($document instanceof GeneratedDocument, 404);

        if (! $this->account->regenerate($document)) {
            $this->dispatch('toast', message: "This document can't be regenerated right now.", type: 'error');

            return;
        }

        $this->dispatch('toast', message: "{$document->document_type->label()} queued to regenerate. Our team reviews it before you can download it.", type: 'success');
        $this->dispatch('account-updated');
    }

    public function regenerateAll(): void
    {
        $count = $this->account->staleDocuments()
            ->filter(fn ($document) => $this->account->regenerate($document))
            ->count();

        $this->dispatch('toast', message: $count > 0
            ? "{$count} ".str('document')->plural($count).' queued to regenerate. Our team reviews them before you can download them.'
            : 'There are no documents to regenerate.', type: $count > 0 ? 'success' : 'error');
        $this->dispatch('account-updated');
    }
};
?>

@php
$account = $this->account;
$documents = $account->documents();
$staleCount = $account->staleDocuments()->count();
$multipleOrders = $documents->pluck('order_id')->unique()->count() > 1;
$submissions = $account->orders()
    ->map->intakeSubmission
    ->filter(fn ($s) => $s && $s->submitted_at && $s->status !== IntakeSubmissionStatus::Draft)
    ->unique('id');
$uploads = $account->orders()->map->intakeSubmission->filter()->unique('id')->flatMap->intakeUploads;
@endphp

<section id="documents" data-account-section aria-labelledby="h-documents"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
            <h2 id="h-documents" class="text-lg font-semibold text-[#12304f]">Documents</h2>
            <p class="text-sm text-[#5d6e7f]">Your generated manuals and the files you uploaded during intake.</p>
        </div>
        @if($staleCount > 0)
        <button type="button" wire:click="regenerateAll" wire:loading.attr="disabled" wire:target="regenerateAll" data-regenerate-all
            class="inline-flex items-center gap-1.5 rounded-lg bg-[#12304f] px-3.5 py-1.5 text-xs font-bold text-white hover:bg-[#0c233b] transition-colors">
            <span wire:loading.remove wire:target="regenerateAll">Regenerate {{ $staleCount }} {{ Str::plural('document', $staleCount) }}</span>
            <span wire:loading.inline-flex wire:target="regenerateAll" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Queueing…</span>
        </button>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-[#eef2f6]">
        <table class="w-full min-w-xl text-sm">
            <thead class="bg-[#f8fbfd] text-left text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1]">
                <tr>
                    <th class="px-4 py-2.5">Document</th>
                    <th class="px-4 py-2.5">Status</th>
                    <th class="px-4 py-2.5">Version</th>
                    <th class="px-4 py-2.5">Updated</th>
                    <th class="px-4 py-2.5 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#eef2f6]">
                @foreach($documents as $document)
                @php
                [$statusLabel, $statusTone] = $document->clientStatus();
                $detail = collect([
                    $document->oshaLocation?->name,
                    $document->intakeUpload?->original_filename,
                    $multipleOrders ? $document->order->package?->name : null,
                ])->filter()->implode(' · ');
                @endphp
                <tr wire:key="doc-{{ $document->id }}" data-document-row="{{ $document->id }}">
                    <td class="px-4 py-3">
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 mt-0.5 shrink-0 text-[#8592a1]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linejoin="round" stroke-width="1.8" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5" /></svg>
                            <div>
                                <p class="font-semibold text-[#173045]">{{ $document->document_type->label() }}</p>
                                @if($detail)<p class="text-xs text-[#5d6e7f]">{{ $detail }}</p>@endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">@include('account._chip', ['label' => $statusLabel, 'tone' => $statusTone])</td>
                    <td class="px-4 py-3 text-[#173045]">v{{ $document->version ?? 1 }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-[#173045]">{{ ($document->generated_at ?? $document->updated_at)?->format('M j, Y') }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if($document->canBeRegeneratedByClient())
                        <button type="button" wire:click="regenerate({{ $document->id }})" wire:loading.attr="disabled" wire:target="regenerate({{ $document->id }})"
                            class="rounded-lg bg-[#12304f] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#0c233b] transition-colors">Regenerate</button>
                        @elseif($document->isReady())
                        <a href="{{ route('documents.download', $document) }}"
                            class="inline-block rounded-lg border border-[#dbe4ee] px-3 py-1.5 text-xs font-semibold text-[#173045] hover:bg-[#f4f7fb] transition-colors">Download</a>
                        @else
                        <span class="text-xs text-[#8592a1]">{{ $statusLabel === 'Generating' ? 'In progress' : 'Awaiting review' }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach

                @foreach($submissions as $submission)
                <tr wire:key="answers-{{ $submission->id }}" data-intake-answers-row>
                    <td class="px-4 py-3">
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 mt-0.5 shrink-0 text-[#8592a1]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linejoin="round" stroke-width="1.8" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5" /></svg>
                            <div>
                                <p class="font-semibold text-[#173045]">Your intake answers</p>
                                <p class="text-xs text-[#5d6e7f]">Submitted{{ $submission->certified_at ? ' and certified' : '' }} {{ $submission->submitted_at->format('M j, Y') }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">@include('account._chip', ['label' => 'Reference', 'tone' => 'muted'])</td>
                    <td class="px-4 py-3 text-[#8592a1]">—</td>
                    <td class="px-4 py-3 whitespace-nowrap text-[#173045]">{{ $submission->updated_at->format('M j, Y') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('intake-submissions.answers', $submission) }}"
                            class="inline-block rounded-lg border border-[#dbe4ee] px-3 py-1.5 text-xs font-semibold text-[#173045] hover:bg-[#f4f7fb] transition-colors">Download</a>
                    </td>
                </tr>
                @endforeach

                @if($documents->isEmpty() && $submissions->isEmpty())
                <tr>
                    <td colspan="5" class="px-4 py-4 text-sm italic text-[#5d6e7f]">Your documents appear here once your intake has been reviewed.</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if($uploads->isNotEmpty())
    <h3 class="text-base font-bold text-[#12304f] mt-6 mb-2">Files you uploaded</h3>
    <ul class="divide-y divide-[#eef2f6] border-y border-[#eef2f6] text-sm">
        @foreach($uploads as $upload)
        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2.5" data-upload-row>
            <span class="flex items-center gap-2 font-semibold text-[#173045] min-w-0">
                <svg class="h-4 w-4 shrink-0 text-[#8592a1]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linejoin="round" stroke-width="1.8" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5" /></svg>
                <span class="truncate">{{ $upload->original_filename }}</span>
            </span>
            <span class="text-xs text-[#5d6e7f]">{{ collect([ucfirst((string) $upload->upload_type?->promptLabel()),$upload->fileSizeForHumans()])->filter()->implode(' · ') }}</span>
        </li>
        @endforeach
    </ul>
    @endif
</section>
