<?php

use App\Enums\AiExtractionStatus;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\UserRole;
use App\Mail\NewSpecialistCallRequestMail;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\SpecialistCallRequest;
use App\Models\User;
use App\Notifications\NewSpecialistCallRequestNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    /** The batch of orders this intake covers — same array the parent portal tracks. */
    public array $orderIds = [];

    // ── Documents screen ──────────────────────────────────────────────────
    // Matches the client prototype's BASE_DOCS list — the same 4 categories are shown (as
    // required for Essential, optional for Professional/Advanced) regardless of tier.
    private const DOCUMENT_CATEGORIES = [
        'compliance_ethics' => 'Compliance & Ethics Program',
        'hipaa_privacy' => 'HIPAA Privacy policies',
        'hipaa_security' => 'HIPAA Security policies',
        'training_materials' => 'Training materials',
    ];

    // Advanced-only, for its Coding & Documentation Mini Audit and Employee Manual Creation
    // features — appended to DOCUMENT_CATEGORIES via requiredDocumentCategories() below.
    private const ADVANCED_DOCUMENT_CATEGORIES = [
        'employee_manual' => 'Employee manual',
        'encounter_list' => 'Encounter list (10 per provider)',
    ];

    private const DOCUMENT_CATEGORY_KEYWORDS = [
        'hipaa_privacy' => ['privacy'],
        'hipaa_security' => ['security'],
        'compliance_ethics' => ['compliance', 'ethics', 'conduct'],
        'training_materials' => ['training'],
        'employee_manual' => ['employee', 'handbook'],
        'encounter_list' => ['encounter'],
    ];

    public array $documentFiles = [];

    /** Proposed document-type tag for each pending $documentFiles entry, index-aligned. */
    public array $documentFileTags = [];

    // ── Basics screens (4 sub-screens: b_profile/b_providers/b_address/b_logo) ─────────────
    public $logoFile = null;

    public string $practiceName = '';

    public string $practiceAddress = '';

    public string $specialty = 'General Practice';

    public int $billableProviders = 1;

    public bool $sameAsBillingAddress = true;

    // ── Team screens (Professional/Advanced/Complete only; 5 sub-screens: t_practice/
    // t_officers/t_it/t_hotline/t_leadership) ────────────────────────────
    public string $legalPracticeName = '';

    public string $dbaName = '';

    public string $otherEntities = '';

    public string $mainPhone = '';

    public string $mainEmail = '';

    /** @var array<int, string> */
    public array $practiceLocations = [''];

    public string $complianceOfficerName = '';

    public string $complianceOfficerPhone = '';

    public string $complianceOfficerEmail = '';

    public string $hipaaPrivacyOfficerName = '';

    public string $hipaaPrivacyOfficerPhone = '';

    public string $hipaaPrivacyOfficerEmail = '';

    public string $hipaaSecurityOfficerName = '';

    public string $hipaaSecurityOfficerPhone = '';

    public string $hipaaSecurityOfficerEmail = '';

    public string $releaseOfInfoOfficerName = '';

    public string $releaseOfInfoOfficerPhone = '';

    public string $releaseOfInfoOfficerEmail = '';

    /** '' (unanswered) | 'vendor' | 'inhouse' */
    public string $itMode = '';

    /** The outside company's name for itMode='vendor' — itContact* below covers both modes'
     *  individual contact (the vendor's rep, or the in-house staff member). */
    public string $itVendorName = '';

    public string $itContactName = '';

    public string $itContactPhone = '';

    public string $itContactEmail = '';

    /** null = unanswered, true = Empower's shared hotline, false = the practice's own. */
    public ?bool $usesEhcpHotline = null;

    /** The practice's own hotline number and/or email, combined into one field to match the
     *  prototype — stored in Practice::compliance_hotline_number (compliance_hotline_email is
     *  left for the admin user-edit form's own separate fields, not populated from here). */
    public string $hotlineContact = '';

    public ?int $hotlinePosterCount = null;

    public bool $committeeNone = false;

    /** @var array<int, array{name: string, title: string}> */
    public array $complianceCommitteeMembers = [];

    /** '' (unanswered) | 'owners' | 'board' */
    public string $boardMode = '';

    /** @var array<int, array{name: string, title: string}> */
    public array $complianceGoverningBoardMembers = [];

    // ── Navigation ────────────────────────────────────────────────────────
    /** 'documents' | 'b_profile' | 'b_providers' | 'b_address' | 'b_logo' | 't_practice' |
     *  't_officers' | 't_it' | 't_hotline' | 't_leadership' | 'question' | 'done' | 'intro' */
    public string $screen = 'documents';

    /** Set by viewWhatYouNeed() so backToQuestions() knows where to return — null means the
     *  'intro' screen is the first-time auto-advancing variant, not the revisited recap. */
    public ?string $returnToScreen = null;

    public ?int $currentQuestionId = null;

    public string $currentResponse = '';

    /** null = neither option chosen yet — a fresh question must show no pre-selected radio
     *  and no requirements/textarea, matching the prototype's "hidden until you choose" state. */
    public ?bool $currentHasDocumentedProcess = null;

    public bool $justSaved = false;

    public function mount(array $orderIds, ?string $editScreen = null): void
    {
        $this->orderIds = $orderIds;

        $practice = $this->practice;

        $this->practiceName = $practice?->name ?? '';
        $this->practiceAddress = $practice?->address ?? '';
        $this->specialty = $practice?->specialty ?? 'General Practice';
        $this->billableProviders = $practice?->billable_providers_count ?? 1;
        // Heuristic: no dedicated "matches billing" flag is persisted, so a saved address that
        // equals the current billing address line is treated as "same as billing" on resume.
        $this->sameAsBillingAddress = empty($practice?->address) || $practice->address === $this->billingAddressLine;

        $submission = $this->currentSubmission;
        $reached = $submission->wizard_reached_screens ?? [];

        // Falls back to the practice name already captured on the "Let's start with your
        // practice" basics screen, so the client isn't asked for the same name twice.
        $this->legalPracticeName = $practice?->legal_practice_name ?: ($practice?->name ?? '');
        $this->dbaName = $practice?->dba_name ?? '';
        $this->otherEntities = $practice?->other_entities ?? '';
        $this->mainPhone = $practice?->main_phone ?? '';
        $this->mainEmail = $practice?->main_email ?? '';
        $this->practiceLocations = ($practice?->practice_locations ?: null) ?? [$practice?->address ?: $this->billingAddressLine];

        $this->complianceOfficerName = $practice?->compliance_officer_name ?? '';
        $this->complianceOfficerPhone = $practice?->compliance_officer_phone ?? '';
        $this->complianceOfficerEmail = $practice?->compliance_officer_email ?? '';
        $this->hipaaPrivacyOfficerName = $practice?->hipaa_privacy_officer_name ?? '';
        $this->hipaaPrivacyOfficerPhone = $practice?->hipaa_privacy_officer_phone ?? '';
        $this->hipaaPrivacyOfficerEmail = $practice?->hipaa_privacy_officer_email ?? '';
        $this->hipaaSecurityOfficerName = $practice?->hipaa_security_officer_name ?? '';
        $this->hipaaSecurityOfficerPhone = $practice?->hipaa_security_officer_phone ?? '';
        $this->hipaaSecurityOfficerEmail = $practice?->hipaa_security_officer_email ?? '';
        $this->releaseOfInfoOfficerName = $practice?->release_of_info_officer_name ?? '';
        $this->releaseOfInfoOfficerPhone = $practice?->release_of_info_officer_phone ?? '';
        $this->releaseOfInfoOfficerEmail = $practice?->release_of_info_officer_email ?? '';

        $this->itMode = $practice?->it_mode ?? '';
        $this->itVendorName = $practice?->it_vendor_name ?? '';
        $this->itContactName = $practice?->it_contact_name ?? '';
        $this->itContactPhone = $practice?->it_contact_phone ?? '';
        $this->itContactEmail = $practice?->it_contact_email ?? '';

        // uses_ehcp_hotline defaults to false at the DB level, which would otherwise look
        // identical to a deliberate "we have our own" answer — only trust it once this screen
        // has actually been reached; before that, the radio starts genuinely unanswered.
        $this->usesEhcpHotline = in_array('t_hotline', $reached, true) ? (bool) $practice?->uses_ehcp_hotline : null;
        $this->hotlineContact = $practice?->compliance_hotline_number ?: ($practice?->compliance_hotline_email ?? '');
        $this->hotlinePosterCount = $practice?->hotline_poster_count;

        $this->committeeNone = (bool) ($practice?->committee_none ?? false);
        $this->complianceCommitteeMembers = $practice?->compliance_committee_members ?: [['name' => '', 'title' => '']];
        $this->boardMode = $practice?->board_mode ?? '';
        $this->complianceGoverningBoardMembers = $practice?->compliance_governing_board_members ?: [['name' => '', 'title' => '']];

        $this->screen = in_array($submission->wizard_screen, ['documents', 'b_profile', 'b_providers', 'b_address', 'b_logo', 't_practice', 't_officers', 't_it', 't_hotline', 't_leadership', 'question', 'done'], true)
            ? $submission->wizard_screen
            : 'documents';

        if (! $this->includesWorkflowQuestionnaire && in_array($this->screen, [...self::TEAM_SUB_SCREENS, 'question'], true)) {
            $this->screen = 'done';
        }

        // First-ever visit to the wizard (nothing marked reached yet) — show the "Before you
        // start" intro first, matching the prototype's screen between Step 1 and Step 2. Not
        // persisted to wizard_screen, so it never replays once 'documents' gets marked reached.
        if ($this->screen === 'documents' && empty($reached)) {
            $this->screen = 'intro';
        }

        if ($this->screen === 'question') {
            $this->loadQuestion($this->remainingQueue[0] ?? null);
        }

        // Step 3's "Your answers" review passes this when the client clicks "Edit" on a
        // specific answer — lands directly on that screen instead of wherever they left off.
        // Tells the parent to clear it once consumed, so a later plain "Back to intake" (which
        // also remounts this component fresh) doesn't replay the same stale jump.
        if ($editScreen !== null) {
            $this->jumpToScreen($editScreen);
            $this->dispatch('intake-edit-screen-consumed');
        }
    }

    private function primaryOrderId(): int
    {
        return min($this->orderIds);
    }

    #[Computed]
    public function practice(): ?Practice
    {
        return auth()->user()?->practice;
    }

    #[Computed]
    public function batchOrders(): Collection
    {
        return Order::whereIn('id', $this->orderIds)->with('package')->get();
    }

    #[Computed]
    public function includesWorkflowQuestionnaire(): bool
    {
        return $this->batchOrders->contains(fn (Order $o) => $o->package?->includesWorkflowQuestionnaire());
    }

    #[Computed]
    public function includesAdvancedDocumentCategories(): bool
    {
        return $this->batchOrders->contains(fn (Order $o) => $o->package?->includesAdvancedDocumentCategories());
    }

    #[Computed]
    public function currentSubmission(): IntakeSubmission
    {
        return IntakeSubmission::firstOrCreate(
            ['order_id' => $this->primaryOrderId()],
            ['status' => IntakeSubmissionStatus::Draft]
        );
    }

    #[Computed]
    public function existingDocuments(): Collection
    {
        return $this->currentSubmission->intakeUploads()
            ->where('upload_type', IntakeUploadType::ClientDocumentForReview)
            ->get();
    }

    #[Computed]
    public function billingAddressLine(): string
    {
        $billingAddress = $this->batchOrders->first()?->billing_address ?? [];

        return collect([
            $billingAddress['address1'] ?? null,
            $billingAddress['city'] ?? null,
            $billingAddress['state'] ?? null,
            $billingAddress['zip'] ?? null,
        ])->filter()->implode(', ');
    }

    #[Computed]
    public function providerInvoiceHint(): string
    {
        $order = $this->batchOrders->first();
        $package = $order?->package;

        if (! $order || ! $package) {
            return '';
        }

        $price = (float) ($package->priceForCycle($order->billing_cycle) ?? 0.0);
        $providers = max(1, $this->billableProviders);
        $total = $price * $providers;

        return sprintf(
            'Final invoice: %d provider%s &times; $%s = <strong>$%s</strong> / %s',
            $providers,
            $providers === 1 ? '' : 's',
            number_format($price, $price == floor($price) ? 0 : 2),
            number_format($total, $total == floor($total) ? 0 : 2),
            $order->billing_cycle?->period() ?? 'year'
        );
    }

    /** @return array<string, string> category key => label */
    #[Computed]
    public function requiredDocumentCategories(): array
    {
        return $this->includesAdvancedDocumentCategories
            ? [...self::DOCUMENT_CATEGORIES, ...self::ADVANCED_DOCUMENT_CATEGORIES]
            : self::DOCUMENT_CATEGORIES;
    }

    #[Computed]
    public function missingDocumentCategories(): array
    {
        return $this->currentSubmission->wizard_missing_document_categories ?? [];
    }

    /** 'uploaded' | 'declined' | 'needed' — combines already-persisted uploads with the tags
     *  proposed for files still sitting in $documentFiles, so the badge updates live before the
     *  practice ever clicks Continue. */
    public function documentCategoryStatus(string $key): string
    {
        if ($this->existingDocuments->contains(fn (IntakeUpload $u) => $u->document_category === $key)) {
            return 'uploaded';
        }

        foreach ($this->documentFileTags as $tag) {
            if ($tag === $key) {
                return 'uploaded';
            }
        }

        return in_array($key, $this->missingDocumentCategories, true) ? 'declined' : 'needed';
    }

    public function toggleDocumentMissing(string $key): void
    {
        $submission = $this->currentSubmission;
        $missing = $submission->wizard_missing_document_categories ?? [];

        $missing = in_array($key, $missing, true)
            ? array_values(array_diff($missing, [$key]))
            : [...$missing, $key];

        $submission->update(['wizard_missing_document_categories' => $missing]);
        unset($this->currentSubmission);
    }

    /** Auto-tags a newly selected file by matching keywords in its filename against the still-
     *  unresolved categories, mirroring the prototype's DOC_KEYWORDS matching — the practice can
     *  still override the guess via the "Document type" dropdown. */
    public function updatedDocumentFiles(): void
    {
        foreach ($this->documentFiles as $i => $file) {
            if (! empty($this->documentFileTags[$i])) {
                continue;
            }

            $name = strtolower($file->getClientOriginalName());

            foreach (self::DOCUMENT_CATEGORY_KEYWORDS as $key => $keywords) {
                if ($this->documentCategoryStatus($key) !== 'needed') {
                    continue;
                }

                if (collect($keywords)->contains(fn (string $kw) => str_contains($name, $kw))) {
                    $this->documentFileTags[$i] = $key;

                    break;
                }
            }
        }
    }

    public function tagExistingDocument(int $uploadId, ?string $category): void
    {
        $upload = $this->existingDocuments->firstWhere('id', $uploadId);

        if (! $upload || $upload->intake_submission_id !== $this->currentSubmission->id) {
            return;
        }

        $upload->update(['document_category' => $category ?: null]);
        unset($this->existingDocuments);

        if ($category && in_array($category, $this->missingDocumentCategories, true)) {
            $this->toggleDocumentMissing($category);
        }
    }

    #[Computed]
    public function sections(): Collection
    {
        return IntakeSection::with('questions.policies')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function masterQuestionIds(): array
    {
        return $this->sections->flatMap(fn (IntakeSection $s) => $s->questions)->pluck('id')->all();
    }

    #[Computed]
    public function answeredQuestionIds(): array
    {
        return $this->currentSubmission->intakeAnswers()->pluck('intake_question_id')->all();
    }

    #[Computed]
    public function skippedQuestionIds(): array
    {
        return $this->currentSubmission->wizard_skipped_question_ids ?? [];
    }

    /** Master order, minus already-answered; still-skipped ones are pushed to the end so
     *  "Skip for now" defers a question without losing it. */
    #[Computed]
    public function remainingQueue(): array
    {
        $remaining = array_values(array_diff($this->masterQuestionIds, $this->answeredQuestionIds));
        $skipped = $this->skippedQuestionIds;

        $unskipped = array_values(array_diff($remaining, $skipped));
        $stillSkipped = array_values(array_intersect($skipped, $remaining));

        return array_merge($unskipped, $stillSkipped);
    }

    /** True once every remaining question has been skipped at least once — the only time it's
     *  meaningful to offer "Finish questionnaire" instead of another "Skip for now". */
    #[Computed]
    public function onlySkippedQuestionsRemain(): bool
    {
        $remaining = $this->remainingQueue;

        return $remaining !== [] && array_diff($remaining, $this->skippedQuestionIds) === [];
    }

    #[Computed]
    public function currentQuestion(): ?IntakeQuestion
    {
        return $this->currentQuestionId
            ? IntakeQuestion::with('policies', 'section')->find($this->currentQuestionId)
            : null;
    }

    #[Computed]
    public function progressPercent(): int
    {
        $total = count($this->masterQuestionIds);

        if ($total === 0) {
            return 100;
        }

        return (int) round((count($this->answeredQuestionIds) / $total) * 100);
    }

    private const BASICS_SUB_SCREENS = ['b_profile', 'b_providers', 'b_address', 'b_logo'];

    private const TEAM_SUB_SCREENS = ['t_practice', 't_officers', 't_it', 't_hotline', 't_leadership'];

    /** The chapter list for the "Section X of Y" header dropdown — screen-level chapters for
     *  documents/team (worth one "done" item each), "Practice basics" worth one item per sub-
     *  screen, then one chapter per workflow section (worth as many items as it has questions) —
     *  mirrors the prototype's topHTML()/qz-sections. */
    #[Computed]
    public function chapters(): array
    {
        $screenDone = fn (string $key) => in_array($key, $this->reachedScreens, true) && $this->screen !== $key ? 1 : 0;

        $chapters = [
            ['key' => 'documents', 'label' => 'Your documents', 'total' => 1, 'done' => $screenDone('documents')],
            [
                'key' => 'basics',
                'label' => 'Practice basics',
                'total' => count(self::BASICS_SUB_SCREENS),
                'done' => collect(self::BASICS_SUB_SCREENS)->sum($screenDone),
            ],
        ];

        if (! $this->includesWorkflowQuestionnaire) {
            return $chapters;
        }

        $chapters[] = [
            'key' => 'team',
            'label' => 'Your team',
            'total' => count(self::TEAM_SUB_SCREENS),
            'done' => collect(self::TEAM_SUB_SCREENS)->sum($screenDone),
        ];

        foreach ($this->sections as $section) {
            $questionIds = $section->questions->pluck('id')->all();
            $chapters[] = [
                'key' => 'section:'.$section->id,
                'label' => $section->label,
                'total' => count($questionIds),
                'done' => count(array_intersect($questionIds, $this->answeredQuestionIds)),
            ];
        }

        return $chapters;
    }

    /** @return array{doneItems: int, totalItems: int, minutesLeft: int} */
    #[Computed]
    public function chapterProgress(): array
    {
        $chapters = $this->chapters;
        $totalItems = array_sum(array_column($chapters, 'total'));
        $doneItems = array_sum(array_column($chapters, 'done'));

        return [
            'doneItems' => $doneItems,
            'totalItems' => $totalItems,
            'minutesLeft' => max(1, (int) round(($totalItems - $doneItems) * 0.6)),
        ];
    }

    #[Computed]
    public function reachedScreens(): array
    {
        $reached = $this->currentSubmission->wizard_reached_screens ?? ['documents'];

        // The "Practice basics"/"Your team" chapter rows are keyed as 'basics'/'team' in the
        // dropdown, but the individual screens each groups are tracked under their own b_*/t_*
        // keys — surface the grouping key too once any of them has been reached, so the row
        // becomes navigable.
        if (array_intersect(self::BASICS_SUB_SCREENS, $reached) !== [] && ! in_array('basics', $reached, true)) {
            $reached[] = 'basics';
        }

        if (array_intersect(self::TEAM_SUB_SCREENS, $reached) !== [] && ! in_array('team', $reached, true)) {
            $reached[] = 'team';
        }

        return $reached;
    }

    private function markReached(string $key): void
    {
        $submission = $this->currentSubmission;
        $reached = $submission->wizard_reached_screens ?? [];

        if (! in_array($key, $reached, true)) {
            $reached[] = $key;
        }

        $submission->update(['wizard_reached_screens' => $reached]);
        unset($this->currentSubmission);
    }

    private function setWizardScreen(string $screen): void
    {
        $this->currentSubmission->update(['wizard_screen' => $screen]);
        unset($this->currentSubmission);
    }

    /** Every forward "Continue" used to call markReached() once or twice then setWizardScreen()
     *  separately — each a fetch-then-update-then-uncache round trip against intake_submissions,
     *  so a single step forward touched the row 2-3 times. This does it in one fetch + one update,
     *  which is the fix for the wizard's per-step lag (every "Continue"/"Save & Continue" click). */
    private function advanceWizardScreen(array $reachedKeys, string $screen): void
    {
        $submission = $this->currentSubmission;
        $reached = $submission->wizard_reached_screens ?? [];

        foreach ($reachedKeys as $key) {
            if (! in_array($key, $reached, true)) {
                $reached[] = $key;
            }
        }

        $submission->update([
            'wizard_reached_screens' => $reached,
            'wizard_screen' => $screen,
        ]);

        unset($this->currentSubmission);
    }

    private function currentNavKey(): string
    {
        return match (true) {
            $this->screen === 'question' => 'section:'.($this->currentQuestion?->intake_section_id ?? ''),
            in_array($this->screen, self::BASICS_SUB_SCREENS, true) => 'basics',
            in_array($this->screen, self::TEAM_SUB_SCREENS, true) => 'team',
            default => $this->screen,
        };
    }

    /** Jump to any already-reached nav entry — mirrors the prototype's "only already-reached
     *  sections are clickable" behavior. */
    public function jumpToScreen(string $key): void
    {
        if (str_starts_with($key, 'question:')) {
            $questionId = (int) substr($key, strlen('question:'));
            $sectionId = IntakeQuestion::find($questionId)?->intake_section_id;

            if ($sectionId === null || ! in_array('section:'.$sectionId, $this->reachedScreens, true)) {
                return;
            }

            $this->loadQuestion($questionId);
            $this->screen = 'question';
            $this->setWizardScreen('question');

            return;
        }

        if (! in_array($key, $this->reachedScreens, true) && $key !== $this->currentNavKey()) {
            return;
        }

        if (str_starts_with($key, 'section:')) {
            $sectionId = (int) substr($key, strlen('section:'));
            $sectionQuestionIds = $this->sections->firstWhere('id', $sectionId)?->questions->pluck('id')->all() ?? [];

            $firstUnanswered = collect($this->remainingQueue)->first(fn ($id) => in_array($id, $sectionQuestionIds, true));
            $target = $firstUnanswered ?? ($sectionQuestionIds[0] ?? null);

            $this->loadQuestion($target);
            $this->screen = 'question';
        } elseif ($key === 'basics') {
            $this->screen = collect(self::BASICS_SUB_SCREENS)
                ->first(fn (string $s) => ! in_array($s, $this->reachedScreens, true)) ?? self::BASICS_SUB_SCREENS[0];
        } elseif ($key === 'team') {
            $this->screen = collect(self::TEAM_SUB_SCREENS)
                ->first(fn (string $s) => ! in_array($s, $this->reachedScreens, true)) ?? self::TEAM_SUB_SCREENS[0];
        } else {
            $this->screen = $key;
        }

        $this->setWizardScreen($this->screen === 'question' ? 'question' : $this->screen);
    }

    // ── Documents ─────────────────────────────────────────────────────────

    public function removeDocumentFile(int $index): void
    {
        unset($this->documentFiles[$index], $this->documentFileTags[$index]);
        $this->documentFiles = array_values($this->documentFiles);
        $this->documentFileTags = array_values($this->documentFileTags);
        $this->resetErrorBag('documentFiles');
    }

    public function deleteExistingDocument(int $uploadId): void
    {
        $upload = $this->existingDocuments->firstWhere('id', $uploadId);

        if (! $upload || $upload->intake_submission_id !== $this->currentSubmission->id) {
            return;
        }

        if ($upload->storage_path) {
            Storage::disk('local')->delete($upload->storage_path);
        }

        $upload->delete();
        unset($this->existingDocuments);
    }

    /** @param bool $skipValidation "Skip for now"/"Save & continue later" bypass this screen's
     *  completeness checks — matching the prototype, skipping never bypasses real file-integrity
     *  validation, only the "have you resolved every category" checks.
     *  @param bool $stayOnScreen "Save & continue later" persists progress without advancing. */
    public function continueFromDocuments(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        // Professional/Advanced treat this screen as fully optional, same as the prototype's
        // isPro() bypass — only Essential enforces the checks below.
        if (! $skipValidation && ! $this->includesWorkflowQuestionnaire) {
            if ($this->existingDocuments->isEmpty() && empty($this->documentFiles)) {
                $this->addError('documentFiles', 'Please upload at least one existing document, so our team has something to review.');

                return;
            }

            $untagged = collect($this->documentFiles)
                ->filter(fn ($file, $i) => empty($this->documentFileTags[$i]))
                ->count();

            if ($untagged > 0) {
                $this->addError('documentFiles', $untagged > 1
                    ? "Tell us what {$untagged} files are, using the \"Document type\" menu."
                    : 'Tell us what your file is, using the "Document type" menu.');

                return;
            }

            $missing = collect(self::DOCUMENT_CATEGORIES)
                ->filter(fn ($label, $key) => $this->documentCategoryStatus($key) === 'needed')
                ->values();

            if ($missing->isNotEmpty()) {
                $this->addError('documentFiles', 'Upload or mark "I don\'t have this" for: '.$missing->implode(', ').'.');

                return;
            }
        }

        $this->validate([
            'documentFiles.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png,docx|max:20480',
        ]);

        $submission = $this->currentSubmission;
        $batchToken = (string) Str::ulid();

        foreach ($this->documentFiles as $i => $file) {
            IntakeUpload::create([
                'intake_submission_id' => $submission->id,
                'upload_type' => IntakeUploadType::ClientDocumentForReview,
                'document_category' => $this->documentFileTags[$i] ?? null,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $file->store("uploads/batch/{$batchToken}"),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                // Left as-is while the client is still drafting the wizard, so re-uploading or
                // removing a file mid-draft never burns an OpenAI call. ⚡portal.blade.php's
                // finalizeIntake() flips this to Pending and dispatches the AI compliance review
                // once the intake is actually submitted.
                'ai_extraction_status' => AiExtractionStatus::NotApplicable,
            ]);
        }

        $this->documentFiles = [];
        $this->documentFileTags = [];
        unset($this->existingDocuments);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $this->screen = 'b_profile';
        $this->advanceWizardScreen(['documents', 'b_profile'], 'b_profile');
    }

    // ── Basics: 1) Profile ───────────────────────────────────────────────────

    private function profileRules(): array
    {
        return [
            'practiceName' => 'required|string|max:150',
            'specialty' => 'required|string|max:100',
        ];
    }

    public function backToDocuments(): void
    {
        $this->screen = 'documents';
        $this->setWizardScreen('documents');
    }

    public function continueFromProfile(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        if (! $skipValidation) {
            $this->validate($this->profileRules());
        }

        $practice = $this->practice ?? Practice::create(['user_id' => auth()->id(), 'name' => $this->practiceName]);

        $practice->update([
            'name' => $practice->is_profile_locked ? $practice->name : ($this->practiceName ?: $practice->name),
            'specialty' => $this->specialty ?: null,
        ]);

        // The name lock takes effect the moment a real (non-skipped, non-saved-for-later) name is
        // provided — this only governs the Practice Name field; the logo stays freely editable
        // (Replace/Remove) on its own screen regardless of lock state.
        if (! $skipValidation && ! $stayOnScreen && ! $practice->is_profile_locked) {
            $practice->update(['is_profile_locked' => true, 'locked_at' => $practice->locked_at ?? now()]);
        }

        unset($this->practice);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $this->screen = 'b_providers';
        $this->advanceWizardScreen(['b_profile', 'b_providers'], 'b_providers');
    }

    // ── Basics: 2) Providers (read-only — confirmed at checkout in Step 1) ───────────────────

    public function backToProfile(): void
    {
        $this->screen = 'b_profile';
        $this->setWizardScreen('b_profile');
    }

    /** No validation or persistence needed any more — the count was already saved to the
     *  Practice at Step 1 checkout (⚡portal.blade.php's pay()/payFreeTrial()), which is also
     *  what drove the price charged. This screen just confirms it before moving on. */
    public function continueFromProviders(): void
    {
        $this->screen = 'b_address';
        $this->advanceWizardScreen(['b_providers', 'b_address'], 'b_address');
    }

    // ── Basics: 3) Address ───────────────────────────────────────────────────

    public function backToProviders(): void
    {
        $this->screen = 'b_providers';
        $this->setWizardScreen('b_providers');
    }

    public function continueFromAddress(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        if (! $skipValidation && ! $this->sameAsBillingAddress) {
            $this->validate(['practiceAddress' => 'required|string|max:255']);
        }

        $address = $this->sameAsBillingAddress ? $this->billingAddressLine : $this->practiceAddress;
        $this->practice?->update(['address' => $address ?: null]);
        unset($this->practice);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $this->screen = 'b_logo';
        $this->advanceWizardScreen(['b_address', 'b_logo'], 'b_logo');
    }

    // ── Basics: 4) Logo (always optional) ────────────────────────────────────

    public function backToAddress(): void
    {
        $this->screen = 'b_address';
        $this->setWizardScreen('b_address');
    }

    public function removeLogo(): void
    {
        $practice = $this->practice;

        if ($practice?->logo_path) {
            Storage::disk('public')->delete($practice->logo_path);
            $practice->update(['logo_path' => null]);
            unset($this->practice);
        }
    }

    public function continueFromLogo(bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        $this->validate(['logoFile' => 'nullable|file|mimes:png,jpg,jpeg|max:2048']);

        $practice = $this->practice;

        if ($this->logoFile && $practice) {
            if ($practice->logo_path) {
                Storage::disk('public')->delete($practice->logo_path);
            }

            $practice->update(['logo_path' => $this->logoFile->store('logos', 'public')]);
        }

        $this->logoFile = null;
        unset($this->practice);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        if ($this->includesWorkflowQuestionnaire) {
            $this->screen = 't_practice';
            $this->advanceWizardScreen(['b_logo', 't_practice'], 't_practice');
        } else {
            // Same cosmetic "saving" transition the workflow-questionnaire tiers get after their
            // last question — not persisted, its own timer calls finishWizard().
            $this->markReached('b_logo');
            $this->screen = 'saving';
        }
    }

    // ── Team: 1/5 — Your practice's legal details ───────────────────────────

    private function filterMembers(array $members): array
    {
        return collect($members)->filter(fn ($m) => trim($m['name'] ?? '') !== '')->values()->all();
    }

    public function addLocation(): void
    {
        $this->practiceLocations[] = '';
    }

    public function removeLocation(int $index): void
    {
        unset($this->practiceLocations[$index]);
        $this->practiceLocations = array_values($this->practiceLocations) ?: [''];
    }

    public function backToBasics(): void
    {
        $this->screen = 'b_logo';
        $this->setWizardScreen('b_logo');
    }

    public function continueFromPractice(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        if (! $skipValidation) {
            $validator = Validator::make(
                [
                    'legalPracticeName' => $this->legalPracticeName,
                    'mainPhone' => $this->mainPhone,
                    'mainEmail' => $this->mainEmail,
                    'practiceLocations' => $this->practiceLocations,
                ],
                [
                    'legalPracticeName' => 'required|string|max:200',
                    'mainPhone' => 'required|string|max:30',
                    'mainEmail' => 'required|email|max:150',
                    'practiceLocations' => 'required|array|min:1',
                    'practiceLocations.*' => 'nullable|string|max:255',
                ],
                ['practiceLocations.required' => 'Add at least one location.'],
            );

            $validator->after(function ($validator) {
                if (collect($this->practiceLocations)->filter(fn ($l) => trim($l) !== '')->isEmpty()) {
                    $validator->errors()->add('practiceLocations', 'Add at least one location.');
                }
            });

            $validator->validate();
        }

        $this->practice?->update([
            'legal_practice_name' => $this->legalPracticeName,
            'dba_name' => $this->dbaName ?: null,
            'other_entities' => $this->otherEntities ?: null,
            'main_phone' => $this->mainPhone,
            'main_email' => $this->mainEmail,
            'practice_locations' => collect($this->practiceLocations)->filter(fn ($l) => trim($l) !== '')->values()->all(),
        ]);
        unset($this->practice);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $this->screen = 't_officers';
        $this->advanceWizardScreen(['t_practice'], 't_officers');
    }

    // ── Team: 2/5 — Who fills your compliance roles? ────────────────────────

    private const OFFICER_PREFIXES = [
        'hipaaPrivacyOfficer' => 'HIPAA Privacy Officer',
        'hipaaSecurityOfficer' => 'HIPAA Security Officer',
        'releaseOfInfoOfficer' => 'Release of Information Officer',
        'complianceOfficer' => 'Compliance Officer',
    ];

    /** @return array<string, string> */
    #[Computed]
    public function officerPrefixes(): array
    {
        return self::OFFICER_PREFIXES;
    }

    /** People already known by this screen — the account holder plus any other officer that
     *  already has a name — offered as "Same person as…" options for each officer card. */
    #[Computed]
    public function knownTeamPeople(): array
    {
        $people = ['you' => 'You ('.(auth()->user()?->name ?: 'you').')'];

        foreach (self::OFFICER_PREFIXES as $prefix => $label) {
            $name = $this->{$prefix.'Name'};

            if (trim((string) $name) !== '') {
                $people[$prefix] = "{$label} ({$name})";
            }
        }

        return $people;
    }

    /** Quick-add roster for the Compliance Committee / governing board lists — deduplicated by
     *  name, so a person holding several officer roles (or matching the account holder) gets
     *  exactly one pill instead of one per role, and their title pre-fills as every role they
     *  hold, comma-separated. A name with no officer role at all (just the account holder, with
     *  nothing else matching) gets an empty role string.
     *
     * @return array<int, array{name: string, roles: string}>
     */
    #[Computed]
    public function knownTeamRoster(): array
    {
        $entries = collect();

        $accountName = trim((string) (auth()->user()?->name ?? ''));

        if ($accountName !== '') {
            $entries->push(['name' => $accountName, 'role' => null]);
        }

        foreach (self::OFFICER_PREFIXES as $prefix => $label) {
            $name = trim((string) $this->{$prefix.'Name'});

            if ($name !== '') {
                $entries->push(['name' => $name, 'role' => $label]);
            }
        }

        return $entries->groupBy('name')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'roles' => $group->pluck('role')->filter()->unique()->implode(', '),
            ])
            ->values()
            ->all();
    }

    /** Copies a name/phone/email from the account holder or another officer onto $toPrefix,
     *  mirroring the prototype's "Same person as…" dropdown. */
    public function copyOfficerContact(string $toPrefix, string $source): void
    {
        if ($source === '' || ! array_key_exists($toPrefix, self::OFFICER_PREFIXES)) {
            return;
        }

        if ($source === 'you') {
            $this->{$toPrefix.'Name'} = auth()->user()?->name ?? '';
            $this->{$toPrefix.'Email'} = auth()->user()?->email ?? '';

            return;
        }

        if (! array_key_exists($source, self::OFFICER_PREFIXES)) {
            return;
        }

        $this->{$toPrefix.'Name'} = $this->{$source.'Name'};
        $this->{$toPrefix.'Phone'} = $this->{$source.'Phone'};
        $this->{$toPrefix.'Email'} = $this->{$source.'Email'};
    }

    /** Same "Same person as…" copy behavior as copyOfficerContact(), for the in-house IT
     *  contact — a plain property trio, not one of the 4 named officer roles. */
    public function copyItContact(string $source): void
    {
        if ($source === 'you') {
            $this->itContactName = auth()->user()?->name ?? '';
            $this->itContactEmail = auth()->user()?->email ?? '';

            return;
        }

        if (! array_key_exists($source, self::OFFICER_PREFIXES)) {
            return;
        }

        $this->itContactName = $this->{$source.'Name'};
        $this->itContactPhone = $this->{$source.'Phone'};
        $this->itContactEmail = $this->{$source.'Email'};
    }

    public function backToPractice(): void
    {
        $this->screen = 't_practice';
        $this->setWizardScreen('t_practice');
    }

    public function continueFromOfficers(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        if (! $skipValidation) {
            $this->validate([
                'complianceOfficerName' => 'required|string|max:150',
                'complianceOfficerPhone' => 'required|string|max:30',
                'complianceOfficerEmail' => 'required|email|max:150',
                'hipaaPrivacyOfficerName' => 'required|string|max:150',
                'hipaaPrivacyOfficerPhone' => 'required|string|max:30',
                'hipaaPrivacyOfficerEmail' => 'required|email|max:150',
                'hipaaSecurityOfficerName' => 'required|string|max:150',
                'hipaaSecurityOfficerPhone' => 'required|string|max:30',
                'hipaaSecurityOfficerEmail' => 'required|email|max:150',
                'releaseOfInfoOfficerName' => 'required|string|max:150',
                'releaseOfInfoOfficerPhone' => 'required|string|max:30',
                'releaseOfInfoOfficerEmail' => 'required|email|max:150',
            ]);
        }

        $this->practice?->update([
            'compliance_officer_name' => $this->complianceOfficerName,
            'compliance_officer_phone' => $this->complianceOfficerPhone,
            'compliance_officer_email' => $this->complianceOfficerEmail,
            'hipaa_privacy_officer_name' => $this->hipaaPrivacyOfficerName,
            'hipaa_privacy_officer_phone' => $this->hipaaPrivacyOfficerPhone,
            'hipaa_privacy_officer_email' => $this->hipaaPrivacyOfficerEmail,
            'hipaa_security_officer_name' => $this->hipaaSecurityOfficerName,
            'hipaa_security_officer_phone' => $this->hipaaSecurityOfficerPhone,
            'hipaa_security_officer_email' => $this->hipaaSecurityOfficerEmail,
            'release_of_info_officer_name' => $this->releaseOfInfoOfficerName,
            'release_of_info_officer_phone' => $this->releaseOfInfoOfficerPhone,
            'release_of_info_officer_email' => $this->releaseOfInfoOfficerEmail,
        ]);
        unset($this->practice);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $this->screen = 't_it';
        $this->advanceWizardScreen(['t_officers'], 't_it');
    }

    // ── Team: 3/5 — Who handles your IT? ─────────────────────────────────────

    public function backToOfficers(): void
    {
        $this->screen = 't_officers';
        $this->setWizardScreen('t_officers');
    }

    public function continueFromIt(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        if (! $skipValidation) {
            $rules = ['itMode' => 'required|in:vendor,inhouse'];

            if ($this->itMode === 'vendor') {
                $rules['itVendorName'] = 'required|string|max:150';
                $rules['itContactPhone'] = 'required|string|max:30';
                $rules['itContactEmail'] = 'required|email|max:150';
            } elseif ($this->itMode === 'inhouse') {
                $rules['itContactName'] = 'required|string|max:150';
                $rules['itContactPhone'] = 'required|string|max:30';
                $rules['itContactEmail'] = 'required|email|max:150';
            }

            $this->validate($rules);
        }

        $this->practice?->update([
            'it_mode' => $this->itMode ?: null,
            'it_vendor_name' => $this->itMode === 'vendor' ? $this->itVendorName : null,
            'it_contact_name' => $this->itContactName ?: null,
            'it_contact_phone' => $this->itContactPhone ?: null,
            'it_contact_email' => $this->itContactEmail ?: null,
        ]);
        unset($this->practice);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $this->screen = 't_hotline';
        $this->advanceWizardScreen(['t_it'], 't_hotline');
    }

    // ── Team: 4/5 — How can staff reach a compliance hotline? ────────────────

    public function backToIt(): void
    {
        $this->screen = 't_it';
        $this->setWizardScreen('t_it');
    }

    public function continueFromHotline(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        if (! $skipValidation) {
            $rules = ['usesEhcpHotline' => 'required'];

            if ($this->usesEhcpHotline === false) {
                $rules['hotlineContact'] = 'required|string|max:200';
            }

            $this->validate($rules, ['usesEhcpHotline.required' => 'Choose one option.']);
        }

        $locationCount = max(1, collect($this->practiceLocations)->filter(fn ($l) => trim($l) !== '')->count());
        $posterCount = $this->hotlinePosterCount ?? ($locationCount * 2);

        $this->practice?->update([
            'uses_ehcp_hotline' => (bool) $this->usesEhcpHotline,
            'compliance_hotline_number' => $this->usesEhcpHotline === false ? $this->hotlineContact : null,
            'hotline_poster_count' => $posterCount,
        ]);
        unset($this->practice);
        $this->hotlinePosterCount = $posterCount;

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $this->screen = 't_leadership';
        $this->advanceWizardScreen(['t_hotline'], 't_leadership');
    }

    // ── Team: 5/5 — Who leads compliance oversight? ──────────────────────────

    public function addCommitteeMember(): void
    {
        $this->complianceCommitteeMembers[] = ['name' => '', 'title' => ''];
    }

    public function removeCommitteeMember(int $index): void
    {
        unset($this->complianceCommitteeMembers[$index]);
        $this->complianceCommitteeMembers = array_values($this->complianceCommitteeMembers) ?: [['name' => '', 'title' => '']];
    }

    public function addBoardMember(): void
    {
        $this->complianceGoverningBoardMembers[] = ['name' => '', 'title' => ''];
    }

    public function removeBoardMember(int $index): void
    {
        unset($this->complianceGoverningBoardMembers[$index]);
        $this->complianceGoverningBoardMembers = array_values($this->complianceGoverningBoardMembers) ?: [['name' => '', 'title' => '']];
    }

    /** One-click add for the Compliance Committee / governing board lists, from the account
     *  holder's name or any officer already named on the previous screen. */
    public function quickAddMember(string $group, string $name, string $roles = ''): void
    {
        if (trim($name) === '') {
            return;
        }

        $property = $group === 'board' ? 'complianceGoverningBoardMembers' : 'complianceCommitteeMembers';
        $members = $this->{$property};

        if (collect($members)->contains(fn ($m) => ($m['name'] ?? '') === $name)) {
            return;
        }

        $members = collect($members)->filter(fn ($m) => trim($m['name'] ?? '') !== '')->values()->all();
        $members[] = ['name' => $name, 'title' => $roles];
        $this->{$property} = $members;
    }

    public function backToHotline(): void
    {
        $this->screen = 't_hotline';
        $this->setWizardScreen('t_hotline');
    }

    public function continueFromLeadership(bool $skipValidation = false, bool $stayOnScreen = false): void
    {
        $this->justSaved = false;

        if (! $skipValidation) {
            $this->validate([
                'boardMode' => 'required|in:owners,board',
                'complianceCommitteeMembers.*.name' => 'nullable|string|max:150',
                'complianceCommitteeMembers.*.title' => 'nullable|string|max:150',
                'complianceGoverningBoardMembers.*.name' => 'nullable|string|max:150',
                'complianceGoverningBoardMembers.*.title' => 'nullable|string|max:150',
            ], ['boardMode.required' => 'Choose one option.']);

            if (! $this->committeeNone && collect($this->complianceCommitteeMembers)->every(fn ($m) => trim($m['name'] ?? '') === '')) {
                $this->addError('complianceCommitteeMembers', 'Add a committee member, or tick "We don\'t have a committee yet."');

                return;
            }

            if (collect($this->complianceGoverningBoardMembers)->every(fn ($m) => trim($m['name'] ?? '') === '')) {
                $this->addError('complianceGoverningBoardMembers', $this->boardMode === 'board' ? 'List each board member.' : 'List the owners or partners who oversee compliance.');

                return;
            }
        }

        $this->practice?->update([
            'committee_none' => $this->committeeNone,
            'compliance_committee_members' => $this->committeeNone ? [] : $this->filterMembers($this->complianceCommitteeMembers),
            'board_mode' => $this->boardMode ?: null,
            'compliance_governing_board_members' => $this->filterMembers($this->complianceGoverningBoardMembers),
        ]);
        unset($this->practice);

        if ($stayOnScreen) {
            $this->justSaved = true;

            return;
        }

        $first = $this->remainingQueue[0] ?? null;

        if ($first === null) {
            $this->markReached('t_leadership');
            $this->finishWizard();

            return;
        }

        $this->loadQuestion($first);
        $this->screen = 'question';
        $this->advanceWizardScreen(['t_leadership', 'section:'.$this->currentQuestion->intake_section_id], 'question');
    }

    // ── Questions ─────────────────────────────────────────────────────────

    private function loadQuestion(?int $questionId): void
    {
        $this->currentQuestionId = $questionId;
        $this->justSaved = false;

        if ($questionId === null) {
            $this->currentResponse = '';
            $this->currentHasDocumentedProcess = null;

            return;
        }

        $existing = $this->currentSubmission->intakeAnswers()->where('intake_question_id', $questionId)->first();

        $this->currentResponse = $existing?->response ?? '';
        $this->currentHasDocumentedProcess = $existing ? (bool) $existing->has_documented_process : null;
    }

    public function chooseDocumentedProcess(): void
    {
        $this->currentHasDocumentedProcess = true;
    }

    public function chooseNoDocumentedProcess(): void
    {
        $this->currentHasDocumentedProcess = false;
        $this->saveCurrentAnswer('');
    }

    public function saveCurrentAnswer(?string $response = null): void
    {
        $response = $response ?? $this->currentResponse;

        if ($this->currentHasDocumentedProcess === null) {
            $this->addError('currentResponse', 'Choose one of the two options above.');

            return;
        }

        if ($this->currentHasDocumentedProcess === true && trim((string) $response) === '') {
            $this->addError('currentResponse', 'Please describe your practice\'s process, or choose "We don\'t have a documented answer" instead.');

            return;
        }

        $submission = $this->currentSubmission;

        $submission->intakeAnswers()->updateOrCreate(
            ['intake_question_id' => $this->currentQuestionId],
            [
                'response' => $this->currentHasDocumentedProcess === true ? $response : null,
                'has_documented_process' => $this->currentHasDocumentedProcess,
                'skipped' => false,
                'answered_at' => now(),
            ]
        );

        $skipped = $submission->wizard_skipped_question_ids ?? [];
        if (in_array($this->currentQuestionId, $skipped, true)) {
            $submission->update(['wizard_skipped_question_ids' => array_values(array_diff($skipped, [$this->currentQuestionId]))]);
        }

        unset($this->currentSubmission);
        $this->resetErrorBag('currentResponse');
        $this->advanceToNextQuestion();
    }

    public function skipCurrentQuestion(): void
    {
        $submission = $this->currentSubmission;
        $skipped = $submission->wizard_skipped_question_ids ?? [];

        if (! in_array($this->currentQuestionId, $skipped, true)) {
            $skipped[] = $this->currentQuestionId;
        }

        $submission->update(['wizard_skipped_question_ids' => $skipped]);
        unset($this->currentSubmission);

        $this->advanceToNextQuestion();
    }

    private function advanceToNextQuestion(): void
    {
        $next = $this->remainingQueue[0] ?? null;

        if ($next === null) {
            // Deliberately not persisted to wizard_screen — this is a few-second cosmetic
            // transition, not a real resumable step. A refresh mid-animation just lands back on
            // the last question, which re-triggers it.
            $this->screen = 'saving';

            return;
        }

        $this->loadQuestion($next);
        $this->advanceWizardScreen(['section:'.$this->currentQuestion->intake_section_id], 'question');
    }

    public function backOneQuestion(): void
    {
        $master = $this->masterQuestionIds;
        $position = array_search($this->currentQuestionId, $master, true);

        if ($position === false || $position === 0) {
            $this->screen = 't_leadership';
            $this->setWizardScreen('t_leadership');

            return;
        }

        $this->loadQuestion($master[$position - 1]);
    }

    /** Called by the "saving" screen's own timer once its checklist animation finishes. */
    public function finishWizard(): void
    {
        $this->screen = 'done';
        $this->setWizardScreen('done');

        // Matches the reference prototype: skip the "you're through the intake" screen and go
        // straight to Step 3 when nothing was left unanswered — it only needs to show when there
        // are skipped questions the client might want to go back and answer.
        if ($this->skippedQuestionIds === []) {
            $this->continueToConfirm();
        }
    }

    /** Called by the "intro" screen's own timer once its checklist animation finishes. */
    public function continueFromIntro(): void
    {
        $this->screen = 'documents';
        $this->markReached('documents');
    }

    /** "What you'll need" in the section-jump dropdown — reopens the intro screen as a static
     *  reference recap (no loading animation, no auto-advance) instead of a popup, so it matches
     *  the same screen a first-time visitor saw between Step 1 and Step 2. */
    public function viewWhatYouNeed(): void
    {
        $this->returnToScreen = $this->screen;
        $this->screen = 'intro';
    }

    /** The revisited intro screen's "Back to the questions" button. */
    public function backToQuestions(): void
    {
        $this->screen = $this->returnToScreen ?? 'documents';
        $this->returnToScreen = null;
    }

    // ── "Talk to a specialist" call booking ─────────────────────────────────
    private const CALL_TIME_SLOTS = ['9:00 AM', '10:30 AM', '12:00 PM', '1:30 PM', '3:00 PM', '4:30 PM'];

    private const CALL_TOPICS = [
        'A question in the intake',
        'Choosing the right package',
        'Pricing or billing',
        'Something else',
    ];

    public bool $callDialogOpen = false;

    public bool $callBooked = false;

    #[Validate('required|date')]
    public ?string $callDate = null;

    #[Validate('required|string')]
    public ?string $callTime = null;

    #[Validate('required|regex:/^\+?[1-9]\d{7,14}$/')]
    public string $callPhone = '';

    public string $callTopic = 'A question in the intake';

    #[Validate('nullable|string|max:1000')]
    public string $callNotes = '';

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'callPhone.regex' => 'Please enter a valid international phone number, digits only (e.g. +15551234567).',
        ];
    }

    /** @return array<int, \Illuminate\Support\Carbon> the next 5 weekdays, starting tomorrow. */
    #[Computed]
    public function availableCallDays(): array
    {
        $days = [];
        $cursor = now()->addDay();

        while (count($days) < 5) {
            if (! $cursor->isWeekend()) {
                $days[] = $cursor->copy();
            }

            $cursor->addDay();
        }

        return $days;
    }

    /** @return array<int, string> */
    public function availableCallTimes(): array
    {
        return self::CALL_TIME_SLOTS;
    }

    /** @return array<int, string> */
    public function availableCallTopics(): array
    {
        return self::CALL_TOPICS;
    }

    public function openCallDialog(): void
    {
        $this->callDialogOpen = true;
        $this->callBooked = false;
        $this->callDate = $this->availableCallDays[0]->toDateString();
        $this->callTime = self::CALL_TIME_SLOTS[0];
        $this->callPhone = '';
        $this->callTopic = self::CALL_TOPICS[0];
        $this->callNotes = '';
        $this->resetErrorBag();
    }

    public function closeCallDialog(): void
    {
        $this->callDialogOpen = false;
        $this->callBooked = false;
    }

    public function bookSpecialistCall(): void
    {
        $this->validate();

        $order = $this->batchOrders->first();

        $callRequest = SpecialistCallRequest::create([
            'user_id' => auth()->id(),
            'order_id' => $order?->id,
            'requested_date' => $this->callDate,
            'requested_time' => $this->callTime,
            'phone' => $this->callPhone,
            'topic' => $this->callTopic,
            'notes' => $this->callNotes ?: null,
        ]);

        $admins = User::where('role', UserRole::Admin)->get();

        $admins->each(function (User $admin) use ($callRequest) {
            try {
                Mail::to($admin->email)->send(new NewSpecialistCallRequestMail($callRequest));
            } catch (\Throwable $e) {
                report($e);
            }
        });

        try {
            Notification::send($admins, new NewSpecialistCallRequestNotification($callRequest));
        } catch (\Throwable $e) {
            report($e);
        }

        $this->callBooked = true;
    }

    /** "Review all answers" in the section-jump dropdown — lets the client preview Step 3's
     *  summary at any point in the questionnaire, matching the reference prototype. */
    public function requestReview(): void
    {
        $this->dispatch('intake-wizard-review-requested');
    }

    /** The "done" screen's "Continue to Upload & Confirm" button — only now does the parent
     *  portal actually advance to Step 3, so the client sees the intake-complete confirmation
     *  screen first, matching the reference prototype. */
    public function continueToConfirm(): void
    {
        $this->dispatch('intake-wizard-complete');
    }
};
?>

