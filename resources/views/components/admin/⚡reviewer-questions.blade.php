<?php

use App\Mail\ClientReviewerQuestionMail;
use App\Models\ActivityLog;
use App\Models\IntakeSubmission;
use App\Models\ReviewerQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $submissionId;

    public string $questionInput = '';

    #[Computed]
    public function submission(): IntakeSubmission
    {
        return IntakeSubmission::with('order.user')->findOrFail($this->submissionId);
    }

    /** @return Collection<int, ReviewerQuestion> */
    #[Computed]
    public function questions(): Collection
    {
        return $this->submission->reviewerQuestions()->get();
    }

    public function ask(): void
    {
        $this->validate(['questionInput' => 'required|string|max:2000|not_regex:/[<>]/'], [
            'questionInput.not_regex' => 'Please remove the < and > characters.',
        ]);

        $submission = $this->submission;

        $question = $submission->reviewerQuestions()->create([
            'asked_by' => auth()->id(),
            'question' => trim(strip_tags($this->questionInput)),
        ]);

        ActivityLog::record(
            'submission.reviewer_question_asked',
            "Reviewer asked a question on order #{$submission->order_id}.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        try {
            Mail::to($submission->order->user->email)->send(new ClientReviewerQuestionMail($question));
        } catch (\Throwable $e) {
            report($e);
        }

        $this->questionInput = '';
        unset($this->questions);

        $this->dispatch('toast', message: 'Question sent to the client.', type: 'success');
    }
};
?>

{{-- Polls so a client's reply shows up here without a page refresh, like the client side. --}}
<div wire:poll.5s class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
    @php $waiting = $this->questions->contains(fn ($q) => ! $q->isAnswered()); @endphp

    <div class="flex items-center justify-between gap-3 mb-3">
        <h3 class="text-sm font-semibold text-navy">Questions for the Client</h3>
        @if($this->questions->isNotEmpty())
            <span class="inline-flex rounded-full px-2.5 py-0.5 text-[0.68rem] font-extrabold uppercase tracking-wider {{ $waiting ? 'bg-[#fff3cd] text-[#9a6700]' : 'bg-[#dff7f0] text-[#0f7a4f]' }}">
                {{ $waiting ? 'Waiting on client' : 'All answered' }}
            </span>
        @endif
    </div>

    @if($this->questions->isNotEmpty())
        <div class="space-y-3 mb-4 max-h-96 overflow-y-auto pr-1">
            @foreach($this->questions as $question)
                <div wire:key="rq-{{ $question->id }}" class="rounded-xl border px-4 py-3 text-sm {{ $question->isAnswered() ? 'border-empower-border bg-page' : 'border-amber-200 bg-amber-50' }}">
                    <p class="text-xs font-bold uppercase tracking-wide text-empower-muted mb-1">
                        Question &middot; {{ $question->created_at->format('M j, g:i A') }}@if($question->askedBy) &middot; {{ $question->askedBy->name }}@endif
                    </p>
                    <p class="text-empower-text">{{ $question->question }}</p>

                    @if($question->isAnswered())
                        <p class="mt-2 text-xs font-bold uppercase tracking-wide text-[#0f7a4f]">Client's reply &middot; {{ $question->replied_at?->format('M j, g:i A') }}</p>
                        <p class="text-empower-text">{{ $question->reply }}</p>
                    @else
                        <p class="mt-2 text-xs font-bold uppercase tracking-wide text-amber-700">Awaiting reply</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="mb-3">
        <textarea wire:model="questionInput" rows="2"
            placeholder="e.g. Is the HIPAA Privacy policy you uploaded the most recent version your staff use?"
            class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition resize-none"></textarea>
        @error('questionInput') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <button type="button" wire:click="ask" wire:target="ask" wire:loading.attr="disabled"
        class="inline-flex items-center gap-1 rounded-lg border border-empower-border px-5 py-2 text-sm font-bold text-navy hover:bg-page transition-colors">
        <span wire:loading.remove wire:target="ask">{{ $waiting ? 'Ask another question' : 'Send question' }}</span>
        <span wire:loading.inline-flex wire:target="ask" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Sending&hellip;</span>
    </button>
</div>
