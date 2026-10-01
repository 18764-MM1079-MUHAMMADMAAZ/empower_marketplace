<?php

namespace App\Services;

use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\Practice;
use Illuminate\Support\Collection;

class IntakeAnswersPdfGenerator
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

    /** Renders the submission's answers to a branded, paginated PDF and returns the raw bytes. */
    public function generate(IntakeSubmission $submission): string
    {
        $html = view('documents.intake-answers-pdf', $this->buildViewData($submission))->render();

        $pdf = new CompliancePdf('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setCreator(config('app.name'));
        $pdf->setPrintFooter(false);
        // CompliancePdf's Header() repeats this logo + title on every page — the same
        // box-fit-logo-then-title layout used for the compliance manuals.
        $pdf->headerTitle = config('app.name')." \u{2014} Practice Intake Answers";
        $pdf->headerLogoPath = public_path('images/logo-email.png');
        $pdf->setMargins(15, 26, 15);
        $pdf->setHeaderMargin(8);
        $pdf->setPrintHeader(true);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return (string) $pdf->Output('', 'S');
    }

    /** Exposed separately from generate() so tests can assert on the data a submission produces
     *  without needing to inspect compressed PDF bytes.
     *
     * @return array<string, mixed>
     */
    public function buildViewData(IntakeSubmission $submission): array
    {
        $submission->loadMissing([
            'order.user.practice',
            'order.package',
            'intakeUploads',
            'intakeAnswers.question.section',
            'intakeAnswers.question.policies',
        ]);

        $practice = $submission->order->user->practice;
        $includesWorkflowQuestionnaire = (bool) $submission->order->package?->includesWorkflowQuestionnaire();

        return [
            'practice' => $practice,
            'submission' => $submission,
            'order' => $submission->order,
            'documentRows' => $this->documentRows($submission),
            'includesWorkflowQuestionnaire' => $includesWorkflowQuestionnaire,
            'teamRows' => $includesWorkflowQuestionnaire ? $this->teamRows($practice) : [],
            'workflowSections' => $includesWorkflowQuestionnaire ? $this->workflowSections($submission) : collect(),
            'certification' => $this->certification($submission),
        ];
    }

    /** @return array<int, array{label: string, status: string}> */
    private function documentRows(IntakeSubmission $submission): array
    {
        $uploads = $submission->intakeUploads;
        $missing = $submission->wizard_missing_document_categories ?? [];
        $rows = [];

        foreach (self::DOCUMENT_CATEGORIES as $key => $label) {
            $filesForCategory = $uploads->where('document_category', $key);

            $rows[] = [
                'label' => $label,
                'status' => match (true) {
                    $filesForCategory->isNotEmpty() => 'Uploaded: '.$filesForCategory->pluck('original_filename')->implode(', '),
                    in_array($key, $missing, true) => 'Marked "I don\'t have this."',
                    default => 'Not yet provided.',
                },
            ];
        }

        $otherUploads = $uploads->reject(fn ($u) => array_key_exists($u->document_category, self::DOCUMENT_CATEGORIES));

        if ($otherUploads->isNotEmpty()) {
            $rows[] = [
                'label' => 'Other uploaded files',
                'status' => $otherUploads->pluck('original_filename')->implode(', '),
            ];
        }

        return $rows;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function teamRows(?Practice $practice): array
    {
        return [
            ['label' => 'Legal practice name', 'value' => collect([$practice?->legal_practice_name, $practice?->dba_name ? "DBA {$practice->dba_name}" : null])->filter()->implode(' ') ?: '(not yet provided)'],
            ['label' => 'Main phone / email', 'value' => collect([$practice?->main_phone, $practice?->main_email])->filter()->implode(' · ') ?: '(not yet provided)'],
            ['label' => 'Locations', 'value' => collect($practice?->practice_locations ?? [])->filter()->implode(' | ') ?: '(not yet provided)'],
            ['label' => 'HIPAA Privacy Officer', 'value' => $practice?->hipaa_privacy_officer_name ?: '(not yet provided)'],
            ['label' => 'HIPAA Security Officer', 'value' => $practice?->hipaa_security_officer_name ?: '(not yet provided)'],
            ['label' => 'Release of Information Officer', 'value' => $practice?->release_of_info_officer_name ?: '(not yet provided)'],
            ['label' => 'Compliance Officer', 'value' => $practice?->compliance_officer_name ?: '(not yet provided)'],
            ['label' => 'IT', 'value' => ($practice?->it_mode === 'vendor' ? $practice?->it_vendor_name : $practice?->it_contact_name) ?: '(not yet provided)'],
            ['label' => 'Compliance hotline', 'value' => $practice?->uses_ehcp_hotline ? "Empower's shared hotline" : ($practice?->compliance_hotline_number ?: '(not yet provided)')],
            [
                'label' => 'Compliance Committee',
                'value' => $practice?->committee_none
                    ? 'No committee yet'
                    : (collect($practice?->compliance_committee_members ?? [])->pluck('name')->filter()->implode(', ') ?: '(not yet provided)'),
            ],
            [
                'label' => $practice?->board_mode === 'board' ? 'Governing board' : 'Owners overseeing compliance',
                'value' => collect($practice?->compliance_governing_board_members ?? [])->pluck('name')->filter()->implode(', ') ?: '(not yet provided)',
            ],
        ];
    }

    /** @return Collection<int, array{label: string, questions: array<int, array{title: string, value: string, badge: array{label: string, color: string, background: string}}>}> */
    private function workflowSections(IntakeSubmission $submission): Collection
    {
        $answersByQuestionId = $submission->intakeAnswers->keyBy('intake_question_id');

        return IntakeSection::with('questions')->orderBy('sort_order')->get()
            ->reject(fn (IntakeSection $section) => $section->questions->isEmpty())
            ->map(function (IntakeSection $section) use ($answersByQuestionId) {
                return [
                    'label' => $section->label,
                    'questions' => $section->questions->map(function ($question) use ($answersByQuestionId) {
                        $answer = $answersByQuestionId->get($question->id);

                        [$value, $badge] = match (true) {
                            $answer === null => ['Not yet answered.', ['label' => 'Open', 'color' => '5d6e7f', 'background' => 'eef1f5']],
                            ! $answer->has_documented_process => ["No documented process \u{2014} best-practice language used.", ['label' => 'Policy default', 'color' => '1a7aad', 'background' => 'eaf5fb']],
                            default => [$answer->response ?: '(no response provided)', ['label' => 'Practice response', 'color' => '117a51', 'background' => 'd7f3ea']],
                        };

                        return ['title' => $question->title, 'value' => $value, 'badge' => $badge];
                    })->all(),
                ];
            })
            ->values();
    }

    /** @return array{by: ?string, signature: ?string, date: ?string}|null */
    private function certification(IntakeSubmission $submission): ?array
    {
        if (! $submission->certified_by_name && ! $submission->certified_at) {
            return null;
        }

        return [
            'by' => trim($submission->certified_by_name.($submission->certified_by_title ? ', '.$submission->certified_by_title : '')),
            'signature' => $submission->certified_signature,
            'date' => $submission->certified_at?->format('F j, Y'),
        ];
    }
}
