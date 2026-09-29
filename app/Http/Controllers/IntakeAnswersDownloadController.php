<?php

namespace App\Http\Controllers;

use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\Practice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IntakeAnswersDownloadController extends Controller
{
    // Mirrors <livewire:portal.practice-intake-wizard>'s DOCUMENT_CATEGORIES / ⚡portal.blade.php's
    // REVIEW_DOCUMENT_CATEGORIES — kept in sync by hand since this is a plain download, not a
    // component that can share the Volt class's private const.
    private const DOCUMENT_CATEGORIES = [
        'compliance_ethics' => 'Compliance & Ethics Program',
        'hipaa_privacy' => 'HIPAA Privacy policies',
        'hipaa_security' => 'HIPAA Security policies',
        'training_materials' => 'Training materials',
    ];

    public function show(Request $request, IntakeSubmission $submission): Response
    {
        $submission->loadMissing([
            'order.user.practice',
            'order.package',
            'intakeUploads',
            'intakeAnswers.question.section',
        ]);

        if ($submission->order?->user_id !== $request->user()->id) {
            abort(403);
        }

        $practice = $submission->order->user->practice;
        $practiceName = $practice?->name ?: 'Practice';

        $lines = ["Practice Intake Answers \u{2014} {$practiceName}", 'Generated '.now()->format('F j, Y'), ''];

        $this->appendBasics($lines, $practice);
        $this->appendDocuments($lines, $submission);

        $includesWorkflowQuestionnaire = (bool) $submission->order->package?->includesWorkflowQuestionnaire();

        if ($includesWorkflowQuestionnaire) {
            $this->appendTeam($lines, $practice);
            $this->appendWorkflowAnswers($lines, $submission);
        }

        $this->appendCertification($lines, $submission);

        $filename = 'intake-answers-'.$submission->id.'.txt';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @param  array<int, string>  $lines */
    private function heading(array &$lines, string $label): void
    {
        $lines[] = strtoupper($label);
        $lines[] = str_repeat('-', strlen($label));
    }

    /** @param  array<int, string>  $lines */
    private function appendBasics(array &$lines, ?Practice $practice): void
    {
        $this->heading($lines, 'Practice basics');

        $providers = $practice?->billable_providers_count ?? 1;

        $lines[] = "Let's start with your practice";
        $lines[] = collect([$practice?->name, $practice?->specialty])->filter()->implode(' · ') ?: '(not yet provided)';
        $lines[] = '';

        $lines[] = 'How many billable providers do you have?';
        $lines[] = $providers.' billable provider'.($providers === 1 ? '' : 's');
        $lines[] = '';

        $lines[] = 'Where is your practice located?';
        $lines[] = $practice?->address ?: '(not yet provided)';
        $lines[] = '';

        $lines[] = 'Practice logo';
        $lines[] = $practice?->logo_path ? 'Logo uploaded' : 'No logo added';
        $lines[] = '';
    }

    /** @param  array<int, string>  $lines */
    private function appendDocuments(array &$lines, IntakeSubmission $submission): void
    {
        $this->heading($lines, 'Uploaded documents');

        $uploads = $submission->intakeUploads;
        $missing = $submission->wizard_missing_document_categories ?? [];

        foreach (self::DOCUMENT_CATEGORIES as $key => $label) {
            $filesForCategory = $uploads->where('document_category', $key);

            $lines[] = $label;
            $lines[] = match (true) {
                $filesForCategory->isNotEmpty() => 'Uploaded: '.$filesForCategory->pluck('original_filename')->implode(', '),
                in_array($key, $missing, true) => "Marked \"I don't have this.\"",
                default => 'Not yet provided.',
            };
            $lines[] = '';
        }

        $otherUploads = $uploads->reject(fn ($u) => array_key_exists($u->document_category, self::DOCUMENT_CATEGORIES));

        if ($otherUploads->isNotEmpty()) {
            $lines[] = 'Other uploaded files';
            $lines[] = $otherUploads->pluck('original_filename')->implode(', ');
            $lines[] = '';
        }
    }

    /** @param  array<int, string>  $lines */
    private function appendTeam(array &$lines, ?Practice $practice): void
    {
        $this->heading($lines, 'Your team');

        $officers = [
            ['Compliance Officer', $practice?->compliance_officer_name],
            ['HIPAA Privacy Officer', $practice?->hipaa_privacy_officer_name],
            ['HIPAA Security Officer', $practice?->hipaa_security_officer_name],
            ['Release of Information Officer', $practice?->release_of_info_officer_name],
            ['IT Vendor', $practice?->it_vendor_name],
        ];

        foreach ($officers as [$label, $value]) {
            $lines[] = $label;
            $lines[] = $value ?: '(not yet provided)';
            $lines[] = '';
        }
    }

    /** @param  array<int, string>  $lines */
    private function appendWorkflowAnswers(array &$lines, IntakeSubmission $submission): void
    {
        $answersByQuestionId = $submission->intakeAnswers->keyBy('intake_question_id');

        foreach (IntakeSection::with('questions')->orderBy('sort_order')->get() as $section) {
            if ($section->questions->isEmpty()) {
                continue;
            }

            $this->heading($lines, $section->label);

            foreach ($section->questions as $question) {
                $answer = $answersByQuestionId->get($question->id);

                $lines[] = $question->title;
                $lines[] = match (true) {
                    $answer === null => 'Not yet answered.',
                    ! $answer->has_documented_process => 'No documented process — best-practice language used.',
                    default => $answer->response ?: '(no response provided)',
                };
                $lines[] = '';
            }
        }
    }

    /** @param  array<int, string>  $lines */
    private function appendCertification(array &$lines, IntakeSubmission $submission): void
    {
        if (! $submission->certified_by_name && ! $submission->certified_at) {
            return;
        }

        $this->heading($lines, 'Certification');

        $lines[] = 'Certified by';
        $lines[] = trim($submission->certified_by_name.($submission->certified_by_title ? ', '.$submission->certified_by_title : ''));
        $lines[] = '';

        $lines[] = 'Signature';
        $lines[] = $submission->certified_signature ?: '(not provided)';
        $lines[] = '';

        $lines[] = 'Date';
        $lines[] = $submission->certified_at?->format('F j, Y') ?? '(not provided)';
        $lines[] = '';
    }
}
