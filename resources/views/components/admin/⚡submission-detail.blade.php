<?php

use App\Enums\AiExtractionStatus;
use App\Enums\DocumentDeliverySource;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\OrderStatus;
use App\Jobs\GenerateComplianceDocument;
use App\Jobs\ProcessIntakeUpload;
use App\Mail\ClientDocumentsApprovedMail;
use App\Mail\ClientReviewerQuestionMail;
use App\Mail\ClientSubmissionStatusMail;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Practice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public int $submissionId;

    public string $reviewerNotes = '';

    public string $reviewerQuestionInput = '';

    /** Set when an action succeeded but its client notification email failed to send. */
    public ?string $notice = null;

    /** Keyed by GeneratedDocument id. */
    public array $customDocumentFiles = [];

    /** The intake question currently open for editing in the Practice Intake Answers panel below,
     *  or null when none is being edited. */
    public ?int $editingAnswerQuestionId = null;

    public string $editingAnswerResponse = '';

    public bool $editingAnswerHasDocumentedProcess = true;

    public bool $editingTeam = false;

    /** @var array<string, mixed> */
    public array $teamForm = [];

    public function mount(IntakeSubmission $submission): void
    {
        $this->submissionId = $submission->id;
        $this->reviewerNotes = $submission->reviewer_notes ?? '';

        $this->ensureExpectedDocumentsExist($submission);
        $this->demoteDocumentsWithFailedExtraction($submission);
    }

    /**
     * A document generated from a questionnaire whose AI extraction failed is filled with
     * "[No response provided]" placeholders rather than real practice data — GenerateComplianceDocument
     * has no visibility into extraction failures, so it still marks the document Completed with its
     * default "ai_generated" delivery source. Force it off that default so it isn't silently
     * approvable until the admin uploads a custom file or deliberately re-selects the AI version.
     * Already-approved documents are left untouched so a past delivery isn't retroactively hidden.
     */
    private function demoteDocumentsWithFailedExtraction(IntakeSubmission $submission): void
    {
        $uploadsByType = $submission->intakeUploads->keyBy(fn (IntakeUpload $u) => $u->upload_type->value);

        GeneratedDocument::where('order_id', $submission->order_id)
            ->where('delivery_source', DocumentDeliverySource::AiGenerated)
            ->whereNull('custom_storage_path')
            ->whereNull('reviewed_at')
            ->get()
            ->each(function (GeneratedDocument $document) use ($uploadsByType) {
                $upload = $this->sourceUploadFor($document, $uploadsByType);

                if ($upload?->ai_extraction_status === AiExtractionStatus::Failed) {
                    $document->update(['delivery_source' => DocumentDeliverySource::Custom]);
                }
            });
    }

    /**
     * The IntakeUpload a document's content was extracted from: the document's own
     * intake_upload_id for per-upload types, or a lookup by the document type's linked
     * questionnaire type for the questionnaire-linked manuals (which don't set intake_upload_id).
     *
     * @param  Collection<string, IntakeUpload>  $uploadsByType
     */
    private function sourceUploadFor(GeneratedDocument $document, Collection $uploadsByType): ?IntakeUpload
    {
        if ($document->intake_upload_id) {
            return $document->intakeUpload;
        }

        $linkedType = $document->document_type->linkedQuestionnaireType();

        return $linkedType ? $uploadsByType->get($linkedType->value) : null;
    }

    /**
     * Materializes a Pending GeneratedDocument row for every document type the client's
     * uploaded questionnaires entitle them to, so Document Review shows every expected
     * document — and lets the admin upload a custom file for one — even before the AI
     * generation pipeline has run, instead of only once a row already exists. Also does the
     * same for the package's included (policy-driven) manual types — those aren't tied to any
     * upload, so Document Review would otherwise show nothing for them until the submission is
     * actually approved and generateIncludedDocuments() fires.
     *
     * Mirrors ProcessIntakeUpload::dispatchDocumentGeneration()'s branching exactly, but
     * uses firstOrCreate() instead of dispatch(). It uses the identical key tuple as
     * GenerateComplianceDocument::handle()'s own firstOrCreate(), so when that job actually
     * runs it finds this same row rather than creating a duplicate.
     */
    private function ensureExpectedDocumentsExist(IntakeSubmission $submission): void
    {
        $submission->loadMissing('order.package', 'order.user.practice.oshaLocations', 'intakeUploads');
        $order = $submission->order;
        $oshaLocations = $order->user->practice?->oshaLocations ?? collect();
        $uploadedQuestionnaireTypes = $submission->intakeUploads->map(fn ($u) => $u->upload_type)->unique();

        $hasEncounterList = $submission->intakeUploads->contains(fn (IntakeUpload $u) => $u->document_category === 'encounter_list');

        foreach ($order->package?->included_document_types ?? [] as $typeValue) {
            $docType = DocumentType::tryFrom($typeValue);

            if ($docType === null) {
                continue;
            }

            // The Mini Audit report can only be synthesized from an uploaded encounter list —
            // don't create a placeholder for it (or keep one already sitting Pending/Failed from
            // before the client uploaded anything) until an encounter list actually exists.
            if ($docType === DocumentType::CodingMiniAuditReport && ! $hasEncounterList) {
                GeneratedDocument::where([
                    'order_id' => $order->id,
                    'document_type' => $docType,
                    'osha_location_id' => null,
                    'intake_upload_id' => null,
                ])->whereIn('status', [DocumentStatus::Pending, DocumentStatus::Failed])
                    ->whereNull('custom_storage_path')
                    ->delete();

                continue;
            }

            GeneratedDocument::firstOrCreate([
                'order_id' => $order->id,
                'document_type' => $docType,
                'osha_location_id' => null,
                'intake_upload_id' => null,
            ], ['status' => DocumentStatus::Pending]);
        }

        foreach ($uploadedQuestionnaireTypes as $uploadType) {
            $docType = DocumentType::forQuestionnaireType($uploadType);

            if ($docType === null) {
                continue;
            }

            if ($docType->isPerUpload()) {
                // finalizeIntake() flips a wizard document upload from NotApplicable to Pending
                // (and dispatches its AI review) only once the intake is actually submitted — an
                // upload still sitting at NotApplicable belongs to an in-progress draft, so there's
                // no "Reviewed & Polished Document" to expect for it yet. Without this filter,
                // that row would show as stuck "Waiting on AI generation" forever, since nothing
                // has dispatched its generation.
                $submission->intakeUploads
                    ->where('upload_type', $uploadType)
                    ->reject(fn (IntakeUpload $upload) => $upload->ai_extraction_status === AiExtractionStatus::NotApplicable)
                    ->each(fn (IntakeUpload $upload) => GeneratedDocument::firstOrCreate([
                        'order_id' => $order->id,
                        'document_type' => $docType,
                        'osha_location_id' => null,
                        'intake_upload_id' => $upload->id,
                    ], ['status' => DocumentStatus::Pending]));

                continue;
            }

            if ($docType->isPerLocation()) {
                foreach ($oshaLocations as $location) {
                    GeneratedDocument::firstOrCreate([
                        'order_id' => $order->id,
                        'document_type' => $docType,
                        'osha_location_id' => $location->id,
                        'intake_upload_id' => null,
                    ], ['status' => DocumentStatus::Pending]);
                }

                if ($oshaLocations->isEmpty()) {
                    GeneratedDocument::firstOrCreate([
                        'order_id' => $order->id,
                        'document_type' => $docType,
                        'osha_location_id' => null,
                        'intake_upload_id' => null,
                    ], ['status' => DocumentStatus::Pending]);
                }
            } else {
                GeneratedDocument::firstOrCreate([
                    'order_id' => $order->id,
                    'document_type' => $docType,
                    'osha_location_id' => null,
                    'intake_upload_id' => null,
                ], ['status' => DocumentStatus::Pending]);
            }
        }
    }

    #[Computed]
    public function submission(): IntakeSubmission
    {
        return IntakeSubmission::with([
            'order.package',
            'order.user.practice.oshaLocations',
            'intakeUploads',
            'intakeAnswers',
            'reviewer',
        ])->findOrFail($this->submissionId);
    }

    /**
     * The Practice Intake wizard's 66 workflow questions, grouped by section, each paired with
     * this submission's answer (if any) — shown to the admin as what will drive the generated
     * manuals' content, with an edit option (see startEditingAnswer()/saveEditedAnswer() below)
     * for correcting a practice's answer before documents are generated or re-generated. Empty
     * for a submission with no answers at all (Essential tier, or one predating the wizard).
     * Mirrors ⚡portal.blade.php's sectionDetailRows() badge/meta shape (client-facing "Your
     * answers" review).
     *
     * @return Collection<int, array{label: string, done: int, total: int, questions: Collection}>
     */
    #[Computed]
    public function intakeAnswersBySection(): Collection
    {
        $answersByQuestionId = $this->submission->intakeAnswers->keyBy('intake_question_id');

        if ($answersByQuestionId->isEmpty()) {
            return collect();
        }

        return IntakeSection::with('questions.policies')->orderBy('sort_order')->get()
            ->map(function (IntakeSection $section) use ($answersByQuestionId) {
                $questions = $section->questions->map(function (IntakeQuestion $question) use ($answersByQuestionId) {
                    $answer = $answersByQuestionId->get($question->id);

                    [$value, $badge] = match (true) {
                        $answer === null => ['Not yet answered', ['label' => 'Open', 'class' => 'bg-[#edf2f7] text-empower-muted']],
                        (bool) $answer->has_documented_process => [(string) $answer->response, ['label' => 'Practice response', 'class' => 'bg-[#dff7f0] text-[#0f7a4f]']],
                        default => ['No documented answer · policy default language applies', ['label' => 'Policy default', 'class' => 'bg-[#eaf5fb] text-[#1a7aad]']],
                    };

                    return [
                        'id' => $question->id,
                        'title' => $question->title,
                        'value' => $value,
                        'badge' => $badge,
                        'done' => $answer !== null,
                        'meta' => $question->policies->pluck('code')->implode(' · ') ?: null,
                    ];
                });

                return [
                    'label' => $section->label,
                    'done' => $questions->filter(fn (array $q) => $q['done'])->count(),
                    'total' => $questions->count(),
                    'questions' => $questions,
                ];
            })
            ->filter(fn (array $section) => $section['done'] > 0);
    }

    /** Opens a question's answer for editing, pre-filled with its current value (or sensible
     *  defaults for a question that was never answered — "Open" questions are still editable
     *  since the section they belong to already has at least one real answer). */
    public function startEditingAnswer(int $questionId): void
    {
        $answer = $this->submission->intakeAnswers->firstWhere('intake_question_id', $questionId);

        $this->editingAnswerQuestionId = $questionId;
        $this->editingAnswerResponse = $answer?->response ?? '';
        $this->editingAnswerHasDocumentedProcess = $answer === null || (bool) $answer->has_documented_process;
        $this->resetErrorBag('editingAnswerResponse');
    }

    public function cancelEditingAnswer(): void
    {
        $this->editingAnswerQuestionId = null;
        $this->editingAnswerResponse = '';
        $this->resetErrorBag('editingAnswerResponse');
    }

    public function saveEditedAnswer(): void
    {
        if ($this->editingAnswerQuestionId === null) {
            return;
        }

        if ($this->editingAnswerHasDocumentedProcess && trim($this->editingAnswerResponse) === '') {
            $this->addError('editingAnswerResponse', 'Enter a response, or switch to "No documented process."');

            return;
        }

        $submission = $this->submission;
        $question = IntakeQuestion::find($this->editingAnswerQuestionId);

        $submission->intakeAnswers()->updateOrCreate(
            ['intake_question_id' => $this->editingAnswerQuestionId],
            [
                'response' => $this->editingAnswerHasDocumentedProcess ? trim($this->editingAnswerResponse) : null,
                'has_documented_process' => $this->editingAnswerHasDocumentedProcess,
                'skipped' => false,
                'answered_at' => now(),
            ]
        );

        ActivityLog::record(
            'submission.answer_edited',
            "An admin edited the answer to \"{$question?->title}\" for order #{$submission->order_id}.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        $this->editingAnswerQuestionId = null;
        $this->editingAnswerResponse = '';

        unset($this->submission, $this->intakeAnswersBySection);

        $this->dispatch('toast', message: 'Answer updated.', type: 'success');
    }

    /** Renders a {name, title} member list (committee/board, same shape the wizard stores) as
     *  one "Name — Title" line per member, for a plain textarea rather than the wizard's own
     *  repeatable-row UI — this is an admin correction tool, not a guided form. */
    private function formatMembers(?array $members): string
    {
        return collect($members ?? [])
            ->map(fn ($m) => trim(($m['name'] ?? '').(filled($m['title'] ?? null) ? ' — '.$m['title'] : '')))
            ->filter()
            ->implode("\n");
    }

    /** @return array<int, array{name: string, title: string}> */
    private function parseMembers(string $text): array
    {
        return collect(preg_split('/\r?\n/', $text))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(function (string $line) {
                [$name, $title] = array_pad(explode('—', $line, 2), 2, '');

                return ['name' => trim($name), 'title' => trim($title)];
            })
            ->values()
            ->all();
    }

    public function startEditingTeam(): void
    {
        $practice = $this->submission->order?->user?->practice;

        $this->teamForm = [
            'legal_practice_name' => $practice?->legal_practice_name ?? '',
            'dba_name' => $practice?->dba_name ?? '',
            'other_entities' => $practice?->other_entities ?? '',
            'main_phone' => $practice?->main_phone ?? '',
            'main_email' => $practice?->main_email ?? '',
            'locations' => implode("\n", $practice?->practice_locations ?? []),
            'compliance_officer_name' => $practice?->compliance_officer_name ?? '',
            'compliance_officer_phone' => $practice?->compliance_officer_phone ?? '',
            'compliance_officer_email' => $practice?->compliance_officer_email ?? '',
            'hipaa_privacy_officer_name' => $practice?->hipaa_privacy_officer_name ?? '',
            'hipaa_privacy_officer_phone' => $practice?->hipaa_privacy_officer_phone ?? '',
            'hipaa_privacy_officer_email' => $practice?->hipaa_privacy_officer_email ?? '',
            'hipaa_security_officer_name' => $practice?->hipaa_security_officer_name ?? '',
            'hipaa_security_officer_phone' => $practice?->hipaa_security_officer_phone ?? '',
            'hipaa_security_officer_email' => $practice?->hipaa_security_officer_email ?? '',
            'release_of_info_officer_name' => $practice?->release_of_info_officer_name ?? '',
            'release_of_info_officer_phone' => $practice?->release_of_info_officer_phone ?? '',
            'release_of_info_officer_email' => $practice?->release_of_info_officer_email ?? '',
            'it_mode' => $practice?->it_mode ?? '',
            'it_vendor_name' => $practice?->it_vendor_name ?? '',
            'it_contact_name' => $practice?->it_contact_name ?? '',
            'it_contact_phone' => $practice?->it_contact_phone ?? '',
            'it_contact_email' => $practice?->it_contact_email ?? '',
            'uses_ehcp_hotline' => $practice?->uses_ehcp_hotline ?? true,
            'compliance_hotline_number' => $practice?->compliance_hotline_number ?? '',
            'hotline_poster_count' => (string) ($practice?->hotline_poster_count ?? ''),
            'committee_none' => $practice?->committee_none ?? false,
            'committee_members' => $this->formatMembers($practice?->compliance_committee_members),
            'board_mode' => $practice?->board_mode ?? 'owners',
            'board_members' => $this->formatMembers($practice?->compliance_governing_board_members),
        ];

        $this->editingTeam = true;
    }

    public function cancelEditingTeam(): void
    {
        $this->editingTeam = false;
    }

    public function saveTeam(): void
    {
        $practice = $this->submission->order?->user?->practice;

        if ($practice === null) {
            $this->editingTeam = false;

            return;
        }

        $form = $this->teamForm;

        $practice->update([
            'legal_practice_name' => trim($form['legal_practice_name']) ?: null,
            'dba_name' => trim($form['dba_name']) ?: null,
            'other_entities' => trim($form['other_entities']) ?: null,
            'main_phone' => trim($form['main_phone']) ?: null,
            'main_email' => trim($form['main_email']) ?: null,
            'practice_locations' => collect(preg_split('/\r?\n/', $form['locations']))->map(fn ($l) => trim($l))->filter()->values()->all(),
            'compliance_officer_name' => trim($form['compliance_officer_name']) ?: null,
            'compliance_officer_phone' => trim($form['compliance_officer_phone']) ?: null,
            'compliance_officer_email' => trim($form['compliance_officer_email']) ?: null,
            'hipaa_privacy_officer_name' => trim($form['hipaa_privacy_officer_name']) ?: null,
            'hipaa_privacy_officer_phone' => trim($form['hipaa_privacy_officer_phone']) ?: null,
            'hipaa_privacy_officer_email' => trim($form['hipaa_privacy_officer_email']) ?: null,
            'hipaa_security_officer_name' => trim($form['hipaa_security_officer_name']) ?: null,
            'hipaa_security_officer_phone' => trim($form['hipaa_security_officer_phone']) ?: null,
            'hipaa_security_officer_email' => trim($form['hipaa_security_officer_email']) ?: null,
            'release_of_info_officer_name' => trim($form['release_of_info_officer_name']) ?: null,
            'release_of_info_officer_phone' => trim($form['release_of_info_officer_phone']) ?: null,
            'release_of_info_officer_email' => trim($form['release_of_info_officer_email']) ?: null,
            'it_mode' => $form['it_mode'] ?: null,
            'it_vendor_name' => $form['it_mode'] === 'vendor' ? (trim($form['it_vendor_name']) ?: null) : null,
            'it_contact_name' => trim($form['it_contact_name']) ?: null,
            'it_contact_phone' => trim($form['it_contact_phone']) ?: null,
            'it_contact_email' => trim($form['it_contact_email']) ?: null,
            'uses_ehcp_hotline' => (bool) $form['uses_ehcp_hotline'],
            'compliance_hotline_number' => ! $form['uses_ehcp_hotline'] ? (trim($form['compliance_hotline_number']) ?: null) : null,
            'hotline_poster_count' => $form['hotline_poster_count'] !== '' ? (int) $form['hotline_poster_count'] : null,
            'committee_none' => (bool) $form['committee_none'],
            'compliance_committee_members' => $form['committee_none'] ? [] : $this->parseMembers($form['committee_members']),
            'board_mode' => $form['board_mode'] ?: null,
            'compliance_governing_board_members' => $this->parseMembers($form['board_members']),
        ]);

        ActivityLog::record(
            'submission.team_info_edited',
            "An admin edited the team/compliance contact info for order #{$this->submission->order_id}.",
            user: auth()->user(),
            order: $this->submission->order,
            subject: $this->submission,
        );

        $this->editingTeam = false;
        $this->teamForm = [];

        unset($this->submission);

        $this->dispatch('toast', message: 'Team info updated.', type: 'success');
    }

    /** Aggregate AI-extraction status across every uploaded file, for the prominent banner at
     *  the top of the page — failed takes priority, then in-progress, then complete. */
    #[Computed]
    public function aiExtractionBanner(): ?array
    {
        // Reference documents from the Practice Intake wizard are never extracted at all —
        // excluded here so this banner doesn't falsely claim "AI Extraction Complete" for them.
        $statuses = $this->submission->intakeUploads
            ->pluck('ai_extraction_status')
            ->reject(fn ($s) => $s === AiExtractionStatus::NotApplicable);

        if ($statuses->isEmpty()) {
            return null;
        }

        $total = $statuses->count();
        $failed = $statuses->filter(fn ($s) => $s === AiExtractionStatus::Failed)->count();
        $inProgress = $statuses->filter(fn ($s) => in_array($s, [AiExtractionStatus::Pending, AiExtractionStatus::Processing], true))->count();

        return match (true) {
            $failed > 0 => [
                'style' => 'danger',
                'icon' => '⚠️',
                'title' => 'AI Extraction Failed',
                'message' => $failed === $total
                    ? 'AI extraction failed for all uploaded files. Review and consider regenerating.'
                    : "AI extraction failed for {$failed} of {$total} uploaded files. Review and consider regenerating.",
            ],
            $inProgress > 0 => [
                'style' => 'warning',
                'icon' => '⏳',
                'title' => 'AI Extraction In Progress',
                'message' => $inProgress === $total
                    ? 'The AI is still processing the uploaded file(s). This page will reflect the latest status once complete.'
                    : "The AI is still processing {$inProgress} of {$total} uploaded files.",
            ],
            default => [
                'style' => 'success',
                'icon' => '✅',
                'title' => 'AI Extraction Complete',
                'message' => 'All uploaded files have been successfully processed by AI extraction.',
            ],
        };
    }

    /** Every generated document for this submission's order, paired with whether its
     *  custom-upload slot should be shown (only when linked to a questionnaire the
     *  client actually uploaded, or when it has no questionnaire link at all). */
    #[Computed]
    public function documentsForReview(): Collection
    {
        $submission = $this->submission;
        $uploadedTypes = $submission->intakeUploads->map(fn ($u) => $u->upload_type)->all();
        $uploadsByType = $submission->intakeUploads->keyBy(fn (IntakeUpload $u) => $u->upload_type->value);

        return GeneratedDocument::where('order_id', $submission->order_id)
            ->with(['reviewedBy', 'intakeUpload'])
            ->orderBy('document_type')
            ->get()
            ->map(function (GeneratedDocument $document) use ($uploadedTypes, $uploadsByType) {
                $linkedType = $document->document_type->linkedQuestionnaireType();
                $document->showsCustomUploadSlot = $linkedType === null || in_array($linkedType, $uploadedTypes, true);

                $sourceUpload = $this->sourceUploadFor($document, $uploadsByType);
                $document->extractionFailed = $sourceUpload?->ai_extraction_status === AiExtractionStatus::Failed;
                $document->sourceUploadId = $sourceUpload?->id;

                return $document;
            });
    }

    /** Whether anything on this page is still mid-generation, so the view knows to poll for
     *  live updates instead of leaving the admin to guess and manually refresh. */
    #[Computed]
    public function isGenerating(): bool
    {
        $uploadPending = $this->submission->intakeUploads
            ->contains(fn (IntakeUpload $u) => in_array($u->ai_extraction_status, [AiExtractionStatus::Pending, AiExtractionStatus::Processing], true));

        // Package-included manuals (intake_upload_id/osha_location_id both null) are only
        // dispatched once review starts (see startReview()/generateIncludedDocuments()), but
        // ensureExpectedDocumentsExist() pre-creates their Pending placeholder rows the moment
        // this page loads — well before that. Per-upload and per-location documents, by
        // contrast, are dispatched independently as soon as their source upload finishes
        // processing, so a Pending status on those really does mean "generating."
        $documentPending = $this->documentsForReview->contains(function (GeneratedDocument $d) {
            if (! in_array($d->status, [DocumentStatus::Pending, DocumentStatus::Generating], true)) {
                return false;
            }

            if ($d->intake_upload_id === null && $d->osha_location_id === null) {
                return $this->submission->status !== IntakeSubmissionStatus::Submitted;
            }

            return true;
        });

        return $uploadPending || $documentPending;
    }

    public function startReview(): void
    {
        $submission = $this->submission;

        if ($submission->status !== IntakeSubmissionStatus::Submitted) {
            return;
        }

        $submission->update(['status' => IntakeSubmissionStatus::UnderReview, 'under_review_started_at' => now()]);

        ActivityLog::record(
            'submission.under_review',
            "Submission for order #{$submission->order_id} moved to under review.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        // Kicks off AI generation as soon as review starts, rather than waiting for the final
        // Approve — otherwise there's nothing yet for the admin to actually review below.
        $this->generateIncludedDocuments($submission->order);

        unset($this->submission);

        $this->dispatch('toast', message: 'Review started — document generation is underway.', type: 'success');
    }

    public function askReviewerQuestion(): void
    {
        $this->validate(['reviewerQuestionInput' => 'required|string|max:2000']);

        $submission = $this->submission;

        $submission->update([
            'reviewer_question' => $this->reviewerQuestionInput,
            'reviewer_question_asked_at' => now(),
            'reviewer_question_reply' => null,
            'reviewer_question_replied_at' => null,
        ]);

        ActivityLog::record(
            'submission.reviewer_question_asked',
            "Reviewer asked a question on order #{$submission->order_id}.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        try {
            Mail::to($submission->order->user->email)->send(new ClientReviewerQuestionMail($submission));
        } catch (\Throwable $e) {
            report($e);
        }

        $this->reviewerQuestionInput = '';
        unset($this->submission);

        $this->dispatch('toast', message: 'Question sent to the client.', type: 'success');
    }

    public function deleteIntakeUpload(int $uploadId): void
    {
        $submission = $this->submission;

        $upload = IntakeUpload::where('id', $uploadId)
            ->where('intake_submission_id', $submission->id)
            ->firstOrFail();

        if ($upload->storage_path) {
            Storage::disk('local')->delete($upload->storage_path);
        }

        // generated_documents.intake_upload_id is nullOnDelete (not cascade), so without this
        // the document generated from this upload would survive as an orphan — its FK nulled
        // out, but the row still showing up forever in Document Review with no filename.
        $this->deleteGeneratedDocumentsForUploads([$upload->id]);

        $filename = $upload->original_filename;
        $upload->delete();

        ActivityLog::record(
            'upload.deleted',
            "{$filename} was deleted from order #{$submission->order_id} by an admin.",
            user: auth()->user(),
            order: $submission->order,
        );

        unset($this->submission, $this->documentsForReview);

        $this->dispatch('toast', message: "{$filename} deleted.", type: 'success');
    }

    /**
     * Sends the client back to Step 3 "Upload & Confirm" to re-certify and resubmit their
     * existing intake as-is — every intake_answer, intake_upload and GeneratedDocument is left
     * untouched, only the certification/submission state is cleared. Distinct from reject(),
     * which requires a reviewer note and is meant for "this needs changes"; this is for
     * resetting a submission that's stuck or needs a clean resubmission with no content changes.
     */
    public function sendBackForResubmission(): void
    {
        $submission = $this->submission;

        $submission->update([
            'status' => IntakeSubmissionStatus::Draft,
            'reviewer_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'submitted_at' => null,
            'certified_by_name' => null,
            'certified_by_title' => null,
            'certified_signature' => null,
            'certified_at' => null,
        ]);

        ActivityLog::record(
            'submission.sent_back_for_resubmission',
            "Submission for order #{$submission->order_id} sent back to the client for resubmission.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        // Back to Draft means it no longer belongs in the admin queue (submission-list excludes
        // Draft submissions) — leave for the list rather than sit on a now-stale detail view.
        session()->flash('toast', 'Sent back for resubmission.');
        $this->redirect(route('admin.submissions'), navigate: true);
    }

    /** @param  array<int, int>  $uploadIds */
    private function deleteGeneratedDocumentsForUploads(array $uploadIds): void
    {
        $documents = GeneratedDocument::whereIn('intake_upload_id', $uploadIds)->get();

        $this->deleteGeneratedDocumentFiles($documents);
        GeneratedDocument::whereIn('id', $documents->pluck('id'))->delete();
    }

    /** @param  Collection<int, GeneratedDocument>  $documents */
    private function deleteGeneratedDocumentFiles(Collection $documents): void
    {
        foreach ($documents as $document) {
            foreach ([$document->pdf_storage_path, $document->docx_storage_path, $document->custom_storage_path] as $path) {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
            }
        }
    }

    public function revokeApproval(int $documentId): void
    {
        $submission = $this->submission;

        $document = GeneratedDocument::where('id', $documentId)
            ->where('order_id', $submission->order_id)
            ->firstOrFail();

        if (! $document->isApproved()) {
            return;
        }

        $document->update(['reviewed_at' => null, 'reviewed_by' => null, 'revoked_at' => now()]);

        ActivityLog::record(
            'document.approval_revoked',
            "Approval for {$document->document_type->label()} was revoked for order #{$document->order_id}.",
            user: auth()->user(),
            order: $document->order,
            subject: $document,
        );

        unset($this->documentsForReview);

        $this->dispatch('toast', message: "Approval revoked for {$document->document_type->label()}.", type: 'success');
    }

    /**
     * Approves a single ready document without touching the submission's own status — for a
     * document the client uploaded for review after their submission was already approved (the
     * bulk Approve/Reject section only reappears for a Submitted/UnderReview submission, which
     * would otherwise leave a document like this stuck with no way to release it). Safe to use
     * during the normal review flow too: it only ever acts on the one document given.
     */
    public function approveDocument(int $documentId): void
    {
        $submission = $this->submission;

        $document = GeneratedDocument::where('id', $documentId)
            ->where('order_id', $submission->order_id)
            ->firstOrFail();

        if (! $document->canBeApproved()) {
            return;
        }

        $document->update(['reviewed_at' => now(), 'reviewed_by' => auth()->id(), 'revoked_at' => null]);

        ActivityLog::record(
            'document.approved',
            "{$document->document_type->label()} approved for order #{$document->order_id}.",
            user: auth()->user(),
            order: $document->order,
            subject: $document,
        );

        try {
            Mail::to($document->order->user->email)->send(new ClientDocumentsApprovedMail($document->order, $document->newCollection([$document])));
            $this->dispatch('toast', message: "{$document->document_type->label()} approved and the client notified.", type: 'success');
        } catch (\Throwable $e) {
            report($e);
            $this->notice = 'Document approved, but the client notification email failed to send.';
            $this->dispatch('toast', message: $this->notice, type: 'error');
        }

        unset($this->documentsForReview);
    }

    public function deleteGeneratedDocument(int $documentId): void
    {
        $submission = $this->submission;

        $document = GeneratedDocument::where('id', $documentId)
            ->where('order_id', $submission->order_id)
            ->firstOrFail();

        foreach ([$document->pdf_storage_path, $document->docx_storage_path, $document->custom_storage_path] as $path) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }

        $label = $document->document_type->label();
        $document->delete();

        ActivityLog::record('document.deleted', "{$label} was deleted from order #{$submission->order_id} by an admin.", user: auth()->user(), order: $submission->order);

        unset($this->documentsForReview);

        $this->dispatch('toast', message: "{$label} deleted.", type: 'success');
    }

    /**
     * Re-runs AI extraction on the questionnaire a failed document was built from, rather than
     * just re-running document generation (which would only re-merge the same missing data and
     * fail the same way). Once extraction succeeds, ProcessIntakeUpload's own completion handler
     * regenerates every document tied to the submission, this one included.
     */
    public function regenerateExtraction(int $documentId): void
    {
        $submission = $this->submission;

        $document = GeneratedDocument::where('id', $documentId)
            ->where('order_id', $submission->order_id)
            ->firstOrFail();

        $uploadsByType = $submission->intakeUploads->keyBy(fn (IntakeUpload $u) => $u->upload_type->value);
        $upload = $this->sourceUploadFor($document, $uploadsByType);

        if (! $upload) {
            $this->dispatch('toast', message: 'No source upload found to regenerate from.', type: 'error');

            return;
        }

        $upload->update([
            'ai_extraction_status' => AiExtractionStatus::Pending,
            'ai_extracted_data' => null,
            'ai_error_message' => null,
            'processed_at' => null,
        ]);

        ProcessIntakeUpload::dispatch($upload);

        ActivityLog::record(
            'upload.extraction_regenerate_requested',
            "AI extraction re-requested for {$upload->original_filename} (order #{$submission->order_id}).",
            user: auth()->user(),
            order: $submission->order,
        );

        unset($this->submission, $this->documentsForReview);

        $this->dispatch('toast', message: 'Regeneration started — this can take a couple of minutes.', type: 'success');
    }

    /** Undoes an accidental rejection — clears the reviewer notes and puts it back under review. */
    public function reopen(): void
    {
        $submission = $this->submission;

        if ($submission->status !== IntakeSubmissionStatus::Rejected) {
            return;
        }

        $submission->update([
            'status' => IntakeSubmissionStatus::UnderReview,
            'reviewer_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        ActivityLog::record(
            'submission.reopened',
            "Submission for order #{$submission->order_id} reopened for review after being rejected.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        $this->reviewerNotes = '';

        unset($this->submission);

        $this->dispatch('toast', message: 'Submission reopened for review.', type: 'success');
    }

    public function approve(): void
    {
        $submission = $this->submission;

        $submission->update([
            'status' => IntakeSubmissionStatus::Approved,
            'reviewer_notes' => null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $submission->order->update(['status' => OrderStatus::Approved]);

        ActivityLog::record(
            'submission.approved',
            "Submission for order #{$submission->order_id} approved.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        $this->generateIncludedDocuments($submission->order);

        // Approving the submission is the only approval action now — finalize every document
        // that already has a file ready to go (AI-generated or custom) in the same step, rather
        // than requiring a separate per-document approval. Doesn't email on its own — the
        // client is notified once, below, for the submission as a whole.
        $readyDocuments = GeneratedDocument::where('order_id', $submission->order_id)
            ->get()
            ->filter(fn (GeneratedDocument $document) => $document->canBeApproved());

        $this->finalizeDocumentApprovals($submission->order, $readyDocuments);

        try {
            Mail::to($submission->order->user->email)->send(new ClientSubmissionStatusMail($submission));
        } catch (\Throwable $e) {
            report($e);
            $this->notice = 'Submission approved, but the client notification email failed to send.';
        }

        // One consolidated "your documents are ready" email listing every ready document for
        // this order, in case any were already approved and delivered before this submission-level
        // approval (e.g. a prior approve/reopen/approve cycle).
        $allReadyDocuments = GeneratedDocument::where('order_id', $submission->order_id)
            ->get()
            ->filter(fn (GeneratedDocument $document) => $document->isReady());

        if ($allReadyDocuments->isNotEmpty()) {
            try {
                Mail::to($submission->order->user->email)->send(new ClientDocumentsApprovedMail($submission->order, $allReadyDocuments));
            } catch (\Throwable $e) {
                report($e);
                $this->notice = trim(($this->notice ? $this->notice.' ' : '').'Submission approved, but the documents-ready email failed to send.');
            }
        }

        unset($this->submission);

        $this->dispatch('toast', message: $this->notice ?? 'Submission approved and the client notified.', type: $this->notice ? 'error' : 'success');
    }

    /**
     * Dispatches generation for every one of the package's included document types that
     * doesn't already have a document row for this order yet — first-time generation only.
     * A document that already exists (from an earlier approval) is left as-is; regenerating
     * it after the fact is the existing explicit "Regenerate" admin/client action, not
     * something approving the submission does implicitly.
     */
    private function generateIncludedDocuments(Order $order): void
    {
        $includedTypes = $order->package?->included_document_types ?? [];

        // Excludes Pending rows deliberately — ensureExpectedDocumentsExist() pre-creates those
        // as placeholders the moment this page loads, well before approval, so their presence
        // alone can't mean "already generated." Only a status past Pending means generation was
        // actually attempted at least once.
        $alreadyGeneratedTypes = GeneratedDocument::where('order_id', $order->id)
            ->where('status', '!=', DocumentStatus::Pending)
            ->pluck('document_type');

        $hasEncounterList = $order->intakeSubmission?->intakeUploads
            ->contains(fn (IntakeUpload $u) => $u->document_category === 'encounter_list') ?? false;

        foreach ($includedTypes as $typeValue) {
            $documentType = DocumentType::tryFrom($typeValue);

            if ($documentType === null || $alreadyGeneratedTypes->contains($documentType)) {
                continue;
            }

            // No point dispatching a Mini Audit report that will just fail — see
            // ensureExpectedDocumentsExist(), which already keeps its placeholder from existing.
            if ($documentType === DocumentType::CodingMiniAuditReport && ! $hasEncounterList) {
                continue;
            }

            GenerateComplianceDocument::dispatch($order, $documentType);
        }
    }

    /** Marks each document approved, as part of approving the submission as a whole. Does NOT
     *  email the client itself: the client is notified once, by approve(), for the submission. */
    private function finalizeDocumentApprovals(Order $order, Collection $documents): void
    {
        if ($documents->isEmpty()) {
            return;
        }

        foreach ($documents as $document) {
            $document->update(['reviewed_at' => now(), 'reviewed_by' => auth()->id(), 'revoked_at' => null]);
        }

        ActivityLog::record(
            'documents.approved',
            "{$documents->count()} document(s) approved for order #{$order->id}.",
            user: auth()->user(),
            order: $order,
            metadata: ['document_types' => $documents->map(fn ($d) => $d->document_type->value)->all()],
        );

        unset($this->documentsForReview);
    }

    /** Livewire calls this automatically as soon as a file finishes uploading into
     *  customDocumentFiles.{documentId} — no separate "Upload" click needed. */
    public function updatedCustomDocumentFiles($value, $key): void
    {
        $this->uploadCustomDocument((int) $key);
    }

    public function uploadCustomDocument(int $documentId): void
    {
        $submission = $this->submission;

        $document = GeneratedDocument::where('id', $documentId)
            ->where('order_id', $submission->order_id)
            ->firstOrFail();

        $this->validate([
            "customDocumentFiles.{$documentId}" => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        $wasApproved = $document->isApproved();

        $file = $this->customDocumentFiles[$documentId];
        $storagePath = $file->store("private/compliance/{$document->order_id}/custom", 'local');

        $document->update([
            'custom_storage_path' => $storagePath,
            'custom_original_filename' => $file->getClientOriginalName(),
            'delivery_source' => DocumentDeliverySource::Custom,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'revoked_at' => $wasApproved ? now() : $document->revoked_at,
        ]);

        ActivityLog::record(
            'document.custom_uploaded',
            "Custom {$document->document_type->label()} uploaded for order #{$document->order_id}.",
            user: auth()->user(),
            order: $document->order,
            subject: $document,
        );

        unset($this->customDocumentFiles[$documentId], $this->documentsForReview);

        $this->dispatch('toast', message: "Custom {$document->document_type->label()} uploaded.", type: 'success');
    }

    public function deleteCustomDocument(int $documentId): void
    {
        $submission = $this->submission;

        $document = GeneratedDocument::where('id', $documentId)
            ->where('order_id', $submission->order_id)
            ->firstOrFail();

        if (! $document->hasCustomDocument()) {
            return;
        }

        Storage::disk('local')->delete($document->custom_storage_path);

        // If the custom file was the one set to deliver, falling back to the
        // AI-generated version means any prior approval no longer reflects
        // what will actually be sent — revoke it so it's reviewed again.
        $wasActiveDeliverySource = $document->delivery_source === DocumentDeliverySource::Custom;
        $revokes = $wasActiveDeliverySource && $document->isApproved();

        $document->update([
            'custom_storage_path' => null,
            'custom_original_filename' => null,
            'delivery_source' => DocumentDeliverySource::AiGenerated,
            'reviewed_at' => $wasActiveDeliverySource ? null : $document->reviewed_at,
            'reviewed_by' => $wasActiveDeliverySource ? null : $document->reviewed_by,
            'revoked_at' => $revokes ? now() : $document->revoked_at,
        ]);

        ActivityLog::record(
            'document.custom_deleted',
            "Custom {$document->document_type->label()} removed for order #{$document->order_id}.",
            user: auth()->user(),
            order: $document->order,
            subject: $document,
        );

        unset($this->documentsForReview);

        $this->dispatch('toast', message: "Custom {$document->document_type->label()} removed.", type: 'success');
    }

    /** Fires when the admin checks the "AI-Generated File" or "Custom File" box for a
     *  document, choosing which version will be delivered once the submission is approved. */
    public function setDeliverySource(int $documentId, string $source): void
    {
        $submission = $this->submission;
        $deliverySource = DocumentDeliverySource::from($source);

        $document = GeneratedDocument::where('id', $documentId)
            ->where('order_id', $submission->order_id)
            ->firstOrFail();

        if ($deliverySource === DocumentDeliverySource::Custom && ! $document->hasCustomDocument()) {
            return;
        }

        if ($document->delivery_source === $deliverySource) {
            return;
        }

        $wasApproved = $document->isApproved();

        $document->update([
            'delivery_source' => $deliverySource,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'revoked_at' => $wasApproved ? now() : $document->revoked_at,
        ]);

        unset($this->documentsForReview);
    }

    public function reject(): void
    {
        $this->validate([
            'reviewerNotes' => 'required|string|max:2000',
        ], [
            'reviewerNotes.required' => 'Please explain what needs to be fixed before rejecting.',
        ]);

        $submission = $this->submission;

        $submission->update([
            'status' => IntakeSubmissionStatus::Rejected,
            'reviewer_notes' => $this->reviewerNotes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ActivityLog::record(
            'submission.rejected',
            "Submission for order #{$submission->order_id} rejected.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
            metadata: ['reviewer_notes' => $this->reviewerNotes],
        );

        try {
            Mail::to($submission->order->user->email)->send(new ClientSubmissionStatusMail($submission));
        } catch (\Throwable $e) {
            report($e);
            $this->notice = 'Submission rejected, but the client notification email failed to send.';
        }

        unset($this->submission);

        $this->dispatch('toast', message: $this->notice ?? 'Submission rejected and the client notified.', type: $this->notice ? 'error' : 'success');
    }
};
?>

<div class="space-y-4" x-data="{
    confirmAction: null,
    confirmDocumentId: null,
    confirmUploadId: null,
    modalText: {
        approve: { title: 'Approve this submission?', body: 'Every document that currently has a file ready (AI-generated or custom) will be approved and become visible to the client at once. The client is emailed a single notification.', label: 'Approve', danger: false },
        reject: { title: 'Reject this submission?', body: 'The client will be asked to re-upload based on your reviewer notes.', label: 'Reject', danger: true },
        deleteCustom: { title: 'Remove this custom file?', body: 'This cannot be undone. The AI-generated file will be delivered instead unless a new custom file is uploaded.', label: 'Remove', danger: true },
        reopen: { title: 'Reopen this submission for review?', body: 'This clears the rejection and reviewer notes, and puts the submission back under review.', label: 'Reopen', danger: false },
        revokeApproval: { title: 'Revoke approval for this document?', body: 'It goes back to pending review and the client will no longer be able to download it until it is approved again.', label: 'Revoke', danger: true },
        approveDocument: { title: 'Approve this document?', body: 'It becomes visible to the client immediately and they are emailed a notification.', label: 'Approve', danger: false },
        deleteDocument: { title: 'Delete this document?', body: 'This permanently deletes the generated document and any custom file uploaded for it. This cannot be undone.', label: 'Delete', danger: true },
        regenerateExtraction: { title: 'Regenerate this document?', body: 'Re-runs AI extraction on the source questionnaire and rebuilds every document for this submission once it completes. This can take a couple of minutes.', label: 'Regenerate', danger: false },
        deleteUpload: { title: 'Delete this uploaded file?', body: 'This permanently deletes the file the client uploaded. This cannot be undone.', label: 'Delete', danger: true },
        sendBackForResubmission: { title: 'Send back for resubmission?', body: 'The client is returned to Step 3 (Upload & Confirm) to re-certify and resubmit their existing intake as-is. Every answer, upload and generated document is kept — only the certification and submitted status are cleared.', label: 'Send back', danger: true },
    },
}">
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.submissions') }}" wire:navigate class="text-sm font-semibold text-[#0b9ed0] hover:underline">&larr; Back to submissions</a>
        <button type="button" x-on:click="confirmAction = 'sendBackForResubmission'" class="text-xs font-bold text-red-600 hover:underline">Send Back for Resubmission</button>
    </div>

    @if($notice)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-start justify-between gap-3">
            <span>{{ $notice }}</span>
            <button type="button" wire:click="$set('notice', null)" class="text-amber-600 hover:text-amber-800 font-bold leading-none">&times;</button>
        </div>
    @endif

    @php $submission = $this->submission; $practice = $submission->order?->user?->practice; @endphp

    @if($this->aiExtractionBanner)
        @php $banner = $this->aiExtractionBanner; @endphp
        <div class="rounded-xl px-4 py-3 flex items-center gap-2 text-sm {{ match ($banner['style']) {
            'danger' => 'bg-red-50 text-red-800',
            'warning' => 'bg-amber-50 text-amber-800',
            'success' => 'bg-[#dff7f0] text-[#0f7a4f]',
        } }}">
            <span class="leading-none">{{ $banner['icon'] }}</span>
            <p><span class="font-bold">{{ $banner['title'] }}</span> — {{ $banner['message'] }}</p>
        </div>
    @endif

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-navy">{{ $practice?->name ?: 'Unnamed practice' }}</h2>
                <p class="text-sm text-empower-muted">{{ $submission->order?->user?->email }} &middot; {{ $submission->order?->package?->name }}</p>
            </div>
            @php
                $badgeClasses = match($submission->status) {
                    IntakeSubmissionStatus::Approved => 'bg-[#dff7f0] text-[#0f7a4f]',
                    IntakeSubmissionStatus::Rejected => 'bg-[#fde2e2] text-[#a53b3b]',
                    IntakeSubmissionStatus::UnderReview => 'bg-[#fff3cd] text-[#9a6700]',
                    default => 'bg-[#eef6fb] text-empower-muted',
                };
            @endphp
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wider {{ $badgeClasses }}">
                {{ str_replace('_', ' ', $submission->status->value) }}
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div><span class="text-empower-muted">Address</span><br><span class="text-empower-text">{{ $practice?->address ?: '—' }}</span></div>
            <div><span class="text-empower-muted">Specialty</span><br><span class="text-empower-text">{{ $practice?->specialty ?: '—' }}</span></div>
            <div><span class="text-empower-muted">Billable Providers</span><br><span class="text-empower-text">{{ $practice?->billable_providers_count ?: '—' }}</span></div>
        </div>

        @if($practice?->oshaLocations->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-empower-border">
                <p class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-2">OSHA Locations</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($practice->oshaLocations as $loc)
                        <span class="inline-flex items-center rounded-full bg-page px-3 py-1 text-xs font-semibold text-navy">{{ $loc->name }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if($submission->status === IntakeSubmissionStatus::Submitted)
        <div class="rounded-xl border border-[#9ed3e9] bg-[#eef6fb] px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-navy">Review hasn't started yet</p>
                <p class="text-xs text-empower-muted">Document generation for this submission's manuals only begins once you mark it as under review.</p>
            </div>
            <button wire:click="startReview" wire:target="startReview" wire:loading.attr="disabled" wire:target="startReview"
                class="inline-flex items-center gap-1.5 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors whitespace-nowrap">
                <span wire:loading.remove wire:target="startReview">Mark as Under Review</span>
                <span wire:loading.inline-flex wire:target="startReview" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Updating…</span>
            </button>
        </div>
    @endif

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h3 class="text-sm font-semibold text-navy mb-3">Uploaded Forms</h3>
        @forelse($submission->intakeUploads as $upload)
            <div class="flex items-center justify-between gap-3 py-2.5 border-b border-empower-border last:border-b-0">
                <div>
                    <p class="text-sm font-semibold text-empower-text">{{ $upload->original_filename }}</p>
                    <p class="text-xs text-empower-muted">{{ $upload->upload_type->value }} &middot; {{ $upload->fileSizeForHumans() }} &middot;
                        {{ $upload->ai_extraction_status === AiExtractionStatus::NotApplicable ? 'Reference document (not AI-processed)' : 'AI extraction: '.$upload->ai_extraction_status->value }}</p>
                    @if($upload->ai_extraction_status === AiExtractionStatus::Failed && $upload->ai_error_message)
                        <p class="text-xs text-[#a53b3b] mt-0.5">{{ $upload->ai_error_message }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.uploads.download', $upload) }}" class="text-xs font-bold text-[#0b9ed0] hover:underline">Download</a>
                    <button type="button" x-on:click="confirmAction = 'deleteUpload'; confirmUploadId = {{ $upload->id }}"
                        class="text-xs font-bold text-red-600 hover:underline">Delete</button>
                </div>
            </div>
        @empty
            <p class="text-sm text-empower-muted italic">No files uploaded.</p>
        @endforelse
    </div>

    @if($practice)
    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <div class="flex items-start justify-between gap-3 mb-1">
            <h3 class="text-sm font-semibold text-navy">Practice Team &amp; Compliance Contacts</h3>
            @if(! $editingTeam)
            <button type="button" wire:click="startEditingTeam" wire:loading.attr="disabled" wire:target="startEditingTeam"
                class="text-xs font-bold text-accent hover:underline flex-shrink-0">Edit</button>
            @endif
        </div>
        <p class="text-xs text-empower-muted mb-4">Who's listed as the compliance officers, IT contact, hotline, and committee/board — this also drives the generated manuals' content.</p>

        @if($editingTeam)
        @php $inputClass = 'w-full rounded-lg border border-empower-border bg-white px-3 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition'; @endphp
        <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold text-empower-muted mb-1">Legal practice name</label><input type="text" wire:model="teamForm.legal_practice_name" class="{{ $inputClass }}"></div>
                <div><label class="block text-xs font-semibold text-empower-muted mb-1">DBA name</label><input type="text" wire:model="teamForm.dba_name" class="{{ $inputClass }}"></div>
                <div><label class="block text-xs font-semibold text-empower-muted mb-1">Other entities</label><input type="text" wire:model="teamForm.other_entities" class="{{ $inputClass }}"></div>
                <div><label class="block text-xs font-semibold text-empower-muted mb-1">Main phone</label><input type="text" wire:model="teamForm.main_phone" class="{{ $inputClass }}"></div>
                <div><label class="block text-xs font-semibold text-empower-muted mb-1">Main email</label><input type="email" wire:model="teamForm.main_email" class="{{ $inputClass }}"></div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-empower-muted mb-1">Locations (one per line)</label>
                <textarea wire:model="teamForm.locations" rows="2" class="{{ $inputClass }}"></textarea>
            </div>

            <div class="pt-3 border-t border-empower-border">
                <p class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-2">Compliance officers</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-3">
                    @foreach([
                        ['compliance_officer', 'Compliance Officer'],
                        ['hipaa_privacy_officer', 'HIPAA Privacy Officer'],
                        ['hipaa_security_officer', 'HIPAA Security Officer'],
                        ['release_of_info_officer', 'Release of Information Officer'],
                    ] as [$prefix, $label])
                    <div class="rounded-lg border border-empower-border p-3">
                        <p class="text-xs font-bold text-empower-text mb-2">{{ $label }}</p>
                        <input type="text" placeholder="Name" wire:model="teamForm.{{ $prefix }}_name" class="{{ $inputClass }} mb-1.5">
                        <input type="text" placeholder="Phone" wire:model="teamForm.{{ $prefix }}_phone" class="{{ $inputClass }} mb-1.5">
                        <input type="email" placeholder="Email" wire:model="teamForm.{{ $prefix }}_email" class="{{ $inputClass }}">
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="pt-3 border-t border-empower-border">
                <p class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-2">IT</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-empower-muted mb-1">Setup</label>
                        <select wire:model="teamForm.it_mode" class="{{ $inputClass }}">
                            <option value="">(not yet provided)</option>
                            <option value="vendor">Outside vendor</option>
                            <option value="inhouse">In-house staff</option>
                        </select>
                    </div>
                    @if($teamForm['it_mode'] === 'vendor')
                    <div><label class="block text-xs font-semibold text-empower-muted mb-1">Vendor name</label><input type="text" wire:model="teamForm.it_vendor_name" class="{{ $inputClass }}"></div>
                    @endif
                    <div><label class="block text-xs font-semibold text-empower-muted mb-1">{{ $teamForm['it_mode'] === 'vendor' ? "Vendor's contact name" : 'Staff contact name' }}</label><input type="text" wire:model="teamForm.it_contact_name" class="{{ $inputClass }}"></div>
                    <div><label class="block text-xs font-semibold text-empower-muted mb-1">Contact phone</label><input type="text" wire:model="teamForm.it_contact_phone" class="{{ $inputClass }}"></div>
                    <div><label class="block text-xs font-semibold text-empower-muted mb-1">Contact email</label><input type="email" wire:model="teamForm.it_contact_email" class="{{ $inputClass }}"></div>
                </div>
            </div>

            <div class="pt-3 border-t border-empower-border">
                <p class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-2">Compliance hotline</p>
                <div class="flex items-center gap-2 mb-2">
                    <button type="button" wire:click="$set('teamForm.uses_ehcp_hotline', true)" class="rounded-full px-3 py-1 text-[11px] font-bold {{ $teamForm['uses_ehcp_hotline'] ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted' }}">Uses Empower's hotline</button>
                    <button type="button" wire:click="$set('teamForm.uses_ehcp_hotline', false)" class="rounded-full px-3 py-1 text-[11px] font-bold {{ ! $teamForm['uses_ehcp_hotline'] ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted' }}">Own hotline number</button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @if(! $teamForm['uses_ehcp_hotline'])
                    <div><label class="block text-xs font-semibold text-empower-muted mb-1">Hotline number</label><input type="text" wire:model="teamForm.compliance_hotline_number" class="{{ $inputClass }}"></div>
                    @endif
                    <div><label class="block text-xs font-semibold text-empower-muted mb-1">Posters distributed</label><input type="number" min="0" wire:model="teamForm.hotline_poster_count" class="{{ $inputClass }}"></div>
                </div>
            </div>

            <div class="pt-3 border-t border-empower-border">
                <p class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-2">Leadership</p>
                <label class="flex items-center gap-2 mb-2 text-xs font-semibold text-empower-text">
                    <input type="checkbox" wire:model="teamForm.committee_none" class="h-4 w-4 rounded border-empower-border text-accent focus:ring-accent">
                    No Compliance Committee yet
                </label>
                @if(! $teamForm['committee_none'])
                <div class="mb-3">
                    <label class="block text-xs font-semibold text-empower-muted mb-1">Compliance Committee members (one per line: Name — Title)</label>
                    <textarea wire:model="teamForm.committee_members" rows="3" class="{{ $inputClass }}"></textarea>
                </div>
                @endif

                <div class="flex items-center gap-2 mb-2">
                    <button type="button" wire:click="$set('teamForm.board_mode', 'owners')" class="rounded-full px-3 py-1 text-[11px] font-bold {{ $teamForm['board_mode'] === 'owners' ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted' }}">Owners/partners oversee compliance</button>
                    <button type="button" wire:click="$set('teamForm.board_mode', 'board')" class="rounded-full px-3 py-1 text-[11px] font-bold {{ $teamForm['board_mode'] === 'board' ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted' }}">Governing board</button>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-empower-muted mb-1">{{ $teamForm['board_mode'] === 'board' ? 'Governing board members' : 'Owners overseeing compliance' }} (one per line: Name — Title)</label>
                    <textarea wire:model="teamForm.board_members" rows="3" class="{{ $inputClass }}"></textarea>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" wire:click="saveTeam" wire:loading.attr="disabled" wire:target="saveTeam,cancelEditingTeam"
                    class="rounded-lg bg-accent px-4 py-2 text-sm font-bold text-white hover:opacity-90 transition">
                    <span wire:loading.remove wire:target="saveTeam">Save</span>
                    <span wire:loading wire:target="saveTeam">Saving…</span>
                </button>
                <button type="button" wire:click="cancelEditingTeam" wire:loading.attr="disabled" wire:target="saveTeam,cancelEditingTeam"
                    class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition">Cancel</button>
            </div>
        </div>
        @else
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div><span class="text-empower-muted">Legal practice name</span><br><span class="text-empower-text">{{ collect([$practice->legal_practice_name, $practice->dba_name ? "DBA {$practice->dba_name}" : null])->filter()->implode(' ') ?: '—' }}</span></div>
            <div><span class="text-empower-muted">Main phone / email</span><br><span class="text-empower-text">{{ collect([$practice->main_phone, $practice->main_email])->filter()->implode(' · ') ?: '—' }}</span></div>
            <div><span class="text-empower-muted">Locations</span><br><span class="text-empower-text">{{ collect($practice->practice_locations ?? [])->filter()->implode(' | ') ?: '—' }}</span></div>
            <div><span class="text-empower-muted">Compliance Officer</span><br><span class="text-empower-text">{{ $practice->compliance_officer_name ?: '—' }}</span></div>
            <div><span class="text-empower-muted">HIPAA Privacy Officer</span><br><span class="text-empower-text">{{ $practice->hipaa_privacy_officer_name ?: '—' }}</span></div>
            <div><span class="text-empower-muted">HIPAA Security Officer</span><br><span class="text-empower-text">{{ $practice->hipaa_security_officer_name ?: '—' }}</span></div>
            <div><span class="text-empower-muted">Release of Information Officer</span><br><span class="text-empower-text">{{ $practice->release_of_info_officer_name ?: '—' }}</span></div>
            <div><span class="text-empower-muted">IT</span><br><span class="text-empower-text">{{ ($practice->it_mode === 'vendor' ? $practice->it_vendor_name : $practice->it_contact_name) ?: '—' }}</span></div>
            <div><span class="text-empower-muted">Compliance hotline</span><br><span class="text-empower-text">{{ $practice->uses_ehcp_hotline ? "Empower's shared hotline" : ($practice->compliance_hotline_number ?: '—') }}</span></div>
            <div><span class="text-empower-muted">Compliance Committee</span><br><span class="text-empower-text">{{ $practice->committee_none ? 'No committee yet' : (collect($practice->compliance_committee_members ?? [])->pluck('name')->filter()->implode(', ') ?: '—') }}</span></div>
            <div><span class="text-empower-muted">{{ $practice->board_mode === 'board' ? 'Governing board' : 'Owners overseeing compliance' }}</span><br><span class="text-empower-text">{{ collect($practice->compliance_governing_board_members ?? [])->pluck('name')->filter()->implode(', ') ?: '—' }}</span></div>
        </div>
        @endif
    </div>
    @endif

    @if($this->intakeAnswersBySection->isNotEmpty())
    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h3 class="text-sm font-semibold text-navy mb-1">Practice Intake Answers</h3>
        <p class="text-xs text-empower-muted mb-4">What the practice typed into the intake wizard — this drives the generated manuals' content. Edit an answer below to correct it before documents are (re)generated.</p>

        <div class="divide-y divide-empower-border border border-empower-border rounded-xl">
            @foreach($this->intakeAnswersBySection as $section)
            <div x-data="{ open: {{ $section['questions']->contains('id', $editingAnswerQuestionId) ? 'true' : 'false' }} }">
                <button type="button" x-on:click="open = !open"
                    class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left cursor-pointer hover:bg-page transition-colors">
                    <span class="text-sm font-semibold text-empower-text flex items-center gap-1.5">
                        {{ $section['label'] }}
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" x-bind:class="open ? 'rotate-180' : ''"
                            class="text-empower-muted transition-transform">
                            <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span class="text-xs text-empower-muted flex-shrink-0">{{ $section['done'] }}/{{ $section['total'] }} {{ $section['done'] === $section['total'] ? '✓' : '' }}</span>
                </button>
                <div x-show="open" x-cloak x-transition class="px-4 pb-3 space-y-3">
                    @foreach($section['questions'] as $q)
                    <div class="{{ ! $loop->last ? 'border-b border-empower-border pb-3' : '' }}">
                        @if($editingAnswerQuestionId === $q['id'])
                        <div class="rounded-xl border border-accent/40 bg-page p-3" wire:key="editing-answer-{{ $q['id'] }}">
                            <p class="text-sm font-semibold text-empower-text mb-2">{{ $q['title'] }}</p>
                            <div class="flex items-center gap-2 mb-2">
                                <button type="button" wire:click="$set('editingAnswerHasDocumentedProcess', true)"
                                    wire:loading.attr="disabled" wire:target="saveEditedAnswer,cancelEditingAnswer"
                                    class="rounded-full px-3 py-1 text-[11px] font-bold {{ $editingAnswerHasDocumentedProcess ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted' }}">Documented process</button>
                                <button type="button" wire:click="$set('editingAnswerHasDocumentedProcess', false)"
                                    wire:loading.attr="disabled" wire:target="saveEditedAnswer,cancelEditingAnswer"
                                    class="rounded-full px-3 py-1 text-[11px] font-bold {{ ! $editingAnswerHasDocumentedProcess ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted' }}">No documented answer</button>
                            </div>
                            @if($editingAnswerHasDocumentedProcess)
                            <textarea wire:model="editingAnswerResponse" rows="5"
                                class="w-full rounded-lg border {{ $errors->has('editingAnswerResponse') ? 'border-red-400' : 'border-empower-border' }} bg-white px-3 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                            @error('editingAnswerResponse') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            @else
                            <p class="text-xs text-empower-muted italic">The policy's best-practice language will be used instead of a practice response.</p>
                            @endif
                            <div class="flex items-center gap-2 mt-3">
                                <button type="button" wire:click="saveEditedAnswer" wire:loading.attr="disabled" wire:target="saveEditedAnswer,cancelEditingAnswer"
                                    class="rounded-lg bg-accent px-3 py-1.5 text-xs font-bold text-white hover:opacity-90 transition">
                                    <span wire:loading.remove wire:target="saveEditedAnswer">Save</span>
                                    <span wire:loading wire:target="saveEditedAnswer">Saving…</span>
                                </button>
                                <button type="button" wire:click="cancelEditingAnswer" wire:loading.attr="disabled" wire:target="saveEditedAnswer,cancelEditingAnswer"
                                    class="rounded-lg border border-empower-border px-3 py-1.5 text-xs font-semibold text-empower-muted hover:bg-white transition">Cancel</button>
                            </div>
                        </div>
                        @else
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-empower-text">{{ $q['title'] }}</p>
                                <p class="text-sm text-empower-muted mt-0.5 whitespace-pre-line">{{ $q['value'] }}</p>
                                @if($q['meta'])
                                <p class="text-[11px] text-empower-muted mt-1">{{ $q['meta'] }}</p>
                                @endif
                                <button type="button" wire:click="startEditingAnswer({{ $q['id'] }})" wire:loading.attr="disabled" wire:target="startEditingAnswer({{ $q['id'] }})"
                                    class="text-xs font-bold text-accent hover:underline mt-1.5">Edit</button>
                            </div>
                            <span class="flex-shrink-0 rounded-full px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide {{ $q['badge']['class'] }}">
                                {{ $q['badge']['label'] }}
                            </span>
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <div @if($this->isGenerating) wire:poll.5s="$refresh" @endif
        class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-1">
                <h3 class="text-sm font-semibold text-navy">Document Review</h3>
            </div>
            <p class="text-xs text-empower-muted mb-4">Every document this package includes, with its current generation status. Policy-driven manuals generate once the submission is approved. If a document fails or takes too long to generate, upload a corrected file below and choose which version to deliver, then use Approve/Reject below to finalize the whole submission.</p>

            @if($this->isGenerating)
                <div class="mb-4 flex items-center gap-2 text-xs font-semibold text-empower-text bg-page border border-empower-border rounded-xl px-3 py-2">
                    <x-spinner class="h-3.5 w-3.5 text-accent" />
                    Documents are being generated — this updates automatically every few seconds.
                </div>
            @endif

            <div class="space-y-4">
                @forelse($this->documentsForReview as $document)
                    @php
                        $badge = match(true) {
                            $document->is_stale => ['Outdated', 'bg-[#fde2e2] text-[#a53b3b]'],
                            $document->isApproved() => ['Approved', 'bg-[#dff7f0] text-[#0f7a4f]'],
                            $document->status === DocumentStatus::Completed => ['Pending Review', 'bg-[#eef6fb] text-empower-muted'],
                            $document->status === DocumentStatus::Failed => ['Failed', 'bg-[#fde2e2] text-[#a53b3b]'],
                            $document->status === DocumentStatus::Pending => ['Not Started', 'bg-[#edf2f7] text-empower-muted'],
                            default => ['Generating', 'bg-[#fff3cd] text-[#9a6700]'],
                        };
                    @endphp
                    @php
                        // A document can only be checked into a box while it's awaiting a decision —
                        // once approved or marked outdated, the boxes go read-only (see below).
                        $reviewable = ! $document->isApproved() && ! $document->is_stale;
                        $aiSelected = $reviewable && $document->delivery_source === DocumentDeliverySource::AiGenerated;
                        $customSelected = $reviewable && $document->delivery_source === DocumentDeliverySource::Custom;
                    @endphp
                    <div class="rounded-xl border border-empower-border p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                            <div>
                                <p class="text-sm font-semibold text-empower-text">
                                    {{ $document->intakeUpload?->document_category === 'employee_manual' ? 'Employee manual (reviewed)' : $document->document_type->label() }}{{ $document->oshaLocation ? ' — '.$document->oshaLocation->name : '' }}{{ $document->intakeUpload ? ' — '.$document->intakeUpload->original_filename : '' }}
                                </p>
                                @if($document->isApproved())
                                    <p class="text-xs text-empower-muted">Approved by {{ $document->reviewedBy?->name ?? 'admin' }} &middot; {{ $document->reviewed_at->format('M j, Y') }} &middot; delivered the {{ $document->delivery_source === DocumentDeliverySource::Custom ? 'custom' : 'AI-generated' }} file</p>
                                @elseif($document->extractionFailed && ! $document->hasCustomDocument())
                                    <p class="text-xs text-[#a53b3b]">The AI extraction for this {{ $document->document_type->label() }} is failed. You should regenerate or custom upload your file</p>
                                @elseif($document->status === DocumentStatus::Failed && $document->hasCustomDocument())
                                    <p class="text-xs text-[#9a6700]">AI generation failed — a custom file will be delivered instead.</p>
                                @elseif($document->status === DocumentStatus::Failed)
                                    <p class="text-xs text-[#a53b3b]">AI generation failed{{ $document->failure_reason ? ': '.$document->failure_reason : '.' }} Upload a custom file below to deliver this document.</p>
                                @elseif(in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Generating], true) && ! $document->hasCustomDocument())
                                    @if($document->intake_upload_id === null && $document->osha_location_id === null && $this->submission->status === IntakeSubmissionStatus::Submitted)
                                        <p class="text-xs text-empower-muted">Generation hasn't started yet — click "Mark as Under Review" below to begin.</p>
                                    @else
                                        <p class="text-xs text-empower-muted">Waiting on AI generation{{ $document->created_at ? ' since '.$document->created_at->diffForHumans() : '' }}. Taking too long? Upload a custom file below instead.</p>
                                    @endif
                                @endif
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.65rem] font-extrabold uppercase tracking-wider {{ $badge[1] }}">{{ $badge[0] }}</span>
                                @if($document->isApproved())
                                    <button type="button" x-on:click="confirmAction = 'revokeApproval'; confirmDocumentId = {{ $document->id }}"
                                        class="text-xs font-bold text-red-600 hover:underline">Revoke</button>
                                @elseif($document->canBeApproved())
                                    <button type="button" x-on:click="confirmAction = 'approveDocument'; confirmDocumentId = {{ $document->id }}"
                                        class="text-xs font-bold text-[#0b9ed0] hover:underline">Approve</button>
                                @endif
                                <button type="button" x-on:click="confirmAction = 'deleteDocument'; confirmDocumentId = {{ $document->id }}"
                                    class="text-xs font-bold text-red-600 hover:underline">Delete</button>
                                @if($document->extractionFailed && $document->sourceUploadId)
                                    <button type="button" x-on:click="confirmAction = 'regenerateExtraction'; confirmDocumentId = {{ $document->id }}"
                                        class="text-xs font-bold text-[#0b9ed0] hover:underline">Regenerate</button>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="rounded-lg border {{ $aiSelected ? 'border-accent ring-1 ring-accent' : 'border-empower-border' }} bg-[#f9fcff] p-3">
                                @if($reviewable && ($document->pdf_storage_path || $document->docx_storage_path))
                                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                                        <input type="checkbox" wire:click="setDeliverySource({{ $document->id }}, 'ai_generated')" @checked($aiSelected) class="h-4 w-4 rounded border-empower-border text-accent focus:ring-accent">
                                        <span class="text-[0.65rem] font-extrabold uppercase tracking-wider text-empower-muted">AI-Generated File</span>
                                    </label>
                                @else
                                    <p class="text-[0.65rem] font-extrabold uppercase tracking-wider text-empower-muted mb-2">AI-Generated File</p>
                                @endif
                                @if($document->pdf_storage_path || $document->docx_storage_path)
                                    <a href="{{ route('admin.generated-documents.download', ['document' => $document->id, 'source' => 'ai']) }}" class="text-xs font-bold text-[#0b9ed0] hover:underline">Download AI-Generated File</a>
                                @else
                                    <p class="text-xs text-empower-muted">Not yet generated.</p>
                                @endif
                            </div>

                            <div class="rounded-lg border {{ $customSelected ? 'border-accent ring-1 ring-accent' : 'border-empower-border' }} bg-[#f9fcff] p-3">
                                @if($reviewable && $document->hasCustomDocument())
                                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                                        <input type="checkbox" wire:click="setDeliverySource({{ $document->id }}, 'custom')" @checked($customSelected) class="h-4 w-4 rounded border-empower-border text-accent focus:ring-accent">
                                        <span class="text-[0.65rem] font-extrabold uppercase tracking-wider text-empower-muted">Custom File</span>
                                    </label>
                                @else
                                    <p class="text-[0.65rem] font-extrabold uppercase tracking-wider text-empower-muted mb-2">Custom File</p>
                                @endif
                                @if($document->hasCustomDocument())
                                    <div class="flex items-center gap-3 mb-2">
                                        <a href="{{ route('admin.generated-documents.download', ['document' => $document->id, 'source' => 'custom']) }}" class="text-xs font-bold text-[#0b9ed0] hover:underline">Download Custom File</a>
                                        @if($reviewable)
                                            <button type="button" x-on:click="confirmAction = 'deleteCustom'; confirmDocumentId = {{ $document->id }}"
                                                class="text-xs font-bold text-red-600 hover:underline">
                                                Remove
                                            </button>
                                        @endif
                                    </div>
                                @endif

                                @if($document->showsCustomUploadSlot)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input wire:model="customDocumentFiles.{{ $document->id }}" type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                            wire:loading.attr="disabled" wire:target="customDocumentFiles.{{ $document->id }}"
                                            class="block text-xs text-[#5c778d] file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:font-bold file:bg-[#0e3a61] file:text-white hover:file:bg-[#0b2e4b] cursor-pointer">
                                        <span wire:loading wire:target="customDocumentFiles.{{ $document->id }}" class="text-xs font-semibold text-empower-muted">Uploading…</span>
                                    </div>
                                    @error("customDocumentFiles.{$document->id}") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                @elseif(! $document->hasCustomDocument())
                                    <p class="text-xs text-empower-muted">No custom file uploaded.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-empower-muted italic">No documents are expected — this package doesn't include any auto-generated manuals, and the practice hasn't uploaded a questionnaire that maps to one.</p>
                @endforelse
            </div>
        </div>

    @if(in_array($submission->status, [IntakeSubmissionStatus::Submitted, IntakeSubmissionStatus::UnderReview]))
        <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
            <h3 class="text-sm font-semibold text-navy mb-3">Ask the Client a Question</h3>

            @if($submission->reviewer_question && ! $submission->reviewer_question_reply)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 mb-3">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-700 mb-1">Waiting on client reply</p>
                    <p>{{ $submission->reviewer_question }}</p>
                </div>
            @elseif($submission->reviewer_question_reply)
                <div class="rounded-xl border border-empower-border bg-page px-4 py-3 text-sm text-empower-text mb-3">
                    <p class="text-xs font-bold uppercase tracking-wide text-empower-muted mb-1">Question</p>
                    <p class="mb-2">{{ $submission->reviewer_question }}</p>
                    <p class="text-xs font-bold uppercase tracking-wide text-empower-muted mb-1">Client's reply</p>
                    <p>{{ $submission->reviewer_question_reply }}</p>
                </div>
            @endif

            <div class="mb-3">
                <textarea wire:model="reviewerQuestionInput" rows="2"
                    placeholder="e.g. Is the HIPAA Privacy policy you uploaded the most recent version your staff use?"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                @error('reviewerQuestionInput') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="button" wire:click="askReviewerQuestion" wire:target="askReviewerQuestion" wire:loading.attr="disabled"
                class="inline-flex items-center gap-1 rounded-lg border border-empower-border px-5 py-2 text-sm font-bold text-navy hover:bg-page transition-colors">
                <span wire:loading.remove wire:target="askReviewerQuestion">{{ $submission->reviewer_question && ! $submission->reviewer_question_reply ? 'Ask another question' : 'Send question' }}</span>
                <span wire:loading.inline-flex wire:target="askReviewerQuestion" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Sending&hellip;</span>
            </button>
        </div>
    @endif

    @if($submission->status === IntakeSubmissionStatus::Rejected)
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            @if($submission->reviewer_notes)
                <p class="font-semibold mb-0.5">Reviewer notes sent to client:</p>
                <p class="mb-3">{{ $submission->reviewer_notes }}</p>
            @endif
            <p class="text-xs text-red-600 mb-2">Rejected by mistake? Reopening clears the reviewer notes and puts it back under review.</p>
            <button type="button" x-on:click="confirmAction = 'reopen'"
                class="inline-flex items-center gap-1 rounded border border-red-300 bg-white px-4 py-1.5 text-xs font-bold text-red-700 hover:bg-red-100 transition-colors">
                Reopen for Review
            </button>
        </div>
    @endif

    @if(in_array($submission->status, [IntakeSubmissionStatus::Submitted, IntakeSubmissionStatus::UnderReview]))
        <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
            <h3 class="text-sm font-semibold text-navy mb-3">Review Decision</h3>

            <div class="mb-4">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Reviewer notes (required to reject)</label>
                <textarea wire:model="reviewerNotes" rows="3" placeholder="Explain what the practice needs to fix…"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                @error('reviewerNotes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="button" x-on:click="confirmAction = 'approve'"
                    class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors">
                    Approve
                </button>
                <button type="button" x-on:click="confirmAction = 'reject'"
                    :disabled="!$wire.reviewerNotes || !$wire.reviewerNotes.trim()"
                    :class="(!$wire.reviewerNotes || !$wire.reviewerNotes.trim()) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-red-50'"
                    class="inline-flex items-center gap-1 rounded border border-red-300 px-5 py-2 text-sm font-bold text-red-700 transition-colors">
                    Reject
                </button>
            </div>
        </div>
    @endif

    {{-- Shared confirmation modal — text/label/danger-styling per action is looked up from modalText so
         adding a new confirmAction doesn't require touching a giant per-attribute ternary chain. --}}
    <div x-show="confirmAction !== null" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmAction = null">
            <h3 class="text-base font-semibold text-navy mb-2" x-text="modalText[confirmAction]?.title"></h3>
            <p class="text-sm text-empower-muted mb-5" x-text="modalText[confirmAction]?.body"></p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmAction = null"
                    class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                    Cancel
                </button>
                @php $modalTargets = 'approve,reject,deleteCustom,reopen,revokeApproval,approveDocument,deleteDocument,regenerateExtraction,deleteUpload,sendBackForResubmission'; @endphp
                <button type="button"
                    x-on:click="(confirmAction === 'approve' ? $wire.approve()
                        : confirmAction === 'reject' ? $wire.reject()
                        : confirmAction === 'deleteCustom' ? $wire.deleteCustomDocument(confirmDocumentId)
                        : confirmAction === 'reopen' ? $wire.reopen()
                        : confirmAction === 'revokeApproval' ? $wire.revokeApproval(confirmDocumentId)
                        : confirmAction === 'approveDocument' ? $wire.approveDocument(confirmDocumentId)
                        : confirmAction === 'deleteDocument' ? $wire.deleteGeneratedDocument(confirmDocumentId)
                        : confirmAction === 'regenerateExtraction' ? $wire.regenerateExtraction(confirmDocumentId)
                        : confirmAction === 'deleteUpload' ? $wire.deleteIntakeUpload(confirmUploadId)
                        : $wire.sendBackForResubmission()
                    ).then(() => confirmAction = null).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="{{ $modalTargets }}"
                    x-bind:class="modalText[confirmAction]?.danger ? 'bg-red-600 text-white hover:bg-red-700' : 'bg-accent text-navy-dark hover:bg-accent-dark'"
                    class="inline-flex items-center gap-1 rounded px-5 py-2 text-sm font-bold transition-colors">
                    <span wire:loading.remove wire:target="{{ $modalTargets }}" x-text="modalText[confirmAction]?.label"></span>
                    <span wire:loading.inline-flex wire:target="{{ $modalTargets }}" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Processing…</span>
                </button>
            </div>
        </div>
    </div>
</div>
