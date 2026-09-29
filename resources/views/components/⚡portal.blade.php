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
use App\Mail\AdminIntakeSubmittedMail;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\ClientPaymentReceiptMail;
use App\Mail\ClientTrialStartedMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\ActivityLog;
use App\Models\DiscountCode;
use App\Models\GeneratedDocument;
use App\Models\IntakeAnswer;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Practice;
use App\Models\User;
use App\Services\CloverChargeService;
use App\Services\EmpowerPaymentApiClient;
use App\Services\TrialBillingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
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

    public string $discountCodeInput = '';

    public ?int $appliedDiscountCodeId = null;

    public string $billingCycle = 'annual';

    public string $accountName = '';

    public string $accountEmail = '';

    // Set only when pay()/payFreeTrial() creates a guest account in the current request, so the
    // Step 1 success banner can announce it once. Deliberately not restored by mount(), so it
    // clears itself on the next page load instead of persisting for the life of the account.
    public ?string $newAccountEmail = null;

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

        $price = $this->selectedPackage->priceForCycle($this->currentBillingCycle()) ?? 0.0;

        return round($price * $this->appliedDiscountCode->percentage / 100, 2);
    }

    #[Computed]
    public function discountedTotal(): float
    {
        $price = $this->selectedPackage?->priceForCycle($this->currentBillingCycle()) ?? 0.0;

        return max(0, $price - $this->discountAmount);
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

        return Order::with(['package', 'intakeSubmission.intakeUploads'])
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
    // <livewire:portal.practice-intake-wizard>'s DOCUMENT_CATEGORIES; kept read-only here since
    // Step 3 only reviews what was captured on Step 2, edited via the "Edit" links back to it).
    private const REVIEW_DOCUMENT_CATEGORIES = [
        'compliance_ethics' => 'Compliance & Ethics Program',
        'hipaa_privacy' => 'HIPAA Privacy policies',
        'hipaa_security' => 'HIPAA Security policies',
        'training_materials' => 'Training materials',
    ];

    private const BASICS_SUB_SCREENS = ['b_profile', 'b_providers', 'b_address', 'b_logo'];

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
        return self::REVIEW_DOCUMENT_CATEGORIES;
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
            ],
            [
                'label' => 'How many billable providers do you have?',
                'value' => $providers.' billable provider'.($providers === 1 ? '' : 's'),
                'done' => in_array('b_providers', $reached, true),
            ],
            [
                'label' => 'Where is your practice located?',
                'value' => $practice?->address ?: '—',
                'done' => in_array('b_address', $reached, true),
            ],
            [
                'label' => 'Add your practice logo',
                'value' => $practice?->logo_path ? 'Logo uploaded' : 'No logo added',
                'done' => in_array('b_logo', $reached, true),
            ],
        ];
    }

    /** @return array<int, array{label: string, value: string, done: bool}> */
    private function teamDetailRows(): array
    {
        $practice = $this->practice;

        $officers = [
            ['Compliance Officer', $practice?->compliance_officer_name],
            ['HIPAA Privacy Officer', $practice?->hipaa_privacy_officer_name],
            ['HIPAA Security Officer', $practice?->hipaa_security_officer_name],
            ['Release of Information Officer', $practice?->release_of_info_officer_name],
            ['IT Vendor', $practice?->it_vendor_name],
        ];

        return collect($officers)
            ->map(fn ($o) => ['label' => $o[0], 'value' => $o[1] ?: '—', 'done' => filled($o[1])])
            ->all();
    }

    /** @return array<int, array{label: string, value: string, done: bool}> */
    private function sectionDetailRows(IntakeSection $section, array $answersByQuestionId): array
    {
        return $section->questions->map(function (IntakeQuestion $question) use ($answersByQuestionId) {
            $answer = $answersByQuestionId[$question->id] ?? null;
            $value = match (true) {
                $answer === null => 'Not yet answered',
                (bool) $answer->has_documented_process => Str::limit((string) $answer->response, 80),
                default => 'No documented process — best-practice language used.',
            };

            return ['label' => $question->title, 'value' => $value, 'done' => $answer !== null];
        })->all();
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
            'done' => in_array('team', $reached, true) ? 1 : 0,
            'total' => 1,
            'details' => $this->teamDetailRows(),
        ];

        $answersByQuestionId = $this->primarySubmission?->intakeAnswers()->get()->keyBy('intake_question_id')->all() ?? [];

        foreach (IntakeSection::with('questions')->orderBy('sort_order')->get() as $section) {
            $questionIds = $section->questions->pluck('id')->all();
            $rows[] = [
                'label' => $section->label,
                'done' => count(array_intersect($questionIds, array_keys($answersByQuestionId))),
                'total' => count($questionIds),
                'details' => $this->sectionDetailRows($section, $answersByQuestionId),
            ];
        }

        return $rows;
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
        unset($this->completedMilestone, $this->batchOrders);
        $this->step = 3;
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
        $originalAmount = (float) $packages->sum(fn (Package $p) => $p->priceForCycle($cycle) ?? 0.0);
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

            $this->addError('payment', $chargeResult->declineMessage ?? 'Your card was declined. Please check your details and try again.');

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

        $batchId = (string) Str::ulid();
        $orderIds = [];

        foreach ($packages as $package) {
            $packagePrice = $package->priceForCycle($cycle) ?? 0.0;
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

            User::where('role', UserRole::Admin)->pluck('email')->each(
                function (string $adminEmail) use ($order) {
                    try {
                        Mail::to($adminEmail)->queue(new AdminPaymentReceivedMail($order));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            );

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

            $this->addError('payment', 'We could not save your card. Please check your details and try again.');

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

        $billingAddress = [
            'name' => $cardName,
            'address1' => $this->billingAddress1,
            'city' => $this->billingCity,
            'state' => $this->billingState,
            'zip' => $this->billingZip,
        ];

        $order = Order::create([
            'user_id' => auth()->id(),
            'package_id' => $package->id,
            'checkout_batch_id' => (string) Str::ulid(),
            'status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Trialing,
            'billing_address' => $billingAddress,
            'billing_cycle' => $cycle,
            'amount_paid' => 0,
            'original_price' => $package->priceForCycle($cycle) ?? 0.0,
            'discount_amount' => $package->priceForCycle($cycle) ?? 0.0,
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

        $primarySubmission->setRelation('order', $primaryOrder);

        User::where('role', UserRole::Admin)->pluck('email')->each(
            function (string $adminEmail) use ($primarySubmission) {
                try {
                    Mail::to($adminEmail)->send(new AdminIntakeSubmittedMail($primarySubmission));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        );

        unset($this->intakeSubmission, $this->currentOrder, $this->completedMilestone, $this->batchOrders, $this->rejectedSubmission, $this->primarySubmission);
        $this->step = 4;
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

    #[On('osha-location-saved')]
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

<div class="space-y-4">

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
        : $heroPackages->sum(fn ($p) => $p->priceForCycle($heroCycle) ?? 0.0);
    @endphp
    <div class="rounded-[1.25rem] p-4 sm:p-4"
        style="background: radial-gradient(circle at top right, rgba(118,200,192,0.2), transparent 32%), linear-gradient(145deg, #12304f 0%, #1c416a 100%);">
        <div class="flex flex-col lg:flex-row lg:items-center gap-5">
            <div class="flex-1">
                <span
                    class="inline-flex items-center rounded-full px-3 py-1 text-[0.7rem] font-extrabold tracking-[0.08em] uppercase bg-accent/16 text-[#dff7f3] mb-2">Portal
                    preview</span>
                <h1 class="text-xl sm:text-2xl font-bold text-white mb-1">
                    @if($heroPackages->isEmpty())
                    Choose a package
                    @elseif($heroPackages->count() === 1)
                    Selected package: {{ $heroPackages->first()->name }}
                    @else
                    {{ $heroPackages->count() }} packages selected
                    @endif
                </h1>
                <p class="text-white/60 text-sm">Payment, practice intake, review, and document generation.</p>
                <p class="text-white text-sm mt-1.5">Need help? <a href="mailto:support@empowerhci.com" class="font-bold text-white underline hover:text-[#dff7f3]">support@empowerhci.com</a></p>
            </div>
            @if($heroPackages->isNotEmpty())
            <div class="bg-white/92 rounded-[1.25rem] p-4 min-w-48">
                <div class="text-empower-muted text-xs uppercase tracking-wider font-semibold mb-1">Summary</div>
                <div class="text-xl font-extrabold text-navy mb-0.5">${{ number_format($heroTotal, $heroTotal ==
                    floor($heroTotal) ? 0 : 2) }}</div>
                <div class="text-empower-muted text-xs">per provider / {{ $heroCycle->period() }}</div>
                <div class="text-empower-muted text-xs mt-1">
                    {{ $heroPackages->pluck('name')->implode(' + ') }}
                </div>
            </div>
            @endif
        </div>
    </div>

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
    <div class="space-y-3">
        @if($milestone >= 1)
        <div
            class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
            <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1</p>
            <h2 class="text-lg font-semibold text-navy mb-1">Selected Package</h2>
            <p class="text-sm text-empower-muted">
                Complete payment first to unlock your practice intake form. Your documents are generated automatically
                once intake is submitted and reviewed.
            </p>
            <p class="text-sm text-empower-muted mt-1 mb-2">Your final invoice reflects the provider count you confirm
                during intake in the next step.</p>

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
                    <span class="text-sm font-semibold text-[#0f7a4f]">Discount ({{ $order->discount_code }})</span>
                    <span class="text-sm font-semibold text-[#0f7a4f]">-${{ number_format((float) $order->discount_amount, 2) }}</span>
                </div>
                @else
                <p class="text-sm text-empower-muted">No discount applied</p>
                @endif
            </div>

            <div class="flex items-center justify-between pt-2 {{ !$loop->last ? 'border-b border-[#eef2f6] mb-2 pb-2.5' : '' }}">
                <span class="text-sm font-semibold text-[#173045]">{{ $orderIsTrial ? 'Due Today' : 'Paid' }}</span>
                <span class="text-lg font-extrabold text-navy">${{ number_format($orderPaid, $orderPaid ==
                    floor($orderPaid) ? 0 : 2) }}</span>
            </div>
            @endforeach
        </div>

        @php
            $firstBatchOrder = $this->batchOrders->first();
            $isTrialBatch = $firstBatchOrder?->payment_status === PaymentStatus::Trialing;
            $totalPaidAmount = (float) $this->batchOrders->sum('amount_paid');
            $cardEnding = $firstBatchOrder?->card_last_four;
        @endphp
        <div
            class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
            <div class="flex items-center gap-3 rounded-xl bg-[#eef8f3] border border-[#bfe3d2] px-3.5 py-2.5">
                <span class="text-[#117a51]">&#10003;</span>
                <p class="text-sm font-semibold text-[#0f7a4f]">
                    @if($isTrialBatch)
                        Your free trial has started{{ $firstBatchOrder?->paid_at ? ' on '.$firstBatchOrder->paid_at->format('M j, Y') : '' }}{{ $cardEnding ? " (card ending {$cardEnding})" : '' }}.
                    @else
                        Payment of ${{ number_format($totalPaidAmount, $totalPaidAmount == floor($totalPaidAmount) ? 0 : 2) }} received{{ $firstBatchOrder?->paid_at ? ' on '.$firstBatchOrder->paid_at->format('M j, Y') : '' }}{{ $cardEnding ? " (card ending {$cardEnding})" : '' }}.
                    @endif
                    @if($newAccountEmail)
                        Account created for {{ $newAccountEmail }}; a login password would be emailed there.
                    @endif
                </p>
            </div>
        </div>
        @else
        <div
            class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
            <p class="text-xs font-extrabold uppercase tracking-widest text-empower-muted mb-1">Step 1</p>
            <h2 class="text-lg font-semibold text-navy mb-1">Selected Package</h2>
            <p class="text-sm text-empower-muted">
                Complete payment first to unlock your practice intake form. Your documents are generated automatically
                once intake is submitted and reviewed.
            </p>
            <p class="text-sm text-empower-muted mt-1 mb-2">Your final invoice reflects the provider count you confirm
                during intake in the next step.</p>
            @if(! $this->selectedPackage)
            <p class="text-sm text-empower-muted italic mb-2">No package selected.</p>
            <a href="{{ route('home') }}#pricing" class="text-xs font-bold text-[#1a7aad] hover:underline">Browse
                packages &rarr;</a>
            @else
            <div class="flex items-center justify-between gap-3 py-2.5 border-b border-[#eef2f6] mb-2">
                <div>
                    <p class="text-sm font-semibold text-[#173045]">{{ $this->selectedPackage->name }}</p>
                    @php $displayPrice = $this->selectedPackage->priceForCycle($this->currentBillingCycle()) ?? 0.0; @endphp
                    <p class="text-xs text-empower-muted">${{ number_format($displayPrice, $displayPrice ==
                        floor($displayPrice) ? 0 : 2) }} /
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
                    <span class="text-sm font-semibold text-[#0f7a4f]">Free Trial ({{ $this->appliedDiscountCode->code
                        }}) — {{ $this->appliedDiscountCode->trial_days }} days</span>
                    <button type="button" wire:click="removeDiscountCode"
                        class="text-xs text-empower-muted hover:underline">Remove</button>
                </div>
                @else
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold text-[#0f7a4f]">Discount ({{ $this->appliedDiscountCode->code
                        }})</span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-[#0f7a4f]">-${{ number_format($this->discountAmount, 2)
                            }}</span>
                        <button type="button" wire:click="removeDiscountCode"
                            class="text-xs text-empower-muted hover:underline">Remove</button>
                    </div>
                </div>
                @endif
            </div>

            @if($this->isFreeTrialCheckout)
            <div class="flex items-center justify-between pt-2 border-t border-[#eef2f6]">
                <span class="text-sm font-semibold text-[#173045]">Due Today</span>
                <span class="text-lg font-extrabold text-navy">$0.00</span>
            </div>
            <p class="text-xs text-empower-muted mt-1">Then ${{ number_format($this->selectedPackage->priceForCycle($this->currentBillingCycle()) ?? 0.0, 2) }}/{{ $this->currentBillingCycle()->period() }} once your free trial ends, unless you cancel first.</p>
            @else
            <div class="flex items-center justify-between pt-2 border-t border-[#eef2f6]">
                <span class="text-sm font-semibold text-[#173045]">Total</span>
                <span class="text-lg font-extrabold text-navy">${{ number_format($this->discountedTotal, 2)
                    }}</span>
            </div>
            @endif
            @endif
        </div>

        @auth
        @else
        <div
            class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
            <h3 class="text-sm font-semibold text-navy mb-1">Account Information</h3>
            <p class="text-xs text-empower-muted mb-3">Create the account that will manage this practice's Empower
                portal.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Your name <span
                            class="text-red-500">*</span></label>
                    <input wire:model.live="accountName" type="text" placeholder="Jane Provider"
                        class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @error('accountName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Email address <span
                            class="text-red-500">*</span></label>
                    <input wire:model.live="accountEmail" type="email" placeholder="jane@practice.com"
                        class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @error('accountEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-empower-muted mt-2">We'll email you a secure, auto-generated password to log in with.
            </p>
        </div>
        @endauth

        <div x-data="{
                cardNameValid: false, cardNumberValid: false, cardExpiryError: '', cardCvcValid: false, showTerms: false, termsAccepted: false,
            }"
            class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-4">
            <h3 class="text-sm font-semibold text-navy mb-1">Payment Details</h3>
            <p class="text-xs text-empower-muted mb-3">Your card is charged securely — these fields are never saved or
                logged by this form.</p>
            @error('payment') <p
                class="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs font-semibold text-red-700">{{
                $message }}</p> @enderror
            @error('termsAccepted') <p x-show="!termsAccepted"
                class="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs font-semibold text-red-700">{{
                $message }}</p> @enderror
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Name on card <span
                            class="text-red-500">*</span></label>
                    <input x-ref="cardName" type="text" placeholder="Jane Provider"
                        x-on:input="cardNameValid = $el.value.trim().length > 0"
                        class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @error('cardName') <p x-show="!cardNameValid" class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Card number <span
                            class="text-red-500">*</span></label>
                    <input x-ref="cardNumber" type="text" placeholder="4242424242424242" inputmode="numeric"
                        maxlength="16"
                        x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '').slice(0, 16); cardNumberValid = $el.value.length === 16"
                        class="w-full rounded-xl border border-empower-border bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @error('cardNumber') <p x-show="!cardNumberValid" class="mt-1 text-xs text-red-600">{{ $message }}
                    </p> @enderror
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
                    @error('cardExpiry') <p x-show="!cardExpiryError" class="mt-1 text-xs text-red-600">{{ $message }}
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
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Billing address <span
                            class="text-red-500">*</span></label>
                    <input wire:model.live="billingAddress1" type="text" placeholder="7 Clyde Road"
                        class="w-full rounded-xl border {{ $errors->has('billingAddress1') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @error('billingAddress1') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2 grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">City <span
                                class="text-red-500">*</span></label>
                        <input wire:model.live="billingCity" type="text" placeholder="Somerset"
                            class="w-full rounded-xl border {{ $errors->has('billingCity') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        @error('billingCity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">State <span
                                class="text-red-500">*</span></label>
                        <input wire:model.live="billingState" type="text" placeholder="NJ or New Jersey" maxlength="50"
                            class="w-full rounded-xl border {{ $errors->has('billingState') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        @error('billingState') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Zip <span
                                class="text-red-500">*</span></label>
                        <input wire:model.live="billingZip" type="text" placeholder="08873" inputmode="numeric"
                            maxlength="10"
                            class="w-full rounded-xl border {{ $errors->has('billingZip') ? 'border-red-400' : 'border-empower-border' }} bg-[#f8fbfd] px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        @error('billingZip') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="mt-3 flex justify-end">
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
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
                <div class="w-full max-w-md bg-white rounded-[1.25rem] shadow-xl p-6"
                    x-on:click.outside="showTerms = false">
                    <h3 class="text-base font-semibold text-navy mb-2">Review &amp; Accept Terms &amp; Conditions</h3>
                    <p class="text-sm text-empower-muted mb-4">Before we process your payment, please confirm you agree
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
                            x-on:click="$wire.payFreeTrial($refs.cardName.value, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value, termsAccepted).finally(() => showTerms = false)"
                            :disabled="!termsAccepted"
                            :class="!termsAccepted ? 'opacity-50 cursor-not-allowed' : 'hover:bg-accent-dark'"
                            class="inline-flex items-center gap-1 rounded bg-accent px-5 py-2 text-sm font-bold text-navy-dark transition-colors"
                            wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                            wire:target="payFreeTrial">
                            <span wire:loading.remove wire:target="payFreeTrial">I Agree — Start Free Trial &rarr;</span>
                            <span wire:loading.inline-flex wire:target="payFreeTrial" class="inline-flex items-center gap-1.5">
                                <x-spinner class="h-3.5 w-3.5" /> Processing…
                            </span>
                        </button>
                        @else
                        <button type="button" wire:key="terms-confirm-pay"
                            x-on:click="$wire.pay($refs.cardName.value, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value, termsAccepted).finally(() => showTerms = false)"
                            :disabled="!termsAccepted"
                            :class="!termsAccepted ? 'opacity-50 cursor-not-allowed' : 'hover:bg-accent-dark'"
                            class="inline-flex items-center gap-1 rounded bg-accent px-5 py-2 text-sm font-bold text-navy-dark transition-colors"
                            wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                            wire:target="pay">
                            <span wire:loading.remove wire:target="pay">I Agree — Pay &rarr;</span>
                            <span wire:loading.inline-flex wire:target="pay" class="inline-flex items-center gap-1.5">
                                <x-spinner class="h-3.5 w-3.5" /> Processing…
                            </span>
                        </button>
                        @endif
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
    </div>
    @endif

    {{-- ── Step 2: Practice Intake ── --}}
    @if($step === 2)
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
                <input wire:model.live="practiceName" type="text" placeholder="Riverside Family Medicine" {{
                    $this->practice?->is_profile_locked ? 'disabled' : '' }}
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
    <livewire:portal.practice-intake-wizard :orderIds="$orderIds" :key="'intake-wizard-'.implode('-', $orderIds)" />
    @endif

    <livewire:portal.osha-location-modal :practiceId="$this->practice?->id ?? 0" />
    @endif

    {{-- ── Step 3: Upload & Confirm ── --}}
    @if($step === 3)
    @php
    $primarySub = $this->primarySubmission;
    $primaryOrder = $this->batchOrders->firstWhere('id', min($this->orderIds ?: [0]));
    $includesWorkflowQuestionnaire = $this->batchOrders->contains(fn ($o) => $o->package?->includesWorkflowQuestionnaire());
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
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Basics</p>
                <p class="text-base font-bold text-[#173045]">{{ $this->basicsCompletedCount }} / 4</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Package</p>
                <p class="text-base font-bold text-[#173045]">{{ $primaryOrder?->package ? ucfirst($primaryOrder->package->tier()->value) : '—' }}</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Status</p>
                <p class="text-base font-bold text-[#173045]">{{ $this->intakeSubmissionStatusLabel($primarySub?->status) }}</p>
            </div>
        </div>

        {{-- Documents --}}
        <div class="border-t border-[#eef2f6] pt-5 mb-5">
            <h3 class="text-base font-semibold text-[#12304f] mb-1">Documents</h3>
            <p class="text-sm text-[#5d6e7f] mb-3">Your existing documents for review and update.</p>

            <ul class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl mb-3">
                @foreach($this->reviewDocumentCategories as $key => $label)
                @php
                    $status = $this->reviewDocumentCategoryStatus($key);
                @endphp
                <li class="flex items-center justify-between gap-3 px-4 py-3">
                    <span class="text-sm font-semibold text-[#173045]">{{ $label }}</span>
                    <span
                        class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold tracking-wide uppercase
                        {{ $status === 'uploaded' ? 'bg-[#d7f3ea] text-[#117a51]' : ($status === 'declined' ? 'bg-[#eef1f5] text-[#5d6e7f]' : 'bg-[#fdf3e0] text-[#a3690f]') }}">
                        {{ $status === 'uploaded' ? 'Uploaded' : ($status === 'declined' ? "Don't have it" : 'Needed') }}
                    </span>
                </li>
                @endforeach
            </ul>

            @if(! $includesWorkflowQuestionnaire && collect($this->reviewDocumentCategories)->keys()->contains(fn ($key) => $this->reviewDocumentCategoryStatus($key) === 'declined'))
            <div class="rounded-xl bg-[#fdf3e0] px-3.5 py-2.5 mb-3 text-xs text-[#8a5a0f] leading-relaxed">
                Missing a document? Essential updates what you have. <strong class="font-bold">Professional</strong>
                creates missing documents for you.
                <a href="{{ route('home') }}#pricing" class="font-bold underline hover:no-underline">Compare
                    packages</a>
            </div>
            @endif

            @if($primarySub?->intakeUploads->isNotEmpty())
            <ul class="space-y-2">
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
            <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
                @foreach($this->answerSummaryRows as $row)
                <div @if(! empty($row['details'])) x-data="{ open: false }" @endif>
                    <button type="button" @if(! empty($row['details'])) x-on:click="open = !open" @endif
                        @if(empty($row['details'])) disabled @endif
                        class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left transition-colors {{ ! empty($row['details']) ? 'cursor-pointer hover:bg-[#f8fbfd]' : 'cursor-default' }}">
                        <span class="text-sm font-semibold text-[#173045] flex items-center gap-1.5">
                            {{ $row['label'] }}
                            @if(! empty($row['details']))
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" x-bind:class="open ? 'rotate-180' : ''"
                                class="text-[#8592a1] transition-transform">
                                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            @endif
                        </span>
                        <span class="text-xs text-[#5d6e7f] flex-shrink-0">{{ $row['done'] }}/{{ $row['total'] }} {{ $row['done'] === $row['total'] ? '✓' : '' }}</span>
                    </button>
                    @if(! empty($row['details']))
                    <div x-show="open" x-cloak x-transition class="px-4 pb-3 space-y-2.5">
                        @foreach($row['details'] as $detail)
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold text-[#173045]">{{ $detail['label'] }}</p>
                                <p class="text-xs text-[#5d6e7f]">{{ $detail['value'] }}</p>
                            </div>
                            <span
                                class="flex-shrink-0 rounded-full px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide {{ $detail['done'] ? 'bg-[#d7f3ea] text-[#117a51]' : 'bg-[#eef1f5] text-[#5d6e7f]' }}">
                                {{ $detail['done'] ? 'Done' : 'Pending' }}
                            </span>
                        </div>
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
                    <input wire:model="certifiedByName" type="text" {{ $isSubmitted ? 'disabled' : '' }}
                        class="w-full rounded-xl border {{ $errors->has('certifiedByName') ? 'border-red-400' : 'border-[#dbe4ee]' }} {{ $isSubmitted ? 'bg-[#f0f4f8] cursor-not-allowed' : 'bg-[#f8fbfd]' }} px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error('certifiedByName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Title <span
                            class="text-red-500">*</span></label>
                    <input wire:model="certifiedByTitle" type="text" {{ $isSubmitted ? 'disabled' : '' }}
                        class="w-full rounded-xl border {{ $errors->has('certifiedByTitle') ? 'border-red-400' : 'border-[#dbe4ee]' }} {{ $isSubmitted ? 'bg-[#f0f4f8] cursor-not-allowed' : 'bg-[#f8fbfd]' }} px-4 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
                    @error('certifiedByTitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#31465b] mb-1.5">Signature (type your full name)
                        <span class="text-red-500">*</span></label>
                    <input wire:model="certifiedSignature" type="text" placeholder="Type your full name to sign"
                        {{ $isSubmitted ? 'disabled' : '' }}
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
                <span class="text-sm text-[#173045]">I certify these responses are accurate for {{ $practiceLabel }}.</span>
            </label>
            @error('certifyChecked') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('payment') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="flex justify-between mt-3">
                <button wire:click="goToStep(2)" wire:target="goToStep(2)" wire:loading.attr="disabled"
                    class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                    <span wire:loading.remove wire:target="goToStep(2)">&larr; Back to intake</span>
                    <span wire:loading wire:target="goToStep(2)"><x-spinner class="h-3.5 w-3.5" /></span>
                </button>
                @if($isSubmitted)
                <button wire:click="goToStep(4)" wire:target="goToStep(4)" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                    <span wire:loading.remove wire:target="goToStep(4)">Continue &rarr;</span>
                    <span wire:loading wire:target="goToStep(4)"><x-spinner class="h-3.5 w-3.5" /></span>
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
    @endif

    {{-- ── Step 4: Review ── --}}
    @if($step === 4)
    <div wire:poll.5s="checkApproval"
        class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <p class="text-xs font-extrabold uppercase tracking-widest text-[#5d6e7f] mb-1">Step 4</p>
        <h2 class="text-lg font-semibold text-[#12304f] mb-1">Review</h2>
        <p class="text-sm text-[#5d6e7f] mb-5">Your submission moves through these states until admin approval or
            requested changes.</p>

        <div class="space-y-6">
            @forelse($this->batchOrders as $order)
            @php
                $status = $order->intakeSubmission?->status;
                $isRejected = $status === IntakeSubmissionStatus::Rejected;
                $doneCount = match (true) {
                    $status === IntakeSubmissionStatus::Approved => 4,
                    $isRejected, $status === IntakeSubmissionStatus::UnderReview => 3,
                    $status === IntakeSubmissionStatus::Submitted => 2,
                    default => 0,
                };
                $milestones = [
                    ['label' => 'Submitted', 'desc' => 'Intake received and queued.'],
                    ['label' => 'AI extraction', 'desc' => 'Structured data pulled from your files and answers.'],
                    ['label' => 'Under review', 'desc' => 'An Empower admin is reviewing your submission.'],
                    $isRejected
                        ? ['label' => 'Changes requested', 'desc' => $order->intakeSubmission?->reviewer_notes ?: 'Please review and resubmit.']
                        : ['label' => 'Approved', 'desc' => 'Documents generated and delivered to your dashboard.'],
                ];
            @endphp
            <div>
                <p class="text-sm font-semibold text-[#12304f] mb-3">{{ $order->package?->name }}</p>

                @foreach($milestones as $mi => $m)
                @php
                    $isDone = $mi < $doneCount;
                    $isLast = $mi === count($milestones) - 1;
                    $isRejectedStep = $isRejected && $mi === 3;
                @endphp
                <div class="flex gap-3">
                    <div class="flex flex-col items-center flex-shrink-0">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0
                            {{ $isRejectedStep ? 'bg-[#fee2e2] text-[#9f1239]' : ($isDone ? 'bg-[#0b9ed0] text-white' : 'bg-[#edf2f7] text-[#5d6e7f]') }}">
                            @if($isRejectedStep)
                                &times;
                            @elseif($isDone)
                                &#10003;
                            @else
                                {{ $mi + 1 }}
                            @endif
                        </div>
                        @if(! $isLast)
                        <div class="w-0.5 flex-1 my-0.5 {{ $mi < $doneCount - 1 ? 'bg-[#0b9ed0]' : 'bg-[#edf2f7]' }}"
                            style="min-height: 1.5rem"></div>
                        @endif
                    </div>
                    <div class="pb-4">
                        <p class="text-sm font-semibold {{ $isRejectedStep ? 'text-[#9f1239]' : 'text-[#173045]' }}">
                            {{ $m['label'] }}</p>
                        <p class="text-xs {{ $isRejectedStep ? 'text-[#9f1239]' : 'text-[#5d6e7f]' }}">{{ $m['desc'] }}</p>
                    </div>
                </div>
                @endforeach

                @if($status === IntakeSubmissionStatus::Approved)
                <div class="flex items-center gap-2.5 rounded-xl bg-[#eef8f3] border border-[#bfe3d2] px-3.5 py-2.5">
                    <span class="text-[#117a51]">&#10003;</span>
                    <p class="text-sm font-semibold text-[#0f7a4f]">Approved. Your documents are ready on the
                        dashboard.</p>
                </div>
                @elseif($isRejected)
                <button wire:click="reuploadForOrder({{ $order->id }})" wire:target="reuploadForOrder({{ $order->id }})"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1 rounded bg-[#9f1239] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#881337] transition-colors">
                    <span wire:loading.remove wire:target="reuploadForOrder({{ $order->id }})">Update &amp; Resubmit
                        &rarr;</span>
                    <span wire:loading.inline-flex wire:target="reuploadForOrder({{ $order->id }})"
                        class="inline-flex items-center gap-1.5">
                        <x-spinner class="h-3.5 w-3.5" /> Loading…
                    </span>
                </button>
                @elseif(! $status)
                <p class="text-sm text-[#5d6e7f] italic">No submission found.</p>
                @endif
            </div>
            @empty
            <p class="text-sm text-[#5d6e7f] italic">No submission found.</p>
            @endforelse
        </div>

        <div class="flex justify-between items-center mt-5">
            <button wire:click="goToStep(3)" wire:target="goToStep(3)" wire:loading.attr="disabled"
                class="rounded border border-[#dbe4ee] px-5 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                <span wire:loading.remove wire:target="goToStep(3)">&larr; Back</span>
                <span wire:loading wire:target="goToStep(3)"><x-spinner class="h-3.5 w-3.5" /></span>
            </button>
            @if($milestone >= 4)
            <button wire:click="goToStep(5)" wire:target="goToStep(5)" wire:loading.attr="disabled"
                class="inline-flex items-center gap-1 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                <span wire:loading.remove wire:target="goToStep(5)">Go to Dashboard &rarr;</span>
                <span wire:loading.inline-flex wire:target="goToStep(5)" class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Loading…
                </span>
            </button>
            @endif
        </div>
    </div>
    @endif

    {{-- ── Step 5: Dashboard ── --}}
    @if($step === 5)
    <div x-data="{
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
            $invoiceLabel = $dashOrderForCards?->billing_cycle === \App\Enums\BillingCycle::Annual ? 'Invoice / Year' : 'Invoice / Month';
            $invoiceAmount = (float) ($dashOrderForCards?->original_price ?? 0);
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Package</p>
                <p class="text-base font-bold text-[#173045]">{{ $dashOrderForCards?->package?->name ?? '—' }}</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">{{ $invoiceLabel }}</p>
                <p class="text-base font-bold text-[#173045]">${{ number_format($invoiceAmount, $invoiceAmount == floor($invoiceAmount) ? 0 : 2) }}</p>
            </div>
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Renews</p>
                <p class="text-base font-bold text-[#173045]">{{ $dashOrderForCards?->next_bill_date?->format('M j, Y') ?? '—' }}</p>
            </div>
        </div>
    </div>

    @php $dashOrder = $this->currentOrder; @endphp
    @if($dashOrder && ! $dashOrder->blockedFromAiGeneration() && $dashOrder->payment_status === PaymentStatus::Trialing)
    <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 flex flex-wrap items-center justify-between gap-3">
        <span>
            Free trial active — ends {{ $dashOrder->trial_ends_at?->format('M j, Y') }}. Card on file ending in
            {{ $dashOrder->card_last_four ?? '····' }}.
        </span>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="convertTrialToPaid({{ $dashOrder->id }})"
                wire:target="convertTrialToPaid({{ $dashOrder->id }})" wire:loading.attr="disabled"
                wire:loading.class="opacity-70 cursor-not-allowed"
                class="rounded-lg bg-accent px-3.5 py-1.5 text-xs font-bold text-navy-dark hover:bg-accent-dark transition-colors">
                <span wire:loading.remove wire:target="convertTrialToPaid({{ $dashOrder->id }})">Proceed with Payment</span>
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
                <input x-ref="cardNumber" type="text" placeholder="Card number" inputmode="numeric" maxlength="19"
                    class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-40">
                <input x-ref="cardExpiry" type="text" placeholder="MM/YY" maxlength="5"
                    class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-20">
                <input x-ref="cardCvc" type="text" placeholder="CVC" inputmode="numeric" maxlength="4"
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
    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex flex-wrap items-center justify-between gap-3">
        <span>
            We couldn't process your last renewal payment{{ $dashOrder->last_renewal_error ? ": {$dashOrder->last_renewal_error}" : '.' }}
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
                <input x-ref="cardNumber" type="text" placeholder="Card number" inputmode="numeric" maxlength="19"
                    class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-40">
                <input x-ref="cardExpiry" type="text" placeholder="MM/YY" maxlength="5"
                    class="rounded-lg border border-empower-border bg-white px-3 py-1.5 text-sm w-20">
                <input x-ref="cardCvc" type="text" placeholder="CVC" inputmode="numeric" maxlength="4"
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
    <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3 text-xs text-empower-muted flex items-center justify-between gap-3">
        <span>Renews {{ $dashOrder->next_bill_date->format('M j, Y') }}</span>
        <button type="button"
            x-on:click="confirmCancel({{ $dashOrder->id }}, `Cancel your subscription? You'll lose access to AI document generation once your current plan year ends.`)"
            class="text-xs font-semibold text-empower-muted hover:underline">Cancel subscription</button>
    </div>
    @endif

    {{-- Tabs --}}
    <div class="bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="flex gap-1 border-b border-[#eef2f6] px-2">
            @foreach(['documents' => 'Documents', 'payments' => 'Payments', 'profile' => 'Practice Profile', 'history' => 'History'] as $tabKey => $tabLabel)
            <button wire:click="$set('dashboardTab', '{{ $tabKey }}')" wire:target="$set('dashboardTab', '{{ $tabKey }}')"
                wire:loading.attr="disabled" wire:target="$set('dashboardTab', '{{ $tabKey }}')"
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
        <button type="button" wire:click="switchOrder({{ $order->id }})" wire:target="switchOrder({{ $order->id }})"
            wire:loading.attr="disabled" wire:target="switchOrder({{ $order->id }})"
            class="inline-flex items-center gap-1 rounded-full px-3.5 py-1.5 text-xs font-bold transition-colors {{ $this->dashboardOrderId === $order->id ? 'bg-navy text-white' : 'bg-white border border-empower-border text-empower-muted hover:border-navy/40' }}">
            <span wire:loading.remove wire:target="switchOrder({{ $order->id }})">{{ $order->package?->name }}</span>
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
            $title = $type->label().($location ? ' — '.$location->name : '').($sourceUpload ? ' —
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
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-[#1a7aad] flex-shrink-0">
                    <path d="M6 2h9l5 5v15H6V2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                </svg>
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold text-[#173045] truncate">{{ $title }}</p>
                        <span
                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[0.6rem] font-extrabold uppercase tracking-wider {{ $badgeClass }}">{{ $badgeLabel }}</span>
                    </div>
                    @if($doc?->generated_at)
                    <p class="text-xs text-[#5d6e7f]">
                        {{ $doc->is_stale ? 'Last generated' : 'Generated' }} {{ $doc->generated_at->format('M j, Y')
                        }}{{ $doc->is_stale ? ' — details changed since.' : ($doc->wasRevoked() ? ' — pulled back for
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
                        <span wire:loading.remove wire:target="regenerateDocument({{ $doc->id }})">Regenerate</span>
                        <span wire:loading.inline-flex wire:target="regenerateDocument({{ $doc->id }})"
                            class="inline-flex items-center gap-1.5">
                            <x-spinner class="h-3.5 w-3.5" /> Regenerating…
                        </span>
                    </button>
                    @elseif($doc?->isReady() && $doc->delivery_source === \App\Enums\DocumentDeliverySource::Custom)
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
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-[#8592a1] flex-shrink-0">
                    <path d="M6 2h9l5 5v15H6V2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    <path d="M15 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                </svg>
                <p class="flex-1 text-sm font-semibold text-[#173045]">Your intake answers</p>
                <a href="{{ route('intake-submissions.answers', $this->primarySubmission) }}"
                    class="text-xs font-bold rounded border border-[#dbe4ee] text-[#173045] px-3 py-1.5 hover:bg-[#f4f7fb] transition-colors flex-shrink-0">Download</a>
            </div>
            @endif
        </div>

        <div class="mt-4 rounded-xl border border-dashed border-[#dbe4ee] bg-[#f8fbfd] p-4">
            <p class="text-sm font-semibold text-[#173045] mb-1">Have another document you'd like reviewed?</p>
            <p class="text-xs text-[#5d6e7f] mb-3">Upload it and our team will review and polish it, same as your other
                documents — no need to redo your intake.</p>

            @if($additionalDocumentNotice)
            <p class="text-xs font-semibold text-[#117a51] mb-3">✓ {{ $additionalDocumentNotice }}</p>
            @endif

            <div class="flex flex-wrap items-start gap-2">
                <div class="flex-1 min-w-[10rem]">
                    <input wire:model="additionalDocumentFile" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx"
                        wire:loading.attr="disabled" wire:target="additionalDocumentFile,uploadAdditionalDocument"
                        class="block w-full text-xs text-[#5c778d] file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-bold file:bg-[#12304f] file:text-white hover:file:bg-[#0a2037] cursor-pointer">
                    @error('additionalDocumentFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <select wire:model="additionalDocumentCategory"
                    class="rounded-lg border border-[#dbe4ee] bg-white px-2.5 py-1.5 text-xs text-[#173045]">
                    <option value="">Document type…</option>
                    @foreach($this->reviewDocumentCategories as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                    <option value="other">Other</option>
                </select>
                <button type="button" wire:click="uploadAdditionalDocument" wire:target="uploadAdditionalDocument"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                    class="text-xs font-bold rounded bg-[#12304f] text-white px-3.5 py-1.5 hover:bg-[#0a2037] transition-colors flex-shrink-0">
                    <span wire:loading.remove wire:target="uploadAdditionalDocument">Upload for Review</span>
                    <span wire:loading.inline-flex wire:target="uploadAdditionalDocument" class="inline-flex items-center gap-1.5">
                        <x-spinner class="h-3.5 w-3.5" /> Uploading…
                    </span>
                </button>
            </div>
        </div>

        <p class="text-xs text-[#5d6e7f] mt-2">For any queries, <a href="{{ route('contact', ['package' => $this->currentOrder->package?->slug]) }}" wire:navigate
                class="font-semibold text-[#1a7aad] hover:underline">contact us</a>.</p>
    </div>
    @elseif($dashboardTab === 'payments')
    <div>
        <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
            @forelse($this->userOrders as $order)
            @php $amountPaid = (float) $order->amount_paid; @endphp
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <p class="text-sm font-semibold text-[#173045]">
                    {{ $order->paid_at?->format('M j, Y') }} &middot; Initial payment{{ $order->card_last_four ? " (card ending {$order->card_last_four})" : '' }}
                </p>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="text-sm font-semibold text-[#173045]">${{ number_format($amountPaid, $amountPaid == floor($amountPaid) ? 0 : 2) }}</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[0.6rem] font-extrabold uppercase tracking-wider bg-[#d7f3ea] text-[#117a51]">Paid</span>
                    <a href="{{ route('orders.receipt', $order) }}" target="_blank"
                        class="text-xs font-bold text-[#1a7aad] hover:underline">View Receipt</a>
                </div>
            </div>
            @if($order->next_bill_date && $order->payment_status === PaymentStatus::Paid)
            @php $nextAmount = (float) ($order->original_price ?? $order->amount_paid); @endphp
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <p class="text-sm font-semibold text-[#173045]">
                    {{ $order->next_bill_date->format('M j, Y') }} &middot; Next {{ $order->billing_cycle?->period() ?? 'monthly' }} charge
                </p>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="text-sm font-semibold text-[#173045]">${{ number_format($nextAmount, $nextAmount == floor($nextAmount) ? 0 : 2) }}</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[0.6rem] font-extrabold uppercase tracking-wider bg-[#edf2f7] text-[#5d6e7f]">Scheduled</span>
                </div>
            </div>
            @endif
            @empty
            <p class="text-sm text-[#5d6e7f] italic px-4 py-3">No purchases yet.</p>
            @endforelse
        </div>

        <p class="text-xs text-[#5d6e7f] mt-3">Want another compliance package for this practice? <a href="{{ route('home') }}#pricing" class="font-semibold text-[#1a7aad] hover:underline">View all packages &rarr;</a></p>
    </div>
    @elseif($dashboardTab === 'profile')
    <div>
        <div class="divide-y divide-[#eef2f6] border border-[#eef2f6] rounded-xl">
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <span class="text-sm text-[#5d6e7f]">Practice</span>
                <span class="text-sm font-semibold text-[#173045] text-right">{{ $this->practice?->name ?: '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <span class="text-sm text-[#5d6e7f]">Specialty</span>
                <span class="text-sm font-semibold text-[#173045] text-right">{{ $this->practice?->specialty ?: '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <span class="text-sm text-[#5d6e7f]">Billable providers</span>
                <span class="text-sm font-semibold text-[#173045] text-right">{{ $this->practice?->billable_providers_count ?? '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <span class="text-sm text-[#5d6e7f]">Address</span>
                <span class="text-sm font-semibold text-[#173045] text-right">{{ $this->practice?->address ?: '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <span class="text-sm text-[#5d6e7f]">Account</span>
                <span class="text-sm font-semibold text-[#173045] text-right">{{ auth()->user()->name }} &middot; {{ auth()->user()->email }}</span>
            </div>
        </div>
        <div class="flex justify-end mt-4">
            <button wire:click="editProfile" wire:target="editProfile" wire:loading.attr="disabled"
                class="inline-flex items-center gap-1 rounded bg-[#12304f] px-5 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">
                <span wire:loading.remove wire:target="editProfile">Edit intake answers</span>
                <span wire:loading.inline-flex wire:target="editProfile" class="inline-flex items-center gap-1.5">
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
                <p class="text-xs text-[#5d6e7f] flex-shrink-0">{{ $log->created_at->format('M j, g:ia') }}</p>
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
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmCancelOrderId = null">
            <h3 class="text-base font-semibold text-[#12304f] mb-2">Cancel your subscription?</h3>
            <p class="text-sm text-[#5d6e7f] mb-5" x-text="confirmCancelMessage"></p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmCancelOrderId = null"
                    class="rounded-lg border border-[#dbe4ee] px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">
                    Keep Subscription
                </button>
                <button type="button"
                    x-on:click="$wire.cancelSubscription(confirmCancelOrderId).then(() => confirmCancelOrderId = null).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="cancelSubscription"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 transition-colors">
                    <span wire:loading.remove wire:target="cancelSubscription">Cancel Subscription</span>
                    <span wire:loading.inline-flex wire:target="cancelSubscription" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Cancelling…</span>
                </button>
            </div>
        </div>
    </div>
    </div>
    @endif

</div>