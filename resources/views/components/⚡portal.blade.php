<?php

use App\Enums\AiExtractionStatus;
use App\Enums\BillingCycle;
use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\EmpowerPaymentApiException;
use App\Jobs\GenerateComplianceDocument;
use App\Jobs\ProcessIntakeUpload;
use App\Jobs\ProvisionLmsAccount;
use App\Mail\AdminIntakeSubmittedMail;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\ClientPaymentReceiptMail;
use App\Mail\ClientTrialStartedMail;
use App\Mail\PreLaunchSignupMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\ActivityLog;
use App\Models\DiscountCode;
use App\Models\GeneratedDocument;
use App\Models\IntakeAnswer;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Practice;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\IntakeSubmittedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\ReviewerQuestionReplyNotification;
use App\Services\CloverChargeService;
use App\Services\EmpowerPaymentApiClient;
use App\Services\IntakeReviewStarter;
use App\Services\TrialBillingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public int $step = 1;

    // The batch of orders created by the most recent checkout — drives Steps 1/3/4.
    public array $orderIds = [];

    // Which order's documents are shown in the Step 5 Documents tab.
    public ?int $dashboardOrderId = null;

    // Step 1
    public ?int $selectedPackageId = null;

    // "Sign up for updates" — shown instead of the payment form while publicLaunchGateActive() is
    // true, i.e. before config('app.public_launch_at'). Not restored by mount(), same as
    // $newAccountEmail below: a one-request confirmation, not persisted state.
    public string $updatesEmail = '';

    public bool $signedUpForUpdates = false;

    public string $discountCodeInput = '';

    public ?int $appliedDiscountCodeId = null;

    public string $billingCycle = 'annual';

    public string $accountName = '';

    public string $accountEmail = '';

    // Set only when pay()/payFreeTrial() creates a guest account in the current request, so the
    // Step 1 "Account Information" summary can announce it once. Deliberately not restored by
    // mount(), so it clears itself on the next page load instead of persisting for the life of
    // the account — a pre-existing authenticated user paying never sees this at all.
    public ?string $newAccountEmail = null;

    // Same one-shot convention as $newAccountEmail above: true only for the request that just
    // charged the card, so the success banner can flash once instead of replaying on every
    // later page load of the already-paid view.
    public bool $justPaid = false;

    // Same one-shot convention again: true only for the request that just submitted the intake,
    // so Step 4 can show its "sending/uploading/queuing" transition once instead of replaying it
    // every time the client revisits an already-submitted Step 4.
    public bool $justSubmitted = false;

    // Reply drafts for an open reviewer question, keyed by intake_submission id.
    public array $reviewerReplyText = [];

    // Billing address for the charge — not cardholder data, safe to bind/validate normally.
    // Card number/expiry/CVC/name are deliberately NOT properties here: Livewire serializes
    // every public property into the page's wire:snapshot and replays it on every subsequent
    // request, which is exactly the kind of persistent exposure raw card data must never have.
    // They're captured from plain (non-wire:model) inputs and passed straight into pay() as
    // method arguments instead — see the Payment Details card and pay() below.
    public string $billingAddress1 = '';

    public string $billingCity = '';

    public string $billingState = '';

    public string $billingZip = '';

    // Card field *messages* only ("The card number field is required.") — never the values
    // themselves. Livewire only persists error-bag entries for real bound properties across
    // requests (see SupportValidation::dehydrate()), so since cardName/cardNumber/etc. are
    // deliberately not properties, their @error() messages would otherwise vanish the moment
    // any other request fires (e.g. live-validating a billing field). This property survives
    // normally and boot() replays it into the error bag every request.
    public array $cardErrors = [];

    // Step 2 — only used by the "edit an already-locked profile" quick-edit path now; the
    // fresh intake wizard lives in <livewire:portal.practice-intake-wizard> and manages its own
    // copies of these fields.
    public $logoFile = null;

    public string $practiceName = '';

    public string $practiceAddress = '';

    public string $specialty = 'General Practice';

    public int $billableProviders = 1;

    public bool $editingProfile = false;

    // Step 5 Dashboard — lets the client send one more document for AI review after their
    // submission is already approved, without reopening the intake wizard or touching anything
    // they already answered.
    public $additionalDocumentFile = null;

    public string $additionalDocumentCategory = '';

    public ?string $additionalDocumentNotice = null;

    // Step 3 "Upload & Confirm" — lets the client add or mark off documents inline, matching
    // the reference prototype's uploadsHTML() widget, instead of forcing a trip back to Step 2.
    public $step3DocumentFile = null;

    public string $step3DocumentCategory = '';

    /** Which wizard screen to land on next time <livewire:portal.practice-intake-wizard> mounts —
     *  set by a "Your answers" row's Edit link just before switching back to Step 2. */
    public ?string $editIntakeScreen = null;

    // Step 3 — certification fields for the "Upload & Confirm" step.
    public string $certifiedByName = '';

    public string $certifiedByTitle = '';

    public string $certifiedSignature = '';

    public bool $certifyChecked = false;

    // Step 5
    public string $dashboardTab = 'documents';

    #[Computed]
    public function packages(): Collection
    {
        return Package::where('is_active', true)->orderBy('sort_order')->get();
    }

    private function defaultPackageId(): ?int
    {
        return Package::where('is_active', true)
            ->where('slug', '!=', 'complete')
            ->orderBy('sort_order')
            ->value('id');
    }

    #[Computed]
    public function selectedPackage(): ?Package
    {
        return $this->selectedPackageId ? Package::find($this->selectedPackageId) : null;
    }

    #[Computed]
    public function appliedDiscountCode(): ?DiscountCode
    {
        return $this->appliedDiscountCodeId ? DiscountCode::find($this->appliedDiscountCodeId) : null;
    }

    /** Falls back to Annual for a stale/tampered value rather than erroring. */
    public function currentBillingCycle(): BillingCycle
    {
        return BillingCycle::tryFrom($this->billingCycle) ?? BillingCycle::Annual;
    }

    #[Computed]
    public function discountAmount(): float
    {
        if (! $this->selectedPackage || ! $this->appliedDiscountCode || $this->appliedDiscountCode->type !== DiscountType::Percentage) {
            return 0.0;
        }

        $price = ($this->selectedPackage->priceForCycle($this->currentBillingCycle()) ?? 0.0) * max(1, $this->billableProviders);

        return round($price * $this->appliedDiscountCode->percentage / 100, 2);
    }

    #[Computed]
    public function discountedTotal(): float
    {
        $price = ($this->selectedPackage?->priceForCycle($this->currentBillingCycle()) ?? 0.0) * max(1, $this->billableProviders);

        return max(0, $price - $this->discountAmount);
    }

    public function incrementProviders(): void
    {
        $this->billableProviders = min(9999, $this->billableProviders + 1);
    }

    public function decrementProviders(): void
    {
        $this->billableProviders = max(1, $this->billableProviders - 1);
    }

    #[Computed]
    public function isFreeTrialCheckout(): bool
    {
        return $this->appliedDiscountCode?->type === DiscountType::FreeTrial;
    }

    /** Every order created by the checkout batch currently being walked through Steps 1/3/4. */
    #[Computed]
    public function batchOrders(): Collection
    {
        if (empty($this->orderIds)) {
            return collect();
        }

        return Order::with(['package', 'intakeSubmission.intakeUploads', 'intakeSubmission.reviewerQuestions'])
            ->whereIn('id', $this->orderIds)
            ->get();
    }

    #[Computed]
    public function rejectedSubmission(): ?IntakeSubmission
    {
        return $this->batchOrders
            ->map(fn ($o) => $o->intakeSubmission)
            ->first(fn ($s) => $s?->status === IntakeSubmissionStatus::Rejected);
    }

    /** The order whose submission actually holds the practice intake wizard's live answers/
     *  uploads — the lowest-id order in the checkout batch, matching
     *  <livewire:portal.practice-intake-wizard>'s own primaryOrderId() selection. */
    #[Computed]
    public function primarySubmission(): ?IntakeSubmission
    {
        if (empty($this->orderIds)) {
            return null;
        }

        return $this->batchOrders->firstWhere('id', min($this->orderIds))?->intakeSubmission;
    }

    // Step 3 "Upload & Confirm" review — matches the client prototype's BASE_DOCS list (same as
    // <livewire:portal.practice-intake-wizard>'s DOCUMENT_CATEGORIES). Editable directly here,
    // same as the prototype's "Anything you already have. You can add more here." — see
    // toggleStep3DocumentMissing()/uploadStep3Document() below.
    private const REVIEW_DOCUMENT_CATEGORIES = [
        'compliance_ethics' => 'Compliance & Ethics Program',
        'hipaa_privacy' => 'HIPAA Privacy policies',
        'hipaa_security' => 'HIPAA Security policies',
        'training_materials' => 'Training materials',
    ];

    // Advanced-only — mirrors <livewire:portal.practice-intake-wizard>'s
    // ADVANCED_DOCUMENT_CATEGORIES, appended via reviewDocumentCategories() below.
    private const ADVANCED_REVIEW_DOCUMENT_CATEGORIES = [
        'employee_manual' => 'Employee manual',
        'encounter_list' => 'Encounter list (10 per provider)',
    ];

    // Dashboard "Documents" tab titles for a per-upload AI-polished review (DocumentType::
    // PolishedClientDocument) — keyed by the source upload's document_category, so each of a
    // client's own uploaded documents shows its own reviewed name instead of the generic
    // "Reviewed & Polished Document" fallback.
    private const POLISHED_UPLOAD_DOCUMENT_TITLES = [
        'compliance_ethics' => 'Compliance & Ethics Program (reviewed)',
        'hipaa_privacy' => 'HIPAA Privacy Policies (reviewed)',
        'hipaa_security' => 'HIPAA Security Policies (reviewed)',
        'training_materials' => 'Training Review Memo',
        'employee_manual' => 'Employee manual (reviewed)',
    ];

    private const BASICS_SUB_SCREENS = ['b_profile', 'b_providers', 'b_address', 'b_logo'];

    private const TEAM_SUB_SCREENS = ['t_practice', 't_officers', 't_it', 't_hotline', 't_leadership'];

    /** Display labels for the wizard's services checklist, keyed the same as
     *  IntakeQuestion::service_key — deliberately duplicated from
     *  practice-intake-wizard.blade.php's SERVICES, same non-coupling as elsewhere in this file,
     *  only used here to word a "not applicable" reason on the answers review. */
    private const SERVICE_LABELS = [
        'lab' => 'We order or perform lab tests or diagnostic procedures',
        'oon' => 'We see Medicare Advantage or Medicaid plan patients while out of network with their plan',
        'wc' => 'We treat workers\' compensation patients',
        'plan' => 'We also act as a health plan or a healthcare clearinghouse',
        'gov' => 'We treat patients in custody or military service members',
        'research' => 'We take part in research that uses patient information',
        'data' => 'We share de-identified data or limited data sets',
        'fund' => 'We use patient information for fundraising',
        'tele' => 'We offer telehealth visits',
        'byod' => 'Staff use personal phones, tablets or laptops for work',
        'remote' => 'Staff connect to our systems from outside the office',
        'wifi' => 'We have Wi-Fi at our office',
    ];

    #[Computed]
    public function basicsCompletedCount(): int
    {
        $reached = $this->primarySubmission?->wizard_reached_screens ?? [];

        return count(array_intersect(self::BASICS_SUB_SCREENS, $reached));
    }

    /** @return array<string, string> category key => label */
    #[Computed]
    public function reviewDocumentCategories(): array
    {
        return $this->batchOrders->contains(fn (Order $o) => $o->package?->includesAdvancedDocumentCategories())
            ? [...self::REVIEW_DOCUMENT_CATEGORIES, ...self::ADVANCED_REVIEW_DOCUMENT_CATEGORIES]
            : self::REVIEW_DOCUMENT_CATEGORIES;
    }

    public function reviewDocumentCategoryStatus(string $key): string
    {
        $uploads = $this->primarySubmission?->intakeUploads ?? collect();

        if ($uploads->contains(fn ($u) => $u->document_category === $key)) {
            return 'uploaded';
        }

        $missing = $this->primarySubmission?->wizard_missing_document_categories ?? [];

        return in_array($key, $missing, true) ? 'declined' : 'needed';
    }

    /** Step 3's "I don't have this" / "Undo" toggle — writes the same
     *  wizard_missing_document_categories column <livewire:portal.practice-intake-wizard> uses,
     *  so the two screens always agree on what's still missing. */
    public function toggleStep3DocumentMissing(string $key): void
    {
        $submission = $this->primarySubmission;

        if (! $submission || $submission->status !== IntakeSubmissionStatus::Draft) {
            return;
        }

        $missing = $submission->wizard_missing_document_categories ?? [];

        $missing = in_array($key, $missing, true)
            ? array_values(array_diff($missing, [$key]))
            : [...$missing, $key];

        $submission->update(['wizard_missing_document_categories' => $missing]);
        unset($this->primarySubmission);
    }

    /** Step 3's "Click to upload files or drag them here" dropzone — same deferred-processing
     *  pattern as the wizard's own documents screen: left at NotApplicable while still a Draft, so
     *  finalizeIntake() is what actually queues it for AI review once the intake is submitted. */
    public function uploadStep3Document(): void
    {
        abort_unless(auth()->check(), 403);

        $submission = $this->primarySubmission;

        if (! $submission || $submission->status !== IntakeSubmissionStatus::Draft) {
            return;
        }

        $this->validate([
            'step3DocumentFile' => 'required|file|mimes:pdf,jpg,jpeg,png,docx|max:20480',
        ]);

        $file = $this->step3DocumentFile;

        IntakeUpload::create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'document_category' => $this->step3DocumentCategory ?: null,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $file->store('uploads/batch/'.(string) Str::ulid()),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'ai_extraction_status' => AiExtractionStatus::NotApplicable,
        ]);

        $this->reset('step3DocumentFile', 'step3DocumentCategory');
        unset($this->primarySubmission);
    }

    /** One row per chapter of the practice intake — "Practice basics" always, plus "Your team"
     *  and each workflow section for Professional/Advanced. Mirrors the wizard's own chapter
     *  breakdown so the counts agree with what the client saw while filling it in. */
    /** @return array<int, array{label: string, value: string, done: bool}> */
    private function basicsDetailRows(): array
    {
        $practice = $this->practice;
        $reached = $this->primarySubmission?->wizard_reached_screens ?? [];
        $providers = $practice?->billable_providers_count ?? 1;

        return [
            [
                'label' => "Let's start with your practice",
                'value' => collect([$practice?->name, $practice?->specialty])->filter()->implode(' · ') ?: '—',
                'done' => in_array('b_profile', $reached, true),
                'screen' => 'b_profile',
            ],
            [
                'label' => 'How many billable providers do you have?',
                'value' => $providers.' billable provider'.($providers === 1 ? '' : 's'),
                'done' => in_array('b_providers', $reached, true),
                'screen' => 'b_providers',
            ],
            [
                'label' => 'Where is your practice located?',
                'value' => $practice?->address ?: '—',
                'done' => in_array('b_address', $reached, true),
                'screen' => 'b_address',
            ],
            [
                'label' => 'Add your practice logo',
                'value' => $practice?->logo_path ? 'Logo uploaded' : 'No logo added',
                'done' => in_array('b_logo', $reached, true),
                'screen' => 'b_logo',
            ],
        ];
    }

    /** One row per Team screen (not per field) — merges each screen's several fields into one
     *  summary line, matching the client's reference "Your team · 5/5" review layout. */
    /** @return array<int, array{label: string, value: string, done: bool, screen: string}> */
    private function teamDetailRows(): array
    {
        $practice = $this->practice;
        $reached = $this->primarySubmission?->wizard_reached_screens ?? [];

        $locations = collect($practice?->practice_locations ?? [])->filter()->implode('; ');

        $hotlineValue = match (true) {
            $practice?->uses_ehcp_hotline === true => "Empower's shared hotline",
            filled($practice?->compliance_hotline_number) => $practice->compliance_hotline_number,
            filled($practice?->compliance_hotline_email) => $practice->compliance_hotline_email,
            default => '—',
        };

        $officerRoles = [
            'Privacy Officer' => $practice?->hipaa_privacy_officer_name,
            'Security Officer' => $practice?->hipaa_security_officer_name,
            'Release of Information Officer' => $practice?->release_of_info_officer_name,
            'Compliance Officer' => $practice?->compliance_officer_name,
        ];

        $itValue = match (true) {
            $practice?->it_mode === 'vendor' => collect(['Vendor: '.($practice?->it_vendor_name ?: '—')])->implode(' · '),
            filled($practice?->it_contact_name) => collect([
                'In-house: '.$practice->it_contact_name,
                $practice?->it_contact_phone,
                $practice?->it_contact_email,
            ])->filter()->implode(' · '),
            default => '—',
        };

        $committeeValue = $practice?->committee_none
            ? 'No committee yet'
            : collect($practice?->compliance_committee_members ?? [])
                ->filter(fn ($m) => filled($m['name'] ?? null))
                ->map(fn ($m) => trim($m['name'].(filled($m['title'] ?? null) ? ' ('.$m['title'].')' : '')))
                ->implode(', ');

        $boardValue = collect($practice?->compliance_governing_board_members ?? [])
            ->filter(fn ($m) => filled($m['name'] ?? null))
            ->map(fn ($m) => trim($m['name'].(filled($m['title'] ?? null) ? ' ('.$m['title'].')' : '')))
            ->implode(', ');

        return [
            [
                'label' => "Your practice's legal details",
                'value' => collect([
                    $practice?->legal_practice_name,
                    $practice?->main_phone,
                    $practice?->main_email,
                    $locations !== '' ? 'Locations: '.$locations : null,
                ])->filter()->implode(' · ') ?: '—',
                'done' => in_array('t_practice', $reached, true),
                'screen' => 't_practice',
            ],
            [
                'label' => 'Who fills your compliance roles?',
                'value' => collect($officerRoles)
                    ->filter(fn ($name) => filled($name))
                    ->map(fn ($name, $role) => "{$role}: {$name}")
                    ->implode(' · ') ?: '—',
                'done' => in_array('t_officers', $reached, true),
                'screen' => 't_officers',
            ],
            [
                'label' => 'Who handles your IT?',
                'value' => $itValue,
                'done' => in_array('t_it', $reached, true),
                'screen' => 't_it',
            ],
            [
                'label' => 'How can staff reach a compliance hotline?',
                'value' => collect([$hotlineValue, filled($practice?->hotline_poster_count) ? $practice->hotline_poster_count.' poster'.((int) $practice->hotline_poster_count === 1 ? '' : 's') : null])->filter()->implode(' · ') ?: '—',
                'done' => in_array('t_hotline', $reached, true),
                'screen' => 't_hotline',
            ],
            [
                'label' => 'Who leads compliance oversight?',
                'value' => collect([
                    $committeeValue !== '' ? 'Committee: '.$committeeValue : null,
                    $boardValue !== '' ? 'Board: '.$boardValue : null,
                ])->filter()->implode(' · ') ?: '—',
                'done' => in_array('t_leadership', $reached, true),
                'screen' => 't_leadership',
            ],
        ];
    }

    /** @param \Illuminate\Support\Collection<int, IntakeQuestion> $questions
     *  @return array<int, array{label: string, value: string, done: bool, badge: array{label: string, class: string}, meta: ?string, screen: string}> */
    private function sectionDetailRows($questions, array $answersByQuestionId): array
    {
        return $questions->map(function (IntakeQuestion $question) use ($answersByQuestionId) {
            $answer = $answersByQuestionId[$question->id] ?? null;

            [$value, $badge] = match (true) {
                $answer === null => ['Not yet answered', ['label' => 'Open', 'class' => 'bg-[#eef1f5] text-[#5d6e7f]']],
                (bool) $answer->has_documented_process => [(string) $answer->response, ['label' => 'Practice response', 'class' => 'bg-[#d7f3ea] text-[#117a51]']],
                default => ['No documented answer · policy default language applies', ['label' => 'Policy default', 'class' => 'bg-[#eaf5fb] text-[#1a7aad]']],
            };

            return [
                'label' => $question->title,
                'value' => $value,
                'done' => $answer !== null,
                'badge' => $badge,
                'meta' => $question->policies->pluck('code')->implode(' · ') ?: null,
                'screen' => 'question:'.$question->id,
            ];
        })->all();
    }

    /** A question is left out of its section's gate entirely (not merely deferred) when the
     *  practice's committee/service answers rule it out — mirrors, rather than shares,
     *  practice-intake-wizard.blade.php's isServiceExcluded()/requires_compliance_committee
     *  checks, same deliberate non-coupling as chapterProgress() above. Returns null when the
     *  question still applies. */
    private function questionRemovedReason(IntakeQuestion $question, Practice $practice, array $selectedServices): ?string
    {
        if ($question->requires_compliance_committee && $practice->committee_none) {
            return 'No Compliance Committee (from your intake)';
        }

        if ($question->service_key && ! in_array($question->service_key, $selectedServices, true)) {
            $service = self::SERVICE_LABELS[$question->service_key] ?? $question->service_key;

            return "Not applicable: \"{$service}\" was not checked";
        }

        return null;
    }

    #[Computed]
    public function answerSummaryRows(): array
    {
        $rows = [
            [
                'label' => 'Practice basics',
                'done' => $this->basicsCompletedCount,
                'total' => count(self::BASICS_SUB_SCREENS),
                'details' => $this->basicsDetailRows(),
            ],
        ];

        $includesWorkflowQuestionnaire = $this->batchOrders->contains(fn (Order $o) => $o->package?->includesWorkflowQuestionnaire());

        if (! $includesWorkflowQuestionnaire) {
            return $rows;
        }

        $reached = $this->primarySubmission?->wizard_reached_screens ?? [];
        $rows[] = [
            'label' => 'Your team',
            'done' => count(array_intersect(self::TEAM_SUB_SCREENS, $reached)),
            'total' => count(self::TEAM_SUB_SCREENS),
            'details' => $this->teamDetailRows(),
        ];
        $rows[] = [
            'label' => 'Your services',
            'done' => in_array('services', $reached, true) ? 1 : 0,
            'total' => 1,
            'details' => [],
        ];

        $practice = $this->practice;
        $selectedServices = $this->primarySubmission?->wizard_selected_services ?? [];
        $sectionGates = $this->primarySubmission?->wizard_section_gates ?? [];
        $answersByQuestionId = $this->primarySubmission?->intakeAnswers()->get()->keyBy('intake_question_id')->all() ?? [];
        $removed = [];

        foreach (IntakeSection::with('questions.policies')->orderBy('sort_order')->get() as $section) {
            $eligibleQuestions = $section->questions->reject(function (IntakeQuestion $question) use ($practice, $selectedServices, &$removed) {
                $reason = $this->questionRemovedReason($question, $practice, $selectedServices);

                if ($reason !== null) {
                    $removed[] = ['question' => $question, 'reason' => $reason];
                }

                return $reason !== null;
            });

            $gate = $sectionGates[(string) $section->id] ?? null;
            $gateMode = $gate['mode'] ?? '';
            $details = [];
            $done = 0;
            $total = 0;

            if (in_array($gateMode, ['none', 'some'], true)) {
                $total++;
                $done++;
                $details[] = [
                    'label' => 'Documented procedures (section gate)',
                    'value' => $gateMode === 'none'
                        ? "No documented procedures · policy defaults for all {$eligibleQuestions->count()}"
                        : 'Documented: '.$eligibleQuestions->whereIn('id', $gate['picked'] ?? [])->pluck('title')->implode(', ')
                            .' · policy defaults for the other '.($eligibleQuestions->count() - count($gate['picked'] ?? [])),
                    'done' => true,
                    'badge' => ['label' => 'Done', 'class' => 'bg-[#d7f3ea] text-[#117a51]'],
                    'meta' => null,
                    'screen' => 'section:'.$section->id,
                ];

                if ($gateMode === 'some') {
                    $pickedQuestions = $eligibleQuestions->whereIn('id', $gate['picked'] ?? []);
                    $total += $pickedQuestions->count();
                    $done += $pickedQuestions->filter(fn (IntakeQuestion $q) => isset($answersByQuestionId[$q->id]))->count();
                    $details = [...$details, ...$this->sectionDetailRows($pickedQuestions, $answersByQuestionId)];
                }
            }

            $rows[] = [
                'label' => $section->label,
                'done' => $done,
                'total' => $total,
                'details' => $details,
            ];
        }

        if (count($removed)) {
            $rows[] = [
                'label' => 'Skipped by your answers (section removed)',
                'count' => count($removed),
                'details' => collect($removed)->map(fn (array $r) => [
                    'label' => $r['question']->title,
                    'value' => $r['reason'],
                    'done' => true,
                    'badge' => ['label' => 'Removed', 'class' => 'bg-[#eef1f5] text-[#5d6e7f]'],
                    'meta' => $r['question']->policies->pluck('code')->implode(' · ') ?: null,
                    'screen' => $r['question']->requires_compliance_committee ? 't_leadership' : 'services',
                ])->all(),
            ];
        }

        return $rows;
    }

    /** Step 3's Pro/Advanced summary boxes: how many workflow questions were answered with a
     *  documented process vs. left to the policy default, and how many are still unanswered. */
    #[Computed]
    public function workflowAnswerCounts(): array
    {
        $practice = $this->practice;
        $selectedServices = $this->primarySubmission?->wizard_selected_services ?? [];
        $sectionGates = $this->primarySubmission?->wizard_section_gates ?? [];
        $answersByQuestionId = $this->primarySubmission?->intakeAnswers()->get()->keyBy('intake_question_id')->all() ?? [];

        $answered = 0;
        $policyDefault = 0;
        $open = 0;

        foreach (IntakeSection::with('questions')->orderBy('sort_order')->get() as $section) {
            $eligibleQuestions = $section->questions->reject(
                fn (IntakeQuestion $q) => $this->questionRemovedReason($q, $practice, $selectedServices) !== null
            );
            $gate = $sectionGates[(string) $section->id] ?? null;
            $gateMode = $gate['mode'] ?? '';
            $picked = $gate['picked'] ?? [];

            foreach ($eligibleQuestions as $question) {
                $answer = $answersByQuestionId[$question->id] ?? null;

                if ($answer !== null) {
                    $answer->has_documented_process ? $answered++ : $policyDefault++;

                    continue;
                }

                if (! in_array($gateMode, ['none', 'some'], true)) {
                    // Gate not yet resolved (including skipped for now) — we don't know yet
                    // whether this topic will be asked or defaulted, so it counts as open.
                    $open++;
                } elseif ($gateMode === 'none' || ! in_array($question->id, $picked, true)) {
                    $policyDefault++;
                } else {
                    $open++;
                }
            }
        }

        return compact('answered', 'policyDefault', 'open');
    }

    private function intakeSubmissionStatusLabel(?IntakeSubmissionStatus $status): string
    {
        return match ($status) {
            IntakeSubmissionStatus::Submitted => 'Submitted',
            IntakeSubmissionStatus::UnderReview => 'Under Review',
            IntakeSubmissionStatus::Approved => 'Approved',
            IntakeSubmissionStatus::Rejected => 'Rejected',
            IntakeSubmissionStatus::Pending => 'Pending',
            default => 'Ready',
        };
    }

    /** The order whose documents are currently displayed on the Step 5 dashboard. */
    #[Computed]
    public function currentOrder(): ?Order
    {
        if (! $this->dashboardOrderId) {
            return null;
        }

        return Order::with(['package', 'intakeSubmission.intakeUploads'])->find($this->dashboardOrderId);
    }

    #[Computed]
    public function practice(): ?Practice
    {
        return auth()->user()?->practice;
    }

    #[Computed]
    public function oshaLocations(): Collection
    {
        return $this->practice?->oshaLocations ?? collect();
    }

    #[Computed]
    public function intakeSubmission(): ?IntakeSubmission
    {
        return $this->currentOrder?->intakeSubmission;
    }

    #[Computed]
    public function generatedDocuments(): Collection
    {
        if (! $this->dashboardOrderId) {
            return collect();
        }

        return GeneratedDocument::where('order_id', $this->dashboardOrderId)
            ->with('intakeUpload')
            ->orderBy('document_type')
            ->get();
    }

    /** Every document the active package entitles this practice to, paired with its generated row (if any). */
    /**
     * One row per questionnaire the client actually uploaded — not per package tier —
     * paired with its generated document (if any). A questionnaire with no matching
     * manual (a retired/generic intake type) produces no row.
     */
    #[Computed]
    public function expectedDocuments(): Collection
    {
        $order = $this->currentOrder;
        if (! $order) {
            return collect();
        }

        $docs = $this->generatedDocuments;
        $locations = $this->oshaLocations;
        $rows = collect();

        $uploadedQuestionnaireTypes = $order->intakeSubmission?->intakeUploads
            ->map(fn ($u) => $u->upload_type)
            ->unique() ?? collect();

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
                $order->intakeSubmission->intakeUploads
                    ->where('upload_type', $uploadType)
                    ->reject(fn ($upload) => $upload->ai_extraction_status === AiExtractionStatus::NotApplicable)
                    ->each(function ($upload) use (&$rows, $docType, $docs) {
                        $rows->push([
                            'type' => $docType,
                            'location' => null,
                            'document' => $docs->first(fn ($d) => $d->document_type === $docType && $d->intake_upload_id === $upload->id),
                            'sourceUpload' => $upload,
                        ]);
                    });
            } elseif ($docType->isPerLocation()) {
                if ($locations->isEmpty()) {
                    $rows->push(['type' => $docType, 'location' => null, 'document' => $docs->first(fn ($d) => $d->document_type === $docType && ! $d->osha_location_id)]);
                }
                foreach ($locations as $location) {
                    $rows->push(['type' => $docType, 'location' => $location, 'document' => $docs->first(fn ($d) => $d->document_type === $docType && $d->osha_location_id === $location->id)]);
                }
            } else {
                $rows->push(['type' => $docType, 'location' => null, 'document' => $docs->first(fn ($d) => $d->document_type === $docType)]);
            }
        }

        // Tier-driven manuals (Professional/Advanced's compliance_ethics_manual/hipaa_privacy_policy/
        // hipaa_security_manual) are generated from the practice intake wizard's answers directly —
        // there's no "questionnaire upload" backing them the way the legacy loop above expects, so
        // list them here from the package's included_document_types instead. Skips any type already
        // covered above so a package that also matches a legacy upload type isn't listed twice.
        $coveredTypes = $rows->pluck('type')->unique();

        foreach (($order->package?->included_document_types ?? []) as $typeValue) {
            $docType = DocumentType::from($typeValue);

            if ($coveredTypes->contains($docType)) {
                continue;
            }

            $rows->push(['type' => $docType, 'location' => null, 'document' => $docs->first(fn ($d) => $d->document_type === $docType)]);
        }

        return $rows;
    }

    /** Gates Step 1's purchase form behind config('app.public_launch_at') — per the SSO
     *  requirements doc §11, non-SSO users see "Sign up for updates" instead of the payment form
     *  until the public launch date. Defaults to off (null) so this never activates unless
     *  explicitly configured — there is no SSO-vs-organic distinction built yet, so this applies
     *  to every not-yet-paid user uniformly until that carve-out exists. */
    #[Computed]
    public function publicLaunchGateActive(): bool
    {
        $launchAt = Setting::read('public_launch_at') ?? config('app.public_launch_at');

        return filled($launchAt) && now()->lt($launchAt);
    }

    public function signUpForUpdates(): void
    {
        $this->validate(['updatesEmail' => 'required|email:rfc,filter|max:255'], [], ['updatesEmail' => 'email']);

        $lead = Lead::create([
            'name' => auth()->user()?->name ?: $this->updatesEmail,
            'email' => $this->updatesEmail,
            'package_interest' => $this->selectedPackage?->slug,
            'source' => 'subscriber',
            'message' => 'Signed up for updates from the Step 1 pre-launch gate.',
        ]);

        try {
            Mail::to($lead->email)->send(new PreLaunchSignupMail($lead, $this->selectedPackage?->name));
        } catch (\Throwable $e) {
            report($e);
        }

        $this->signedUpForUpdates = true;
    }

    #[Computed]
    public function activityLog(): Collection
    {
        return ActivityLog::where('user_id', auth()->id())->latest()->limit(50)->get();
    }

    #[Computed]
    public function userOrders(): Collection
    {
        return auth()->user()->orders()->with(['package', 'intakeSubmission.intakeUploads'])->get();
    }

    #[Computed]
    public function practiceEffectiveDate(): ?Carbon
    {
        return $this->userOrders->pluck('paid_at')->filter()->min();
    }

    #[Computed]
    /**
     * 0 = unpaid. 1 = paid, Practice Intake wizard not yet finished (or not yet started —
     * no submission row exists at all until the wizard's documents/basics screen creates one).
     * 2 = wizard finished (wizard_screen === 'done') but not yet certified in Step 3 — still
     * Draft. 3 = certified and submitted, awaiting admin review (or a past rejection pending
     * re-certification). 4 = every order in the batch is Approved.
     */
    public function completedMilestone(): int
    {
        $orders = $this->batchOrders;

        if ($orders->isEmpty() || $orders->contains(fn ($o) => ! $o->isPaid())) {
            return 0;
        }

        $submissions = $orders->map(fn ($o) => $o->intakeSubmission);

        if ($submissions->contains(fn ($s) => $s === null || ($s->status === IntakeSubmissionStatus::Draft && $s->wizard_screen !== 'done'))) {
            return 1;
        }

        if ($submissions->contains(fn ($s) => $s->status === IntakeSubmissionStatus::Draft)) {
            return 2;
        }

        if ($submissions->contains(fn ($s) => $s->status !== IntakeSubmissionStatus::Approved)) {
            return 3;
        }

        return 4;
    }

    public function canReach(int $step): bool
    {
        return match ($step) {
            1 => true,
            2 => $this->completedMilestone >= 1,
            3 => $this->completedMilestone >= 2,
            4 => $this->completedMilestone >= 3,
            5 => $this->completedMilestone >= 4,
            default => false,
        };
    }

    /**
     * Runs on every request. Replays any persisted card-field error messages back into the
     * (otherwise request-scoped) error bag — see the $cardErrors property for why this is
     * necessary.
     */
    public function boot(): void
    {
        foreach ($this->cardErrors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    public function mount(): void
    {
        $user = auth()->user();

        if ($user?->isAdmin()) {
            $this->redirect(route('admin.dashboard'), navigate: true);

            return;
        }

        // The billing cycle is now chosen on the pricing page and carried in via this query
        // param, rather than a separate toggle in Step 1 — invalid/missing values keep the
        // property's own 'annual' default.
        if ($cycle = BillingCycle::tryFrom((string) request()->query('billing_cycle'))) {
            $this->billingCycle = $cycle->value;
        }

        if (! $user) {
            $this->step = 1;
            $slug = request()->query('package') ?? session()->pull('intended_package');
            $resolvedId = $slug ? Package::where('slug', $slug)->where('is_active', true)->value('id') : null;

            if ($resolvedId) {
                $this->selectedPackageId = $resolvedId;
            } else {
                $this->selectedPackageId = $this->defaultPackageId();
            }

            return;
        }

        $practice = $user->practice ?? Practice::create([
            'user_id' => $user->id,
            'name' => '',
        ]);

        // The practice hasn't set its own address yet — default to whatever billing address
        // was entered at checkout, as a convenience starting point the client can still edit.
        $checkoutBillingAddress = $practice->address
            ? []
            : ($user->orders()->whereNotNull('billing_address')->latest()->first()?->billing_address ?? []);

        $this->practiceName = $practice->name ?? '';
        $this->practiceAddress = $practice->address ?? $this->formatBillingAddressLine($checkoutBillingAddress);
        $this->specialty = $practice->specialty ?? 'General Practice';
        $this->billableProviders = $practice->billable_providers_count ?? 1;

        $requestedSlug = request()->query('package');

        if ($requestedSlug) {
            $requestedPackage = Package::where('slug', $requestedSlug)->where('is_active', true)->first();

            if ($requestedPackage) {
                $existingOrder = $user->orders()->where('package_id', $requestedPackage->id)->latest()->first();

                if (! $existingOrder) {
                    $this->step = 1;
                    $this->selectedPackageId = $requestedPackage->id;

                    return;
                }
            }
        }

        $latestOrder = $user->orders()->with(['package', 'intakeSubmission'])->latest()->first();

        if (! $latestOrder || ! $latestOrder->isPaid()) {
            $this->step = 1;

            if ($latestOrder) {
                $this->orderIds = [$latestOrder->id];
                $this->selectedPackageId = $latestOrder->package_id;
            } else {
                $slug = request()->query('package') ?? session()->pull('intended_package');
                $resolvedId = $slug ? Package::where('slug', $slug)->where('is_active', true)->value('id') : null;

                if ($resolvedId) {
                    $this->selectedPackageId = $resolvedId;
                } else {
                    $this->selectedPackageId = $this->defaultPackageId();
                }
            }

            return;
        }

        $this->orderIds = $latestOrder->checkout_batch_id
            ? $user->orders()->where('checkout_batch_id', $latestOrder->checkout_batch_id)->pluck('id')->all()
            : [$latestOrder->id];

        // Step 3's certification fields default to the logged-in account holder, or restore
        // whatever was typed on a prior certification (e.g. before a rejection).
        $this->certifiedByName = $this->primarySubmission?->certified_by_name ?: $user->name;
        $this->certifiedByTitle = $this->primarySubmission?->certified_by_title ?: 'Account Holder';

        $this->dashboardOrderId = $user->orders()->whereIn('payment_status', Order::PAID_STATUSES)->latest()->value('id');
        $this->selectedPackageId = $latestOrder->package_id;

        $submissions = $this->batchOrders->map(fn ($o) => $o->intakeSubmission);

        // Nobody has ever started the Practice Intake wizard yet — land back on Step 1's payment
        // summary (with its own "Continue to Intake Form" button) rather than auto-advancing,
        // matching the pre-wizard behavior of landing on Step 1 until the profile was locked.
        if ($submissions->contains(fn ($s) => $s === null)) {
            $this->step = 1;

            return;
        }

        if ($submissions->contains(fn ($s) => $s->status === IntakeSubmissionStatus::Draft && $s->wizard_screen !== 'done')) {
            $this->step = 2;

            return;
        }

        if ($submissions->contains(fn ($s) => $s->status === IntakeSubmissionStatus::Draft)) {
            $this->step = 3;

            return;
        }

        if ($submissions->contains(fn ($s) => in_array($s->status, [
            IntakeSubmissionStatus::Pending,
            IntakeSubmissionStatus::Submitted,
            IntakeSubmissionStatus::UnderReview,
            IntakeSubmissionStatus::Rejected,
        ]))) {
            $this->step = 4;

            return;
        }

        $this->step = 5;
    }

    public function goToStep(int $step): void
    {
        if (! $this->canReach($step)) {
            return;
        }

        $this->step = $step;
    }

    /** The child wizard component finished the documents/basics/team/questions flow — advance
     *  to Step 3's certification screen. */
    #[On('intake-wizard-complete')]
    public function onIntakeWizardComplete(): void
    {
        unset($this->completedMilestone, $this->batchOrders, $this->primarySubmission);
        $this->step = 3;

        // mount() only sets these defaults when it runs with an already-paid order — a guest who
        // pays and reaches Step 3 within the same session (no fresh page load) never gets a
        // second mount(), so they'd otherwise see these fields blank. Always re-read from the
        // submission (not just an in-memory emptiness check) so anything already autosaved via
        // updatedCertifiedByName()/updatedCertifiedByTitle() survives a trip back into the wizard
        // and forward again.
        $this->certifiedByName = $this->primarySubmission?->certified_by_name ?: auth()->user()->name;
        $this->certifiedByTitle = $this->primarySubmission?->certified_by_title ?: 'Account Holder';
    }

    /** Autosaves as the client types, so the value survives a trip back into the wizard and
     *  forward again — these are plain (non-.live) wire:model fields, so this only actually fires
     *  once a later request syncs the deferred change, same as any other field on this step. */
    public function updatedCertifiedByName(string $value): void
    {
        $this->primarySubmission?->update(['certified_by_name' => $value]);
    }

    public function updatedCertifiedByTitle(string $value): void
    {
        $this->primarySubmission?->update(['certified_by_title' => $value]);
    }

    /** "Review all answers" from the child wizard's section-jump dropdown — lets the client
     *  preview Step 3 at any point, not just once every question is answered. finalizeIntake()
     *  still refuses to certify/submit until the wizard actually reports 'done'. */
    #[On('intake-wizard-review-requested')]
    public function onIntakeWizardReviewRequested(): void
    {
        $this->step = 3;
    }

    /** Step 3 "Your answers" row Edit link — reopens the wizard on that exact screen. Still used
     *  for every non-question row (basics/team fields) — only workflow-question answers get the
     *  inline editor below, matching the prototype's own inlineEditor(), which is also
     *  question-only. */
    public function editIntakeAnswer(string $screenKey): void
    {
        $this->editIntakeScreen = $screenKey;
        $this->goToStep(2);
    }

    // ── Step 3 inline answer editing (workflow questions only) ──────────────

    public ?int $inlineEditQuestionId = null;

    public bool $inlineEditHasDocumentedProcess = true;

    public string $inlineEditResponse = '';

    public function startInlineEdit(int $questionId): void
    {
        $answer = $this->primarySubmission?->intakeAnswers()->where('intake_question_id', $questionId)->first();

        $this->inlineEditQuestionId = $questionId;
        $this->inlineEditHasDocumentedProcess = $answer ? (bool) $answer->has_documented_process : true;
        $this->inlineEditResponse = $answer?->response ?? '';
        $this->resetErrorBag('inlineEditResponse');
    }

    public function cancelInlineEdit(): void
    {
        $this->inlineEditQuestionId = null;
    }

    public function saveInlineEdit(): void
    {
        $this->resetErrorBag('inlineEditResponse');

        if ($this->inlineEditHasDocumentedProcess && trim($this->inlineEditResponse) === '') {
            $this->addError('inlineEditResponse', 'Write your practice response, or choose "We don\'t have a documented answer."');

            return;
        }

        $submission = $this->primarySubmission;

        if (! $submission) {
            return;
        }

        IntakeAnswer::updateOrCreate(
            ['intake_submission_id' => $submission->id, 'intake_question_id' => $this->inlineEditQuestionId],
            [
                'response' => $this->inlineEditHasDocumentedProcess ? $this->inlineEditResponse : null,
                'has_documented_process' => $this->inlineEditHasDocumentedProcess,
                'skipped' => false,
                'answered_at' => now(),
            ]
        );

        ActivityLog::record('intake_answer.updated_inline', 'A workflow answer was updated from the review screen.', user: auth()->user());

        $this->inlineEditQuestionId = null;
        unset($this->primarySubmission);

        $this->dispatch('toast', message: 'Answer saved.', type: 'success');
    }

    /** The wizard confirms it applied editIntakeScreen on mount — clears it so a later plain
     *  "Back to intake" (which remounts the same component fresh) doesn't replay the same jump. */
    #[On('intake-edit-screen-consumed')]
    public function onIntakeEditScreenConsumed(): void
    {
        $this->editIntakeScreen = null;
    }

    /** The child wizard's "Back" button on its very first screen — it can't call goToStep()
     *  directly since it isn't the parent component. */
    #[On('go-to-payment-step')]
    public function onGoToPaymentStep(): void
    {
        $this->goToStep(1);
    }

    public function editProfile(): void
    {
        abort_unless(auth()->check(), 403);

        $this->editingProfile = true;
        $this->step = 2;
    }

    public function cancelEditProfile(): void
    {
        $this->editingProfile = false;
        $this->step = 5;
    }

    public function switchOrder(int $orderId): void
    {
        abort_unless(auth()->check(), 403);

        $order = Order::where('id', $orderId)->where('user_id', auth()->id())->firstOrFail();

        $this->dashboardOrderId = $order->id;
        unset($this->currentOrder, $this->generatedDocuments, $this->expectedDocuments);
    }

    public function regenerateDocument(int $documentId): void
    {
        abort_unless(auth()->check(), 403);

        $document = GeneratedDocument::with(['oshaLocation', 'order', 'intakeUpload'])->findOrFail($documentId);

        abort_unless($document->order_id === $this->dashboardOrderId, 403);

        if ($document->order->blockedFromAiGeneration()) {
            $this->addError('payment', 'Your trial has ended. Please subscribe to continue using AI document generation.');

            return;
        }

        GenerateComplianceDocument::dispatch($document->order, $document->document_type, $document->oshaLocation, $document->intakeUpload);

        ActivityLog::record(
            'document.regenerate_requested',
            "Regeneration requested for {$document->document_type->label()}.",
            user: auth()->user(),
            order: $document->order,
            subject: $document,
        );

        unset($this->generatedDocuments, $this->expectedDocuments);
    }

    /**
     * Lets the client send one more document for AI review from the Dashboard once their
     * submission is already approved — their practice basics/team/answers don't need to change,
     * so this skips reopening the whole intake wizard. Mirrors the wizard's own upload handling:
     * dispatches ProcessIntakeUpload immediately (no "still drafting" period to wait out here,
     * unlike the wizard's own document screen). The resulting "Reviewed & Polished Document"
     * needs an admin's explicit approval before it appears as downloadable, same as every other
     * generated document.
     */
    public function uploadAdditionalDocument(): void
    {
        abort_unless(auth()->check(), 403);

        $order = Order::where('id', $this->dashboardOrderId)->where('user_id', auth()->id())->first();

        if (! $order) {
            return;
        }

        if ($order->blockedFromAiGeneration()) {
            $this->addError('additionalDocumentFile', 'Your trial has ended. Please subscribe to continue using AI document generation.');

            return;
        }

        $submission = $order->intakeSubmission;

        if (! $submission) {
            $this->addError('additionalDocumentFile', 'No intake submission found for this order.');

            return;
        }

        $this->validate([
            'additionalDocumentFile' => 'required|file|mimes:pdf,jpg,jpeg,png,docx|max:20480',
        ]);

        $file = $this->additionalDocumentFile;

        $upload = IntakeUpload::create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'document_category' => $this->additionalDocumentCategory ?: null,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $file->store('uploads/batch/'.(string) Str::ulid()),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        ProcessIntakeUpload::dispatch($upload);

        ActivityLog::record(
            'upload.additional_document_submitted',
            "{$upload->original_filename} uploaded for AI review on order #{$order->id}.",
            user: auth()->user(),
            order: $order,
            subject: $submission,
        );

        $this->reset('additionalDocumentFile', 'additionalDocumentCategory');
        $this->additionalDocumentNotice = 'Uploaded — this will appear below once our team has reviewed it.';

        unset($this->expectedDocuments, $this->generatedDocuments);
    }

    public function applyDiscountCode(): void
    {
        $this->resetErrorBag('discountCodeInput');
        $code = strtoupper(trim($this->discountCodeInput));

        if ($code === '') {
            $this->addError('discountCodeInput', 'Please enter a discount code.');

            return;
        }

        $discountCode = DiscountCode::whereRaw('UPPER(code) = ?', [$code])->first();

        if (! $discountCode) {
            $this->addError('discountCodeInput', 'This discount code is invalid.');

            return;
        }

        if (! $discountCode->is_active) {
            $this->addError('discountCodeInput', 'This discount code is inactive.');

            return;
        }

        if ($discountCode->isExpired()) {
            $this->addError('discountCodeInput', 'This discount code has expired.');

            return;
        }

        if ($discountCode->starts_at?->isFuture()) {
            $this->addError('discountCodeInput', 'This discount code is not yet active.');

            return;
        }

        if ($discountCode->hasReachedUsageLimit()) {
            $this->addError('discountCodeInput', 'This discount code has reached its usage limit.');

            return;
        }

        $this->appliedDiscountCodeId = $discountCode->id;
        $this->discountCodeInput = $discountCode->code;
    }

    public function removeDiscountCode(): void
    {
        $this->appliedDiscountCodeId = null;
        $this->discountCodeInput = '';
        $this->resetErrorBag('discountCodeInput');
    }

    /** @return array<string, mixed> */
    private function paymentRules(): array
    {
        $rules = [
            'selectedPackageId' => 'required|exists:packages,id',
            'billingAddress1' => 'required|string|max:255',
            'billingCity' => 'required|string|max:100',
            'billingState' => 'required|string|max:50',
            'billingZip' => 'required|string|max:20',
        ];

        if (auth()->guest()) {
            $rules = array_merge($rules, [
                'accountName' => 'required|string|max:100|regex:/^[\p{L}\s.\'-]+$/u',
                'accountEmail' => 'required|email:rfc,filter|max:150|unique:users,email',
            ]);
        }

        return $rules;
    }

    /**
     * Combines a billing address array (address1/city/state/zip, as stored on
     * orders.billing_address) into a single comma-separated line to pre-fill the Step 2
     * Practice Address field, so the client doesn't have to retype what they already entered
     * at checkout.
     *
     * @param  array<string, mixed>  $billingAddress
     */
    private function formatBillingAddressLine(array $billingAddress): string
    {
        return collect([
            $billingAddress['address1'] ?? null,
            $billingAddress['city'] ?? null,
            $billingAddress['state'] ?? null,
            $billingAddress['zip'] ?? null,
        ])->filter()->implode(', ');
    }

    /**
     * Rules for the card fields — kept separate from paymentRules() because these are never
     * bound Livewire properties (see the properties block above), so they're validated
     * manually against the values pay() receives as method arguments, not $this->validate().
     */
    private function cardRules(): array
    {
        return [
            'cardName' => 'required|string|max:255|regex:/^[\p{L}\s.\'-]+$/u',
            'cardNumber' => 'required|digits:16',
            'cardExpiry' => [
                'required',
                'string',
                function (string $attribute, $value, $fail) {
                    if (! preg_match('/^(\d{2})\/(\d{2})$/', (string) $value, $matches)) {
                        $fail('The card expiry field must be in MM/YY format.');

                        return;
                    }

                    [, $month, $year] = $matches;

                    if ($month < '01' || $month > '12') {
                        $fail('The card expiry month must be between 01 and 12.');

                        return;
                    }

                    $expiryYear = (int) ('20'.$year);
                    $expiryMonth = (int) $month;

                    if ($expiryYear < (int) date('Y') || ($expiryYear === (int) date('Y') && $expiryMonth < (int) date('n'))) {
                        $fail('The card has expired.');
                    }
                },
            ],
            'cardCvc' => 'required|digits_between:3,4',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accountName.regex' => 'Please enter a valid name using letters only.',
            'cardName.regex' => 'Please enter a valid name using letters only.',
            'termsAccepted.accepted' => 'Please agree to the Terms & Conditions before completing payment.',
        ];
    }

    public function updated(string $property): void
    {
        $paymentFields = ['selectedPackageId', 'accountName', 'accountEmail', 'billingAddress1', 'billingCity', 'billingState', 'billingZip'];
        $profileFields = ['practiceName', 'practiceAddress', 'specialty', 'billableProviders', 'logoFile'];

        // Switching to a package with no monthly price while Monthly is selected would otherwise
        // leave the toggle pointed at an option that's about to disappear.
        if ($property === 'selectedPackageId'
            && $this->billingCycle === BillingCycle::Monthly->value
            && ! Package::find($this->selectedPackageId)?->hasMonthlyPricing()) {
            $this->billingCycle = BillingCycle::Annual->value;
        }

        if (in_array($property, $paymentFields, true)) {
            $rules = $this->paymentRules();

            if (array_key_exists($property, $rules)) {
                $this->validateOnly($property, [$property => $rules[$property]]);
            }

            return;
        }

        if (in_array($property, $profileFields, true)) {
            $rules = $this->profileRules();

            if (array_key_exists($property, $rules)) {
                $this->validateOnly($property, [$property => $rules[$property]]);
            }
        }
    }

    /**
     * Card details arrive as method arguments, never as bound properties — see the properties
     * block above for why. They're read once, sent to the charge API, and go out of scope the
     * moment this method returns; they're never written to $this, logged, or persisted anywhere.
     *
     * One combined validation pass — billing fields (bound properties) plus card fields (method
     * arguments) — so a client seeing errors on both at once gets shown both at once, the same
     * as before card fields stopped being properties. Shared by validatePayment() (the pre-check
     * that gates whether the Terms & Conditions popup even opens) and pay() (which re-validates
     * the same fields immediately before actually charging the card, rather than trusting that
     * nothing changed between the two calls).
     */
    private function validateBillingAndCardFields(string $cardName, string $cardNumber, string $cardExpiry, string $cardCvc): void
    {
        $this->resetErrorBag();
        $this->cardErrors = [];

        try {
            Validator::make(
                [...$this->only(array_keys($this->paymentRules())), ...compact('cardName', 'cardNumber', 'cardExpiry', 'cardCvc')],
                [...$this->paymentRules(), ...$this->cardRules()],
                $this->messages()
            )->validate();
        } catch (ValidationException $e) {
            // Persist just the card-field messages (see $cardErrors) so they're still visible
            // after the client's next keystroke on an unrelated, live-validated field.
            $this->cardErrors = Arr::only($e->validator->errors()->messages(), array_keys($this->cardRules()));

            throw $e;
        }
    }

    /**
     * Validates billing + card fields only — no charge, no Terms & Conditions check. This is
     * what the "Pay" button calls first; the Terms & Conditions popup (the actual trigger for
     * pay()) only opens once this passes, so the client never has to accept the agreement
     * before finding out they mistyped their card number.
     *
     * Returns a plain boolean instead of letting the ValidationException propagate: Livewire's
     * own validation-exception handling stops the exception from ever reaching the browser as a
     * rejected promise (it's caught and turned into a normal, successful response with the error
     * bag populated instead — see SupportValidation::exception()), so a client-side .then()/
     * .catch() can't tell success from failure. A returned boolean can.
     */
    public function validatePayment(string $cardName = '', string $cardNumber = '', string $cardExpiry = '', string $cardCvc = ''): bool
    {
        try {
            $this->validateBillingAndCardFields($cardName, preg_replace('/\D/', '', $cardNumber ?? ''), $cardExpiry, $cardCvc);
        } catch (ValidationException $e) {
            // Replicate what Livewire's own exception handling would have done had this been
            // allowed to propagate, so @error() blocks still render normally.
            $this->setErrorBag($e->validator->errors());

            return false;
        }

        return true;
    }

    /**
     * The authoritative billing cycle for a charge — never trust the client-supplied
     * $billingCycle alone for money math, same as the discount code re-check just above. Clamps
     * back to Annual if Monthly was requested for a package that has no monthly price, which
     * would otherwise let a forged request charge $0.
     *
     * @param  Collection<int, Package>  $packages
     */
    private function effectiveBillingCycle(Collection $packages): BillingCycle
    {
        $cycle = $this->currentBillingCycle();

        if ($cycle === BillingCycle::Monthly && $packages->contains(fn (Package $p) => ! $p->hasMonthlyPricing())) {
            return BillingCycle::Annual;
        }

        return $cycle;
    }

    public function pay(string $cardName = '', string $cardNumber = '', string $cardExpiry = '', string $cardCvc = '', bool $termsAccepted = false): void
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber ?? '');

        $this->validateBillingAndCardFields($cardName, $cardNumber, $cardExpiry, $cardCvc);

        // Checked separately from the fields above: the popup that sets this can only ever be
        // reached after validateBillingAndCardFields() already passed once, so a failure here
        // only happens via a bypass attempt (e.g. calling pay() directly, skipping the popup).
        try {
            Validator::make(compact('termsAccepted'), ['termsAccepted' => 'accepted'], $this->messages())->validate();
        } catch (ValidationException $e) {
            $this->cardErrors = array_merge($this->cardErrors, $e->validator->errors()->messages());

            throw $e;
        }

        $packages = Package::whereIn('id', array_filter([$this->selectedPackageId]))->get();

        if ($packages->isEmpty()) {
            $this->addError('selectedPackageId', 'Please select at least one package.');

            return;
        }

        if ($packages->count() === 1 && $packages->first()->isCustomQuote()) {
            $this->redirect(route('contact', ['package' => $packages->first()->slug]), navigate: true);

            return;
        }

        $packages = $packages->reject(fn ($p) => $p->isCustomQuote());

        if ($packages->isEmpty()) {
            $this->addError('selectedPackageId', 'Please select at least one package.');

            return;
        }

        // Re-checked here rather than trusting the client-side applyDiscountCode() call — the
        // code could have been deactivated, expired, or hit its usage limit in the meantime.
        $discountCode = null;

        if ($this->appliedDiscountCodeId) {
            $discountCode = DiscountCode::find($this->appliedDiscountCodeId);

            if (! $discountCode || $discountCode->type !== DiscountType::Percentage || ! $discountCode->isCurrentlyValid()) {
                $this->appliedDiscountCodeId = null;
                $this->addError('discountCodeInput', 'This discount code is no longer valid. Please remove it and try again.');

                return;
            }
        }

        $cycle = $this->effectiveBillingCycle($packages);
        $providers = max(1, $this->billableProviders);
        $originalAmount = (float) $packages->sum(fn (Package $p) => ($p->priceForCycle($cycle) ?? 0.0) * $providers);
        $discountAmount = $discountCode ? round($originalAmount * $discountCode->percentage / 100, 2) : 0.0;
        $chargeAmount = max(0, $originalAmount - $discountAmount);

        [$expMonth, $expYear] = explode('/', $cardExpiry);

        $billingAddress = [
            'name' => $cardName,
            'address1' => $this->billingAddress1,
            'city' => $this->billingCity,
            'state' => $this->billingState,
            'zip' => $this->billingZip,
        ];

        $chargeResult = app(CloverChargeService::class)->charge([
            ...$billingAddress,
            'product_Name' => $packages->pluck('name')->implode(', '),
            'amount' => $chargeAmount,
            'cardNumber' => $cardNumber,
            'expMonth' => (int) $expMonth,
            'expYear' => (int) ('20'.$expYear),
            'cvv' => $cardCvc,
        ]);

        if (! $chargeResult->success) {
            PaymentLog::record(
                success: false,
                amount: $chargeAmount,
                user: auth()->user(),
                guestEmail: auth()->guest() ? $this->accountEmail : null,
                package: $packages->count() === 1 ? $packages->first() : null,
                transactionId: $chargeResult->transactionId,
                message: $chargeResult->declineMessage,
                billingAddress: $billingAddress,
            );

            // Two distinct messages, both shown at once: the field error explains why (the real
            // gateway reason, e.g. a genuine decline or "could not reach the payment processor"),
            // the banner reassures that nothing was actually charged.
            $this->addError('cardNumber', $chargeResult->declineMessage ?? 'Your card was declined. Try a different card, or contact your bank.');
            $this->addError('payment', "Payment didn't go through. You haven't been charged.");

            return;
        }

        // Save a reusable card token so this subscription can auto-renew later via the same
        // ProcessSubscriptionBilling engine the free-trial path already uses. Money has already
        // moved above, so a tokenize failure must never fail checkout — it just means this order
        // won't have a saved card yet (surfaces as the existing "no payment method on file" decline
        // on its first renewal attempt, recoverable via the dashboard's "Update Card" action).
        try {
            $tokenizeResult = app(EmpowerPaymentApiClient::class)->tokenize($cardNumber, $cardCvc);
        } catch (EmpowerPaymentApiException $e) {
            report($e);
            $tokenizeResult = null;
        }

        // Charge succeeded — only now do we create an account or any orders, so a decline never
        // leaves behind an orphaned guest account.
        if (auth()->guest()) {
            $generatedPassword = Str::password(16);

            $user = User::create([
                'name' => $this->accountName,
                'email' => $this->accountEmail,
                'password' => $generatedPassword,
                'role' => UserRole::Client,
            ]);

            Practice::create([
                'user_id' => $user->id,
                'name' => '',
            ]);

            try {
                Mail::to($user->email)->queue(new WelcomeCredentialsMail($user, $generatedPassword));
            } catch (\Throwable $e) {
                report($e);
            }

            Auth::login($user);
            $this->newAccountEmail = $user->email;

            // The layout's account menu (<livewire:header-account-menu />) lives outside this
            // component and was rendered while the visitor was still a guest — nothing else
            // prompts it to notice this mid-request login, so it'd otherwise keep showing "Login"
            // until the next full page load.
            $this->dispatch('user-logged-in');
        }

        // The provider count is confirmed here, at checkout, rather than later in the intake
        // wizard — it drives the price charged above, so it needs to be on the Practice record
        // before the wizard's own (now read-only) confirmation screen renders.
        auth()->user()->practice?->update(['billable_providers_count' => $providers]);

        $batchId = (string) Str::ulid();
        $orderIds = [];

        foreach ($packages as $package) {
            $packagePrice = ($package->priceForCycle($cycle) ?? 0.0) * $providers;
            $packageShare = $originalAmount > 0 ? $packagePrice / $originalAmount * $discountAmount : 0.0;

            $order = Order::create([
                'user_id' => auth()->id(),
                'package_id' => $package->id,
                'checkout_batch_id' => $batchId,
                'status' => OrderStatus::Paid,
                'payment_status' => PaymentStatus::Paid,
                'payment_reference' => $chargeResult->transactionId,
                'billing_address' => $billingAddress,
                'billing_cycle' => $cycle,
                'amount_paid' => $packagePrice - $packageShare,
                'original_price' => $packagePrice,
                'discount_amount' => $packageShare,
                'discount_code_id' => $discountCode?->id,
                'discount_code' => $discountCode?->code,
                'discount_percentage' => $discountCode?->percentage,
                'paid_at' => now(),
                'terms_accepted_at' => now(),
                'terms_accepted_ip' => request()->ip(),
                'next_bill_date' => $cycle === BillingCycle::Monthly ? now()->addMonth() : now()->addYear(),
                ...($tokenizeResult ? [
                    'clover_card_token' => $tokenizeResult['token'],
                    'card_expiry_month' => (int) $expMonth,
                    'card_expiry_year' => (int) ('20'.$expYear),
                    'card_last_four' => $tokenizeResult['lastFour'],
                    'mtbc_reference_number' => $tokenizeResult['referenceNumber'],
                ] : []),
            ]);

            ActivityLog::record(
                'order.paid',
                "Payment received for {$package->name} (\${$order->amount_paid}).",
                user: auth()->user(),
                order: $order,
            );

            ActivityLog::record(
                'order.terms_accepted',
                "Accepted the Terms & Conditions and the CareCloud MSA for {$package->name}.",
                user: auth()->user(),
                order: $order,
            );

            PaymentLog::record(
                success: true,
                amount: (float) $order->amount_paid,
                user: auth()->user(),
                package: $package,
                order: $order,
                transactionId: $chargeResult->transactionId,
                billingAddress: $billingAddress,
            );

            $admins = User::where('role', UserRole::Admin)->get();

            $admins->each(function (User $admin) use ($order) {
                try {
                    Mail::to($admin->email)->queue(new AdminPaymentReceivedMail($order));
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            try {
                Notification::send($admins, new PaymentReceivedNotification($order));
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                Mail::to($order->user->email)->queue(new ClientPaymentReceiptMail($order));
            } catch (\Throwable $e) {
                report($e);
            }

            $orderIds[] = $order->id;
        }

        $discountCode?->increment('used_count');
        $this->appliedDiscountCodeId = null;

        $this->orderIds = $orderIds;
        $this->dashboardOrderId = end($orderIds);
        $this->justPaid = true;

        $practice = auth()->user()->practice;
        $this->practiceName = $practice->name ?? '';
        $this->practiceAddress = $practice->address ?? $this->formatBillingAddressLine($billingAddress);
        $this->specialty = $practice->specialty ?? 'General Practice';
        $this->billableProviders = $practice->billable_providers_count ?? 1;

        unset(
            $this->batchOrders, $this->completedMilestone, $this->practice, $this->selectedPackage, $this->userOrders,
            $this->appliedDiscountCode, $this->discountAmount, $this->discountedTotal,
        );
    }

    /**
     * Mirrors pay() (same validation, guest-account bootstrap, and activity/payment logging
     * conventions) except no charge happens today — the card is tokenized via MTBC's Empower
     * Payment API and stored for later, and the created Order is $0/Trialing rather than
     * Paid/charged. Card fields arrive as method arguments for the same reason as pay(): never
     * bound to a Livewire property, never logged, never persisted beyond the token MTBC returns.
     */
    public function payFreeTrial(string $cardName = '', string $cardNumber = '', string $cardExpiry = '', string $cardCvc = '', bool $termsAccepted = false): void
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber ?? '');

        $this->validateBillingAndCardFields($cardName, $cardNumber, $cardExpiry, $cardCvc);

        try {
            Validator::make(compact('termsAccepted'), ['termsAccepted' => 'accepted'], $this->messages())->validate();
        } catch (ValidationException $e) {
            $this->cardErrors = array_merge($this->cardErrors, $e->validator->errors()->messages());

            throw $e;
        }

        $package = $this->selectedPackageId ? Package::find($this->selectedPackageId) : null;

        if (! $package) {
            $this->addError('selectedPackageId', 'Please select a package.');

            return;
        }

        if ($package->isCustomQuote()) {
            $this->redirect(route('contact', ['package' => $package->slug]), navigate: true);

            return;
        }

        $cycle = $this->effectiveBillingCycle(collect([$package]));

        $discountCode = $this->appliedDiscountCodeId ? DiscountCode::find($this->appliedDiscountCodeId) : null;

        if (! $discountCode || $discountCode->type !== DiscountType::FreeTrial || ! $discountCode->isCurrentlyValid()) {
            $this->appliedDiscountCodeId = null;
            $this->addError('discountCodeInput', 'This discount code is no longer valid. Please remove it and try again.');

            return;
        }

        [$expMonth, $expYear] = explode('/', $cardExpiry);
        $expMonth = (int) $expMonth;
        $expYear = (int) ('20'.$expYear);

        try {
            $tokenizeResult = app(EmpowerPaymentApiClient::class)->tokenize($cardNumber, $cardCvc);
        } catch (EmpowerPaymentApiException $e) {
            report($e);

            PaymentLog::record(
                success: false,
                amount: 0,
                user: auth()->user(),
                guestEmail: auth()->guest() ? $this->accountEmail : null,
                package: $package,
                message: 'Free trial signup failed to tokenize the card.',
            );

            $this->addError('cardNumber', 'We could not save your card. Please check your details and try again.');
            $this->addError('payment', "Payment didn't go through. You haven't been charged.");

            return;
        }

        // Tokenize succeeded — only now do we create an account or any orders, same as pay().
        if (auth()->guest()) {
            $generatedPassword = Str::password(16);

            $user = User::create([
                'name' => $this->accountName,
                'email' => $this->accountEmail,
                'password' => $generatedPassword,
                'role' => UserRole::Client,
            ]);

            Practice::create([
                'user_id' => $user->id,
                'name' => '',
            ]);

            try {
                Mail::to($user->email)->queue(new WelcomeCredentialsMail($user, $generatedPassword));
            } catch (\Throwable $e) {
                report($e);
            }

            Auth::login($user);
            $this->newAccountEmail = $user->email;
            $this->dispatch('user-logged-in');
        }

        // Same as pay(): the provider count is confirmed at checkout and drives the price the
        // trial converts to later, via TrialBillingService::convertTrialToPaid()'s frozen
        // original_price.
        $providers = max(1, $this->billableProviders);
        auth()->user()->practice?->update(['billable_providers_count' => $providers]);

        $billingAddress = [
            'name' => $cardName,
            'address1' => $this->billingAddress1,
            'city' => $this->billingCity,
            'state' => $this->billingState,
            'zip' => $this->billingZip,
        ];

        $perProviderPrice = $package->priceForCycle($cycle) ?? 0.0;

        $order = Order::create([
            'user_id' => auth()->id(),
            'package_id' => $package->id,
            'checkout_batch_id' => (string) Str::ulid(),
            'status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Trialing,
            'billing_address' => $billingAddress,
            'billing_cycle' => $cycle,
            'amount_paid' => 0,
            'original_price' => $perProviderPrice * $providers,
            'discount_amount' => $perProviderPrice * $providers,
            'discount_code_id' => $discountCode->id,
            'discount_code' => $discountCode->code,
            'paid_at' => now(),
            'terms_accepted_at' => now(),
            'terms_accepted_ip' => request()->ip(),
            'trial_ends_at' => now()->addDays($discountCode->trial_days),
            'clover_card_token' => $tokenizeResult['token'],
            'card_expiry_month' => $expMonth,
            'card_expiry_year' => $expYear,
            'card_last_four' => $tokenizeResult['lastFour'],
            'mtbc_reference_number' => $tokenizeResult['referenceNumber'],
        ]);

        ActivityLog::record(
            'trial.started',
            "Free trial started for {$package->name}, ending {$order->trial_ends_at->format('M j, Y')}.",
            user: auth()->user(),
            order: $order,
        );

        ActivityLog::record(
            'order.terms_accepted',
            "Accepted the Terms & Conditions and the CareCloud MSA for {$package->name}.",
            user: auth()->user(),
            order: $order,
        );

        PaymentLog::record(
            success: true,
            amount: 0,
            user: auth()->user(),
            package: $package,
            order: $order,
            message: 'Free trial started — card tokenized via Empower Payment API, no charge.',
        );

        try {
            Mail::to($order->user->email)->queue(new ClientTrialStartedMail($order));
        } catch (\Throwable $e) {
            report($e);
        }

        $discountCode->increment('used_count');
        $this->appliedDiscountCodeId = null;

        $this->orderIds = [$order->id];
        $this->dashboardOrderId = $order->id;
        $this->justPaid = true;

        $practice = auth()->user()->practice;
        $this->practiceName = $practice->name ?? '';
        $this->practiceAddress = $practice->address ?? $this->formatBillingAddressLine($billingAddress);
        $this->specialty = $practice->specialty ?? 'General Practice';
        $this->billableProviders = $practice->billable_providers_count ?? 1;

        unset(
            $this->batchOrders, $this->completedMilestone, $this->practice, $this->selectedPackage, $this->userOrders,
            $this->appliedDiscountCode, $this->discountAmount, $this->discountedTotal, $this->isFreeTrialCheckout,
        );
    }

    /**
     * Called from the dashboard's "Proceed with Payment" action on an active trial — the client's
     * first real charge, converting the order from Trialing to Paid.
     */
    public function convertTrialToPaid(int $orderId): void
    {
        $order = Order::where('user_id', auth()->id())
            ->where('payment_status', PaymentStatus::Trialing)
            ->findOrFail($orderId);

        $result = app(TrialBillingService::class)->convertTrialToPaid($order);

        if (! $result->success) {
            $this->addError('payment', $result->declineMessage ?? 'Your card was declined. Please update your payment details and try again.');

            return;
        }

        unset($this->userOrders, $this->batchOrders, $this->currentOrder);
    }

    /** Called from the dashboard to cancel an active trial or a converted paid subscription. */
    public function cancelSubscription(int $orderId): void
    {
        $order = Order::where('user_id', auth()->id())->findOrFail($orderId);

        app(TrialBillingService::class)->cancel($order, 'client_requested');

        unset($this->userOrders, $this->batchOrders, $this->currentOrder);
    }

    /**
     * Re-tokenizes a fresh card for an existing trial/subscription order, so a client whose stored
     * card is expired or was declined has a self-service way to fix it before cancellation.
     */
    public function updateTrialCard(int $orderId, string $cardNumber = '', string $cardExpiry = '', string $cardCvc = ''): void
    {
        $order = Order::where('user_id', auth()->id())->findOrFail($orderId);

        $cardNumber = preg_replace('/\D/', '', $cardNumber ?? '');

        try {
            Validator::make(compact('cardNumber', 'cardExpiry', 'cardCvc'), [
                'cardNumber' => 'required|digits:16',
                'cardExpiry' => $this->cardRules()['cardExpiry'],
                'cardCvc' => 'required|digits_between:3,4',
            ])->validate();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        [$expMonth, $expYear] = explode('/', $cardExpiry);

        try {
            $tokenizeResult = app(EmpowerPaymentApiClient::class)->tokenize($cardNumber, $cardCvc);
        } catch (EmpowerPaymentApiException $e) {
            report($e);
            $this->addError('payment', 'We could not save your card. Please check your details and try again.');

            return;
        }

        $order->update([
            'clover_card_token' => $tokenizeResult['token'],
            'card_expiry_month' => (int) $expMonth,
            'card_expiry_year' => (int) ('20'.$expYear),
            'card_last_four' => $tokenizeResult['lastFour'],
            'mtbc_reference_number' => $tokenizeResult['referenceNumber'],
        ]);

        ActivityLog::record('order.card_updated', "Payment method updated for {$order->package->name}.", user: auth()->user(), order: $order);

        unset($this->userOrders, $this->batchOrders, $this->currentOrder);
    }

    /** @return array<string, mixed> */
    private function profileRules(): array
    {
        $isLocked = (bool) auth()->user()->practice?->is_profile_locked;

        return [
            'practiceName' => 'required|string|max:150',
            'logoFile' => $isLocked ? 'nullable|file|mimes:png,jpg,jpeg|max:2048' : 'required|file|mimes:png,jpg,jpeg|max:2048',
            'practiceAddress' => 'required|string|max:255',
            'specialty' => 'required|string|max:100',
            'billableProviders' => 'required|integer|min:1|max:9999',
        ];
    }

    private function persistProfile(): Practice
    {
        $practice = auth()->user()->practice ?? Practice::create([
            'user_id' => auth()->id(),
            'name' => $this->practiceName,
        ]);

        $logoPath = $practice->is_profile_locked
            ? $practice->logo_path
            : ($this->logoFile ? $this->logoFile->store('logos', 'public') : $practice->logo_path);

        $practice->update([
            'name' => $practice->is_profile_locked ? $practice->name : $this->practiceName,
            'logo_path' => $logoPath,
            'address' => $this->practiceAddress ?: null,
            'specialty' => $this->specialty ?: null,
            'billable_providers_count' => $this->billableProviders,
            'is_profile_locked' => true,
            'locked_at' => $practice->locked_at ?? now(),
        ]);

        $this->logoFile = null;
        unset($this->practice, $this->completedMilestone);

        return $practice;
    }

    /** Only reachable via editProfile() — the fresh intake wizard's own basics screen has its
     *  own save method and never sets $editingProfile. */
    public function saveProfile(): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless($this->editingProfile, 403);

        $this->validate($this->profileRules());

        $practice = $this->persistProfile();

        $this->editingProfile = false;
        ActivityLog::record(
            'practice.updated',
            'Practice details updated from the dashboard.',
            user: auth()->user(),
            order: $this->currentOrder,
            subject: $practice,
        );
        $this->step = 5;
    }

    /**
     * Step 3's "Submit for Review" — certifies and finalizes the practice intake wizard's
     * already-persisted documents/basics/team/answers (all saved as-you-go by
     * <livewire:portal.practice-intake-wizard>). Mirrors every order in the batch off the
     * primary submission, same propagation pattern the old flat-questionnaire flow used.
     */
    public function finalizeIntake(): void
    {
        abort_unless(auth()->check(), 403);

        $this->resetErrorBag();

        if ($this->batchOrders->contains(fn ($o) => $o->blockedFromAiGeneration())) {
            $this->addError('payment', 'Your trial has ended. Please subscribe to continue using AI document generation.');

            return;
        }

        $this->validate([
            'certifiedByName' => 'required|string|max:150',
            'certifiedByTitle' => 'required|string|max:150',
            'certifiedSignature' => 'required|string|max:150',
            'certifyChecked' => 'accepted',
        ]);

        $orders = $this->batchOrders;

        if ($orders->isEmpty()) {
            $this->addError('certifiedSignature', 'No active order found for this submission.');

            return;
        }

        $primaryOrder = $orders->firstWhere('id', min($this->orderIds)) ?? $orders->first();
        $primarySubmission = $primaryOrder->intakeSubmission;

        if (! $primarySubmission) {
            $this->addError('certifiedSignature', 'No intake found for this submission.');

            return;
        }

        if ($primarySubmission->wizard_screen !== 'done') {
            $this->addError('certifiedSignature', 'Please finish the intake questionnaire before submitting for review.');

            return;
        }

        $certifiedAt = now();

        $primarySubmission->update([
            'status' => IntakeSubmissionStatus::Submitted,
            'reviewer_notes' => null,
            'submitted_at' => $certifiedAt,
            'certified_by_name' => $this->certifiedByName,
            'certified_by_title' => $this->certifiedByTitle,
            'certified_signature' => $this->certifiedSignature,
            'certified_at' => $certifiedAt,
        ]);

        Order::where('id', $primaryOrder->id)->update(['status' => OrderStatus::IntakeSubmitted]);

        ActivityLog::record(
            'submission.submitted',
            "Intake form submitted for order #{$primaryOrder->id}.",
            user: auth()->user(),
            order: $primaryOrder,
            subject: $primarySubmission,
        );

        // The wizard's document uploads sit as reference-only (NotApplicable) while the client
        // is still filling out the intake, so re-uploading or removing one mid-draft never burns
        // an OpenAI call. Now that the intake is actually being submitted, flip only the ones
        // still at that default to Pending (an upload some other tool already ran through AI —
        // e.g. admin test data — is left alone rather than re-queued) — the sibling-copy loop
        // below carries this status into each other order's own upload row — and dispatch the AI
        // compliance review once, after every submission/upload row exists.
        $reviewableUploadIds = $primarySubmission->intakeUploads()
            ->where('upload_type', IntakeUploadType::ClientDocumentForReview)
            ->where('ai_extraction_status', AiExtractionStatus::NotApplicable)
            ->pluck('id');

        IntakeUpload::whereIn('id', $reviewableUploadIds)->update(['ai_extraction_status' => AiExtractionStatus::Pending]);
        $primarySubmission->unsetRelation('intakeUploads');

        foreach ($orders as $order) {
            if ($order->id === $primaryOrder->id) {
                continue;
            }

            $siblingSubmission = IntakeSubmission::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'status' => IntakeSubmissionStatus::Submitted,
                    'reviewer_notes' => null,
                    'submitted_at' => $certifiedAt,
                    'certified_by_name' => $this->certifiedByName,
                    'certified_by_title' => $this->certifiedByTitle,
                    'certified_signature' => $this->certifiedSignature,
                    'certified_at' => $certifiedAt,
                ]
            );

            foreach ($primarySubmission->intakeUploads as $upload) {
                IntakeUpload::updateOrCreate(
                    ['intake_submission_id' => $siblingSubmission->id, 'upload_type' => $upload->upload_type],
                    [
                        'original_filename' => $upload->original_filename,
                        'storage_path' => $upload->storage_path,
                        'mime_type' => $upload->mime_type,
                        'file_size' => $upload->file_size,
                        'ai_extraction_status' => $upload->ai_extraction_status,
                    ]
                );
            }

            foreach ($primarySubmission->intakeAnswers as $answer) {
                IntakeAnswer::updateOrCreate(
                    ['intake_submission_id' => $siblingSubmission->id, 'intake_question_id' => $answer->intake_question_id],
                    [
                        'response' => $answer->response,
                        'has_documented_process' => $answer->has_documented_process,
                        'answered_at' => $answer->answered_at,
                    ]
                );
            }

            Order::where('id', $order->id)->update(['status' => OrderStatus::IntakeSubmitted]);

            ActivityLog::record(
                'submission.submitted',
                "Intake form submitted for order #{$order->id}.",
                user: auth()->user(),
                order: $order,
                subject: $siblingSubmission,
            );
        }

        // Dispatched once per upload, after every sibling order's own copy exists — the job
        // itself finds and completes those sibling rows too (matched by storage_path), so this
        // never triggers more than one AI review call per distinct uploaded file.
        IntakeUpload::whereIn('id', $reviewableUploadIds)->get()
            ->each(fn (IntakeUpload $upload) => ProcessIntakeUpload::dispatch($upload));

        // Professional/Advanced go straight into review (and AI document generation) instead of
        // waiting for an admin to click "Mark as Under Review"; Essential stays Submitted since
        // there's nothing to generate for it.
        $reviewStarter = app(IntakeReviewStarter::class);

        foreach ($orders as $order) {
            if ($order->package?->includesWorkflowQuestionnaire()) {
                $reviewStarter->start(IntakeSubmission::where('order_id', $order->id)->first());
            }
        }

        // Intake submission is what unlocks the LMS: enrol the client in the training courses (plus
        // the course for the practice's state) once, if any package in this batch includes access.
        if ($orders->contains(fn (Order $order) => $order->package?->includesLmsAccess())) {
            $practiceAddress = auth()->user()->practice?->address ?: ($primaryOrder->billing_address['state'] ?? null);
            ProvisionLmsAccount::dispatch(auth()->user(), $practiceAddress);
        }

        $primarySubmission->refresh();
        $primarySubmission->setRelation('order', $primaryOrder);

        $admins = User::where('role', UserRole::Admin)->get();

        $admins->each(function (User $admin) use ($primarySubmission) {
            try {
                Mail::to($admin->email)->send(new AdminIntakeSubmittedMail($primarySubmission));
            } catch (\Throwable $e) {
                report($e);
            }
        });

        try {
            Notification::send($admins, new IntakeSubmittedNotification($primarySubmission));
        } catch (\Throwable $e) {
            report($e);
        }

        // Lands straight on step 4 — the "sending/uploading/queuing" transition rendered there
        // (gated on $justSubmitted) is purely cosmetic and clears itself once its own timer
        // finishes, the same pattern as the wizard's "saving" screen.
        $this->justSubmitted = true;
        $this->step = 4;

        unset($this->intakeSubmission, $this->currentOrder, $this->completedMilestone, $this->batchOrders, $this->rejectedSubmission, $this->primarySubmission);
    }

    /** A rejected order's "Re-upload" action — reopen its submission as a Draft so the client
     *  re-enters the Practice Intake wizard (Step 2) exactly where they left off, fixes
     *  whatever the reviewer flagged, then re-certifies through Step 3 again. */
    public function reuploadForOrder(int $orderId): void
    {
        $submission = $this->batchOrders->firstWhere('id', $orderId)?->intakeSubmission;

        $submission?->update(['status' => IntakeSubmissionStatus::Draft]);

        unset($this->completedMilestone, $this->batchOrders, $this->primarySubmission);
        $this->goToStep(2);
    }

    public function checkApproval(): void
    {
        $orders = $this->batchOrders;

        if ($orders->isNotEmpty() && $orders->every(fn ($o) => $o->intakeSubmission?->status === IntakeSubmissionStatus::Approved)) {
            $this->dashboardOrderId ??= $orders->max('id');
            unset($this->completedMilestone, $this->batchOrders);
            $this->step = 5;
        }
    }

    /** Clears the one-shot post-submit loading animation once its client-side timer finishes. */
    public function clearJustSubmitted(): void
    {
        $this->justSubmitted = false;
    }

    /** Toggles one "What's next" dashboard checklist item — clicking its circle marks it done,
     *  clicking again un-marks it, matching the checklist's own click-to-toggle behavior. */
    public function toggleNextStep(string $key): void
    {
        $practice = $this->practice;

        if (! $practice) {
            return;
        }

        $steps = $practice->dashboard_next_steps ?? [];
        $steps = in_array($key, $steps, true) ? array_values(array_diff($steps, [$key])) : [...$steps, $key];
        $practice->update(['dashboard_next_steps' => $steps]);
        unset($this->practice);
    }

    /** Marks one "What's next" item done — used by its action button (download/copy/etc.), which
     *  only ever marks done, never un-marks (that's toggleNextStep()'s job via the checkbox). */
    public function markNextStepDone(string $key): void
    {
        $practice = $this->practice;
        $steps = $practice?->dashboard_next_steps ?? [];

        if ($practice && ! in_array($key, $steps, true)) {
            $practice->update(['dashboard_next_steps' => [...$steps, $key]]);
            unset($this->practice);
        }
    }

    public function replyToReviewerQuestion(int $questionId): void
    {
        $reply = trim(strip_tags($this->reviewerReplyText[$questionId] ?? ''));

        if ($reply === '') {
            $this->addError("reviewerReplyText.{$questionId}", 'Type a reply first.');

            return;
        }

        if (mb_strlen($reply) > 2000) {
            $this->addError("reviewerReplyText.{$questionId}", 'Please keep your reply under 2000 characters.');

            return;
        }

        $question = $this->batchOrders->pluck('intakeSubmission')->filter()
            ->flatMap(fn (IntakeSubmission $submission) => $submission->reviewerQuestions)
            ->firstWhere('id', $questionId);

        abort_unless($question && ! $question->isAnswered(), 404);

        $question->update(['reply' => $reply, 'replied_at' => now()]);

        $submission = $question->intakeSubmission;

        ActivityLog::record(
            'submission.reviewer_question_replied',
            "Client replied to the reviewer's question on order #{$submission->order_id}.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        try {
            Notification::send(User::where('role', UserRole::Admin)->get(), new ReviewerQuestionReplyNotification($submission));
        } catch (\Throwable $e) {
            report($e);
        }

        unset($this->reviewerReplyText[$questionId], $this->batchOrders, $this->primarySubmission);

        $this->dispatch('toast', message: 'Reply sent.', type: 'success');
    }

    public function refreshOshaLocations(): void
    {
        unset($this->oshaLocations, $this->practice);
    }
};
?>

