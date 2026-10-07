<?php

use App\Enums\DocumentStatus;
use App\Enums\IntakeSubmissionStatus;
use App\Models\IntakeSubmission;
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

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function submissions(): LengthAwarePaginator
    {
        return IntakeSubmission::query()
            ->where('status', '!=', IntakeSubmissionStatus::Draft)
            ->with([
                'order.package',
                'order.user.practice',
                // A document can be sitting ready for review without the submission's own status
                // changing at all — e.g. the client sent one more document for AI review from
                // the Dashboard well after this submission was already approved — so this can't
                // be inferred from $submission->status alone; it needs its own indicator below.
                'order.generatedDocuments' => fn ($q) => $q->where('status', DocumentStatus::Completed)
                    ->whereNull('reviewed_at')
                    ->where('is_stale', false),
            ])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', function ($q) {
                $search = $this->search;
                $q->whereHas('order.user.practice', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('order.user', fn ($q) => $q->where('email', 'like', "%{$search}%"));
            })
            ->latest('submitted_at')
            ->paginate(10);
    }

    #[Computed]
    public function statusCounts(): array
    {
        return IntakeSubmission::query()
            ->where('status', '!=', IntakeSubmissionStatus::Draft)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2 justify-between">
        <h1 class="text-2xl font-bold text-navy">Submissions</h1>
        <div class="flex flex-wrap items-center gap-2">
        @foreach([
            'all' => 'All',
            IntakeSubmissionStatus::Submitted->value => 'Submitted',
            IntakeSubmissionStatus::UnderReview->value => 'Under Review',
            IntakeSubmissionStatus::Approved->value => 'Approved',
            IntakeSubmissionStatus::Rejected->value => 'Rejected',
        ] as $value => $label)
            @php $count = $value === 'all' ? array_sum($this->statusCounts) : ($this->statusCounts[$value] ?? 0); @endphp
            <button type="button" wire:click="$set('status', '{{ $value }}')" wire:target="$set('status', '{{ $value }}')"
                wire:loading.attr="disabled" wire:target="$set('status', '{{ $value }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold transition-colors {{ $status === $value ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted hover:border-navy/40' }}">
                <span wire:loading.remove wire:target="$set('status', '{{ $value }}')" class="inline-flex items-center gap-1.5">
                    {{ $label }}
                    <span class="{{ $status === $value ? 'text-white/70' : 'text-empower-muted/70' }}">{{ $count }}</span>
                </span>
                <span wire:loading wire:target="$set('status', '{{ $value }}')"><x-spinner class="h-3 w-3" /></span>
            </button>
        @endforeach

        <input wire:model.live.debounce.400ms="search" type="text" placeholder="Search practice or email…"
            class="w-full sm:w-64 rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
        </div>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[800px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Practice</th>
                    <th class="px-5 py-3">Package</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Submitted</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->submissions as $submission)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-semibold text-navy">{{ $submission->order?->user?->practice?->name ?: 'Unnamed practice' }}</div>
                            <div class="text-xs text-empower-muted">{{ $submission->order?->user?->email }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $submission->order?->package?->name }}</td>
                        <td class="px-5 py-3.5">
                            @php
                                $badgeClasses = match($submission->status) {
                                    IntakeSubmissionStatus::Approved => 'bg-[#dff7f0] text-[#0f7a4f]',
                                    IntakeSubmissionStatus::Rejected => 'bg-[#fde2e2] text-[#a53b3b]',
                                    IntakeSubmissionStatus::UnderReview => 'bg-[#fff3cd] text-[#9a6700]',
                                    default => 'bg-[#eef6fb] text-empower-muted',
                                };
                                $pendingDocumentCount = $submission->order?->generatedDocuments->count() ?? 0;
                            @endphp
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider {{ $badgeClasses }}">
                                    {{ str_replace('_', ' ', $submission->status->value) }}
                                </span>
                                @if($pendingDocumentCount > 0)
                                <span title="{{ $pendingDocumentCount }} document(s) generated and waiting on your review"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[0.65rem] font-extrabold uppercase tracking-wider bg-[#fff3cd] text-[#9a6700]">
                                    <span class="h-1.5 w-1.5 rounded-full bg-[#9a6700]"></span>
                                    {{ $pendingDocumentCount }} to review
                                </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-muted text-xs">{{ $submission->submitted_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-right">
                            <a href="{{ route('admin.submissions.show', $submission) }}" wire:navigate
                                class="text-xs font-bold text-[#0b9ed0] hover:underline">Review &rarr;</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-empower-muted italic">No submissions match this filter.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    <div>{{ $this->submissions->links() }}</div>
</div>