<div class="space-y-5">
    @php
        $chapterHeaderData = [
            'chapters' => $this->chapters,
            'currentKey' => $this->currentNavKey(),
            'reachedScreens' => $this->reachedScreens,
            'doneItems' => $this->chapterProgress['doneItems'],
            'totalItems' => $this->chapterProgress['totalItems'],
            'minutesLeft' => $this->chapterProgress['minutesLeft'],
            'skippedCount' => count($this->skippedQuestionIds),
        ];
    @endphp

    {{-- ── Intro: auto-advancing on a first-ever visit, or a static recap when revisited via
         the "What you'll need" dropdown item ($returnToScreen set means the latter) ── --}}
    @if($screen === 'intro')
    @php $isRevisit = $returnToScreen !== null; @endphp
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        <div class="flex flex-col items-center text-center px-6 py-12 lg:py-16 max-w-xl mx-auto"
            @unless($isRevisit)
            x-data="{ progress: 0 }"
            x-init="
                setTimeout(() => progress = 100, 50);
                setTimeout(() => $wire.continueFromIntro(), 1800);
            "
            @endunless
            >
            <h2 class="text-2xl font-extrabold text-[#0e1b30] mb-5">Before you start, here&rsquo;s what you&rsquo;ll
                need</h2>

            @if($isRevisit)
            <p class="text-sm text-[#5d6e7f] mb-5 max-w-md">Your intake is ready. It takes about 5 minutes, and your
                answers save as you go. Missing something? You can skip any question and come back to it.</p>
            <div class="w-full max-w-sm h-1.5 rounded-full bg-[#eef2f6] overflow-hidden mb-8">
                <div class="h-full bg-[#0b9ed0] rounded-full"
                    style="width: {{ $this->chapterProgress['totalItems'] > 0 ? round($this->chapterProgress['doneItems'] / $this->chapterProgress['totalItems'] * 100) : 0 }}%">
                </div>
            </div>
            @else
            <div class="flex items-center gap-2 text-sm font-semibold text-[#0b9ed0] mb-3">
                <x-spinner class="h-4 w-4" />
                <span>Getting your intake ready&hellip;</span>
            </div>
            <div class="w-full max-w-sm h-1.5 rounded-full bg-[#eef2f6] overflow-hidden mb-8">
                <div class="h-full bg-[#0b9ed0] rounded-full transition-all duration-[1600ms] ease-out"
                    :style="`width: ${progress}%`"></div>
            </div>
            @endif

            <div class="w-full space-y-3 text-left">
                <div class="flex items-start gap-3 rounded-xl border border-[#dbe4ee] px-4 py-3.5">
                    <span
                        class="mt-0.5 h-5 w-5 rounded-full bg-[#e6f3fb] text-[#0b9ed0] flex items-center justify-center flex-shrink-0">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-[#173045]">Your current policies and training materials</p>
                        <p class="text-xs text-[#5d6e7f] mt-0.5">Compliance & Ethics program, HIPAA Privacy and
                            Security policies, and training materials. PDF, Word or Excel.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3 rounded-xl border border-[#dbe4ee] px-4 py-3.5">
                    <span
                        class="mt-0.5 h-5 w-5 rounded-full bg-[#e6f3fb] text-[#0b9ed0] flex items-center justify-center flex-shrink-0">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-[#173045]">Practice name, specialty and address</p>
                        <p class="text-xs text-[#5d6e7f] mt-0.5">Plus your logo if you&rsquo;d like it on the cover
                            (optional).</p>
                    </div>
                </div>
            </div>

            @if($isRevisit)
            <button type="button" wire:click="backToQuestions"
                class="mt-8 w-full max-w-xs rounded bg-[#12304f] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">Back to the questions &rarr;</button>
            @endif

            <p class="text-sm text-[#5d6e7f] mt-5">Questions first? <button type="button" wire:click="openCallDialog"
                    class="font-semibold text-[#0b9ed0] hover:underline">Book a 15-minute call with a
                    specialist</button></p>
        </div>
    </div>
    @endif

    {{-- ── Documents ── --}}
    @if($screen === 'documents')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <div class="max-w-xl w-full">
            <h2 class="text-lg font-semibold text-[#12304f] mb-1">
                {{ $this->includesWorkflowQuestionnaire ? 'First, upload what you already have' : 'Upload your existing policies and manuals' }}
            </h2>
            <p class="text-sm text-[#5d6e7f] mb-5">
                @if($this->includesWorkflowQuestionnaire)
                Share any current policies, manuals or training materials. We'll still walk you through a few questions about how your practice runs, so nothing is missed.
                @else
                The Essential package reviews and updates the documents you already have. Upload each one below, or tell us if you don't have it.
                @endif
            </p>

            <ul class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl mb-4">
                @foreach($this->requiredDocumentCategories as $key => $label)
                @php
                    $status = $this->documentCategoryStatus($key);
                @endphp
                <li class="flex items-center justify-between gap-3 px-4 py-3">
                    <span class="text-sm font-semibold text-[#173045]">
                        {{ $label }}
                        @if($this->includesWorkflowQuestionnaire)
                        <span class="text-xs font-normal text-[#8592a1]">(if you have it)</span>
                        @endif
                    </span>
                    <span class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold tracking-wide uppercase
                            {{ $status === 'uploaded' ? 'bg-[#d7f3ea] text-[#117a51]' : ($status === 'declined' ? 'bg-[#eef1f5] text-[#5d6e7f]' : 'bg-[#fdf3e0] text-[#a3690f]') }}">
                            {{ $status === 'uploaded' ? 'Uploaded' : ($status === 'declined' ? "Don't have it" : ($this->includesWorkflowQuestionnaire ? 'Optional' : 'Needed')) }}
                        </span>
                        @if($status !== 'uploaded')
                        <button type="button" wire:click="toggleDocumentMissing('{{ $key }}')"
                            class="text-xs font-semibold text-[#1a7aad] hover:underline">
                            {{ $status === 'declined' ? 'Undo' : "I don't have this" }}
                        </button>
                        @endif
                    </span>
                </li>
                @endforeach
            </ul>

            @if(! $this->includesWorkflowQuestionnaire && collect($this->requiredDocumentCategories)->keys()->contains(fn ($key) => $this->documentCategoryStatus($key) === 'declined'))
            <div class="rounded-xl bg-[#fdf3e0] px-3.5 py-2.5 mb-4 text-xs text-[#8a5a0f] leading-relaxed">
                Missing a document? Essential updates what you have. <strong class="font-bold">Professional</strong>
                creates missing documents for you.
                <a href="{{ route('home') }}#pricing" class="font-bold underline hover:no-underline">Compare
                    packages</a>
            </div>
            @endif

            <div x-data="{ dragging: false }"
                x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; $refs.documentFilesInput.files = $event.dataTransfer.files; $refs.documentFilesInput.dispatchEvent(new Event('change'))"
                x-on:click="$refs.documentFilesInput.click()"
                x-bind:class="dragging ? 'border-[#009bde] bg-[#edf6ff]' : 'border-[#b9cfe0] bg-[#f7fbfd]'"
                class="border-2 border-dashed rounded-[1rem] p-6 text-center cursor-pointer transition-colors">
                <div
                    class="w-9 h-9 rounded-full bg-white border border-[#b9cfe0] mx-auto mb-2 flex items-center justify-center text-[#12304f]">
                    &#8593;
                </div>
                <p class="text-sm text-[#173045]"><strong class="font-semibold">Click to upload files</strong> or drag
                    them here</p>
                <p class="text-xs text-[#8592a1] mt-1">PDF, DOCX or XLSX</p>
                <input x-ref="documentFilesInput" type="file" wire:model="documentFiles" multiple
                    accept=".pdf,.jpg,.jpeg,.png,.docx,.xls,.xlsx" class="hidden">
            </div>
            @error('documentFiles') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('documentFiles.*') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            <div wire:loading wire:target="documentFiles" class="mt-2 text-xs text-[#5d6e7f]">Uploading&hellip;</div>

            @if($this->existingDocuments->isNotEmpty() || ! empty($documentFiles))
            <ul class="mt-4 space-y-2" wire:loading.remove wire:target="documentFiles">
                @foreach($this->existingDocuments as $doc)
                <li class="border border-[#eef2f6] rounded-lg px-3 py-2.5">
                    <div class="flex items-center gap-2 mb-2">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-[#8592a1] flex-shrink-0">
                            <path d="M6 2h9l5 5v15H6V2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        </svg>
                        <span class="flex-1 truncate text-[#173045] font-medium text-sm">{{ $doc->original_filename }}</span>
                        <span class="text-xs text-[#8592a1] flex-shrink-0">{{ $doc->fileSizeForHumans() }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <select wire:change="tagExistingDocument({{ $doc->id }}, $event.target.value)"
                            class="flex-1 rounded-lg border border-[#dbe4ee] bg-white px-2 py-1.5 text-xs text-[#173045]">
                            <option value="">Document type&hellip;</option>
                            @foreach($this->requiredDocumentCategories as $key => $label)
                            <option value="{{ $key }}" @selected($doc->document_category===$key)>{{ $label }}</option>
                            @endforeach
                            <option value="other" @selected($doc->document_category==='other')>Other</option>
                        </select>
                        <button type="button" wire:click="deleteExistingDocument({{ $doc->id }})"
                            wire:confirm="Remove this document?" aria-label="Remove {{ $doc->original_filename }}"
                            class="flex-shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg border border-[#dbe4ee] text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>
                </li>
                @endforeach
                @foreach($documentFiles as $i => $file)
                <li class="border border-[#eef2f6] rounded-lg px-3 py-2.5">
                    <div class="flex items-center gap-2 mb-2">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-[#8592a1] flex-shrink-0">
                            <path d="M6 2h9l5 5v15H6V2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        </svg>
                        <span class="flex-1 truncate text-[#173045] font-medium text-sm">{{ $file->getClientOriginalName() }}</span>
                        <span class="text-xs text-[#8592a1] flex-shrink-0">{{ number_format(max(1, round($file->getSize() / 1024))) }} KB</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <select wire:model.live="documentFileTags.{{ $i }}"
                            class="flex-1 rounded-lg border border-[#dbe4ee] bg-white px-2 py-1.5 text-xs text-[#173045]">
                            <option value="">Document type&hellip;</option>
                            @foreach($this->requiredDocumentCategories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                            <option value="other">Other</option>
                        </select>
                        <button type="button" wire:click="removeDocumentFile({{ $i }})"
                            aria-label="Remove {{ $file->getClientOriginalName() }}"
                            class="flex-shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg border border-[#dbe4ee] text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>
                </li>
                @endforeach
            </ul>
            @endif

            @if($justSaved)
            <p class="mt-4 text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up
                where you left off.</p>
            @endif

            <div class="flex justify-between items-center mt-5">
                <button wire:click="$dispatch('go-to-payment-step')" wire:target="$dispatch('go-to-payment-step')"
                    class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                    &larr; Back
                </button>
                <div class="flex items-center gap-4">
                    <button wire:click="continueFromDocuments(true)" wire:target="continueFromDocuments"
                        class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                    <button wire:click="continueFromDocuments(true, true)" wire:target="continueFromDocuments"
                        class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                    <button wire:click="continueFromDocuments" wire:target="continueFromDocuments" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                        <span wire:loading.remove wire:target="continueFromDocuments">Continue &rarr;</span>
                        <span wire:loading.inline-flex wire:target="continueFromDocuments" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                    </button>
                </div>
            </div>
        </div>

        <aside class="bg-[#f6f9fc] border border-[#e6edf4] rounded-2xl p-5 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] overflow-y-auto">
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Why we ask</h4>
            <p class="text-sm text-[#173045] leading-relaxed mb-4">
                @if($this->includesWorkflowQuestionnaire)
                Your existing documents show us where you're starting from. We compare them with your answers so the
                new manuals keep what already works.
                @else
                At the Essential level we review and update your own documents rather than writing new ones, so we
                need a copy of each.
                @endif
            </p>
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Used in</h4>
            <div class="flex flex-wrap gap-1.5">
                @foreach($this->requiredDocumentCategories as $label)
                <span
                    class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">{{ $label }}</span>
                @endforeach
            </div>
            <p class="text-xs text-[#5d6e7f] mt-4 pt-4 border-t border-[#e6edf4]">Stuck on this one? <button type="button" wire:click="openCallDialog" class="font-semibold text-[#0b9ed0] hover:underline">Talk to a specialist</button></p>
        </aside>
        </div>
    </div>
    @endif

    {{-- ── Basics: 1) Profile ── --}}
    @if($screen === 'b_profile')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <form wire:submit="continueFromProfile" class="max-w-xl w-full">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#1a7aad] mb-1.5">Practice basics &middot; 1 of 4</p>
        <h2 class="text-lg font-semibold text-[#12304f] mb-1">Let's start with your practice</h2>
        <p class="text-sm text-[#5d6e7f] mb-5">The name patients know you by, and your specialty.</p>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Practice name <span class="text-red-500">*</span></label>
                <input wire:model.live="practiceName" type="text" placeholder="Riverside Family Medicine" required maxlength="150" {{ $this->practice?->is_profile_locked ? 'disabled' : '' }}
                    class="w-full rounded-xl border {{ $errors->has('practiceName') ? 'border-red-400' : 'border-[#dbe4ee]' }} {{ $this->practice?->is_profile_locked ? 'bg-[#f0f4f8] cursor-not-allowed' : 'bg-[#f8fbfd]' }} px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('practiceName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Specialty <span class="text-red-500">*</span></label>
                <select wire:model.live="specialty"
                    class="w-full rounded-xl border {{ $errors->has('specialty') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    <option value="">Select your specialty</option>
                    @foreach(Practice::SPECIALTIES as $s)
                    <option value="{{ $s }}" @selected($specialty===$s)>{{ $s }}</option>
                    @endforeach
                </select>
                @error('specialty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        @if($justSaved)
        <p class="mt-3 text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up
            where you left off.</p>
        @endif

        <div class="flex justify-between items-center mt-5">
            <button type="button" wire:click="backToDocuments" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button type="button" wire:click="continueFromProfile(true)" wire:target="continueFromProfile"
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                <button type="button" wire:click="continueFromProfile(true, true)" wire:target="continueFromProfile"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button type="submit" wire:target="continueFromProfile" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromProfile">Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromProfile" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </form>

        <aside class="bg-[#f6f9fc] border border-[#e6edf4] rounded-2xl p-5 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] overflow-y-auto">
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Why we ask</h4>
            <p class="text-sm text-[#173045] leading-relaxed mb-4">Your practice name appears on the cover and header of every document. Your specialty matches you to the right policy templates.</p>
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Used in</h4>
            <div class="flex flex-wrap gap-1.5">
                <span class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">All documents</span>
            </div>
            <p class="text-xs text-[#5d6e7f] mt-4 pt-4 border-t border-[#e6edf4]">Stuck on this one? <button type="button" wire:click="openCallDialog" class="font-semibold text-[#0b9ed0] hover:underline">Talk to a specialist</button></p>
        </aside>
        </div>
    </div>
    @endif

    {{-- ── Basics: 2) Providers ── --}}
    @if($screen === 'b_providers')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <div class="max-w-xl w-full">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#1a7aad] mb-1.5">Practice basics &middot; 2 of 4</p>
        <h2 class="text-lg font-semibold text-[#12304f] mb-1">Billable providers</h2>
        <p class="text-sm text-[#5d6e7f] mb-5">Confirmed at checkout — physicians and non-physician practitioners who bill under your group NPI.</p>

        <div class="inline-flex items-center gap-2 rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-5 py-3">
            <span class="text-lg font-semibold text-[#173045]">{{ $billableProviders }}</span>
            <span class="text-sm text-[#5d6e7f]">provider{{ $billableProviders === 1 ? '' : 's' }}</span>
        </div>

        @if($this->providerInvoiceHint)
        <p class="mt-3 text-xs text-[#5d6e7f]">{!! $this->providerInvoiceHint !!}</p>
        @endif

        <div class="flex justify-between items-center mt-5">
            <button wire:click="backToProfile" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <button wire:click="continueFromProviders" wire:target="continueFromProviders" wire:loading.attr="disabled"
                class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                <span wire:loading.remove wire:target="continueFromProviders">Continue &rarr;</span>
                <span wire:loading.inline-flex wire:target="continueFromProviders" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
            </button>
        </div>
        </div>

        <aside class="bg-[#f6f9fc] border border-[#e6edf4] rounded-2xl p-5 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] overflow-y-auto">
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Why we ask</h4>
            <p class="text-sm text-[#173045] leading-relaxed mb-4">Your invoice already reflects this count from checkout. Providers who join mid-term are prorated and trued up at renewal. Need to change it? Contact support.</p>
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Used in</h4>
            <div class="flex flex-wrap gap-1.5">
                <span class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">All documents</span>
            </div>
            <p class="text-xs text-[#5d6e7f] mt-4 pt-4 border-t border-[#e6edf4]">Stuck on this one? <button type="button" wire:click="openCallDialog" class="font-semibold text-[#0b9ed0] hover:underline">Talk to a specialist</button></p>
        </aside>
        </div>
    </div>
    @endif

    {{-- ── Basics: 3) Address ── --}}
    @if($screen === 'b_address')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <form wire:submit="continueFromAddress" class="max-w-xl w-full">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#1a7aad] mb-1.5">Practice basics &middot; 3 of 4</p>
        <h2 class="text-lg font-semibold text-[#12304f] mb-1">Where is your practice located?</h2>
        <p class="text-sm text-[#5d6e7f] mb-5">Your main practice address.</p>

        <label class="flex items-start gap-2.5 rounded-xl border {{ $sameAsBillingAddress ? 'border-[#12304f] bg-[#f4f8fc]' : 'border-[#dbe4ee] bg-white hover:border-[#9ed3e9]' }} px-4 py-3 cursor-pointer transition">
            <input type="checkbox" wire:model.live="sameAsBillingAddress" class="mt-0.5 accent-[#12304f]">
            <span>
                <span class="block text-sm font-bold text-[#173045]">Same as my billing address</span>
                <span class="block text-xs text-[#5d6e7f] mt-0.5">{{ $this->billingAddressLine ?: 'Billing address from step 1' }}</span>
            </span>
        </label>

        @unless($sameAsBillingAddress)
        <div class="mt-4 relative" x-data="addressAutocomplete({ address1: 'practiceAddress' })">
            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Street address <span class="text-red-500">*</span></label>
            <input type="text" placeholder="123 Main St, Springfield, IL" required maxlength="255" autocomplete="off"
                role="combobox" aria-autocomplete="list" aria-expanded="open" data-ac-field="practiceAddress"
                x-model="query" x-on:input="onAddressInput()"
                x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.enter.prevent="chooseHighlighted()" x-on:keydown.escape="close()"
                x-on:blur="setTimeout(() => close(), 150)"
                class="w-full rounded-xl border {{ $errors->has('practiceAddress') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            <ul x-show="open" x-cloak role="listbox"
                class="absolute z-10 mt-1 w-full max-h-56 overflow-y-auto rounded-xl border border-[#dbe4ee] bg-white shadow-lg py-1">
                <template x-for="(suggestion, index) in suggestions" :key="suggestion.label">
                    <li role="option" x-text="suggestion.label" x-on:mousedown.prevent="select(suggestion)"
                        :class="index === highlightedIndex ? 'bg-[#f0f7fb]' : ''"
                        class="px-4 py-2 text-sm text-[#173045] cursor-pointer hover:bg-[#f0f7fb]">
                    </li>
                </template>
            </ul>
            @error('practiceAddress') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        @endunless

        @if($justSaved)
        <p class="mt-3 text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up
            where you left off.</p>
        @endif

        <div class="flex justify-between items-center mt-5">
            <button type="button" wire:click="backToProviders" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button type="button" wire:click="continueFromAddress(true)" wire:target="continueFromAddress"
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                <button type="button" wire:click="continueFromAddress(true, true)" wire:target="continueFromAddress"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button type="submit" wire:target="continueFromAddress" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromAddress">Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromAddress" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </form>

        <aside class="bg-[#f6f9fc] border border-[#e6edf4] rounded-2xl p-5 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] overflow-y-auto">
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Why we ask</h4>
            <p class="text-sm text-[#173045] leading-relaxed mb-4">Your primary address appears in the header of every manual.</p>
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Used in</h4>
            <div class="flex flex-wrap gap-1.5">
                <span class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">All documents</span>
            </div>
            <p class="text-xs text-[#5d6e7f] mt-4 pt-4 border-t border-[#e6edf4]">Stuck on this one? <button type="button" wire:click="openCallDialog" class="font-semibold text-[#0b9ed0] hover:underline">Talk to a specialist</button></p>
        </aside>
        </div>
    </div>
    @endif

    {{-- ── Basics: 4) Logo ── --}}
    @if($screen === 'b_logo')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <div class="max-w-xl w-full">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#1a7aad] mb-1.5">Practice basics &middot; 4 of 4</p>
        <h2 class="text-lg font-semibold text-[#12304f] mb-1">Add your practice logo</h2>
        <p class="text-sm text-[#5d6e7f] mb-5">Optional. We'll put it on the cover of your documents.</p>

        @if($logoFile)
        <div class="flex items-center gap-4 rounded-xl border border-[#dbe4ee] p-4">
            <div class="w-20 h-14 rounded-lg border border-[#e6edf4] bg-[#f8fbfd] flex items-center justify-center overflow-hidden flex-shrink-0">
                <img src="{{ $logoFile->temporaryUrl() }}" alt="Logo preview" class="max-w-full max-h-full object-contain">
            </div>
            <div class="flex items-center gap-3">
                <label class="rounded-lg border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] cursor-pointer transition-colors">
                    Replace
                    <input wire:model.live="logoFile" type="file" accept=".png,.jpg,.jpeg,.svg" class="hidden">
                </label>
                <button type="button" wire:click="$set('logoFile', null)" class="text-xs font-semibold text-red-600 hover:underline">Remove</button>
            </div>
        </div>
        @elseif($this->practice?->logo_path)
        <div class="flex items-center gap-4 rounded-xl border border-[#dbe4ee] p-4">
            <div class="w-20 h-14 rounded-lg border border-[#e6edf4] bg-[#f8fbfd] flex items-center justify-center overflow-hidden flex-shrink-0">
                <img src="{{ Storage::disk('public')->url($this->practice->logo_path) }}" alt="Practice logo" class="max-w-full max-h-full object-contain">
            </div>
            <div class="flex items-center gap-3">
                <label class="rounded-lg border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] cursor-pointer transition-colors">
                    Replace
                    <input wire:model.live="logoFile" type="file" accept=".png,.jpg,.jpeg,.svg" class="hidden">
                </label>
                <button type="button" wire:click="removeLogo" wire:confirm="Remove your practice logo?" class="text-xs font-semibold text-red-600 hover:underline">Remove</button>
            </div>
        </div>
        @else
        <label class="flex flex-col items-center justify-center gap-1.5 text-center border-2 border-dashed border-[#c7d3df] rounded-2xl bg-[#f8fbfd] hover:border-[#0b9ed0] hover:bg-[#eaf7fc] transition-colors p-7 cursor-pointer">
            <span class="w-11 h-11 rounded-full bg-white border border-[#dbe4ee] text-[#0b9ed0] flex items-center justify-center mb-1">&#8593;</span>
            <span class="text-sm text-[#173045]"><strong class="font-semibold text-[#12304f]">Click to upload logo</strong> or drag it here</span>
            <span class="text-xs text-[#5d6e7f]">PNG, JPG or SVG &middot; under 500 KB</span>
            <input wire:model.live="logoFile" type="file" accept=".png,.jpg,.jpeg,.svg" class="hidden">
        </label>
        @endif
        @error('logoFile') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        <div wire:loading wire:target="logoFile" class="mt-2 text-xs text-[#5d6e7f]">Uploading&hellip;</div>

        @if($justSaved)
        <p class="mt-3 text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up
            where you left off.</p>
        @endif

        <div class="flex justify-between items-center mt-5">
            <button wire:click="backToAddress" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button wire:click="continueFromLogo(true)" wire:target="continueFromLogo"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button wire:click="continueFromLogo" wire:target="continueFromLogo" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromLogo">{{ $logoFile || $this->practice?->logo_path ? 'Continue' : 'Continue without a logo' }} &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromLogo" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </div>

        <aside class="bg-[#f6f9fc] border border-[#e6edf4] rounded-2xl p-5 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] overflow-y-auto">
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Why we ask</h4>
            <p class="text-sm text-[#173045] leading-relaxed mb-4">Branded documents look official to staff, payers and auditors.</p>
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Used in</h4>
            <div class="flex flex-wrap gap-1.5">
                <span class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">All documents</span>
            </div>
            <p class="text-xs text-[#5d6e7f] mt-4 pt-4 border-t border-[#e6edf4]">Stuck on this one? <button type="button" wire:click="openCallDialog" class="font-semibold text-[#0b9ed0] hover:underline">Talk to a specialist</button></p>
        </aside>
        </div>
    </div>
    @endif

    {{-- ── Team ── --}}
    @php
        $teamAside = function (string $why) {
            return '<aside class="bg-[#f6f9fc] border border-[#e6edf4] rounded-2xl p-5 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] overflow-y-auto">
                <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Why we ask</h4>
                <p class="text-sm text-[#173045] leading-relaxed mb-4">'.$why.' Asked once, and used in all three manuals.</p>
                <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Used in</h4>
                <div class="flex flex-wrap gap-1.5">
                    <span class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">Compliance &amp; Ethics &middot; &sect;1</span>
                    <span class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">HIPAA Privacy &middot; &sect;1</span>
                    <span class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">HIPAA Security &middot; &sect;1</span>
                </div>
                <p class="text-xs text-[#5d6e7f] mt-4 pt-4 border-t border-[#e6edf4]">Stuck on this one? <button type="button" wire:click="openCallDialog" class="font-semibold text-[#0b9ed0] hover:underline">Talk to a specialist</button></p>
            </aside>';
        };
    @endphp

    {{-- ── Team 1/5: Your practice's legal details ── --}}
    @if($screen === 't_practice')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <form wire:submit="continueFromPractice" class="max-w-xl w-full space-y-5">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-wide text-[#1a7aad] mb-1">Your team &middot; 1 of 5</p>
            <h2 class="text-lg font-semibold text-[#12304f] mb-1">Your practice's legal details</h2>
            <p class="text-sm text-[#5d6e7f]">Legal name, main phone and email, and every location.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Legal practice name <span class="text-red-500">*</span></label>
            <input wire:model="legalPracticeName" type="text" required maxlength="200"
                class="w-full rounded-xl border {{ $errors->has('legalPracticeName') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            @error('legalPracticeName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">DBA <span class="text-xs font-normal text-[#8592a1]">(if any)</span></label>
                <input wire:model="dbaName" type="text"
                    class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Other entities <span class="text-xs font-normal text-[#8592a1]">(if any)</span></label>
                <input wire:model="otherEntities" type="text"
                    class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Main phone <span class="text-red-500">*</span></label>
                <input wire:model="mainPhone" type="tel" inputmode="tel" required maxlength="30"
                    pattern="[+]?[0-9\s().\-]{7,30}" title="A valid phone number"
                    x-on:input="$el.value = $el.value.replace(/[^0-9+()\-.\s]/g, '')"
                    class="w-full rounded-xl border {{ $errors->has('mainPhone') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('mainPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Main email <span class="text-red-500">*</span></label>
                <input wire:model="mainEmail" type="email" required maxlength="150"
                    pattern="[^\s@]+@[^\s@]+\.[^\s@]+" title="Please include a domain extension, e.g. name@example.com"
                    class="w-full rounded-xl border {{ $errors->has('mainEmail') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('mainEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="border-t border-[#eef2f6] pt-4">
            <label class="block text-sm font-semibold text-[#31465b] mb-0.5">Locations <span class="text-red-500">*</span></label>
            <p class="text-xs text-[#8592a1] mb-2">Legal address first, then each additional site.</p>
            @foreach($practiceLocations as $i => $location)
            <div class="flex gap-2 mb-2">
                <input wire:model="practiceLocations.{{ $i }}" type="text" maxlength="255" placeholder="{{ $i ? 'Location '.($i + 1).' address' : 'Legal / main address' }}"
                    class="flex-1 rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                <button type="button" wire:click="removeLocation({{ $i }})" @disabled(count($practiceLocations) < 2)
                    class="flex-shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-[#dbe4ee] text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors disabled:opacity-40">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                </button>
            </div>
            @endforeach
            @error('practiceLocations') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <button type="button" wire:click="addLocation" class="text-xs font-bold text-[#1a7aad] hover:underline">+ Add another location</button>
        </div>

        @if($justSaved)
        <p class="text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up where
            you left off.</p>
        @endif

        <div class="flex justify-between items-center pt-2">
            <button type="button" wire:click="backToBasics" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button type="button" wire:click="continueFromPractice(true)" wire:target="continueFromPractice"
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                <button type="button" wire:click="continueFromPractice(true, true)" wire:target="continueFromPractice"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button type="submit" wire:target="continueFromPractice" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromPractice">Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromPractice" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </form>
        {!! $teamAside("Section 1 starts with your legal name and addresses. Facility security policies and hotline poster counts depend on your locations.") !!}
        </div>
    </div>
    @endif

    {{-- ── Team 2/5: Who fills your compliance roles? ── --}}
    @if($screen === 't_officers')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <form wire:submit="continueFromOfficers" class="max-w-xl w-full space-y-5">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-wide text-[#1a7aad] mb-1">Your team &middot; 2 of 5</p>
            <h2 class="text-lg font-semibold text-[#12304f] mb-1">Who fills your compliance roles?</h2>
            <p class="text-sm text-[#5d6e7f]">One person can hold more than one role. Use "Same person as" to copy details.</p>
        </div>

        @foreach($this->officerPrefixes as $prefix => $label)
        <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] p-4">
            <div class="flex items-center justify-between gap-3 mb-3">
                <p class="text-sm font-semibold text-[#12304f]">{{ $label }}</p>
                <select wire:change="copyOfficerContact('{{ $prefix }}', $event.target.value)"
                    class="rounded-lg border border-[#dbe4ee] bg-white px-2 py-1.5 text-xs text-[#173045]">
                    <option value="">Same person as&hellip;</option>
                    @foreach($this->knownTeamPeople as $key => $personLabel)
                        @if($key !== $prefix)
                        <option value="{{ $key }}">{{ $personLabel }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Full name <span class="text-red-500">*</span></label>
                    <input wire:model="{{ $prefix }}Name" type="text" required maxlength="150"
                        class="w-full rounded-xl border {{ $errors->has($prefix.'Name') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-white px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error($prefix.'Name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Phone <span class="text-red-500">*</span></label>
                    <input wire:model="{{ $prefix }}Phone" type="tel" inputmode="tel" required maxlength="30"
                        pattern="[+]?[0-9\s().\-]{7,30}" title="A valid phone number"
                        x-on:input="$el.value = $el.value.replace(/[^0-9+()\-.\s]/g, '')"
                        class="w-full rounded-xl border {{ $errors->has($prefix.'Phone') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-white px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error($prefix.'Phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Email <span class="text-red-500">*</span></label>
                    <input wire:model="{{ $prefix }}Email" type="email" required maxlength="150"
                        pattern="[^\s@]+@[^\s@]+\.[^\s@]+" title="Please include a domain extension, e.g. name@example.com"
                        class="w-full rounded-xl border {{ $errors->has($prefix.'Email') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-white px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error($prefix.'Email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
        @endforeach

        @if($justSaved)
        <p class="text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up where
            you left off.</p>
        @endif

        <div class="flex justify-between items-center pt-2">
            <button type="button" wire:click="backToPractice" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button type="button" wire:click="continueFromOfficers(true)" wire:target="continueFromOfficers"
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                <button type="button" wire:click="continueFromOfficers(true, true)" wire:target="continueFromOfficers"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button type="submit" wire:target="continueFromOfficers" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromOfficers">Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromOfficers" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </form>
        {!! $teamAside("HIPAA requires designated Privacy and Security Officers, and OIG guidance calls for a Compliance Officer. They are named throughout all three manuals.") !!}
        </div>
    </div>
    @endif

    {{-- ── Team 3/5: Who handles your IT? ── --}}
    @if($screen === 't_it')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <form wire:submit="continueFromIt" class="max-w-xl w-full space-y-5">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-wide text-[#1a7aad] mb-1">Your team &middot; 3 of 5</p>
            <h2 class="text-lg font-semibold text-[#12304f] mb-1">Who handles your IT?</h2>
            <p class="text-sm text-[#5d6e7f]">An outside IT company or someone in-house.</p>
        </div>

        <div class="space-y-2">
            <label class="flex items-start gap-3 rounded-xl border {{ $itMode === 'vendor' ? 'border-[#0b9ed0] ring-1 ring-[#0b9ed0]' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-3 cursor-pointer">
                <input type="radio" wire:model.live="itMode" value="vendor" class="mt-1 text-[#0b9ed0] focus:ring-[#0b9ed0]">
                <span>
                    <span class="block text-sm font-semibold text-[#173045]">An outside IT company</span>
                    <span class="block text-xs text-[#5d6e7f]">A managed service provider or IT consultant.</span>
                </span>
            </label>
            <label class="flex items-start gap-3 rounded-xl border {{ $itMode === 'inhouse' ? 'border-[#0b9ed0] ring-1 ring-[#0b9ed0]' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-3 cursor-pointer">
                <input type="radio" wire:model.live="itMode" value="inhouse" class="mt-1 text-[#0b9ed0] focus:ring-[#0b9ed0]">
                <span>
                    <span class="block text-sm font-semibold text-[#173045]">We handle IT in-house</span>
                    <span class="block text-xs text-[#5d6e7f]">A staff member manages computers and systems.</span>
                </span>
            </label>
            @error('itMode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        @if($itMode === 'vendor')
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Company name <span class="text-red-500">*</span></label>
                <input wire:model="itVendorName" type="text" required maxlength="150"
                    class="w-full rounded-xl border {{ $errors->has('itVendorName') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('itVendorName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Contact name</label>
                <input wire:model="itContactName" type="text" maxlength="150"
                    class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Phone <span class="text-red-500">*</span></label>
                <input wire:model="itContactPhone" type="tel" inputmode="tel" required maxlength="30"
                    pattern="[+]?[0-9\s().\-]{7,30}" title="A valid phone number"
                    x-on:input="$el.value = $el.value.replace(/[^0-9+()\-.\s]/g, '')"
                    class="w-full rounded-xl border {{ $errors->has('itContactPhone') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('itContactPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Email <span class="text-red-500">*</span></label>
                <input wire:model="itContactEmail" type="email" required maxlength="150"
                    pattern="[^\s@]+@[^\s@]+\.[^\s@]+" title="Please include a domain extension, e.g. name@example.com"
                    class="w-full rounded-xl border {{ $errors->has('itContactEmail') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('itContactEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        @elseif($itMode === 'inhouse')
        <div class="border border-[#eef2f6] rounded-xl p-4">
            <div class="flex items-center justify-between gap-3 mb-2">
                <p class="text-sm font-semibold text-[#12304f]">Who handles IT?</p>
                <select wire:change="copyItContact($event.target.value)"
                    class="rounded-lg border border-[#dbe4ee] bg-white px-2 py-1.5 text-xs text-[#173045]">
                    <option value="">Same person as&hellip;</option>
                    @foreach($this->knownTeamPeople as $key => $personLabel)
                    <option value="{{ $key }}">{{ $personLabel }}</option>
                    @endforeach
                </select>
            </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Full name <span class="text-red-500">*</span></label>
                <input wire:model="itContactName" type="text" required maxlength="150"
                    class="w-full rounded-xl border {{ $errors->has('itContactName') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('itContactName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Phone <span class="text-red-500">*</span></label>
                <input wire:model="itContactPhone" type="tel" inputmode="tel" required maxlength="30"
                    pattern="[+]?[0-9\s().\-]{7,30}" title="A valid phone number"
                    x-on:input="$el.value = $el.value.replace(/[^0-9+()\-.\s]/g, '')"
                    class="w-full rounded-xl border {{ $errors->has('itContactPhone') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('itContactPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Email <span class="text-red-500">*</span></label>
                <input wire:model="itContactEmail" type="email" required maxlength="150"
                    pattern="[^\s@]+@[^\s@]+\.[^\s@]+" title="Please include a domain extension, e.g. name@example.com"
                    class="w-full rounded-xl border {{ $errors->has('itContactEmail') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                @error('itContactEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        </div>
        @endif

        @if($justSaved)
        <p class="text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up where
            you left off.</p>
        @endif

        <div class="flex justify-between items-center pt-2">
            <button type="button" wire:click="backToOfficers" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button type="button" wire:click="continueFromIt(true)" wire:target="continueFromIt"
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                <button type="button" wire:click="continueFromIt(true, true)" wire:target="continueFromIt"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button type="submit" wire:target="continueFromIt" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromIt">Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromIt" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </form>
        {!! $teamAside("Your IT contact is named in most HIPAA Security policies.") !!}
        </div>
    </div>
    @endif

    {{-- ── Team 4/5: How can staff reach a compliance hotline? ── --}}
    @if($screen === 't_hotline')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <form wire:submit="continueFromHotline" class="max-w-xl w-full space-y-5">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-wide text-[#1a7aad] mb-1">Your team &middot; 4 of 5</p>
            <h2 class="text-lg font-semibold text-[#12304f] mb-1">How can staff reach a compliance hotline?</h2>
            <p class="text-sm text-[#5d6e7f]">Staff need a way to report concerns anonymously.</p>
        </div>

        <div class="space-y-2">
            <label class="flex items-start gap-3 rounded-xl border {{ $usesEhcpHotline === true ? 'border-[#0b9ed0] ring-1 ring-[#0b9ed0]' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-3 cursor-pointer">
                <input type="radio" wire:model.live="usesEhcpHotline" value="1" class="mt-1 text-[#0b9ed0] focus:ring-[#0b9ed0]">
                <span>
                    <span class="block text-sm font-semibold text-[#173045]">Use the Empower compliance hotline</span>
                    <span class="block text-xs text-[#5d6e7f]">Included in your package. Anonymous phone and web reporting.</span>
                </span>
            </label>
            <label class="flex items-start gap-3 rounded-xl border {{ $usesEhcpHotline === false ? 'border-[#0b9ed0] ring-1 ring-[#0b9ed0]' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-3 cursor-pointer">
                <input type="radio" wire:model.live="usesEhcpHotline" value="0" class="mt-1 text-[#0b9ed0] focus:ring-[#0b9ed0]">
                <span>
                    <span class="block text-sm font-semibold text-[#173045]">We have our own hotline</span>
                    <span class="block text-xs text-[#5d6e7f]">Tell us the number and/or email.</span>
                </span>
            </label>
            @error('usesEhcpHotline') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        @if($usesEhcpHotline === false)
        <div>
            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Hotline number and/or email <span class="text-red-500">*</span></label>
            <input wire:model="hotlineContact" type="text" required maxlength="200"
                class="w-full rounded-xl border {{ $errors->has('hotlineContact') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            @error('hotlineContact') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        @endif

        @if($usesEhcpHotline !== null)
        @php $locationCount = max(1, collect($practiceLocations)->filter(fn ($l) => trim($l) !== '')->count()); @endphp
        <div>
            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Hotline posters needed</label>
            <input wire:model="hotlinePosterCount" type="number" min="0" placeholder="{{ $locationCount * 2 }}"
                class="w-full max-w-[10rem] rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            <p class="text-xs text-[#8592a1] mt-1">2 per location recommended ({{ $locationCount }} location{{ $locationCount > 1 ? 's' : '' }}).</p>
        </div>
        @endif

        @if($justSaved)
        <p class="text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up where
            you left off.</p>
        @endif

        <div class="flex justify-between items-center pt-2">
            <button type="button" wire:click="backToIt" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button type="button" wire:click="continueFromHotline(true)" wire:target="continueFromHotline"
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                <button type="button" wire:click="continueFromHotline(true, true)" wire:target="continueFromHotline"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button type="submit" wire:target="continueFromHotline" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromHotline">Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromHotline" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </form>
        {!! $teamAside("A hotline is part of the reporting element. Section 1 recommends 2 posters per location.") !!}
        </div>
    </div>
    @endif

    {{-- ── Team 5/5: Who leads compliance oversight? ── --}}
    @if($screen === 't_leadership')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <div class="max-w-xl w-full space-y-5">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-wide text-[#1a7aad] mb-1">Your team &middot; 5 of 5</p>
            <h2 class="text-lg font-semibold text-[#12304f] mb-1">Who leads compliance oversight?</h2>
            <p class="text-sm text-[#5d6e7f]">Your Compliance Committee, and your owners or governing board.</p>
        </div>

        <div class="border border-[#eef2f6] rounded-xl p-4">
            <p class="text-sm font-semibold text-[#12304f] mb-2">Compliance Committee</p>
            <label class="inline-flex items-center gap-2 text-sm text-[#173045] cursor-pointer mb-3">
                <input type="checkbox" wire:model.live="committeeNone" class="rounded text-[#0b9ed0] focus:ring-[#0b9ed0]">
                We don't have a committee yet
            </label>
            @unless($committeeNone)
            @foreach($complianceCommitteeMembers as $i => $member)
            <div class="flex gap-2 mb-2">
                <input wire:model="complianceCommitteeMembers.{{ $i }}.name" type="text" placeholder="Name" maxlength="150"
                    class="flex-1 rounded-xl border border-[#dbe4ee] bg-white px-4 py-2 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                <input wire:model="complianceCommitteeMembers.{{ $i }}.title" type="text" placeholder="Title or role" maxlength="150"
                    class="flex-1 rounded-xl border border-[#dbe4ee] bg-white px-4 py-2 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                <button type="button" wire:click="removeCommitteeMember({{ $i }})" @disabled(count($complianceCommitteeMembers) < 2)
                    class="flex-shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-[#dbe4ee] text-[#5d6e7f] hover:bg-white transition-colors disabled:opacity-40">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                </button>
            </div>
            @endforeach
            @error('complianceCommitteeMembers') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <button type="button" wire:click="addCommitteeMember" class="text-xs font-bold text-[#1a7aad] hover:underline">+ Add member</button>
            @if($this->knownTeamRoster !== [])
            <p class="text-xs text-[#8592a1] mt-2">Quick add:
                @foreach($this->knownTeamRoster as $person)
                    <button type="button" wire:click="quickAddMember('committee', '{{ addslashes($person['name']) }}', '{{ addslashes($person['roles']) }}')"
                        title="{{ $person['roles'] ?: 'No role assigned yet' }}"
                        class="inline-flex items-center rounded-full border border-[#dbe4ee] bg-white px-2 py-0.5 text-[0.7rem] font-semibold text-[#173045] hover:bg-[#f4f7fb] mr-1">+ {{ $person['name'] }}</button>
                @endforeach
            </p>
            @endif
            @endunless
        </div>

        <div class="border border-[#eef2f6] rounded-xl p-4">
            <p class="text-sm font-semibold text-[#12304f] mb-2">Owners or governing board</p>
            <div class="space-y-2 mb-3">
                <label class="flex items-start gap-3 rounded-xl border {{ $boardMode === 'owners' ? 'border-[#0b9ed0] ring-1 ring-[#0b9ed0]' : 'border-[#dbe4ee]' }} bg-white px-4 py-3 cursor-pointer">
                    <input type="radio" wire:model.live="boardMode" value="owners" class="mt-1 text-[#0b9ed0] focus:ring-[#0b9ed0]">
                    <span>
                        <span class="block text-sm font-semibold text-[#173045]">Our owners or partners oversee compliance</span>
                        <span class="block text-xs text-[#5d6e7f]">No formal governing board.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 rounded-xl border {{ $boardMode === 'board' ? 'border-[#0b9ed0] ring-1 ring-[#0b9ed0]' : 'border-[#dbe4ee]' }} bg-white px-4 py-3 cursor-pointer">
                    <input type="radio" wire:model.live="boardMode" value="board" class="mt-1 text-[#0b9ed0] focus:ring-[#0b9ed0]">
                    <span>
                        <span class="block text-sm font-semibold text-[#173045]">We have a governing board</span>
                        <span class="block text-xs text-[#5d6e7f]">List each board member.</span>
                    </span>
                </label>
                @error('boardMode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if($boardMode !== '')
            @foreach($complianceGoverningBoardMembers as $i => $member)
            <div class="flex gap-2 mb-2">
                <input wire:model="complianceGoverningBoardMembers.{{ $i }}.name" type="text" placeholder="Name" maxlength="150"
                    class="flex-1 rounded-xl border border-[#dbe4ee] bg-white px-4 py-2 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                <input wire:model="complianceGoverningBoardMembers.{{ $i }}.title" type="text" placeholder="Title or role" maxlength="150"
                    class="flex-1 rounded-xl border border-[#dbe4ee] bg-white px-4 py-2 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                <button type="button" wire:click="removeBoardMember({{ $i }})" @disabled(count($complianceGoverningBoardMembers) < 2)
                    class="flex-shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-[#dbe4ee] text-[#5d6e7f] hover:bg-white transition-colors disabled:opacity-40">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                </button>
            </div>
            @endforeach
            @error('complianceGoverningBoardMembers') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <button type="button" wire:click="addBoardMember" class="text-xs font-bold text-[#1a7aad] hover:underline">+ Add {{ $boardMode === 'owners' ? 'owner' : 'board member' }}</button>
            @if($this->knownTeamRoster !== [])
            <p class="text-xs text-[#8592a1] mt-2">Quick add:
                @foreach($this->knownTeamRoster as $person)
                    <button type="button" wire:click="quickAddMember('board', '{{ addslashes($person['name']) }}', '{{ addslashes($person['roles']) }}')"
                        title="{{ $person['roles'] ?: 'No role assigned yet' }}"
                        class="inline-flex items-center rounded-full border border-[#dbe4ee] bg-white px-2 py-0.5 text-[0.7rem] font-semibold text-[#173045] hover:bg-[#f4f7fb] mr-1">+ {{ $person['name'] }}</button>
                @endforeach
            </p>
            @endif
            @endif
        </div>

        @if($justSaved)
        <p class="text-xs font-semibold text-[#1f9d6b]">&#10003; Progress saved — come back anytime to pick up where
            you left off.</p>
        @endif

        <div class="flex justify-between items-center pt-2">
            <button wire:click="backToHotline" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">&larr; Back</button>
            <div class="flex items-center gap-4">
                <button wire:click="continueFromLeadership(true)" wire:target="continueFromLeadership"
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">Skip for now</button>
                <button wire:click="continueFromLeadership(true, true)" wire:target="continueFromLeadership"
                    class="text-sm font-semibold text-[#5d6e7f] hover:underline">Save &amp; continue later</button>
                <button wire:click="continueFromLeadership" wire:target="continueFromLeadership" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="continueFromLeadership">Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="continueFromLeadership" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </div>
        </div>
        </div>
        {!! $teamAside("Committee and board members are listed in the Compliance & Ethics Manual. OIG guidance expects leadership to oversee the program.") !!}
        </div>
    </div>
    @endif

    {{-- ── Question ── --}}
    @if($screen === 'question' && $this->currentQuestion)
    @php
        $question = $this->currentQuestion;
        $sectionQuestionIds = $question->section->questions->pluck('id')->all();
        $questionPosition = array_search($question->id, $sectionQuestionIds, true);
        $questionPosition = $questionPosition === false ? 0 : $questionPosition + 1;
        $wasSkipped = in_array($question->id, $this->skippedQuestionIds, true);
        $isAnswered = in_array($question->id, $this->answeredQuestionIds, true);
    @endphp
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_310px] lg:items-start gap-8 p-6 lg:p-10">
        <div class="max-w-xl w-full">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#1a7aad] mb-1.5 flex items-center gap-2 flex-wrap">
            {{ $question->section->label }} &middot; {{ $questionPosition }} of {{ count($sectionQuestionIds) }}
            @if($wasSkipped)
            <span class="text-[10.5px] font-bold tracking-normal normal-case bg-[#fdf3e0] text-[#b7791f] px-2 py-0.5 rounded-full">Skipped earlier</span>
            @endif
            @if($isAnswered)
            <span class="text-[10.5px] font-bold tracking-normal normal-case bg-[#e6f6ef] text-[#1f9d6b] px-2 py-0.5 rounded-full">Answered</span>
            @endif
        </p>
        <h2 class="text-[28px] font-extrabold text-[#0e1b30] mb-1">{{ $question->title }}</h2>
        @if($question->prompt_summary)
        <p class="text-sm text-[#5d6e7f] mb-4">{{ $question->prompt_summary }}</p>
        @endif

        <div class="space-y-2.5 mb-4" wire:loading.class="opacity-60 pointer-events-none" wire:target="chooseDocumentedProcess,chooseNoDocumentedProcess,saveCurrentAnswer,skipCurrentQuestion,backOneQuestion">
            <label class="flex items-start gap-2.5 rounded-xl border {{ $currentHasDocumentedProcess === true ? 'border-[#12304f] bg-[#f4f8fc]' : 'border-[#dbe4ee] bg-white hover:border-[#9ed3e9]' }} px-4 py-3 cursor-pointer transition">
                <input type="radio" wire:key="has-documented-process-yes-{{ $currentQuestionId }}" name="has_documented_process_{{ $currentQuestionId }}" wire:click="chooseDocumentedProcess" wire:loading.attr="disabled" wire:target="chooseDocumentedProcess,chooseNoDocumentedProcess,saveCurrentAnswer,skipCurrentQuestion,backOneQuestion" @checked($currentHasDocumentedProcess === true) class="mt-0.5 accent-[#12304f]">
                <span>
                    <span class="block text-sm font-bold text-[#173045]">We have a documented process</span>
                    <span class="block text-xs text-[#5d6e7f] mt-0.5">Describe it in your own words. Your response is used exactly as you write it.</span>
                </span>
            </label>
            <label class="flex items-start gap-2.5 rounded-xl border {{ $currentHasDocumentedProcess === false ? 'border-[#12304f] bg-[#f4f8fc]' : 'border-[#dbe4ee] bg-white hover:border-[#9ed3e9]' }} px-4 py-3 cursor-pointer transition">
                <input type="radio" wire:key="has-documented-process-no-{{ $currentQuestionId }}" name="has_documented_process_{{ $currentQuestionId }}" wire:click="chooseNoDocumentedProcess" wire:loading.attr="disabled" wire:target="chooseDocumentedProcess,chooseNoDocumentedProcess,saveCurrentAnswer,skipCurrentQuestion,backOneQuestion" @checked($currentHasDocumentedProcess === false) class="mt-0.5 accent-[#12304f]">
                <span>
                    <span class="block text-sm font-bold text-[#173045]">We don't have a documented answer</span>
                    <span class="block text-xs text-[#5d6e7f] mt-0.5">The policy's best-practice language becomes your default, and we move you to the next question.</span>
                </span>
            </label>
        </div>

        @if($currentHasDocumentedProcess === true)
        @if($question->policies->isNotEmpty())
        <div class="rounded-xl bg-[#f8fbfd] border-l-[3px] border-l-[#0b9ed0] border-y border-r border-y-[#e6edf4] border-r-[#e6edf4] p-4 mb-3">
            <p class="text-xs font-extrabold uppercase tracking-wide text-[#12304f] mb-2">Your response should cover{{ $question->policies->count() > 1 ? ' all '.$question->policies->count().' policies' : '' }}</p>
            @foreach($question->policies as $policy)
            <div class="{{ ! $loop->last ? 'border-b border-[#eef2f6] mb-2.5 pb-2.5' : '' }}">
                @if($question->policies->count() > 1)
                <p class="text-xs text-[#12304f] mb-1.5"><strong class="text-[11.5px] bg-[#eaf5fb] text-[#1a7aad] rounded-full px-1.5 py-0.5 mr-1">{{ $policy->code }}</strong> {{ $policy->title }}</p>
                @endif
                <ul class="list-disc list-inside text-xs text-[#173045] space-y-1">
                    @foreach(($policy->requirements['bullets'] ?? []) as $bullet)
                    <li>{{ $bullet }}</li>
                    @endforeach
                </ul>
                @if($policy->requirements['full_question'] ?? null)
                <details class="group mt-1.5">
                    <summary class="list-none [&::-webkit-details-marker]:hidden text-xs font-bold text-[#1a7aad] cursor-pointer before:content-['▸_'] group-open:before:content-['▾_']">Full question{{ $question->policies->count() > 1 ? ' for '.$policy->code : '' }}</summary>
                    <p class="text-xs text-[#5d6e7f] leading-relaxed mt-1.5">{{ $policy->requirements['full_question'] }}</p>
                </details>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        <div x-data="{ text: @js($currentResponse), get wordCount() { return this.text.trim() === '' ? 0 : this.text.trim().split(/\s+/).filter(Boolean).length } }" wire:key="response-field-{{ $currentQuestionId }}">
            <label for="qText" class="block text-sm font-semibold text-[#173045] mb-1.5">Practice response <span class="text-red-500">*</span></label>
            <textarea wire:model="currentResponse" x-on:input="text = $event.target.value" id="qText" rows="8" placeholder="Describe your practice's process&hellip;"
                class="w-full rounded-xl border {{ $errors->has('currentResponse') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] leading-relaxed focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"></textarea>
            @error('currentResponse') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1.5 text-xs text-[#5d6e7f]"><span x-text="wordCount"></span> <span x-text="wordCount === 1 ? 'word' : 'words'"></span> &middot; Name real roles, real systems, real vendors, and real timeframes &mdash; not &ldquo;as required by policy.&rdquo; Empower does not edit practice responses.</p>
        </div>
        @endif

        <div class="flex items-center justify-between mt-5">
            <button wire:click="backOneQuestion" wire:loading.attr="disabled" wire:target="chooseDocumentedProcess,chooseNoDocumentedProcess,saveCurrentAnswer,skipCurrentQuestion,backOneQuestion" class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors disabled:opacity-50">&larr; Back</button>
            <div class="flex items-center gap-2">
                @if($this->onlySkippedQuestionsRemain)
                <button wire:click="skipCurrentQuestion" wire:loading.attr="disabled" wire:target="chooseDocumentedProcess,chooseNoDocumentedProcess,saveCurrentAnswer,skipCurrentQuestion,backOneQuestion"
                    class="rounded border border-[#dbe4ee] px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors disabled:opacity-50">Finish questionnaire</button>
                @else
                <button wire:click="skipCurrentQuestion" wire:loading.attr="disabled" wire:target="chooseDocumentedProcess,chooseNoDocumentedProcess,saveCurrentAnswer,skipCurrentQuestion,backOneQuestion"
                    class="rounded border border-[#dbe4ee] px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors disabled:opacity-50">Skip for now</button>
                @endif
                @if($currentHasDocumentedProcess === true)
                <button wire:click="saveCurrentAnswer" wire:loading.attr="disabled" wire:target="chooseDocumentedProcess,chooseNoDocumentedProcess,saveCurrentAnswer,skipCurrentQuestion,backOneQuestion"
                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveCurrentAnswer">Save &amp; Continue &rarr;</span>
                    <span wire:loading.inline-flex wire:target="saveCurrentAnswer" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
                @endif
            </div>
        </div>
        </div>

        <aside class="bg-[#f6f9fc] border border-[#e6edf4] rounded-2xl p-5 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] overflow-y-auto">
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Why we ask</h4>
            <p class="text-xs text-[#173045] leading-snug mb-4">{{ $question->why_we_ask ?: 'This answer feeds directly into your compliance manuals.' }}</p>
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">No documented answer?</h4>
            <p class="text-xs text-[#173045] leading-snug mb-4">Choose "We don't have a documented answer." The policy's best-practice language becomes your default and you move to the next question. If you do respond, your response is used as written.</p>
            <h4 class="text-[11.5px] font-extrabold uppercase tracking-wide text-[#5d6e7f] mb-1.5">Fills these policies</h4>
            @php($policyManualCount = $question->policies->pluck('manual')->unique()->count())
            @if($question->policies->count() > 1)
            <p class="text-[12.5px] text-[#135f41] bg-[#e6f6ef] rounded-lg px-2.5 py-1.5 mb-2">One answer fills <strong>{{ $question->policies->count() }} policies</strong>{{ $policyManualCount > 1 ? ' across '.$policyManualCount.' manuals' : '' }}.</p>
            @endif
            <div class="flex flex-wrap gap-1.5">
                @foreach($question->policies as $policy)
                <span title="{{ $policy->title }}"
                    class="text-[11.5px] font-bold bg-white border border-[#dbe4ee] text-[#12304f] rounded-full px-2.5 py-1">{{ $policy->code }}</span>
                @endforeach
            </div>
            <ul class="list-none mt-2 text-xs text-[#5d6e7f] leading-relaxed space-y-0.5">
                @foreach($question->policies as $policy)
                <li>&middot; {{ $policy->title }}</li>
                @endforeach
            </ul>
            <p class="text-xs text-[#5d6e7f] mt-4 pt-4 border-t border-[#e6edf4]">Stuck on this one? <button type="button" wire:click="openCallDialog" class="font-semibold text-[#0b9ed0] hover:underline">Talk to a specialist</button></p>
        </aside>
        </div>
    </div>
    @endif

    {{-- ── Done ── --}}
    {{-- ── Saving (cosmetic transition between the last question and the done screen) ── --}}
    @if($screen === 'saving')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        <div class="flex flex-col items-center text-center px-6 py-14 lg:py-20 max-w-xl mx-auto"
            x-data="{
                step: 0,
                items: ['Saving your answers securely', 'Linking your answers to your policies', 'Preparing your review'],
            }"
            x-init="
                setTimeout(() => step = 1, 500);
                setTimeout(() => step = 2, 1000);
                setTimeout(() => step = 3, 1500);
                setTimeout(() => $wire.finishWizard(), 2200);
            ">
            <div
                class="h-14 w-14 rounded-full border-[2.5px] border-[#0b9ed0] text-[#0b9ed0] flex items-center justify-center mb-5">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h2 class="text-2xl font-extrabold text-[#0e1b30] mb-1.5">Answers saved</h2>
            <p class="text-sm text-[#5d6e7f] mb-6">Next, you&rsquo;ll review everything before you submit.</p>
            <ul class="space-y-2.5 text-left">
                <template x-for="(item, index) in items" :key="index">
                    <li class="flex items-center gap-2.5 text-sm" :class="index < step ? 'text-[#173045] font-semibold' : 'text-[#9aabbd]'">
                        <span class="flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center"
                            :class="index < step ? 'bg-[#e6f6ef] text-[#1f9d6b]' : 'bg-[#eef2f6]'">
                            <svg x-show="index < step" width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span x-text="item"></span>
                    </li>
                </template>
            </ul>
        </div>
    </div>
    @endif

    @if($screen === 'done')
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)]">
        @include('components.portal._intake-wizard-chapter-header', $chapterHeaderData)
        <div class="flex flex-col items-center text-center px-6 py-14 lg:py-20 max-w-xl mx-auto">
            <div class="w-14 h-14 rounded-full flex items-center justify-center mb-5 bg-[#e6f6ef] text-[#1f9d6b]">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h2 class="text-[28px] font-extrabold text-[#0e1b30] mb-1.5">You're through the intake</h2>
            <p class="text-sm text-[#5d6e7f] mb-6">Next, review your answers and documents, then certify and submit.</p>

            <button type="button" wire:click="continueToConfirm" wire:target="continueToConfirm" wire:loading.attr="disabled"
                class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-6 py-2.5 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                <span wire:loading.remove wire:target="continueToConfirm">Continue to Upload &amp; Confirm &rarr;</span>
                <span wire:loading.inline-flex wire:target="continueToConfirm" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Continuing&hellip;</span>
            </button>
        </div>
    </div>
    @endif

    {{-- "Talk to a specialist" call-booking dialog --}}
    <div x-show="$wire.callDialogOpen" x-cloak x-on:keydown.escape.window="$wire.closeCallDialog()"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 px-4">
        <div class="relative w-full max-w-xl bg-white rounded-2xl shadow-xl overflow-hidden max-h-[90vh] overflow-y-auto"
            x-on:click.outside="$wire.closeCallDialog()">
            <div class="flex items-start justify-between px-6 pt-6 pb-4">
                <div>
                    <h3 class="text-lg font-extrabold text-[#0e1b30]">Talk to a compliance specialist</h3>
                    @unless($callBooked)
                    <p class="text-sm text-[#5d6e7f] mt-1">Book a free 15-minute call. We&rsquo;ll call you at the
                        time you choose.</p>
                    @endunless
                </div>
                <button type="button" wire:click="closeCallDialog" aria-label="Close"
                    class="flex-shrink-0 text-[#5c778d] hover:text-[#0e3a61] transition-colors">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($callBooked)
            <div class="px-6 pb-8 text-center">
                <div
                    class="mx-auto mb-4 h-14 w-14 rounded-full bg-[#e6f6ef] text-[#1f9d6b] flex items-center justify-center">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <h4 class="text-base font-bold text-[#173045] mb-1">Call requested</h4>
                <p class="text-sm text-[#5d6e7f]">We&rsquo;ll call {{ $callPhone }} on
                    {{ Carbon::parse($callDate)->format('l, M j') }} at {{ $callTime }} Eastern.
                </p>
            </div>
            @else
            <div class="px-6 pb-6 border-t border-[#eef2f6] pt-4">
                <p class="text-xs font-bold text-[#31465b] mb-2">Pick a day</p>
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach($this->availableCallDays as $day)
                    <button type="button" wire:click="$set('callDate', '{{ $day->toDateString() }}')"
                        class="rounded-full border px-3.5 py-1.5 text-sm font-semibold transition-colors {{ $callDate === $day->toDateString() ? 'border-[#12304f] bg-[#12304f] text-white' : 'border-[#dbe4ee] text-[#173045] hover:border-[#0b9ed0]' }}">
                        {{ $day->format('D, M j') }}</button>
                    @endforeach
                </div>

                <p class="text-xs font-bold text-[#31465b] mb-2">Pick a time (Eastern)</p>
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach($this->availableCallTimes() as $time)
                    <button type="button" wire:click="$set('callTime', '{{ $time }}')"
                        class="rounded-full border px-3.5 py-1.5 text-sm font-semibold transition-colors {{ $callTime === $time ? 'border-[#12304f] bg-[#12304f] text-white' : 'border-[#dbe4ee] text-[#173045] hover:border-[#0b9ed0]' }}">
                        {{ $time }}</button>
                    @endforeach
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-[#31465b] mb-1.5">Phone number</label>
                        <input wire:model="callPhone" type="tel" placeholder="+1 555 123 4567"
                            class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                        @error('callPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-[#31465b] mb-1.5">Topic</label>
                        <select wire:model="callTopic"
                            class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                            @foreach($this->availableCallTopics() as $topic)
                            <option value="{{ $topic }}">{{ $topic }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <label class="block text-xs font-bold text-[#31465b] mb-1.5">Anything we should know? <span
                        class="font-normal text-[#5d6e7f]">(optional)</span></label>
                <textarea wire:model="callNotes" rows="3"
                    class="w-full rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"></textarea>
                @error('callNotes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center justify-between gap-3 bg-[#f8fafc] border-t border-[#eef2f6] px-6 py-4">
                <button type="button" wire:click="closeCallDialog"
                    class="rounded-full border border-[#dbe4ee] bg-white px-4 py-2 text-sm font-semibold text-[#173045] hover:bg-[#f5f7fa] transition-colors">Cancel</button>
                <button type="button" wire:click="bookSpecialistCall" wire:loading.attr="disabled"
                    wire:target="bookSpecialistCall"
                    class="rounded-full bg-[#3a9bd5] px-5 py-2 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors">
                    <span wire:loading.remove wire:target="bookSpecialistCall">Book call</span>
                    <span wire:loading.inline-flex wire:target="bookSpecialistCall" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Booking&hellip;</span>
                </button>
            </div>
            @endif
        </div>
    </div>
</div>