@php
$steps = [
1 => 'Payment',
2 => 'Practice Intake',
3 => 'Upload & Confirm',
4 => 'Review',
5 => 'Dashboard',
];
$milestone = $this->completedMilestone;
$progressPct = ($milestone / 4) * 100;
@endphp

<div class="space-y-4 xl:space-y-0 xl:grid xl:grid-cols-[1fr_320px] xl:gap-6 xl:items-start">

    {{-- ── Portal preview hero ── --}}
    @php
    $heroPackages = $milestone >= 1 ? $this->batchOrders->pluck('package')->filter()->values() :
    collect([$this->selectedPackage])->filter()->values();
    // Post-payment, sum each order's own frozen original_price rather than re-reading the
    // package's live price — otherwise this display would silently drift if an admin edits a
    // package's price after checkout, and it wouldn't reflect the cycle actually billed.
    $heroCycle = $milestone >= 1
    ? ($this->batchOrders->first()?->billing_cycle ?? BillingCycle::Annual)
    : $this->currentBillingCycle();
    $heroTotal = $milestone >= 1
    ? $this->batchOrders->sum('original_price')
    : $heroPackages->sum(fn ($p) => $p->priceForCycle($heroCycle) ?? 0.0) * max(1, $this->billableProviders);
    $heroProviders = $milestone >= 1 ? auth()->user()?->practice?->billable_providers_count : $this->billableProviders;
    @endphp
    {{-- On desktop widths (xl, ≥1280px) this becomes the right-hand column of a shared grid with
        the main content — same container, same margins, just a second track — and sticks in place
        as that (taller) main column scrolls. Below xl there isn't room for a second column, so it
        stays exactly as it's always been: a static banner at the top of the page. --}}
    <div class="rounded-[1.25rem] p-4 sm:p-4 xl:col-start-2 xl:row-start-1 xl:sticky xl:top-24"
        style="background: radial-gradient(circle at top right, rgba(118,200,192,0.2), transparent 32%), linear-gradient(145deg, #12304f 0%, #1c416a 100%);">
        <div class="flex flex-col lg:flex-row xl:flex-col lg:items-center xl:items-stretch gap-5">
            <div class="flex-1">
                <span
                    class="inline-flex items-center rounded-full px-3 py-1 text-[0.7rem] font-extrabold tracking-[0.08em] uppercase bg-accent/16 text-[#dff7f3] mb-2">Portal
                    preview</span>
                <h1 class="text-xl sm:text-2xl xl:text-lg font-bold text-white mb-1 xl:mb-2">
                    @if($heroPackages->isEmpty())
                    Choose a package
                    @elseif($heroPackages->count() === 1)
                    Selected package: {{ $heroPackages->first()->name }}
                    @else
                    {{ $heroPackages->count() }} packages selected
                    @endif
                </h1>
                <p class="text-white/60 text-sm xl:text-xs xl:mb-2">Payment, practice intake, review, and document
                    generation.</p>
                <p class="text-white text-sm xl:text-xs mt-1.5 xl:mt-0">Need help? <a
                        href="mailto:support@empowerhci.com"
                        class="font-bold text-white underline hover:text-[#dff7f3]">support@empowerhci.com</a></p>
            </div>
            @if($heroPackages->isNotEmpty())
            <div class="bg-white/92 rounded-[1.25rem] p-4 min-w-48 xl:min-w-0 xl:w-full">
                <div class="text-empower-muted text-xs uppercase tracking-wider font-semibold mb-1">Summary</div>
                <div class="text-xl font-extrabold text-navy mb-0.5">${{ number_format($heroTotal, $heroTotal ==
                    floor($heroTotal) ? 0 : 2) }}</div>
                <div class="text-empower-muted text-xs">/ {{ $heroCycle->period() }}</div>
                <div class="text-empower-muted text-xs mt-1">
                    {{ $heroPackages->pluck('name')->implode(' + ') }}
                </div>
                @if($heroProviders)
                <div class="text-empower-muted text-xs mt-1 pt-1 border-t border-[#eef2f6]">
                    {{ $heroProviders }} provider{{ $heroProviders === 1 ? '' : 's' }} &middot; ${{
                    number_format($heroTotal, $heroTotal == floor($heroTotal) ? 0 : 2) }} / {{ $heroCycle->period() }}
                </div>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- ── Main column (everything but the hero) — its own track in the xl grid above ── --}}
    <div class="xl:col-start-1 xl:row-start-1 min-w-0 space-y-4">

    {{-- ── Stepper ── --}}
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <div class="flex justify-between items-center mb-4">
            <p class="text-xs text-[#5d6e7f]">Complete each step to receive your compliance documents.</p>
            <span
                class="inline-flex items-center px-3 py-1 rounded-full bg-[#12304f]/[0.08] text-[#12304f] text-[0.72rem] font-extrabold tracking-wide uppercase">
                Step {{ $step }} of 5
            </span>
        </div>

        <div class="flex items-start gap-1.5 overflow-x-auto pb-1 -mb-1">
            @foreach ($steps as $n => $title)
            @php
            $isDone = $n <= $milestone; $isActive=$n===$step; $reachable=$this->canReach($n);
                @endphp
                <div class="flex flex-col items-center gap-1.5 flex-shrink-0 min-w-[4.5rem] {{ $reachable ? 'cursor-pointer' : 'cursor-not-allowed opacity-50' }}"
                    @if($reachable && !$isActive) wire:click="goToStep({{ $n }})" wire:target="goToStep({{ $n }})"
                    wire:loading.class="opacity-50" wire:target="goToStep({{ $n }})" @endif>
                    <div
                        class="w-9 h-9 rounded-full inline-flex items-center justify-center text-sm font-extrabold flex-shrink-0
                        {{ $isActive ? 'bg-[#12304f] text-white' : ($isDone ? 'bg-[#0b9ed0] text-white' : 'bg-[#edf2f7] text-[#5d6e7f]') }}">
                        @if($reachable && !$isActive)
                        <span wire:loading.remove wire:target="goToStep({{ $n }})">@if($isDone && !$isActive) ✓ @else {{
                            $n }} @endif</span>
                        <span wire:loading wire:target="goToStep({{ $n }})">
                            <x-spinner class="h-3.5 w-3.5" />
                        </span>
                        @else
                        @if($isDone && !$isActive) ✓ @else {{ $n }} @endif
                        @endif
                    </div>
                    <div class="text-[0.78rem] text-center leading-tight max-w-[6rem]
                        {{ $isActive ? 'font-bold text-[#12304f]' : 'text-[#5d6e7f]' }}">
                        {{ $title }}
                    </div>
                </div>
                @if(!$loop->last)
                <div class="flex-1 h-0.5 mt-[1.125rem] min-w-4 {{ $n < $milestone ? 'bg-[#0b9ed0]' : 'bg-[#dfe7ef]' }}">
                </div>
                @endif
                @endforeach
        </div>

        <div class="mt-4 h-1.5 bg-[#dbe4ee] rounded-full overflow-hidden">
            <div class="h-full bg-[#12304f] rounded-full transition-all duration-500"
                style="width: {{ $progressPct }}%"></div>
        </div>
    </div>

    {{-- ── Step 1: Payment ── --}}
    @if($step === 1)
    <div wire:key="portal-step-1">
    <div class="space-y-3">
        @if($this->publicLaunchGateActive && $milestone < 1)
        <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
            <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1</p>
            <h2 class="text-lg font-semibold text-navy mb-1">We're not quite open to the public yet</h2>
            <p class="text-sm text-empower-muted mb-4">
                @if($this->selectedPackage)
                {{ $this->selectedPackage->name }} isn't available for purchase yet.
                @else
                Purchasing isn't available yet.
                @endif
                Leave your email and we'll let you know the moment it is.
            </p>
            @if($signedUpForUpdates)
            <p class="text-sm font-semibold text-[#117a51]">&#10003; Thanks — we'll email you as soon as this package is available.</p>
            @else
            <form wire:submit="signUpForUpdates" class="flex flex-col sm:flex-row gap-2.5 max-w-md">
                <input wire:model="updatesEmail" type="email" required placeholder="you@practice.com"
                    class="flex-1 rounded-xl border {{ $errors->has('updatesEmail') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                <button type="submit" wire:loading.attr="disabled" wire:target="signUpForUpdates"
                    class="inline-flex items-center justify-center gap-1.5 rounded bg-accent px-5 py-2.5 text-sm font-bold text-navy-dark hover:bg-accent-dark transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="signUpForUpdates">Sign up for updates</span>
                    <span wire:loading.inline-flex wire:target="signUpForUpdates" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving&hellip;</span>
                </button>
            </form>
            @error('updatesEmail') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            @endif
        </div>
        @else
        @if($milestone >= 1)
        @php
        $firstBatchOrder = $this->batchOrders->first();
        $isTrialBatch = $firstBatchOrder?->payment_status === PaymentStatus::Trialing;
        $totalPaidAmount = (float) $this->batchOrders->sum('amount_paid');
        $cardEnding = $firstBatchOrder?->card_last_four;
        $paidProviders = auth()->user()?->practice?->billable_providers_count;
        @endphp
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 items-stretch">
            <div
                class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
                <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1</p>
                <h2 class="text-lg font-semibold text-navy mb-1">Selected Package</h2>
                <p class="text-sm text-empower-muted">
                    Complete payment first to unlock your practice intake form. Your documents are generated
                    automatically
                    once intake is submitted and reviewed.
                </p>

                @foreach($this->batchOrders as $order)
                @php
                $orderIsTrial = $order->payment_status === PaymentStatus::Trialing;
                $orderPrice = (float) $order->original_price;
                $orderPaid = (float) $order->amount_paid;
                @endphp
                <div class="flex items-center justify-between gap-3 py-2.5 border-b border-[#eef2f6] mb-2">
                    <div>
                        <p class="text-sm font-semibold text-[#173045]">{{ $order->package?->name }}</p>
                        <p class="text-xs text-empower-muted">${{ number_format($orderPrice, $orderPrice ==
                            floor($orderPrice) ? 0 : 2) }} /
                            {{ $order->billing_cycle?->period() }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span
                            class="inline-flex items-center px-2.5 py-1 rounded-full bg-[#d7f3ea] text-[#117a51] text-[0.68rem] font-extrabold tracking-wide uppercase">
                            {{ $orderIsTrial ? 'Trial' : 'Paid' }}
                        </span>
                        <a href="{{ route('orders.receipt', $order) }}" target="_blank"
                            class="text-xs font-semibold text-[#1a7aad] hover:underline">View Receipt</a>
                    </div>
                </div>

                <div class="py-2.5 border-b border-[#eef2f6] mb-2">
                    @if($order->discount_code)
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-[#0f7a4f]">Discount ({{ $order->discount_code
                            }})</span>
                        <span class="text-sm font-semibold text-[#0f7a4f]">-${{ number_format((float)
                            $order->discount_amount, 2) }}</span>
                    </div>
                    @else
                    <p class="text-sm text-empower-muted">No discount applied</p>
                    @endif
                </div>

                <div
                    class="flex items-center justify-between pt-2 {{ !$loop->last ? 'border-b border-[#eef2f6] mb-2 pb-2.5' : '' }}">
                    <span class="text-sm font-semibold text-[#173045]">{{ $orderIsTrial ? 'Due Today' : 'Paid'
                        }}</span>
                    <span class="text-lg font-extrabold text-navy">${{ number_format($orderPaid, $orderPaid ==
                        floor($orderPaid) ? 0 : 2) }}</span>
                </div>
                @endforeach

                @if($newAccountEmail)
                <div class="mt-1 pt-3 border-t border-[#eef2f6]">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1.1</p>
                    <h3 class="text-sm font-semibold text-navy mb-1">Account Information</h3>
                    <p class="text-xs text-empower-muted">Account created for {{ auth()->user()->name }}
                        ({{ $newAccountEmail }}). A login password would be emailed there.</p>
                </div>
                @endif

                <div class="flex items-center justify-between gap-3 pt-3 mt-3 border-t border-[#eef2f6]">
                    <div>
                        <p class="text-sm font-semibold text-[#31465b]">How many billable providers do you have?</p>
                        <p class="text-xs text-empower-muted mt-0.5 max-w-sm">Physicians and non-physician
                            practitioners billing under your group NPI.</p>
                    </div>
                    <p class="text-sm font-semibold text-navy flex-shrink-0">{{ $paidProviders }} provider{{
                        $paidProviders === 1 ? '' : 's' }}</p>
                </div>
            </div>

            <div
                class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
                <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1.2</p>
                <h3 class="text-lg font-semibold text-navy mb-3">Payment Details</h3>
                <div
                    @if($justPaid) x-data x-init="$el.animate([{ backgroundColor: '#d7f3e3' }, { backgroundColor: '#eef8f3' }], { duration: 1200, easing: 'ease-out' })" @endif
                    class="flex items-center gap-3 rounded-xl bg-[#eef8f3] border border-[#bfe3d2] px-3.5 py-2.5">
                    <span class="text-[#117a51]">&#10003;</span>
                    <p class="text-sm font-semibold text-[#0f7a4f]">
                        @if($isTrialBatch)
                        Your free trial has started{{ $firstBatchOrder?->paid_at ? ' on
                        '.$firstBatchOrder->paid_at->format('M j, Y') : '' }}{{ $cardEnding ? " (card ending
                        {$cardEnding})" : '' }}.
                        @else
                        Payment of ${{ number_format($totalPaidAmount, $totalPaidAmount == floor($totalPaidAmount) ? 0
                        : 2)
                        }} received{{ $firstBatchOrder?->paid_at ? ' on '.$firstBatchOrder->paid_at->format('M j, Y') :
                        ''
                        }}{{ $cardEnding ? " (card ending {$cardEnding})" : '' }}.
                        @endif
                    </p>
                </div>
            </div>
        </div>
        @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 items-stretch">
            <div
                class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
                <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1</p>
                <h2 class="text-lg font-semibold text-navy mb-1">Selected Package</h2>
                <p class="text-xs text-empower-muted">
                    Complete payment first to unlock your practice intake form. Your documents are generated
                    automatically
                    once intake is submitted and reviewed.
                </p>
                @if(! $this->selectedPackage)
                <p class="text-sm text-empower-muted italic mb-2">No package selected.</p>
                <a href="{{ route('home') }}#pricing" class="text-xs font-bold text-[#1a7aad] hover:underline">Browse
                    packages &rarr;</a>
                @else
                <div class="flex items-center justify-between gap-3 py-2.5 border-b border-[#eef2f6] mb-2">
                    <div>
                        <p class="text-sm font-semibold text-[#173045]">{{ $this->selectedPackage->name }}</p>
                        @php $displayPrice = $this->selectedPackage->priceForCycle($this->currentBillingCycle()) ?? 0.0;
                        @endphp
                        <p class="text-xs text-empower-muted">${{ number_format($displayPrice, $displayPrice ==
                            floor($displayPrice) ? 0 : 2) }} per provider /
                            {{ $this->currentBillingCycle()->period() }}</p>
                    </div>
                    <a href="{{ route('home') }}#pricing"
                        class="text-xs font-semibold text-[#1a7aad] hover:underline">Change package</a>
                </div>

                <div class="py-2.5 border-b border-[#eef2f6] mb-2">
                    @if(! $this->appliedDiscountCode)
                    <div class="flex gap-2">
                        <input wire:model="discountCodeInput" type="text" placeholder="Discount code"
                            class="flex-1 min-w-0 rounded-lg border border-empower-border bg-[#f8fbfd] px-3 py-2 text-sm uppercase text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        <button type="button" wire:click="applyDiscountCode" wire:target="applyDiscountCode"
                            wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                            wire:target="applyDiscountCode"
                            class="rounded-lg border border-empower-border px-3.5 py-2 text-xs font-bold text-[#173045] hover:bg-page transition-colors">
                            <span wire:loading.remove wire:target="applyDiscountCode">Apply</span>
                            <span wire:loading wire:target="applyDiscountCode">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                        </button>
                    </div>
                    @error('discountCodeInput') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @elseif($this->isFreeTrialCheckout)
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-[#0f7a4f]">Free Trial ({{
                            $this->appliedDiscountCode->code
                            }}) — {{ $this->appliedDiscountCode->trial_days }} days</span>
                        <button type="button" wire:click="removeDiscountCode"
                            class="text-xs text-empower-muted hover:underline">Remove</button>
                    </div>
                    @else
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-[#0f7a4f]">Discount ({{ $this->appliedDiscountCode->code
                            }})</span>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-[#0f7a4f]">-${{ number_format($this->discountAmount,
                                2)
                                }}</span>
                            <button type="button" wire:click="removeDiscountCode"
                                class="text-xs text-empower-muted hover:underline">Remove</button>
                        </div>
                    </div>
                    @endif
                </div>

                @php $perProviderDisplay = ($this->selectedPackage->priceForCycle($this->currentBillingCycle()) ?? 0.0) * max(1, $this->billableProviders); @endphp
                @if($this->isFreeTrialCheckout)
                <div class="flex items-center justify-between pt-2 border-t border-[#eef2f6]">
                    <span class="text-sm font-semibold text-[#173045]">Due Today</span>
                    <span class="text-lg font-extrabold text-navy">$0.00</span>
                </div>
                <p class="text-xs text-empower-muted mt-1">Then ${{ number_format($perProviderDisplay, 2) }}/{{
                    $this->currentBillingCycle()->period() }} once your free trial ends, unless you cancel first.</p>
                @else
                <div class="flex items-center justify-between pt-2 border-t border-[#eef2f6]">
                    <span class="text-sm font-semibold text-[#173045]">Total</span>
                    <span class="text-lg font-extrabold text-navy">${{ number_format($this->discountedTotal, 2)
                        }}</span>
                </div>
                @if($this->billableProviders > 1)
                <p class="text-xs text-empower-muted mt-1 text-right">{{ $this->billableProviders }} &times; ${{
                    number_format($displayPrice, 2) }}/{{ $this->currentBillingCycle()->period() }}</p>
                @endif
                @endif
                @endif

                @auth
                @else
                <div class="mt-3 pt-3 border-t border-[#eef2f6]">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1 mt-3">Step 1.1
                    </p>
                    <h3 class="text-sm font-semibold text-navy mb-1">Account Information</h3>
                    <p class="text-xs text-empower-muted mb-3">Create the account that will manage this practice's
                        Empower portal.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Your name <span
                                    class="text-red-500">*</span></label>
                            <input wire:model.live="accountName" type="text" placeholder="Jane Provider" required
                                maxlength="100" pattern="[\p{L}\s.'\-]+"
                                title="Letters, spaces, periods, apostrophes and hyphens only"
                                class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                            @error('accountName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Email address <span
                                    class="text-red-500">*</span></label>
                            <input wire:model.live="accountEmail" type="email" placeholder="jane@practice.com" required
                                maxlength="150" pattern="[^\s@]+@[^\s@]+\.[^\s@]+"
                                title="Please include a domain extension, e.g. name@example.com"
                                class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                            @error('accountEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-xs text-empower-muted mt-2">We'll email you a secure, auto-generated password to log
                        in with.
                    </p>
                </div>
                @endauth

                <div class="flex items-center justify-between gap-3 py-2.5 pt-3 border-t border-[#eef2f6]">
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b]">How many billable providers do you
                            have? <span class="text-red-500">*</span></label>
                        <p class="text-xs text-empower-muted mt-0.5 max-w-sm">Physicians and non-physician
                            practitioners billing under your group NPI.</p>
                        @error('billableProviders') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <button type="button" wire:click="decrementProviders" aria-label="Fewer providers"
                            class="h-9 w-9 rounded-lg border border-empower-border text-navy font-bold hover:bg-page transition-colors">&minus;</button>
                        <input wire:model.live="billableProviders" type="number" min="1" max="9999"
                            aria-label="Billable providers"
                            class="w-16 text-center rounded-lg border border-empower-border bg-[#f8fbfd] px-2 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        <button type="button" wire:click="incrementProviders" aria-label="More providers"
                            class="h-9 w-9 rounded-lg border border-empower-border text-navy font-bold hover:bg-page transition-colors">&plus;</button>
                    </div>
                </div>
            </div>

            <div x-data="{
                    cardNameValid: false, cardNumberValid: false, cardExpiryError: '', cardCvcValid: false, showTerms: false, termsAccepted: false,
                }"
                class="flex flex-col bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
                <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1.2</p>
                <h3 class="text-lg font-semibold text-navy mb-1">Payment Details</h3>
                <p class="text-xs text-empower-muted mb-3">Your card is charged securely — these fields are never saved
                    or
                    logged by this form.</p>
                @error('payment') <p
                    class="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs font-semibold text-red-700">
                    {{
                    $message }}</p> @enderror
                @error('termsAccepted') <p x-show="!termsAccepted"
                    class="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs font-semibold text-red-700">
                    {{
                    $message }}</p> @enderror
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Name on card <span
                                class="text-red-500">*</span></label>
                        <input x-ref="cardName" type="text" placeholder="Jane Provider" maxlength="255"
                            x-on:input="cardNameValid = /^[\p{L}\s.'-]+$/u.test($el.value.trim())"
                            class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        @error('cardName') <p x-show="!cardNameValid" class="mt-1 text-xs text-red-600">{{ $message }}
                        </p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Card number <span
                                class="text-red-500">*</span></label>
                        <input x-ref="cardNumber" type="text" placeholder="4242424242424242" inputmode="numeric"
                            maxlength="16"
                            x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '').slice(0, 16); cardNumberValid = $el.value.length === 16"
                            class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        @error('cardNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Expiry <span
                                class="text-red-500">*</span></label>
                        <input x-ref="cardExpiry" type="text" placeholder="MM / YY" inputmode="numeric" maxlength="5"
                            x-on:input="
                            let digits = $el.value.replace(/[^0-9]/g, '').slice(0, 4);
                            let deleting = ($event.inputType || '').startsWith('delete');
                            $el.value = (digits.length >= 2 && !deleting) ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
                            let mm = parseInt(digits.slice(0, 2), 10);
                            let yyyy = 2000 + parseInt(digits.slice(2, 4), 10);
                            let now = new Date();
                            if (digits.length < 4) {
                                cardExpiryError = '';
                            } else if (mm < 1 || mm > 12) {
                                cardExpiryError = 'The card expiry month must be between 01 and 12.';
                            } else if (yyyy < now.getFullYear() || (yyyy === now.getFullYear() && mm < now.getMonth() + 1)) {
                                cardExpiryError = 'The card has expired.';
                            } else {
                                cardExpiryError = '';
                            }
                        " x-bind:class="cardExpiryError ? 'border-red-400' : 'border-empower-border'"
                            class="w-full rounded-xl border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        <p x-show="cardExpiryError" x-text="cardExpiryError" class="mt-1 text-xs text-red-600"></p>
                        @error('cardExpiry') <p x-show="!cardExpiryError" class="mt-1 text-xs text-red-600">{{ $message
                            }}
                        </p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">CVC <span
                                class="text-red-500">*</span></label>
                        <input x-ref="cardCvc" type="text" placeholder="123" inputmode="numeric" maxlength="4"
                            x-on:input="$el.value = $el.value.replace(/[^0-9]/g, ''); cardCvcValid = $el.value.length >= 3 && $el.value.length <= 4"
                            class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        @error('cardCvc') <p x-show="!cardCvcValid" class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3"
                    x-data="addressAutocomplete({ address1: 'billingAddress1', city: 'billingCity', state: 'billingState', zip: 'billingZip' })">
                    <div class="sm:col-span-2 relative">
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Billing address <span
                                class="text-red-500">*</span></label>
                        <input type="text" placeholder="7 Clyde Road" required maxlength="255" autocomplete="off"
                            role="combobox" aria-autocomplete="list" aria-expanded="open" data-ac-field="billingAddress1"
                            x-model="query" x-on:input="onAddressInput()"
                            x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                            x-on:keydown.enter.prevent="chooseHighlighted()" x-on:keydown.escape="close()"
                            x-on:blur="setTimeout(() => close(), 150)"
                            class="w-full rounded-xl border {{ $errors->has('billingAddress1') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        <ul x-show="open" x-cloak role="listbox"
                            class="absolute z-10 mt-1 w-full max-h-56 overflow-y-auto rounded-xl border border-empower-border bg-white shadow-lg py-1">
                            <template x-for="(suggestion, index) in suggestions" :key="suggestion.label">
                                <li role="option" x-text="suggestion.label" x-on:mousedown.prevent="select(suggestion)"
                                    :class="index === highlightedIndex ? 'bg-[#f0f7fb]' : ''"
                                    class="px-4 py-2 text-sm text-empower-text cursor-pointer hover:bg-[#f0f7fb]">
                                </li>
                            </template>
                        </ul>
                        @error('billingAddress1') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2 grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">City <span
                                    class="text-red-500">*</span></label>
                            <input wire:model.live="billingCity" type="text" placeholder="Somerset" required
                                maxlength="100" data-ac-field="billingCity"
                                class="w-full rounded-xl border {{ $errors->has('billingCity') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                            @error('billingCity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">State <span
                                    class="text-red-500">*</span></label>
                            <input wire:model.live="billingState" type="text" placeholder="NJ or New Jersey" required
                                maxlength="50" data-ac-field="billingState"
                                class="w-full rounded-xl border {{ $errors->has('billingState') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                            @error('billingState') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Zip <span
                                    class="text-red-500">*</span></label>
                            <input wire:model.live="billingZip" type="text" placeholder="08873" inputmode="numeric"
                                required maxlength="10" data-ac-field="billingZip" x-on:input="onZipInput($event.target.value)"
                                class="w-full rounded-xl border {{ $errors->has('billingZip') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                            @error('billingZip') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="mt-auto pt-5 flex justify-end">
                    <button
                        x-on:click="$wire.validatePayment($refs.cardName.value, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value).then((valid) => { if (valid) showTerms = true })"
                        class="inline-flex items-center gap-1 rounded bg-accent px-5 py-2 text-sm font-bold text-navy-dark hover:bg-accent-dark transition-colors"
                        wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                        wire:target="validatePayment">
                        <span wire:loading.remove wire:target="validatePayment">
                            @if($this->isFreeTrialCheckout)
                            Start Free Trial &rarr;
                            @else
                            Pay ${{ number_format($this->discountedTotal, 2) }}
                            &rarr;
                            @endif
                        </span>
                        <span wire:loading.inline-flex wire:target="validatePayment"
                            class="inline-flex items-center gap-1.5">
                            <x-spinner class="h-3.5 w-3.5" /> Checking…
                        </span>
                    </button>
                </div>

                <div x-show="showTerms" x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
                    <div class="w-full max-w-md bg-white rounded-[1.25rem] shadow-xl p-6"
                        x-on:click.outside="showTerms = false">
                        <h3 class="text-base font-semibold text-navy mb-2">Review &amp; Accept Terms &amp; Conditions
                        </h3>
                        <p class="text-sm text-empower-muted mb-4">Before we process your payment, please confirm you
                            agree
                            to our Terms &amp; Conditions and the CareCloud Master Services Agreement (MSA).</p>
                        <a href="{{ config('services.carecloud.msa_url') }}" target="_blank" rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-sm font-semibold text-[#1a7aad] hover:underline mb-4">
                            Read the CareCloud MSA &#8599;
                        </a>
                        <label class="flex items-start gap-2.5 mb-5 cursor-pointer">
                            <input type="checkbox" x-model="termsAccepted"
                                class="mt-0.5 h-4 w-4 rounded border-empower-border text-accent focus:ring-accent">
                            <span class="text-sm text-empower-text">I agree to the Terms &amp; Conditions.</span>
                        </label>
                        <div class="flex justify-end gap-3">
                            <button type="button" x-on:click="showTerms = false"
                                class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                                Cancel
                            </button>
                            @if($this->isFreeTrialCheckout)
                            <button type="button" wire:key="terms-confirm-payfreetrial"
                                x-on:click="
                                    if (!navigator.onLine) { $dispatch('toast', { message: 'You\'re offline. Reconnect to the internet, then try again.', type: 'error' }); return; }
                                    $wire.payFreeTrial($refs.cardName.value, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value, termsAccepted).finally(() => showTerms = false)
                                "
                                :disabled="!termsAccepted"
                                :class="!termsAccepted ? 'opacity-50 cursor-not-allowed' : 'hover:bg-accent-dark'"
                                class="inline-flex items-center gap-1 rounded bg-accent px-5 py-2 text-sm font-bold text-navy-dark transition-colors"
                                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                                wire:target="payFreeTrial">
                                <span wire:loading.remove wire:target="payFreeTrial">I Agree — Start Free Trial
                                    &rarr;</span>
                                <span wire:loading.inline-flex wire:target="payFreeTrial"
                                    class="inline-flex items-center gap-1.5">
                                    <x-spinner class="h-3.5 w-3.5" /> Processing…
                                </span>
                            </button>
                            @else
                            <button type="button" wire:key="terms-confirm-pay"
                                x-on:click="
                                    if (!navigator.onLine) { $dispatch('toast', { message: 'You\'re offline. Reconnect to the internet, then try again.', type: 'error' }); return; }
                                    $wire.pay($refs.cardName.value, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value, termsAccepted).finally(() => showTerms = false)
                                "
                                :disabled="!termsAccepted"
                                :class="!termsAccepted ? 'opacity-50 cursor-not-allowed' : 'hover:bg-accent-dark'"
                                class="inline-flex items-center gap-1 rounded bg-accent px-5 py-2 text-sm font-bold text-navy-dark transition-colors"
                                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                                wire:target="pay">
                                <span wire:loading.remove wire:target="pay">I Agree — Pay &rarr;</span>
                                <span wire:loading.inline-flex wire:target="pay"
                                    class="inline-flex items-center gap-1.5">
                                    <x-spinner class="h-3.5 w-3.5" /> Processing…
                                </span>
                            </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div class="flex justify-end">
            <button wire:click="goToStep(2)" wire:target="goToStep(2)" @disabled($milestone < 1)
                wire:loading.attr="disabled" wire:target="goToStep(2)"
                class="inline-flex items-center gap-1 rounded bg-accent px-5 py-2 text-sm font-bold text-navy-dark hover:bg-accent-dark transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <span wire:loading.remove wire:target="goToStep(2)">Continue to Intake Form &rarr;</span>
                <span wire:loading.inline-flex wire:target="goToStep(2)" class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Loading…
                </span>
            </button>
        </div>
        @endif
    </div>
    </div>
    @endif

    {{-- ── Step 2: Practice Intake ── --}}
    @if($step === 2)
    <div wire:key="portal-step-2">
    @if($editingProfile)
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <div
            class="flex items-center gap-2 rounded-xl bg-[#edf6ff] border border-[#bfdcf3] px-4 py-3 mb-4 text-sm text-[#12304f]">
            You're updating practice details for an already-paid plan. Documents you've already generated will be marked
            outdated until you regenerate them.
        </div>
        <h2 class="text-lg font-semibold text-[#12304f] mb-1">Update Your Practice Details</h2>
        <p class="text-sm text-[#5d6e7f] mb-5">This information is inserted directly into your compliance documents —
            please check accuracy. Practice Name and Logo lock permanently after your first submission.</p>

        <div class="flex items-start gap-4 mb-5">
            <div
                class="flex-shrink-0 w-16 h-16 rounded-xl border-2 border-dashed border-[#b9cfe0] bg-[#f7fbfd] flex items-center justify-center overflow-hidden">
                @if($this->practice?->logo_path)
                <img src="{{ Storage::disk('public')->url($this->practice->logo_path) }}" alt="Practice logo"
                    class="w-full h-full object-contain">
                @else
                <span class="text-[0.62rem] font-bold text-[#5d6e7f] uppercase tracking-wider">Logo</span>
                @endif
            </div>
            <div class="flex-1">
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">
                    Practice Logo
                    @if($this->practice?->is_profile_locked)
                    <span
                        class="ml-1 inline-flex items-center gap-0.5 text-[0.68rem] font-extrabold text-[#9a6700] bg-[#fff3cd] rounded px-1.5 py-0.5 uppercase tracking-wider">🔒
                        Locked</span>
                    @else
                    <span class="text-red-500">*</span>
                    @endif
                </label>
                @unless($this->practice?->is_profile_locked)
                <input wire:model.live="logoFile" type="file" accept=".png,.jpg,.jpeg"
                    class="block w-full text-sm text-[#5d6e7f] file:mr-3 file:py-1.5 file:px-4 file:rounded file:border-0 file:text-xs file:font-bold file:bg-[#12304f] file:text-white hover:file:bg-[#0a2037] cursor-pointer">
                @error('logoFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endunless
                <p class="mt-1 text-xs text-[#5d6e7f]">PNG or JPG recommended, square aspect ratio.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">
                    Practice Name <span class="text-red-500">*</span>
                    @if($this->practice?->is_profile_locked)
                    <span
                        class="ml-1 inline-flex items-center gap-0.5 text-[0.68rem] font-extrabold text-[#9a6700] bg-[#fff3cd] rounded px-1.5 py-0.5 uppercase tracking-wider">🔒
                        Locked</span>
                    @endif
                </label>
                <input wire:model.live="practiceName" type="text" placeholder="Riverside Family Medicine" required
                    maxlength="150" {{ $this->practice?->is_profile_locked ? 'disabled' : '' }}
                class="w-full rounded-xl border {{ $errors->has('practiceName') ? 'border-red-400' : 'border-[#dbe4ee]'
                }} {{ $this->practice?->is_profile_locked ? 'bg-[#f0f4f8] cursor-not-allowed' : 'bg-[#f8fbfd]' }} px-4
                py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde]
                focus:border-transparent transition">
                @error('practiceName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Practice Address <span
                        class="text-red-500">*</span></label>
                <input wire:model.live="practiceAddress" type="text" placeholder="123 Main St, Springfield, IL"
                    class="w-full rounded-xl border {{ $errors->has('practiceAddress') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                @error('practiceAddress') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Specialty <span
                        class="text-red-500">*</span></label>
                <select wire:model.live="specialty"
                    class="w-full rounded-xl border {{ $errors->has('specialty') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                    @foreach(Practice::SPECIALTIES as $s)
                    <option value="{{ $s }}" @selected($specialty===$s)>{{ $s }}</option>
                    @endforeach
                </select>
                @error('specialty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Billable Providers <span
                        class="text-red-500">*</span></label>
                <input wire:model.live="billableProviders" type="number" min="1"
                    class="w-full rounded-xl border {{ $errors->has('billableProviders') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#009bde] focus:border-transparent transition">
                @error('billableProviders') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-between mt-5">
            <button wire:click="cancelEditProfile" wire:target="cancelEditProfile" wire:loading.attr="disabled"
                wire:target="cancelEditProfile"
                class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                <span wire:loading.remove wire:target="cancelEditProfile">Cancel</span>
                <span wire:loading wire:target="cancelEditProfile">
                    <x-spinner class="h-3.5 w-3.5" />
                </span>
            </button>
            <button wire:click="saveProfile" wire:target="saveProfile"
                class="inline-flex items-center gap-1 rounded bg-[#009bde] px-5 py-2 text-sm font-bold text-[#0a2037] hover:bg-[#5bb2aa] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                wire:target="saveProfile">
                <span wire:loading.remove wire:target="saveProfile">Save Changes &rarr;</span>
                <span wire:loading.inline-flex wire:target="saveProfile" class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Saving…
                </span>
            </button>
        </div>
    </div>
    @else
    <livewire:portal.practice-intake-wizard :orderIds="$orderIds" :editScreen="$this->editIntakeScreen"
        :key="'intake-wizard-'.implode('-', $orderIds)" />
    @endif

    <livewire:portal.osha-location-modal :practiceId="$this->practice?->id ?? 0" />
    </div>
    @endif

    {{-- ── Step 3: Upload & Confirm ── --}}
    @if($step === 3)
    <div wire:key="portal-step-3">
    @php
    $primarySub = $this->primarySubmission;
    $primaryOrder = $this->batchOrders->firstWhere('id', min($this->orderIds ?: [0]));
    $includesWorkflowQuestionnaire = $this->batchOrders->contains(fn ($o) =>
    $o->package?->includesWorkflowQuestionnaire());
    $isSubmitted = $primarySub && $primarySub->status !== IntakeSubmissionStatus::Draft;
    $practiceLabel = $this->practice?->name ?: 'this organization';
    @endphp

    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#5d6e7f] mb-1">Step 3</p>
        <h2 class="text-lg font-semibold text-[#12304f] mb-1">Upload &amp; Confirm</h2>
        <p class="text-sm text-[#5d6e7f] mb-5">
            @if($this->rejectedSubmission)
            Your previous submission was rejected. Please review the reviewer's notes below, make any needed changes,
            and re-certify.
            @elseif($isSubmitted)
            Submitted for review. Here's what you sent.
            @else
            Check your documents and answers, then certify and submit for review.
            @endif
        </p>

        @if($this->rejectedSubmission?->reviewer_notes)
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold mb-0.5">Reviewer notes:</p>
            <p>{{ $this->rejectedSubmission->reviewer_notes }}</p>
        </div>
        @endif

        {{-- Summary cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Files</p>
                <p class="text-base font-bold text-[#173045]">{{ $primarySub?->intakeUploads->count() ?? 0 }}</p>
            </div>
            @if($includesWorkflowQuestionnaire)
            @php
            $workflowCounts = $this->workflowAnswerCounts;
            @endphp
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Answered</p>
                <p class="text-base font-bold text-[#173045]">{{ $workflowCounts['answered'] }}</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Policy default</p>
                <p class="text-base font-bold text-[#173045]">{{ $workflowCounts['policyDefault'] }}</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Open</p>
                <p class="text-base font-bold text-[#173045]">{{ $workflowCounts['open'] }}</p>
            </div>
            @else
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Basics</p>
                <p class="text-base font-bold text-[#173045]">{{ $this->basicsCompletedCount }} / 4</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Package</p>
                <p class="text-base font-bold text-[#173045]">{{ $primaryOrder?->package ?
                    ucfirst($primaryOrder->package->tier()->value) : '—' }}</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Status</p>
                <p class="text-base font-bold text-[#173045]">{{
                    $this->intakeSubmissionStatusLabel($primarySub?->status) }}</p>
            </div>
            @endif
        </div>

        {{-- Documents --}}
        <div class="border-t border-[#eef2f6] pt-5 mb-5">
            <h3 class="text-base font-semibold text-[#12304f] mb-1">Documents</h3>
            <p class="text-sm text-[#5d6e7f] mb-3">
                {{ $includesWorkflowQuestionnaire ? 'Anything you already have. You can add more here.' : 'Your existing
                documents for review and update.' }}
            </p>

            <ul class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl mb-3">
                @foreach($this->reviewDocumentCategories as $key => $label)
                @php
                $status = $this->reviewDocumentCategoryStatus($key);
                @endphp
                <li class="flex items-center justify-between gap-3 px-4 py-3">
                    <span class="text-sm font-semibold text-[#173045]">
                        {{ $label }}
                        @if($includesWorkflowQuestionnaire)
                        <span class="text-xs font-normal text-[#8592a1]">(if you have it)</span>
                        @endif
                    </span>
                    <span class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold tracking-wide uppercase
                            {{ $status === 'uploaded' ? 'bg-[#d7f3ea] text-[#117a51]' : ($status === 'declined' ? 'bg-[#eef1f5] text-[#5d6e7f]' : 'bg-[#fdf3e0] text-[#a3690f]') }}">
                            {{ $status === 'uploaded' ? 'Uploaded' : ($status === 'declined' ? "Don't have it" :
                            ($includesWorkflowQuestionnaire ? 'Optional' : 'Needed')) }}
                        </span>
                        @if($status !== 'uploaded' && ! $isSubmitted)
                        <button type="button" wire:click="toggleStep3DocumentMissing('{{ $key }}')"
                            class="text-xs font-semibold text-[#1a7aad] hover:underline">
                            {{ $status === 'declined' ? 'Undo' : "I don't have this" }}
                        </button>
                        @endif
                    </span>
                </li>
                @endforeach
            </ul>

            @if(! $includesWorkflowQuestionnaire && collect($this->reviewDocumentCategories)->keys()->contains(fn ($key)
            => $this->reviewDocumentCategoryStatus($key) === 'declined'))
            <div class="rounded-xl bg-[#fdf3e0] px-3.5 py-2.5 mb-3 text-xs text-[#8a5a0f] leading-relaxed">
                Missing a document? Essential updates what you have. <strong class="font-bold">Professional</strong>
                creates missing documents for you.
                <a href="{{ route('home') }}#pricing" class="font-bold underline hover:no-underline">Compare
                    packages</a>
            </div>
            @endif

            @if($primarySub?->intakeUploads->isNotEmpty())
            <ul class="space-y-2 mb-3">
                @foreach($primarySub->intakeUploads as $upload)
                <li class="flex items-center gap-2 text-sm border border-[#eef2f6] rounded-lg px-3 py-2">
                    <span class="flex-1 truncate text-[#173045] font-medium">{{ $upload->original_filename }}</span>
                    <span class="text-xs text-[#8592a1] flex-shrink-0">{{ $upload->fileSizeForHumans() }}</span>
                    <span
                        class="flex-shrink-0 rounded-lg border border-[#dbe4ee] bg-white px-2 py-1.5 text-xs text-[#173045]">
                        {{ $this->reviewDocumentCategories[$upload->document_category] ?? 'Other' }}
                    </span>
                </li>
                @endforeach
            </ul>
            @endif

            @if(! $isSubmitted)
            <div class="rounded-xl border border-dashed border-[#dbe4ee] bg-[#f8fbfd] p-4" x-data="{ uploading: false, progress: 0 }"
                x-on:livewire-upload-start.window="uploading = true; progress = 0"
                x-on:livewire-upload-finish.window="uploading = false"
                x-on:livewire-upload-error.window="uploading = false"
                x-on:livewire-upload-progress.window="progress = $event.detail.progress">
                <div class="flex flex-wrap items-start gap-2">
                    <div class="flex-1 min-w-[10rem]">
                        <input wire:model="step3DocumentFile" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx"
                            wire:loading.attr="disabled" wire:target="step3DocumentFile,uploadStep3Document"
                            class="block w-full text-xs text-[#5c778d] file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-bold file:bg-[#12304f] file:text-white hover:file:bg-[#0a2037] cursor-pointer">
                        @error('step3DocumentFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <div x-show="uploading" x-cloak class="mt-2">
                            <div class="h-1.5 rounded-full bg-[#e8eef4] overflow-hidden">
                                <div class="h-full rounded-full bg-[#0b9ed0] transition-all duration-75" :style="`width: ${progress}%`"></div>
                            </div>
                            <p class="text-[11px] text-[#5c778d] mt-1">Uploading&hellip; <span x-text="progress"></span>%</p>
                        </div>
                    </div>
                    <select wire:model="step3DocumentCategory"
                        class="rounded-lg border border-[#dbe4ee] bg-white px-2.5 py-1.5 text-xs text-[#173045]">
                        <option value="">Document type&hellip;</option>
                        @foreach($this->reviewDocumentCategories as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                        <option value="other">Other</option>
                    </select>
                    <button type="button" wire:click="uploadStep3Document" wire:target="uploadStep3Document"
                        wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                        class="text-xs font-bold rounded bg-[#12304f] text-white px-3.5 py-1.5 hover:bg-[#0a2037] transition-colors flex-shrink-0">
                        <span wire:loading.remove wire:target="uploadStep3Document">Upload</span>
                        <span wire:loading.inline-flex wire:target="uploadStep3Document"
                            class="inline-flex items-center gap-1.5">
                            <x-spinner class="h-3.5 w-3.5" /> Uploading&hellip;
                        </span>
                    </button>
                </div>
                <p class="text-xs text-[#8592a1] mt-2">PDF, JPG, PNG or DOCX &middot; up to 20MB</p>
            </div>
            @endif
        </div>

        {{-- Your answers --}}
        <div class="border-t border-[#eef2f6] pt-5 mb-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold text-[#12304f]">Your answers</h3>
                @if($primarySub)
                <a href="{{ route('intake-submissions.answers', $primarySub) }}"
                    class="text-xs font-bold text-[#1a7aad] hover:underline rounded border border-[#dbe4ee] px-3 py-1.5">Download
                    my answers</a>
                @endif
            </div>
            @if($this->batchOrders->contains(fn ($o) => $o->package?->includesWorkflowQuestionnaire()))
            <p class="text-xs text-[#8592a1] mb-3">Your responses are used exactly as entered. Empower does not edit practice responses. Questions marked &ldquo;Policy default&rdquo; use the manual's best-practice language.</p>
            @endif
            <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
                @foreach($this->answerSummaryRows as $row)
                <div @if(! empty($row['details'])) x-data="{ open: false }" @endif>
                    <button type="button" @if(! empty($row['details'])) x-on:click="open = !open" @endif
                        @if(empty($row['details'])) disabled @endif
                        class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left transition-colors {{ ! empty($row['details']) ? 'cursor-pointer hover:bg-[#f8fbfd]' : 'cursor-default' }}">
                        <span class="text-sm font-semibold text-[#173045] flex items-center gap-1.5">
                            {{ $row['label'] }}
                            @if(! empty($row['details']))
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                x-bind:class="open ? 'rotate-180' : ''" class="text-[#8592a1] transition-transform">
                                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            @endif
                        </span>
                        <span class="text-xs text-[#5d6e7f] flex-shrink-0">
                            @if(isset($row['count']))
                            {{ $row['count'] }}
                            @else
                            {{ $row['done'] }}/{{ $row['total'] }} {{ $row['done'] === $row['total'] ? '✓' : '' }}
                            @endif
                        </span>
                    </button>
                    @if(! empty($row['details']))
                    <div x-show="open" x-cloak x-transition class="px-4 pb-3 space-y-2.5">
                        @foreach($row['details'] as $detail)
                        @php
                        $badge = $detail['badge'] ?? ($detail['done'] ? ['label' => 'Done', 'class' => 'bg-[#d7f3ea]
                        text-[#117a51]'] : ['label' => 'Pending', 'class' => 'bg-[#eef1f5] text-[#5d6e7f]']);
                        $detailQuestionId = str_starts_with($detail['screen'] ?? '', 'question:')
                            ? (int) substr($detail['screen'], strlen('question:'))
                            : null;
                        @endphp
                        @if($detailQuestionId !== null && $this->inlineEditQuestionId === $detailQuestionId)
                        {{-- Inline editor — workflow-question answers only, matching the
                             prototype's own inlineEditor(), which is also question-only. --}}
                        <div class="rounded-xl border border-[#9ed3e9] bg-[#f2f9fd] p-3 space-y-2.5">
                            <p class="text-xs font-semibold text-[#173045]">{{ $detail['label'] }}</p>
                            <div class="space-y-1.5">
                                <label class="flex items-center gap-2 text-xs text-[#173045] cursor-pointer">
                                    <input type="radio" wire:model.live="inlineEditHasDocumentedProcess" value="1" class="accent-[#12304f]">
                                    We have a documented process
                                </label>
                                <label class="flex items-center gap-2 text-xs text-[#173045] cursor-pointer">
                                    <input type="radio" wire:model.live="inlineEditHasDocumentedProcess" value="0" class="accent-[#12304f]">
                                    We don't have a documented answer
                                </label>
                            </div>
                            @if($inlineEditHasDocumentedProcess)
                            <textarea wire:model="inlineEditResponse" rows="4"
                                class="w-full rounded-lg border {{ $errors->has('inlineEditResponse') ? 'border-red-400' : 'border-[#dbe4ee]' }} bg-white px-3 py-2 text-xs text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"></textarea>
                            @error('inlineEditResponse') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            @endif
                            <div class="flex items-center gap-3">
                                <button type="button" wire:click="saveInlineEdit" wire:target="saveInlineEdit" wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded bg-[#12304f] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#0c233b] transition-colors disabled:opacity-50">
                                    <span wire:loading.remove wire:target="saveInlineEdit">Save</span>
                                    <span wire:loading.inline-flex wire:target="saveInlineEdit" class="inline-flex items-center gap-1.5"><x-spinner class="h-3 w-3" /> Saving&hellip;</span>
                                </button>
                                <button type="button" wire:click="cancelInlineEdit" class="text-xs font-semibold text-[#5d6e7f] hover:underline">Cancel</button>
                            </div>
                        </div>
                        @else
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold text-[#173045]">{{ $detail['label'] }}</p>
                                <p class="text-xs text-[#5d6e7f]">{{ $detail['value'] }}</p>
                                @if(! empty($detail['meta']))
                                <p class="text-[11px] text-[#8592a1] mt-0.5">{{ $detail['meta'] }}</p>
                                @endif
                            </div>
                            <div class="flex-shrink-0 flex items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide {{ $badge['class'] }}">
                                    {{ $badge['label'] }}
                                </span>
                                @if(! $isSubmitted && $detailQuestionId !== null)
                                <button type="button" wire:click="startInlineEdit({{ $detailQuestionId }})"
                                    wire:target="startInlineEdit"
                                    class="text-xs font-bold text-[#1a7aad] hover:underline">Edit</button>
                                @elseif(! $isSubmitted && ! empty($detail['screen']))
                                <button type="button" wire:click="editIntakeAnswer('{{ $detail['screen'] }}')"
                                    wire:target="editIntakeAnswer"
                                    class="text-xs font-bold text-[#1a7aad] hover:underline">Edit</button>
                                @endif
                            </div>
                        </div>
                        @endif
                        @endforeach
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
        </div>

        <div class="border-t border-[#eef2f6] pt-5">
            <h3 class="text-base font-semibold text-[#12304f] mb-1">Certification</h3>
            <p class="text-sm text-[#5d6e7f] mb-4">The responses above accurately describe the current operations of
                this organization.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Completed by (print name) <span
                            class="text-red-500">*</span></label>
                    <input wire:model="certifiedByName" type="text" required maxlength="150" {{ $isSubmitted
                        ? 'disabled' : '' }}
                        class="w-full rounded-xl border {{ $errors->has('certifiedByName') ? 'border-red-400' : 'border-[#dbe4ee]' }} {{ $isSubmitted ? 'bg-[#f0f4f8] cursor-not-allowed' : 'bg-[#f8fbfd]' }} px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error('certifiedByName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Title <span
                            class="text-red-500">*</span></label>
                    <input wire:model="certifiedByTitle" type="text" required maxlength="150" {{ $isSubmitted
                        ? 'disabled' : '' }}
                        class="w-full rounded-xl border {{ $errors->has('certifiedByTitle') ? 'border-red-400' : 'border-[#dbe4ee]' }} {{ $isSubmitted ? 'bg-[#f0f4f8] cursor-not-allowed' : 'bg-[#f8fbfd]' }} px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error('certifiedByTitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Signature (type your full name)
                        <span class="text-red-500">*</span></label>
                    <input wire:model="certifiedSignature" type="text" placeholder="Type your full name to sign"
                        required maxlength="150" {{ $isSubmitted ? 'disabled' : '' }}
                        class="w-full rounded-xl border {{ $errors->has('certifiedSignature') ? 'border-red-400' : 'border-[#dbe4ee]' }} {{ $isSubmitted ? 'bg-[#f0f4f8] cursor-not-allowed' : 'bg-[#f8fbfd]' }} px-4 py-2.5 text-sm italic text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error('certifiedSignature') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Date</label>
                    <input type="text" disabled value="{{ ($primarySub?->certified_at ?? now())->format('M j, Y') }}"
                        class="w-full rounded-xl border border-[#dbe4ee] bg-[#f0f4f8] px-4 py-2.5 text-sm text-[#5d6e7f] cursor-not-allowed">
                </div>
            </div>

            <label class="flex items-start gap-2.5 mb-2 {{ $isSubmitted ? '' : 'cursor-pointer' }}">
                <input type="checkbox" wire:model="certifyChecked" {{ $isSubmitted ? 'disabled' : '' }}
                    class="mt-0.5 rounded text-[#0b9ed0] focus:ring-[#0b9ed0]">
                <span class="text-sm text-[#173045]">I certify these responses are accurate for {{ $practiceLabel
                    }}.</span>
            </label>
            @error('certifyChecked') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('payment') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="flex justify-between mt-3">
                <button wire:click="goToStep(2)" wire:target="goToStep(2)" wire:loading.attr="disabled"
                    class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                    <span wire:loading.remove wire:target="goToStep(2)">&larr; Back to intake</span>
                    <span wire:loading wire:target="goToStep(2)">
                        <x-spinner class="h-3.5 w-3.5" />
                    </span>
                </button>
                @if($isSubmitted)
                <button wire:click="goToStep(4)" wire:target="goToStep(4)" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="goToStep(4)">Continue &rarr;</span>
                    <span wire:loading wire:target="goToStep(4)">
                        <x-spinner class="h-3.5 w-3.5" />
                    </span>
                </button>
                @else
                <button wire:click="finalizeIntake" wire:target="finalizeIntake" wire:loading.attr="disabled"
                    wire:loading.class="opacity-70 cursor-not-allowed"
                    class="inline-flex items-center gap-1 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="finalizeIntake">Submit for Review &rarr;</span>
                    <span wire:loading.inline-flex wire:target="finalizeIntake"
                        class="inline-flex items-center gap-1.5">
                        <x-spinner class="h-3.5 w-3.5" /> Submitting…
                    </span>
                </button>
                @endif
            </div>
        </div>
    </div>
    </div>
    @endif

    {{-- ── Step 4: Review ── --}}
    @if($step === 4)
    <div wire:key="portal-step-4">
    @php
    $reviewStages = [
    ['Submitted', 'We received your intake and documents.'],
    ['Processed', 'Your answers and files were organized for review.'],
    ['In review', 'An Empower compliance specialist is reviewing your submission.'],
    ['Questions for you', 'Only if we need anything. We’ll email you and show it here.'],
    ['Approved', 'Your documents are generated and delivered to your dashboard.'],
    ];
    $reviewerFirstName = Str::of(auth()->user()?->name ?? '')->before(' ')->toString() ?: 'there';
    @endphp
    <div wire:poll.5s="checkApproval"
        class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#5d6e7f] mb-1">Step 4</p>
        <h2 class="text-lg font-semibold text-[#12304f] mb-4">Review</h2>

        @if($justSubmitted)
        {{-- One-shot cosmetic loading sequence right after finalizeIntake() — purely client-side
             timing, no real backend stage happens between "Submitted" and "In review" below. --}}
        <div class="rounded-2xl border border-[#e6edf4] bg-[#f6f9fc] px-4 py-4 mb-5" x-data="{ pct: 0, message: 'Sending your responses…' }" x-init="
                setTimeout(() => { pct = 25; message = 'Uploading your documents…'; }, 500);
                setTimeout(() => { pct = 55; message = 'Reading documents and answers…'; }, 1100);
                setTimeout(() => { pct = 85; message = 'Queuing for review…'; }, 1700);
                setTimeout(() => { pct = 100; }, 2200);
                setTimeout(() => $wire.clearJustSubmitted(), 2500);
            ">
            <div class="flex items-center justify-between gap-3 mb-3">
                <span class="inline-flex items-center gap-2 text-sm font-bold text-[#12304f]">
                    <x-spinner class="h-4 w-4 text-[#0b9ed0]" />
                    <span x-text="message"></span>
                </span>
                <span class="text-sm font-bold text-[#12304f]" x-text="`${pct}%`"></span>
            </div>
            <div class="h-2 rounded-full bg-[#e2eaf2] overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r from-[#0b9ed0] to-[#12304f] transition-all duration-500 ease-linear"
                    :style="`width: ${pct}%`"></div>
            </div>
            <p class="text-xs text-[#5d6e7f] mt-2.5">Please keep this page open. This only takes a moment.</p>
        </div>
        @endif

        <div class="space-y-8">
            @forelse($this->batchOrders as $order)
            @php
            $submission = $order->intakeSubmission;
            $status = $submission?->status;
            $isApproved = $status === IntakeSubmissionStatus::Approved;
            $isRejected = $status === IntakeSubmissionStatus::Rejected;
            $subAt = $submission?->submitted_at ?? now();
            $dueBy = $subAt->copy()->addWeekdays(5);
            $reviewerQuestions = $submission?->reviewerQuestions ?? collect();
            $hasQuestion = $reviewerQuestions->isNotEmpty();
            $pendingQuestion = $reviewerQuestions->contains(fn ($q) => ! $q->isAnswered());
            $questionAnswered = $hasQuestion && ! $pendingQuestion;

            $stageRows = [];
            foreach ($reviewStages as $i => [$title, $desc]) {
            if ($justSubmitted) {
            $cls = $i === 0 ? 'cur' : 'upcoming';
            $note = null;
            } elseif ($isRejected) {
            $cls = $i < 3 ? 'done' : 'upcoming';
            $note = $i < 2 ? $subAt->format('M j, g:i A') : null;
            if ($i === 3) {
            $cls = 'rejected';
            $title = 'Changes requested';
            $desc = $submission->reviewer_notes ?: 'Please review and resubmit.';
            }
            } else {
            $cls = match (true) {
            $isApproved => 'done',
            $i < 2 => 'done',
            $i === 2 => 'cur',
            $i === 3 && $pendingQuestion => 'attn',
            $i === 3 && $questionAnswered => 'done',
            default => 'upcoming',
            };
            $note = null;
            if ($i < 2 && $cls === 'done') {
            $note = $subAt->format('M j, g:i A');
            }
            if ($i === 2 && $cls === 'cur') {
            $note = 'Started '.($submission->under_review_started_at ?? $subAt)->format('M j, g:i A');
            }
            if ($i === 3 && $cls === 'done' && $questionAnswered) {
            $note = $reviewerQuestions->last()->replied_at?->format('M j, g:i A');
            }
            if ($i === 4 && $isApproved) {
            $note = $submission->reviewed_at?->format('M j, g:i A');
            }
            if ($i === 3) {
            if ($pendingQuestion) {
            $desc = 'Your reviewer asked a question. Reply to it above so we can finish.';
            } elseif ($questionAnswered) {
            $desc = 'You answered the reviewer’s question.';
            } elseif ($isApproved) {
            $desc = 'No questions were needed.';
            }
            }
            if ($i === 4 && ! $isApproved) {
            $desc = "{$desc} Expected by {$dueBy->format('D, M j')}.";
            }
            }
            $stageRows[] = ['title' => $title, 'desc' => $desc, 'cls' => $cls, 'note' => $note];
            }
            @endphp
            <div>
                <p class="text-sm font-semibold text-[#12304f] mb-3">{{ $order->package?->name }}</p>

                @if(! $justSubmitted)
                @if($isRejected)
                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3.5 mb-4">
                    <p class="text-sm font-semibold text-red-700 mb-1">Changes requested</p>
                    <p class="text-sm text-red-700 mb-3">{{ $submission->reviewer_notes ?: 'Please review and resubmit.' }}</p>
                    <button wire:click="reuploadForOrder({{ $order->id }})" wire:target="reuploadForOrder({{ $order->id }})"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1 rounded bg-[#9f1239] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#881337] transition-colors">
                        <span wire:loading.remove wire:target="reuploadForOrder({{ $order->id }})">Update &amp; Resubmit &rarr;</span>
                        <span wire:loading.inline-flex wire:target="reuploadForOrder({{ $order->id }})" class="inline-flex items-center gap-1.5">
                            <x-spinner class="h-3.5 w-3.5" /> Loading…
                        </span>
                    </button>
                </div>
                @else
                {{-- "celebrate" block --}}
                <div class="text-center px-2 pt-1 pb-1 mb-1">
                    <div class="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full border-2 border-[#1f9d6b] bg-[#e6f6ef]">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                            <path d="M5 13l4 4 10-10" stroke="#1f9d6b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                    <h3 class="text-[19px] font-extrabold text-[#0e1b30] mb-1.5">
                        {{ $isApproved ? 'Approved — your documents are ready' : 'Submitted — pending review' }}
                    </h3>
                    <p class="text-sm text-[#5d6e7f] leading-relaxed max-w-md mx-auto">
                        @if($isApproved)
                        Great news, {{ $reviewerFirstName }}. Your submission was approved and your documents have been generated.
                        @else
                        Thanks, {{ $reviewerFirstName }}. Your intake is now pending review. We'll reach out if we have any questions or need clarification.
                        @endif
                    </p>
                    <p class="inline-flex items-center gap-1.5 mt-3 rounded-full bg-[#e6f6ef] px-3 py-1.5 text-xs text-[#135f41]">
                        @if($isApproved)
                        &#10003; We've emailed a confirmation to <strong>{{ auth()->user()?->email }}</strong>.
                        @else
                        You'll receive an email confirmation at <strong>{{ auth()->user()?->email }}</strong> once it's approved.
                        @endif
                    </p>
                </div>

                @if(! $isApproved)
                <div class="flex items-start gap-2 rounded-xl bg-[#eef7fc] text-[#1d4e6b] text-[13.5px] leading-relaxed px-3 py-2.5 mt-3 mb-1">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="flex-shrink-0 mt-0.5 text-[#0b9ed0]">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                        <path d="M12 11v5M12 8v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <span>Reviews typically take <strong>3–5 business days</strong>. We expect to finish by <strong>{{ $dueBy->format('D, M j') }}</strong>.</span>
                </div>
                @endif

                @if($hasQuestion && ! $isApproved)
                @foreach($reviewerQuestions as $reviewerQuestion)
                @php $answered = $reviewerQuestion->isAnswered(); @endphp
                <div wire:key="reviewer-question-{{ $reviewerQuestion->id }}" class="mt-3.5 rounded-2xl border px-3.5 py-3.5 {{ $answered ? 'border-[#cfe9dc] bg-[#f5fbf8]' : 'border-[#f3d29a] bg-[#fffaf1]' }}">
                    <p class="text-[11px] font-bold uppercase tracking-wide mb-1.5 {{ $answered ? 'text-[#1f9d6b]' : 'text-[#9a5b00]' }}">
                        Question from your Empower reviewer &middot; {{ $reviewerQuestion->created_at->format('M j, g:i A') }}
                    </p>
                    <p class="text-sm text-[#173045] mb-2.5">{{ $reviewerQuestion->question }}</p>
                    @if($answered)
                    <p class="text-sm text-[#173045]"><strong>Your reply:</strong> {{ $reviewerQuestion->reply }}</p>
                    <p class="text-xs text-[#5d6e7f] mt-1">Thanks. Your reviewer will continue from here.</p>
                    @else
                    <textarea wire:model="reviewerReplyText.{{ $reviewerQuestion->id }}" rows="3"
                        placeholder="Type your reply…"
                        class="w-full rounded-xl border border-[#f3d29a] bg-white px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition resize-none"></textarea>
                    @error("reviewerReplyText.{$reviewerQuestion->id}") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="mt-2">
                        <button type="button" wire:click="replyToReviewerQuestion({{ $reviewerQuestion->id }})"
                            wire:target="replyToReviewerQuestion({{ $reviewerQuestion->id }})" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-[#0b9ed0] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#0a8cba] transition-colors">
                            <span wire:loading.remove wire:target="replyToReviewerQuestion({{ $reviewerQuestion->id }})">Send reply</span>
                            <span wire:loading.inline-flex wire:target="replyToReviewerQuestion({{ $reviewerQuestion->id }})" class="inline-flex items-center gap-1.5">
                                <x-spinner class="h-3.5 w-3.5" /> Sending&hellip;
                            </span>
                        </button>
                    </div>
                    @endif
                </div>
                @endforeach
                @endif
                @endif
                @endif

                {{-- Timeline --}}
                <ol class="mt-4.5">
                    @foreach($stageRows as $i => $row)
                    @php $isLast = $i === count($stageRows) - 1; @endphp
                    <li class="flex gap-3.5 {{ $isLast ? '' : 'pb-4.5' }}">
                        <div class="flex flex-col items-center flex-shrink-0">
                            <div class="relative w-7 h-7 rounded-full border-2 flex items-center justify-center flex-shrink-0
                                {{ match($row['cls']) {
                                    'done' => 'bg-[#0b9ed0] border-[#0b9ed0]',
                                    'cur' => 'border-[#0b9ed0] bg-white',
                                    'attn' => 'border-[#e0930f] bg-white',
                                    'rejected' => 'border-red-500 bg-white',
                                    default => 'border-[#d7dee6] bg-white',
                                } }}">
                                @if($row['cls'] === 'done')
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none">
                                    <path d="M5 13l4 4 10-10" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                @elseif($row['cls'] === 'rejected')
                                <span class="text-red-600 text-sm leading-none">&times;</span>
                                @elseif($row['cls'] === 'cur' || $row['cls'] === 'attn')
                                <span class="absolute w-2.5 h-2.5 rounded-full animate-ping {{ $row['cls'] === 'attn' ? 'bg-[#e0930f]' : 'bg-[#0b9ed0]' }}"></span>
                                <span class="relative w-2.5 h-2.5 rounded-full {{ $row['cls'] === 'attn' ? 'bg-[#e0930f]' : 'bg-[#0b9ed0]' }}"></span>
                                @endif
                            </div>
                            @if(! $isLast)
                            <div class="w-0.5 flex-1 my-0.5 {{ $row['cls'] === 'done' ? 'bg-[#0b9ed0]' : 'bg-[#edf2f7]' }}" style="min-height: 1.25rem"></div>
                            @endif
                        </div>
                        <div class="pb-0.5">
                            <p class="text-sm font-extrabold
                                {{ $row['cls'] === 'rejected' ? 'text-red-700' : ($row['cls'] === 'attn' ? 'text-[#9a5b00]' : ($row['cls'] === 'upcoming' ? 'text-[#aab4bf]' : 'text-[#173045]')) }}">
                                {{ $row['title'] }}
                                @if($row['note'])
                                <span class="ml-1.5 text-xs font-semibold text-[#8592a1]">{{ $row['note'] }}</span>
                                @endif
                            </p>
                            <p class="text-xs mt-0.5 {{ $row['cls'] === 'rejected' || $row['cls'] === 'attn' ? 'text-[#9a5b00]' : 'text-[#5d6e7f]' }}">
                                {{ $row['desc'] }}
                            </p>
                        </div>
                    </li>
                    @endforeach
                </ol>
            </div>
            @empty
            <p class="text-sm text-[#5d6e7f] italic">No submission found.</p>
            @endforelse
        </div>
    </div>

    <div class="flex justify-between items-center mt-5">
        <button wire:click="goToStep(3)" wire:target="goToStep(3)" wire:loading.attr="disabled" @disabled($justSubmitted)
            class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="goToStep(3)">&larr; Back</span>
            <span wire:loading wire:target="goToStep(3)">
                <x-spinner class="h-3.5 w-3.5" />
            </span>
        </button>
        <button wire:click="goToStep(5)" wire:target="goToStep(5)" wire:loading.attr="disabled" @disabled($milestone < 4)
            class="inline-flex items-center gap-1 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-[#12304f]">
            <span wire:loading.remove wire:target="goToStep(5)">Go to Dashboard &rarr;</span>
            <span wire:loading.inline-flex wire:target="goToStep(5)" class="inline-flex items-center gap-1.5">
                <x-spinner class="h-3.5 w-3.5" /> Loading…
            </span>
        </button>
    </div>

    {{-- Dashboard skeleton — masks the brief gap while Step 5's documents/activity/billing load,
         matching the prototype's .sk shimmer treatment. Only ever visible during the goToStep(5)
         request itself (Livewire re-renders the whole component atomically, so this can't be a
         true progressive-load skeleton — it's a loading mask over the transition, not over partial
         real content). --}}
    <div wire:loading.block wire:target="goToStep(5)" class="space-y-4 mt-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @for($i = 0; $i < 4; $i++)
            <div class="bg-white border border-empower-border rounded-[1.25rem] p-5 space-y-3">
                <div class="h-3 w-20 rounded bg-[#eef2f6] animate-pulse"></div>
                <div class="h-7 w-16 rounded bg-[#eef2f6] animate-pulse"></div>
            </div>
            @endfor
        </div>
        <div class="bg-white border border-empower-border rounded-[1.25rem] p-5 space-y-3">
            @for($i = 0; $i < 3; $i++)
            <div class="h-10 rounded-lg bg-[#eef2f6] animate-pulse"></div>
            @endfor
        </div>
    </div>
    </div>
    @endif

{{-- ── Step 5: Dashboard ── --}}
@if($step === 5)
<div wire:key="portal-step-5" x-data="{
            confirmCancelOrderId: null,
            confirmCancelMessage: '',
            confirmCancel(orderId, message) { this.confirmCancelOrderId = orderId; this.confirmCancelMessage = message; },
        }">
    <div class="space-y-4">
        <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
            <p class="text-xs font-extrabold uppercase tracking-widest text-[#5d6e7f] mb-1">Step 5</p>
            <h2 class="text-lg font-semibold text-[#12304f] mb-1">Dashboard</h2>
            <p class="text-sm text-[#5d6e7f] mb-5">Your history, payments and generated documents for
                {{ $this->practice?->name ?: 'your practice' }}.</p>

            @php
            $dashOrderForCards = $this->currentOrder;
            $invoiceLabel = $dashOrderForCards?->billing_cycle === \App\Enums\BillingCycle::Annual ? 'Invoice / Year' :
            'Invoice / Month';
            $invoiceAmount = (float) ($dashOrderForCards?->original_price ?? 0);
            @endphp
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                    <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Package</p>
                    <p class="text-base font-bold text-[#173045]">{{ $dashOrderForCards?->package?->name ?? '—' }}</p>
                </div>
                <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                    <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">{{ $invoiceLabel
                        }}</p>
                    <p class="text-base font-bold text-[#173045]">${{ number_format($invoiceAmount, $invoiceAmount ==
                        floor($invoiceAmount) ? 0 : 2) }}</p>
                </div>
                <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                    <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Renews</p>
                    <p class="text-base font-bold text-[#173045]">{{ $dashOrderForCards?->next_bill_date?->format('M j,
                        Y') ?? '—' }}</p>
                </div>
            </div>
        </div>

        @php $dashOrder = $this->currentOrder; @endphp
        @if($dashOrder && ! $dashOrder->blockedFromAiGeneration() && $dashOrder->payment_status ===
        PaymentStatus::Trialing)
        <div
            class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 flex flex-wrap items-center justify-between gap-3">
            <span>
                Free trial active — ends {{ $dashOrder->trial_ends_at?->format('M j, Y') }}. Card on file ending in
                {{ $dashOrder->card_last_four ?? '····' }}.
            </span>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="convertTrialToPaid({{ $dashOrder->id }})"
                    wire:target="convertTrialToPaid({{ $dashOrder->id }})" wire:loading.attr="disabled"
                    wire:loading.class="opacity-70 cursor-not-allowed"
                    class="rounded-lg bg-accent px-3.5 py-1.5 text-xs font-bold text-navy-dark hover:bg-accent-dark transition-colors">
                    <span wire:loading.remove wire:target="convertTrialToPaid({{ $dashOrder->id }})">
                        Proceed with Payment
                    </span>
                    <span wire:loading.inline-flex wire:target="convertTrialToPaid({{ $dashOrder->id }})"
                        class="inline-flex items-center gap-1.5">
                        <x-spinner class="h-3.5 w-3.5" /> Processing…
                    </span>
                </button>
                <button type="button"
                    x-on:click="confirmCancel({{ $dashOrder->id }}, `Cancel your free trial? You'll lose access to AI document generation once it ends.`)"
                    class="rounded-lg border border-blue-300 px-3.5 py-1.5 text-xs font-semibold text-blue-800 hover:bg-blue-100 transition-colors">
                    Cancel
                </button>
            </div>
            <div x-data="{ showUpdateCard: false }" class="w-full">
                <button type="button" x-on:click="showUpdateCard = ! showUpdateCard"
                    class="text-xs font-semibold text-blue-800 hover:underline">Update Card</button>
                <div x-show="showUpdateCard" x-cloak class="mt-2 flex flex-wrap items-end gap-2">
                    <input x-ref="cardNumber" type="text" placeholder="Card number" inputmode="numeric" maxlength="16"
                        x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '').slice(0, 16)"
                        class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-40">
                    <input x-ref="cardExpiry" type="text" placeholder="MM/YY" inputmode="numeric" maxlength="5"
                        x-on:input="
                            let digits = $el.value.replace(/[^0-9]/g, '').slice(0, 4);
                            let deleting = ($event.inputType || '').startsWith('delete');
                            $el.value = (digits.length >= 2 && !deleting) ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
                        " class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-20">
                    <input x-ref="cardCvc" type="text" placeholder="CVC" inputmode="numeric" maxlength="4"
                        x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '')"
                        class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-16">
                    <button type="button"
                        x-on:click="$wire.updateTrialCard({{ $dashOrder->id }}, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value).then(() => showUpdateCard = false)"
                        wire:loading.attr="disabled" wire:target="updateTrialCard"
                        class="rounded-lg bg-navy px-3 py-1.5 text-xs font-bold text-white hover:bg-navy-dark transition-colors">
                        Save Card
                    </button>
                </div>
                @error('cardNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('cardExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('cardCvc') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        @error('payment') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @elseif($dashOrder && $dashOrder->payment_status === PaymentStatus::PastDue)
        <div
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex flex-wrap items-center justify-between gap-3">
            <span>
                We couldn't process your last renewal payment{{ $dashOrder->last_renewal_error ? ":
                {$dashOrder->last_renewal_error}" : '.' }}
                We'll retry automatically — please update your card to avoid cancellation.
            </span>
            <button type="button"
                x-on:click="confirmCancel({{ $dashOrder->id }}, `Cancel this subscription? This can't be undone.`)"
                class="rounded-lg border border-amber-300 px-3.5 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors">
                Cancel Subscription
            </button>
            <div x-data="{ showUpdateCard: false }" class="w-full">
                <button type="button" x-on:click="showUpdateCard = ! showUpdateCard"
                    class="text-xs font-semibold text-amber-800 hover:underline">Update Card</button>
                <div x-show="showUpdateCard" x-cloak class="mt-2 flex flex-wrap items-end gap-2">
                    <input x-ref="cardNumber" type="text" placeholder="Card number" inputmode="numeric" maxlength="16"
                        x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '').slice(0, 16)"
                        class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-40">
                    <input x-ref="cardExpiry" type="text" placeholder="MM/YY" inputmode="numeric" maxlength="5"
                        x-on:input="
                            let digits = $el.value.replace(/[^0-9]/g, '').slice(0, 4);
                            let deleting = ($event.inputType || '').startsWith('delete');
                            $el.value = (digits.length >= 2 && !deleting) ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
                        " class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-20">
                    <input x-ref="cardCvc" type="text" placeholder="CVC" inputmode="numeric" maxlength="4"
                        x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '')"
                        class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-16">
                    <button type="button"
                        x-on:click="$wire.updateTrialCard({{ $dashOrder->id }}, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value).then(() => showUpdateCard = false)"
                        wire:loading.attr="disabled" wire:target="updateTrialCard"
                        class="rounded-lg bg-navy px-3 py-1.5 text-xs font-bold text-white hover:bg-navy-dark transition-colors">
                        Save Card
                    </button>
                </div>
                @error('cardNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('cardExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('cardCvc') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        @error('payment') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @elseif($dashOrder?->blockedFromAiGeneration())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            @if($dashOrder->payment_status === PaymentStatus::Trialing)
            Your free trial ended without a subscription — AI document generation is no longer available for this
            practice. Contact us to resubscribe.
            @else
            This subscription has been cancelled — AI document generation is no longer available for this practice.
            Contact us to resubscribe.
            @endif
        </div>
        @elseif($dashOrder && $dashOrder->payment_status === PaymentStatus::Paid && $dashOrder->next_bill_date)
        <div
            class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3 text-xs text-empower-muted flex items-center justify-between gap-3">
            <span>Renews {{ $dashOrder->next_bill_date->format('M j, Y') }}</span>
            <button type="button"
                x-on:click="confirmCancel({{ $dashOrder->id }}, `Cancel your subscription? You'll lose access to AI document generation once your current plan year ends.`)"
                class="text-xs font-semibold text-empower-muted hover:underline">Cancel subscription</button>
        </div>
        @endif

        {{-- What's next --}}
        @if($dashOrder)
        @php
        $nextStepsIsPro = (bool) $dashOrder->package?->includesWorkflowQuestionnaire();
        $nextStepsApprovedAt = $dashOrder->intakeSubmission?->reviewed_at ?? now();
        $nextReviewDate = $nextStepsApprovedAt->copy()->addYear();
        while ($nextReviewDate->isWeekend()) {
        $nextReviewDate->addDay();
        }

        $nextSteps = [
        ['key' => 'dl', 'title' => 'Download your documents', 'desc' => 'Save a copy of each manual for your records.',
        'action' => 'Download all'],
        ['key' => 'share', 'title' => 'Share the policies with your staff', 'desc' => 'We\'ve drafted a short email you
        can paste and send to everyone.', 'action' => 'Copy email'],
        ['key' => 'sign', 'title' => 'Collect staff sign-offs', 'desc' => 'Each staff member confirms they\'ve read and
        understood the policies.', 'action' => 'Download sign-off form'],
        ['key' => 'train', 'title' => 'Assign HIPAA and compliance training', 'desc' => $nextStepsIsPro ? 'Enroll every
        staff member in the Empower training platform.' : 'Take every staff member through your reviewed training
        materials.', 'action' => $nextStepsIsPro ? 'Open training' : 'Download training checklist'],
        ['key' => 'review', 'title' => 'Schedule your annual policy review', 'desc' => "Put
        {$nextReviewDate->format('D, M j')}, {$nextReviewDate->year} on your calendar to review and update your
        program.", 'action' => 'Add to calendar'],
        ];

        $nextStepsDone = $this->practice?->dashboard_next_steps ?? [];
        $nextStepsDoneCount = collect($nextSteps)->pluck('key')->filter(fn($k) => in_array($k, $nextStepsDone,
        true))->count();
        $nextStepsAllDone = $nextStepsDoneCount === count($nextSteps);

        $nextStepsPracticeName = $this->practice?->name ?: 'our practice';
        $nextStepsDocLabels = $this->expectedDocuments->pluck('type')->unique()->map(fn($t) => $t->label());
        $nextStepsReadyUrls = $this->expectedDocuments
        ->filter(fn($row) => $row['document']?->isReady())
        ->map(fn($row) => route('documents.download', $row['document']))
        ->values();

        $nextStepsContactName = $this->practice?->compliance_officer_name ?: auth()->user()->name;
        $nextStepsContactEmail = $this->practice?->compliance_officer_email ?: auth()->user()->email;
        $nextStepsShareBy = now()->addDays(14);
        $nextStepsShareEmail = "Subject: Our updated compliance and HIPAA policies\n\nHi team,\n\n{$nextStepsPracticeName}
        has updated its compliance program with Empower. Please read the attached documents:\n"
        .$nextStepsDocLabels->map(fn($d) => "- {$d}")->implode("\n")
        ."\n\nBy {$nextStepsShareBy->format('D, M j')}, please sign and return the acknowledgment form to confirm
        you've read them.\n\nQuestions? Contact {$nextStepsContactName}".($nextStepsContactEmail ?
        " ({$nextStepsContactEmail})" : '').".\n\nThank you,\n".auth()->user()->name;

        $nextStepsSignOffDocs = $nextStepsDocLabels->reject(fn($d) => str_contains(strtolower($d), 'report'));
        $nextStepsHasHotline = (bool) ($this->practice?->compliance_hotline_number ||
        $this->practice?->uses_ehcp_hotline);
        $nextStepsSignOffText = "POLICY ACKNOWLEDGMENT — {$nextStepsPracticeName}\n\nI confirm that I have received,
        read and understood:\n"
        .$nextStepsSignOffDocs->map(fn($d) => " [ ] {$d}")->implode("\n")
        ."\n\nI agree to follow these policies and to report compliance concerns to
        {$nextStepsContactName}".($nextStepsHasHotline ? ' or through the compliance hotline' : '').".\n\nName:
        ______________________ Title: ______________________\n\nSignature: __________________ Date:
        _______________\n";

        $nextStepsTrainingText = "STAFF TRAINING CHECKLIST — {$nextStepsPracticeName}\n\nFor each staff member, record
        the date they completed:\n [ ] HIPAA Privacy training\n [ ] HIPAA Security training\n [ ] Compliance & Ethics
        program overview\n [ ] How to report a concern (hotline)\n\nRepeat at least once a year and when policies
        change.\n";

        $nextStepsIcsEnd = $nextReviewDate->copy()->addDay();
        $nextStepsIcsText = implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Empower//Compliance//EN', 'BEGIN:VEVENT',
        'UID:'.(string) \Illuminate\Support\Str::ulid().'@empower',
        'DTSTAMP:'.now()->format('Ymd\THis\Z'),
        'DTSTART;VALUE=DATE:'.$nextReviewDate->format('Ymd'),
        'DTEND;VALUE=DATE:'.$nextStepsIcsEnd->format('Ymd'),
        'SUMMARY:Annual compliance policy review — '.$nextStepsPracticeName,
        'DESCRIPTION:Review and update your Compliance & Ethics\, HIPAA Privacy and HIPAA Security documents with
        Empower.',
        'END:VEVENT', 'END:VCALENDAR',
        ]);
        @endphp
        <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
            <div class="flex justify-between items-start gap-3">
                <div>
                    <h3 class="text-base font-bold text-[#12304f] mb-0.5">What's next</h3>
                    <p class="text-xs text-[#5d6e7f]">Put your new compliance program to work.</p>
                </div>
                <span
                    class="inline-flex items-center rounded-full bg-[#e6f6ef] px-2.5 py-1 text-xs font-bold text-[#1f9d6b] whitespace-nowrap">{{
                    $nextStepsDoneCount }} of {{ count($nextSteps) }} done</span>
            </div>

            <div class="h-1.5 rounded-full bg-[#e3eef6] mt-3 mb-1.5 overflow-hidden">
                <div class="h-full rounded-full bg-[#1f9d6b] transition-all duration-500"
                    style="width: {{ $nextStepsDoneCount / count($nextSteps) * 100 }}%"></div>
            </div>

            @if($nextStepsAllDone)
            <p class="flex items-center gap-2 text-sm text-[#135f41] mt-2.5">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-[#1f9d6b] flex-shrink-0">
                    <path d="M5 13l4 4 10-10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
                You're all set for this year. We'll remind you before your annual review.
            </p>
            @else
            <ul class="divide-y divide-[#eef2f6]">
                @foreach($nextSteps as $step)
                @php $nextStepDone = in_array($step['key'], $nextStepsDone, true); @endphp
                <li class="flex items-center gap-3 py-3">
                    <button type="button" wire:click="toggleNextStep('{{ $step['key'] }}')"
                        wire:target="toggleNextStep('{{ $step['key'] }}')"
                        aria-pressed="{{ $nextStepDone ? 'true' : 'false' }}"
                        aria-label="{{ $nextStepDone ? 'Mark as not done' : 'Mark as done' }}: {{ $step['title'] }}"
                        class="h-6 w-6 flex-shrink-0 rounded-full border-2 flex items-center justify-center transition-colors
                            {{ $nextStepDone ? 'bg-[#1f9d6b] border-[#1f9d6b] text-white' : 'border-[#cfdbe6] text-transparent hover:border-[#1f9d6b]' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none">
                            <path d="M5 13l4 4 10-10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                    </button>
                    <div class="flex-1 min-w-0">
                        <span
                            class="block text-sm font-bold {{ $nextStepDone ? 'text-[#5d6e7f] line-through decoration-[#9fb3c4]' : 'text-[#173045]' }}">{{
                            $step['title'] }}</span>
                        <span class="block text-xs text-[#5d6e7f] mt-0.5">{{ $step['desc'] }}</span>
                    </div>

                    @if($step['key'] === 'dl')
                    <button type="button" x-data="{ urls: @js($nextStepsReadyUrls) }"
                        x-on:click="urls.forEach(u => window.open(u, '_blank')); $wire.markNextStepDone('dl')"
                        class="flex-shrink-0 rounded border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-bold text-[#173045] hover:bg-[#f4f7fb] transition-colors">Download
                        all</button>
                    @elseif($step['key'] === 'share')
                    <button type="button"
                        x-on:click="navigator.clipboard.writeText(@js($nextStepsShareEmail)); $dispatch('toast', { message: 'Email copied. Paste it into a new message to your staff.', type: 'success' }); $wire.markNextStepDone('share')"
                        class="flex-shrink-0 rounded border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-bold text-[#173045] hover:bg-[#f4f7fb] transition-colors">Copy
                        email</button>
                    @elseif($step['key'] === 'sign')
                    <button type="button"
                        x-on:click="window.empowerDownloadText(@js(\Illuminate\Support\Str::slug($nextStepsPracticeName.' policy acknowledgment form').'.txt'), @js($nextStepsSignOffText)); $wire.markNextStepDone('sign')"
                        class="flex-shrink-0 rounded border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-bold text-[#173045] hover:bg-[#f4f7fb] transition-colors">Download
                        sign-off form</button>
                    @elseif($step['key'] === 'train')
                    <button type="button"
                        @if($nextStepsIsPro)
                        x-on:click="$dispatch('toast', { message: 'In the live portal this opens the Empower training platform.', type: 'success' }); $wire.markNextStepDone('train')"
                        @else
                        x-on:click="window.empowerDownloadText(@js(\Illuminate\Support\Str::slug($nextStepsPracticeName.' training checklist').'.txt'), @js($nextStepsTrainingText)); $wire.markNextStepDone('train')"
                        @endif
                        class="flex-shrink-0 rounded border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-bold text-[#173045] hover:bg-[#f4f7fb] transition-colors">{{
                        $step['action'] }}</button>
                    @elseif($step['key'] === 'review')
                    <button type="button"
                        x-on:click="window.empowerDownloadText('Annual_compliance_review.ics', @js($nextStepsIcsText), 'text/calendar'); $wire.markNextStepDone('review')"
                        class="flex-shrink-0 rounded border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-bold text-[#173045] hover:bg-[#f4f7fb] transition-colors">Add
                        to calendar</button>
                    @endif
                </li>
                @endforeach
            </ul>
            @endif
        </div>
        @endif

        {{-- Tabs --}}
        <div
            class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
            <div class="flex gap-1 border-b border-[#eef2f6] px-2">
                @foreach(['documents' => 'Documents', 'payments' => 'Payments', 'profile' => 'Practice Profile',
                'history' => 'History'] as $tabKey => $tabLabel)
                <button wire:click="$set('dashboardTab', '{{ $tabKey }}')"
                    wire:target="$set('dashboardTab', '{{ $tabKey }}')" wire:loading.attr="disabled"
                    wire:target="$set('dashboardTab', '{{ $tabKey }}')"
                    class="px-4 py-3 text-sm font-semibold border-b-2 -mb-px transition-colors {{ $dashboardTab === $tabKey ? 'border-[#12304f] text-[#12304f]' : 'border-transparent text-[#5d6e7f] hover:text-[#12304f]' }}">
                    <span wire:loading.remove wire:target="$set('dashboardTab', '{{ $tabKey }}')">{{ $tabLabel }}</span>
                    <span wire:loading wire:target="$set('dashboardTab', '{{ $tabKey }}')">
                        <x-spinner class="h-3.5 w-3.5" />
                    </span>
                </button>
                @endforeach
            </div>

            <div class="p-5 space-y-5">
                @if($dashboardTab === 'documents')
                @if($this->userOrders->count() > 1)
                <div class="flex flex-wrap gap-2">
                    @foreach($this->userOrders as $order)
                    <button type="button" wire:click="switchOrder({{ $order->id }})"
                        wire:target="switchOrder({{ $order->id }})" wire:loading.attr="disabled"
                        wire:target="switchOrder({{ $order->id }})"
                        class="inline-flex items-center gap-1 rounded-full px-3.5 py-1.5 text-xs font-bold transition-colors {{ $this->dashboardOrderId === $order->id ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted hover:border-navy/40' }}">
                        <span wire:loading.remove wire:target="switchOrder({{ $order->id }})">{{ $order->package?->name
                            }}</span>
                        <span wire:loading wire:target="switchOrder({{ $order->id }})">
                            <x-spinner class="h-3 w-3" />
                        </span>
                    </button>
                    @endforeach
                </div>
                @endif

                <div>
                    <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
                        @foreach($this->expectedDocuments as $row)
                        @php
                        $type = $row['type'];
                        $location = $row['location'];
                        $doc = $row['document'];
                        $sourceUpload = $row['sourceUpload'] ?? null;
                        $title = ($sourceUpload && isset(self::POLISHED_UPLOAD_DOCUMENT_TITLES[$sourceUpload->document_category]))
                        ? self::POLISHED_UPLOAD_DOCUMENT_TITLES[$sourceUpload->document_category]
                        : $type->label().($location ? ' — '.$location->name : '').($sourceUpload ? ' —
                        '.$sourceUpload->original_filename : '');
                        [$badgeClass, $badgeLabel] = match(true) {
                        ! $doc => ['bg-[#fff3cd] text-[#9a6700]', 'Generating'],
                        (bool) $doc->is_stale => ['bg-[#fde2e2] text-[#a53b3b]', 'Outdated'],
                        $doc->isReady() => ['bg-[#d7f3ea] text-[#117a51]', 'Current'],
                        $doc->wasRevoked() => ['bg-[#fde8cc] text-[#9a5b0f]', 'Updated'],
                        $doc->status === DocumentStatus::Failed => ['bg-[#fde2e2] text-[#a53b3b]', 'Failed'],
                        $doc->status === DocumentStatus::Completed => ['bg-[#edf2f7] text-[#5d6e7f]', 'Pending Review'],
                        default => ['bg-[#fff3cd] text-[#9a6700]', 'Generating'],
                        };
                        @endphp
                        <div class="flex items-center gap-3 px-4 py-3">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                class="text-[#1a7aad] flex-shrink-0">
                                <path d="M6 2h9l5 5v15H6V2z" stroke="currentColor" stroke-width="1.6"
                                    stroke-linejoin="round" />
                                <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            </svg>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-[#173045] truncate">{{ $title }}</p>
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[0.6rem] font-extrabold uppercase tracking-wider {{ $badgeClass }}">{{
                                        $badgeLabel }}</span>
                                </div>
                                @if($doc?->generated_at)
                                <p class="text-xs text-[#5d6e7f]">
                                    {{ $doc->is_stale ? 'Last generated' : 'Generated' }} {{
                                    $doc->generated_at->format('M j, Y')
                                    }}{{ $doc->is_stale ? ' — details changed since.' : ($doc->wasRevoked() ? ' — pulled
                                    back for
                                    changes, check back soon.' : '') }}
                                </p>
                                @else
                                <p class="text-xs text-[#5d6e7f]">We'll notify you once this is ready.</p>
                                @endif
                            </div>
                            <div class="flex gap-2 flex-shrink-0">
                                @if($doc?->is_stale)
                                <button wire:click="regenerateDocument({{ $doc->id }})"
                                    wire:confirm="Regenerate this document with your latest details?"
                                    wire:target="regenerateDocument({{ $doc->id }})" wire:loading.attr="disabled"
                                    wire:target="regenerateDocument({{ $doc->id }})"
                                    class="text-xs font-bold rounded bg-[#12304f] text-white px-3 py-1.5 hover:bg-[#0a2037] transition-colors">
                                    <span wire:loading.remove
                                        wire:target="regenerateDocument({{ $doc->id }})">Regenerate</span>
                                    <span wire:loading.inline-flex wire:target="regenerateDocument({{ $doc->id }})"
                                        class="inline-flex items-center gap-1.5">
                                        <x-spinner class="h-3.5 w-3.5" /> Regenerating…
                                    </span>
                                </button>
                                @elseif($doc?->isReady() && $doc->delivery_source ===
                                \App\Enums\DocumentDeliverySource::Custom)
                                <a href="{{ route('documents.download', $doc) }}"
                                    class="text-xs font-bold rounded border border-[#dbe4ee] text-[#173045] px-3 py-1.5 hover:bg-[#f4f7fb] transition-colors">
                                    Download
                                </a>
                                @elseif($doc?->isReady() && $doc->pdf_storage_path)
                                <a href="{{ route('documents.download', $doc) }}"
                                    class="text-xs font-bold rounded border border-[#dbe4ee] text-[#173045] px-3 py-1.5 hover:bg-[#f4f7fb] transition-colors">
                                    Download
                                </a>
                                @elseif($doc?->isReady() && $doc->docx_storage_path)
                                <a href="{{ route('documents.download', $doc) }}?format=docx"
                                    class="text-xs font-bold rounded border border-[#dbe4ee] text-[#173045] px-3 py-1.5 hover:bg-[#f4f7fb] transition-colors">
                                    Download
                                </a>
                                @endif
                            </div>
                        </div>
                        @endforeach

                        @if($this->primarySubmission)
                        <div class="flex items-center gap-3 px-4 py-3">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                class="text-[#8592a1] flex-shrink-0">
                                <path d="M6 2h9l5 5v15H6V2z" stroke="currentColor" stroke-width="1.6"
                                    stroke-linejoin="round" />
                                <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            </svg>
                            <p class="flex-1 text-sm font-semibold text-[#173045]">Your intake answers</p>
                            <a href="{{ route('intake-submissions.answers', $this->primarySubmission) }}"
                                class="text-xs font-bold rounded border border-[#dbe4ee] text-[#173045] px-3 py-1.5 hover:bg-[#f4f7fb] transition-colors flex-shrink-0">Download</a>
                        </div>
                        @endif
                    </div>

                    <p class="text-xs text-[#5d6e7f] mt-2">For any queries, <a
                            href="{{ route('contact', ['package' => $this->currentOrder->package?->slug]) }}"
                            wire:navigate class="font-semibold text-[#1a7aad] hover:underline">contact us</a>.</p>
                </div>
                @elseif($dashboardTab === 'payments')
                <div>
                    <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
                        @forelse($this->userOrders as $order)
                        @php $amountPaid = (float) $order->amount_paid; @endphp
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <p class="text-sm font-semibold text-[#173045]">
                                {{ $order->paid_at?->format('M j, Y') }} &middot; Initial payment{{
                                $order->card_last_four ? " (card ending {$order->card_last_four})" : '' }}
                            </p>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="text-sm font-semibold text-[#173045]">${{ number_format($amountPaid,
                                    $amountPaid == floor($amountPaid) ? 0 : 2) }}</span>
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[0.6rem] font-extrabold uppercase tracking-wider bg-[#d7f3ea] text-[#117a51]">Paid</span>
                                <a href="{{ route('orders.receipt', $order) }}" target="_blank"
                                    class="text-xs font-bold text-[#1a7aad] hover:underline">View Receipt</a>
                            </div>
                        </div>
                        @if($order->next_bill_date && $order->payment_status === PaymentStatus::Paid)
                        @php $nextAmount = (float) ($order->original_price ?? $order->amount_paid); @endphp
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <p class="text-sm font-semibold text-[#173045]">
                                {{ $order->next_bill_date->format('M j, Y') }} &middot; Next {{
                                $order->billing_cycle?->period() ?? 'monthly' }} charge
                            </p>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="text-sm font-semibold text-[#173045]">${{ number_format($nextAmount,
                                    $nextAmount == floor($nextAmount) ? 0 : 2) }}</span>
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[0.6rem] font-extrabold uppercase tracking-wider bg-[#edf2f7] text-[#5d6e7f]">Scheduled</span>
                            </div>
                        </div>
                        @endif
                        @empty
                        <p class="text-sm text-[#5d6e7f] italic px-4 py-3">No purchases yet.</p>
                        @endforelse
                    </div>

                    <p class="text-xs text-[#5d6e7f] mt-3">Want another compliance package for this practice? <a
                            href="{{ route('home') }}#pricing" class="font-semibold text-[#1a7aad] hover:underline">View
                            all packages &rarr;</a></p>
                </div>
                @elseif($dashboardTab === 'profile')
                <div>
                    <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <span class="text-sm text-[#5d6e7f]">Practice</span>
                            <span class="text-sm font-semibold text-[#173045] text-right">{{ $this->practice?->name ?:
                                '—' }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <span class="text-sm text-[#5d6e7f]">Specialty</span>
                            <span class="text-sm font-semibold text-[#173045] text-right">{{ $this->practice?->specialty
                                ?: '—' }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <span class="text-sm text-[#5d6e7f]">Billable providers</span>
                            <span class="text-sm font-semibold text-[#173045] text-right">{{
                                $this->practice?->billable_providers_count ?? '—' }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <span class="text-sm text-[#5d6e7f]">Address</span>
                            <span class="text-sm font-semibold text-[#173045] text-right">{{ $this->practice?->address
                                ?: '—' }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <span class="text-sm text-[#5d6e7f]">Account</span>
                            <span class="text-sm font-semibold text-[#173045] text-right">{{ auth()->user()->name }}
                                &middot; {{ auth()->user()->email }}</span>
                        </div>
                    </div>
                    <div class="flex justify-end mt-4">
                        <button wire:click="editProfile" wire:target="editProfile" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                            <span wire:loading.remove wire:target="editProfile">Edit intake answers</span>
                            <span wire:loading.inline-flex wire:target="editProfile"
                                class="inline-flex items-center gap-1.5">
                                <x-spinner class="h-3.5 w-3.5" /> Loading…
                            </span>
                        </button>
                    </div>
                </div>
                @else
                <div>
                    <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
                        @forelse($this->activityLog as $log)
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <p class="text-sm font-semibold text-[#173045]">{{ $log->description }}</p>
                            <p class="text-xs text-[#5d6e7f] flex-shrink-0">{{ $log->created_at->format('M j, g:ia') }}
                            </p>
                        </div>
                        @empty
                        <p class="text-sm text-[#5d6e7f] italic px-4 py-3">No activity yet.</p>
                        @endforelse
                    </div>
                </div>
                @endif
            </div>
        </div>

        <div class="flex justify-start">
            <button wire:click="goToStep(4)" wire:target="goToStep(4)" wire:loading.attr="disabled"
                wire:target="goToStep(4)"
                class="rounded border border-[#dbe4ee] px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                <span wire:loading.remove wire:target="goToStep(4)">Back to Review</span>
                <span wire:loading.inline-flex wire:target="goToStep(4)" class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Loading…
                </span>
            </button>
        </div>
    </div>

    {{-- Shared "Cancel subscription" confirmation modal — one Alpine scope wraps the whole
    dashboard step since the trigger buttons live in three different conditional banners
    (trial/past-due/active) that are siblings of each other, not nested. Kept outside the
    space-y-4 div above so it doesn't pick up sibling spacing while position:fixed. --}}
    <div x-show="confirmCancelOrderId !== null" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6"
            x-on:click.outside="confirmCancelOrderId = null">
            <h3 class="text-base font-semibold text-[#12304f] mb-2">Cancel your subscription?</h3>
            <p class="text-sm text-[#5d6e7f] mb-5" x-text="confirmCancelMessage"></p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmCancelOrderId = null"
                    class="rounded-lg border border-[#dbe4ee] px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                    Keep Subscription
                </button>
                <button type="button"
                    x-on:click="$wire.cancelSubscription(confirmCancelOrderId).then(() => confirmCancelOrderId = null).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                    wire:target="cancelSubscription"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 transition-colors">
                    <span wire:loading.remove wire:target="cancelSubscription">Cancel Subscription</span>
                    <span wire:loading.inline-flex wire:target="cancelSubscription"
                        class="inline-flex items-center gap-1.5">
                        <x-spinner class="h-3.5 w-3.5" /> Cancelling…
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

    </div>
</div>