<?php

use App\Enums\IntakeSubmissionStatus;
use App\Services\ClientAccount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** My Account › Documents (v5): count tiles, then every document with Approved / In Review /
 *  Reference. Reviewed uploads are rows of their own; the intake answers are the last row. */
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
};
?>

@php
$account = $this->account;
$documents = $account->documents();
$multipleOrders = $documents->pluck('order_id')->unique()->count() > 1;
$submissions = $account->orders()
    ->map->intakeSubmission
    ->filter(fn ($s) => $s && $s->submitted_at && $s->status !== IntakeSubmissionStatus::Draft)
    ->unique('id');
$approved = $documents->filter->isReady();
$inReview = $documents->reject->isReady();
$tile = 'flex flex-col gap-2 rounded-xl border p-4';
$count = 'text-center text-[2rem] font-bold leading-none tracking-tight text-[#0f2a44]';
$iconBox = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg';
$downloadBtn = 'inline-flex h-8 items-center rounded-lg border border-[#cbd5e1] bg-white px-3 text-xs font-semibold text-[#173045] hover:bg-[#f4f7fb] transition-colors';
@endphp

<section id="documents" data-account-section aria-labelledby="h-documents"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <h2 id="h-documents" class="text-lg font-semibold text-[#12304f]">Documents</h2>
    <p class="text-sm text-[#5d6e7f] mt-0.5">Your compliance manuals, reviewed uploads and intake record.</p>

    <ul aria-label="Document status" class="mt-4.5 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <li class="{{ $tile }} border-[#f3d9a4] bg-[#fff8eb]" data-doc-tile="in-review">
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-[#fdf0d8] text-[#7a4a00]" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg></span>
                <span class="text-[13px] font-semibold text-[#5c3a00]">In Review</span>
            </div>
            <p class="{{ $count }}">{{ $inReview->count() }}</p>
            <p class="text-xs text-[#5c3a00]">{{ $inReview->isEmpty() ? 'Nothing waiting on review' : $inReview->map(fn ($d) => $d->document_type->label())->unique()->implode(', ') }}</p>
        </li>
        <li class="{{ $tile }} border-[#e3e8ef] bg-white" data-doc-tile="approved">
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-[#e7f5ee] text-[#146c43]" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" /></svg></span>
                <span class="text-[13px] font-semibold text-[#146c43]">Approved</span>
            </div>
            <p class="{{ $count }}">{{ $approved->count() }}</p>
            <p class="text-xs text-[#5d6e7f]">Ready to download</p>
        </li>
        <li class="{{ $tile }} border-[#e3e8ef] bg-[#f8fafc]" data-doc-tile="reference">
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-[#eef2f6] text-[#334155]" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 016.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" /></svg></span>
                <span class="text-[13px] font-semibold text-[#334155]">Reference</span>
            </div>
            <p class="{{ $count }}">{{ $submissions->count() }}</p>
            <p class="text-xs text-[#5d6e7f]">Intake answers · for your records</p>
        </li>
        <li class="{{ $tile }} border-[#e3e8ef] bg-white" data-doc-tile="all">
            <div class="flex items-center gap-2.5">
                <span class="{{ $iconBox }} bg-[#e8f1fa] text-[#145a93]" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6" /></svg></span>
                <span class="text-[13px] font-semibold text-[#145a93]">All Documents</span>
            </div>
            <p class="{{ $count }}">{{ $documents->count() + $submissions->count() }}</p>
            <p class="text-xs text-[#5d6e7f]">Listed in the table below</p>
        </li>
    </ul>

    <div class="mt-4.5 overflow-x-auto rounded-xl border border-[#e3e8ef]">
        <table class="w-full min-w-xl text-sm">
            <thead class="bg-[#f8fafc] text-left text-[11px] font-bold uppercase tracking-wider text-[#5d6e7f]">
                <tr>
                    <th class="px-4 py-2.5">Document</th>
                    <th class="px-4 py-2.5 w-28">Status</th>
                    <th class="px-4 py-2.5 w-32">Updated</th>
                    <th class="px-4 py-2.5 w-32 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#eef2f6]">
                @foreach($documents as $document)
                @php
                $ready = $document->isReady();
                $upload = $document->intakeUpload;
                $detail = $upload
                    ? 'Your upload · '.$upload->original_filename.' · '.$upload->fileSizeForHumans()
                    : collect([$document->oshaLocation?->name, $multipleOrders ? $document->order->package?->name : null])->filter()->implode(' · ');
                if (! $ready && ! $upload) {
                    $detail = trim($detail.($detail ? ' · ' : '').'Available once the compliance team finishes review');
                }
                @endphp
                <tr wire:key="doc-{{ $document->id }}" data-document-row="{{ $document->id }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="flex h-8.5 w-8.5 shrink-0 items-center justify-center rounded-lg bg-[#f1f5f9] text-[#5d6e7f]" aria-hidden="true">
                                @if($upload)
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12" /></svg>
                                @else
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6" /></svg>
                                @endif
                            </span>
                            <div class="min-w-0">
                                <p class="font-medium text-[#173045]">{{ $document->document_type->label() }}</p>
                                @if($detail)<p class="text-xs text-[#5d6e7f] truncate" title="{{ $detail }}">{{ $detail }}</p>@endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap {{ $ready ? 'bg-[#e7f5ee] text-[#146c43]' : 'bg-[#fdf0d8] text-[#7a4a00]' }}">{{ $ready ? 'Approved' : 'In Review' }}</span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-[13px] text-[#5d6e7f]">{{ ($document->generated_at ?? $document->updated_at)?->format('M j, Y') }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if($ready)
                        <a href="{{ route('documents.download', $document) }}" class="{{ $downloadBtn }}">Download</a>
                        @else
                        <span class="text-xs text-[#5d6e7f]">Not ready yet</span>
                        @endif
                    </td>
                </tr>
                @endforeach

                @foreach($submissions as $submission)
                <tr wire:key="answers-{{ $submission->id }}" data-intake-answers-row>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-8.5 w-8.5 shrink-0 items-center justify-center rounded-lg bg-[#f1f5f9] text-[#5d6e7f]" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" /></svg></span>
                            <div>
                                <p class="font-medium text-[#173045]">Intake answers</p>
                                <p class="text-xs text-[#5d6e7f]">Submitted{{ $submission->certified_at ? ' and certified' : '' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3"><span class="rounded-full bg-[#eef2f6] px-2.5 py-0.5 text-xs font-semibold text-[#334155]">Reference</span></td>
                    <td class="px-4 py-3 whitespace-nowrap text-[13px] text-[#5d6e7f]">{{ $submission->submitted_at->format('M j, Y') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('intake-submissions.answers', $submission) }}" class="{{ $downloadBtn }}">Download</a>
                    </td>
                </tr>
                @endforeach

                @if($documents->isEmpty() && $submissions->isEmpty())
                <tr>
                    <td colspan="4" class="px-4 py-4 text-sm italic text-[#5d6e7f]">Your documents appear here once your intake has been reviewed.</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>
</section>
