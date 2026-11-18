<?php

use App\Enums\IntakeSubmissionStatus;
use App\Models\IntakeSubmission;
use App\Services\ClientAccount;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function submission(): ?IntakeSubmission
    {
        return ClientAccount::for(auth()->user())->primaryOrder()?->intakeSubmission;
    }

    /**
     * The five steps of the review timeline. state: done | current | todo | bad.
     *
     * @return list<array{title: string, when: ?string, state: string}>
     */
    #[Computed]
    public function steps(): array
    {
        $s = $this->submission;
        $status = $s->status;
        $submitted = $s->submitted_at !== null && $status !== IntakeSubmissionStatus::Draft;
        $decided = in_array($status, [IntakeSubmissionStatus::Approved, IntakeSubmissionStatus::Rejected], true);
        $day = fn ($date) => $date?->format('M j');

        $processed = $submitted && ($decided || $s->under_review_started_at || $s->allUploadsProcessed());
        $processedAt = $s->intakeUploads->pluck('processed_at')->filter()->max() ?? $s->submitted_at;

        $questions = $s->reviewerQuestions;
        $waiting = $questions->reject->isAnswered()->count();

        return [
            ['title' => 'Submitted', 'when' => $submitted ? $s->submitted_at->format('M j, g:i A') : 'Not submitted yet', 'state' => $submitted ? 'done' : 'current'],
            ['title' => 'Processed', 'when' => $processed ? $processedAt?->format('M j, g:i A') : ($submitted ? 'Reading your answers and files' : null), 'state' => $processed ? 'done' : ($submitted ? 'current' : 'todo')],
            [
                'title' => 'In review',
                'when' => match (true) {
                    $decided && $s->under_review_started_at && $s->reviewed_at => $day($s->under_review_started_at).' to '.$day($s->reviewed_at),
                    $s->under_review_started_at !== null => 'Since '.$day($s->under_review_started_at),
                    default => null,
                },
                'state' => $decided ? 'done' : ($status === IntakeSubmissionStatus::UnderReview ? 'current' : 'todo'),
            ],
            [
                'title' => 'Questions for you',
                'when' => match (true) {
                    $waiting > 0 => $waiting.' '.str('question')->plural($waiting).' waiting for your reply',
                    $questions->isNotEmpty() => $questions->count().' '.str('question')->plural($questions->count()).', answered '.$day($questions->max('replied_at')),
                    $decided => 'No questions',
                    default => 'None so far',
                },
                'state' => $waiting > 0 ? 'current' : ($questions->isNotEmpty() || $decided ? 'done' : 'todo'),
            ],
            [
                'title' => $status === IntakeSubmissionStatus::Rejected ? 'Changes requested' : 'Approved',
                'when' => $decided ? $s->reviewed_at?->format('M j, g:i A') : null,
                'state' => match ($status) {
                    IntakeSubmissionStatus::Approved => 'done',
                    IntakeSubmissionStatus::Rejected => 'bad',
                    default => 'todo',
                },
            ],
        ];
    }
};
?>

@php
$s = $this->submission;
[$chipLabel, $chipTone] = match ($s->status) {
    IntakeSubmissionStatus::Approved => ['Approved', 'good'],
    IntakeSubmissionStatus::Rejected => ['Changes requested', 'bad'],
    IntakeSubmissionStatus::UnderReview => ['In review', 'info'],
    IntakeSubmissionStatus::Submitted, IntakeSubmissionStatus::Pending => ['Submitted', 'info'],
    default => ['Not submitted', 'muted'],
};
// Same date the dashboard's "Schedule your annual policy review" step uses: a year after
// approval, moved off the weekend.
$nextReview = null;
if ($s->status === IntakeSubmissionStatus::Approved && $s->reviewed_at) {
    $nextReview = $s->reviewed_at->copy()->addYear();
    while ($nextReview->isWeekend()) {
        $nextReview->addDay();
    }
}
$waitingQuestions = $s->reviewerQuestions->reject->isAnswered()->count();
@endphp

<section id="review" data-account-section aria-labelledby="h-review"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
            <h2 id="h-review" class="text-lg font-semibold text-[#12304f]">Review status</h2>
            <p class="text-sm text-[#5d6e7f]">Where your intake submission stands with the Empower compliance team.</p>
        </div>
        @include('account._chip', ['label' => $chipLabel, 'tone' => $chipTone])
    </div>

    <ol class="grid gap-3 sm:grid-cols-5">
        @foreach($this->steps as $step)
        @php
        $dot = match ($step['state']) {
            'done' => 'bg-[#1f9d6b] text-white border-[#1f9d6b]',
            'current' => 'bg-white text-[#0b9ed0] border-[#0b9ed0]',
            'bad' => 'bg-[#c2362b] text-white border-[#c2362b]',
            default => 'bg-white text-[#8592a1] border-[#dbe4ee]',
        };
        @endphp
        <li class="flex sm:block items-start gap-3" data-review-step="{{ $step['state'] }}">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 {{ $dot }}">
                @if($step['state'] === 'done')
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                @elseif($step['state'] === 'bad')
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="3" d="M12 7v6m0 4h.01" /></svg>
                @elseif($step['state'] === 'current')
                <span class="h-2 w-2 rounded-full bg-[#0b9ed0]"></span>
                @endif
            </span>
            <div class="sm:mt-2">
                <p class="text-sm font-bold {{ $step['state'] === 'todo' ? 'text-[#8592a1]' : 'text-[#173045]' }}">{{ $step['title'] }}</p>
                @if($step['when'])<p class="text-xs text-[#5d6e7f] mt-0.5">{{ $step['when'] }}</p>@endif
            </div>
        </li>
        @endforeach
    </ol>

    @if($waitingQuestions > 0)
    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#bfe3f2] bg-[#e7f5fb] px-4 py-3 text-sm text-[#0a6f96]">
        <span>Our reviewer has {{ $waitingQuestions === 1 ? 'a question' : $waitingQuestions.' questions' }} for you.</span>
        <a href="{{ route('portal') }}" class="font-bold hover:underline">Answer in My Portal &rarr;</a>
    </div>
    @elseif($s->status === IntakeSubmissionStatus::Draft)
    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3 text-sm text-[#5d6e7f]">
        <span>Your intake hasn't been submitted yet.</span>
        <a href="{{ route('portal') }}" class="font-bold text-[#1a7aad] hover:underline">Continue your intake &rarr;</a>
    </div>
    @elseif($s->status === IntakeSubmissionStatus::Rejected)
    <div class="mt-5 rounded-xl border border-[#f3c5c0] bg-[#fdecea] px-4 py-3 text-sm text-[#9b2c22]">
        Our team asked for some changes{{ $s->reviewer_notes ? ': '.$s->reviewer_notes : '.' }}
        <a href="{{ route('portal') }}" class="font-bold hover:underline">Update your intake &rarr;</a>
    </div>
    @elseif($nextReview)
    <div class="mt-5 rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3 text-sm text-[#5d6e7f]">
        Your next annual policy review is due <strong class="text-[#173045]">{{ $nextReview->format('M j, Y') }}</strong>.
    </div>
    @endif
</section>
