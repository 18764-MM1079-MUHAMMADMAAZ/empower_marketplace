<?php

use App\Enums\AiExtractionStatus;
use App\Enums\BillingCycle;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateComplianceDocument;
use App\Jobs\ProcessIntakeUpload;
use App\Models\ActivityLog;
use App\Models\CompliancePolicy;
use App\Models\GeneratedDocument;
use App\Models\IntakeQuestion;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Support\Questionnaires;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Admin-only sandbox for exercising the intake → AI extraction → document generation → release
 * pipeline against a real user without a real payment. Creates a SimulatedPaid test order (same
 * status the app already uses for "checkout complete, no real charge"), then walks through the
 * same steps a client normally does themselves, all on one page, so admins can test document
 * generation without needing a paid order or the client's involvement. Approving here never emails
 * the client — this is for internal testing only, not the real submission-approval flow (that's
 * still ⚡admin/submission-detail.blade.php).
 */
new class extends Component
{
    use WithFileUploads;

    #[Url]
    public ?int $orderId = null;

    public ?int $userId = null;

    public ?int $packageId = null;

    public string $userSearch = '';

    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $questionnaireFiles = [];

    public ?string $uploadNotice = null;

    /** Only overrides the #[Url]-hydrated value when explicitly passed (e.g. in tests) — the real
     *  page never passes this, so a normal load keeps whatever the query string already set. */
    public function mount(?int $orderId = null): void
    {
        if ($orderId !== null) {
            $this->orderId = $orderId;
        }
    }

    #[Computed]
    public function order(): ?Order
    {
        if (! $this->orderId) {
            return null;
        }

        return Order::with(['user.practice.oshaLocations', 'package', 'intakeSubmission.intakeUploads'])
            ->find($this->orderId);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function candidateUsers(): Collection
    {
        return User::where('role', UserRole::Client)
            ->when($this->userSearch !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->userSearch}%")
                ->orWhere('email', 'like', "%{$this->userSearch}%")))
            ->orderBy('name')
            ->limit(25)
            ->get();
    }

    /** @return Collection<int, Package> */
    #[Computed]
    public function packages(): Collection
    {
        return Package::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->reject(fn (Package $p) => $p->isCustomQuote());
    }

    /** @return Collection<int, array{file: string, title: string, description: string, tiers: ?array<int, string>, uploadType: IntakeUploadType, required: bool}> */
    #[Computed]
    public function applicableQuestionnaires(): Collection
    {
        $package = $this->order?->package;

        return $package ? Questionnaires::forTiers([$package->tier()->value]) : collect();
    }

    /** The package's included document types that are now driven by the Practice Intake wizard
     *  (i.e. have real CompliancePolicy rows) rather than the old upload+AI-extraction path —
     *  these need a separate test path since no questionnaire file ever produces them. */
    #[Computed]
    public function wizardDrivenIncludedTypes(): Collection
    {
        $package = $this->order?->package;

        if (! $package) {
            return collect();
        }

        return collect($package->included_document_types ?? [])
            ->map(fn ($value) => DocumentType::tryFrom($value))
            ->filter()
            ->filter(fn (DocumentType $type) => CompliancePolicy::where('manual', $type->value)->exists())
            ->values();
    }

    /** Fills every Practice Intake question with a placeholder test answer, so the wizard-driven
     *  manuals below have something real to merge — same purpose as uploading filled forms does
     *  for the old questionnaire-driven types, just for the new answer-based ones. */
    public function autoFillWizardAnswers(): void
    {
        $order = $this->order;

        abort_unless($order, 404);

        $submission = IntakeSubmission::firstOrCreate(
            ['order_id' => $order->id],
            ['status' => IntakeSubmissionStatus::Draft]
        );

        $answeredQuestionIds = $submission->intakeAnswers()->pluck('intake_question_id')->all();

        IntakeQuestion::whereNotIn('id', $answeredQuestionIds)->get()->each(fn (IntakeQuestion $question) => $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => "Test answer for \"{$question->title}\", entered via the admin Document Generator.",
            'has_documented_process' => true,
            'answered_at' => now(),
        ]));

        $submission->update(['wizard_screen' => 'done']);

        ActivityLog::record(
            'submission.admin_test_answers_filled',
            "Practice Intake questions auto-filled with test answers for test order #{$order->id} via the admin Document Generator.",
            user: auth()->user(),
            order: $order,
            subject: $submission,
        );

        unset($this->order);

        $this->dispatch('toast', message: 'Test answers auto-filled.', type: 'success');
    }

    /** Dispatches generation for every wizard-driven manual this package includes — mirrors
     *  ⚡admin/submission-detail.blade.php's generateIncludedDocuments(), minus the "already
     *  generated" guard, since re-running here (after re-filling answers) is exactly the point. */
    public function generateWizardDrivenManuals(): void
    {
        $order = $this->order;

        abort_unless($order, 404);

        foreach ($this->wizardDrivenIncludedTypes as $documentType) {
            GenerateComplianceDocument::dispatch($order, $documentType);
        }

        ActivityLog::record(
            'document.test_generation_requested',
            'Wizard-driven manual generation requested for test order #'.$order->id.' via the admin Document Generator.',
            user: auth()->user(),
            order: $order,
        );

        unset($this->documentsForReview);

        $this->dispatch('toast', message: 'Manual generation dispatched.', type: 'success');
    }

    /** Questionnaires already uploaded for the current submission, keyed by upload type value —
     *  lets the page show what's already on file instead of leaving the browser's own stale
     *  "file chosen" text as the only (misleading) indicator after a successful submit. */
    #[Computed]
    public function existingUploadsByType(): Collection
    {
        $submission = $this->order?->intakeSubmission;

        return $submission ? $submission->intakeUploads->keyBy(fn (IntakeUpload $u) => $u->upload_type->value) : collect();
    }

    /** @return Collection<int, GeneratedDocument> */
    #[Computed]
    public function documentsForReview(): Collection
    {
        $order = $this->order;

        return $order
            ? GeneratedDocument::where('order_id', $order->id)->orderBy('document_type')->get()
            : collect();
    }

    #[Computed]
    public function isProcessing(): bool
    {
        $order = $this->order;

        if (! $order?->intakeSubmission) {
            return false;
        }

        $uploadPending = $order->intakeSubmission->intakeUploads
            ->contains(fn (IntakeUpload $u) => in_array($u->ai_extraction_status, [AiExtractionStatus::Pending, AiExtractionStatus::Processing], true));

        $documentPending = $this->documentsForReview
            ->contains(fn (GeneratedDocument $d) => in_array($d->status, [DocumentStatus::Pending, DocumentStatus::Generating], true));

        return $uploadPending || $documentPending;
    }

    public function createTestOrder(): void
    {
        $this->validate([
            'userId' => 'required|exists:users,id',
            'packageId' => 'required|exists:packages,id',
        ]);

        $user = User::findOrFail($this->userId);
        $package = Package::findOrFail($this->packageId);

        Practice::firstOrCreate(['user_id' => $user->id], ['name' => '']);

        $order = Order::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'amount_paid' => 0,
            'original_price' => $package->annual_price,
            'billing_cycle' => BillingCycle::Annual,
            'paid_at' => now(),
            'next_bill_date' => now()->addYear(),
            'notes' => "Test order created via the admin Document Generator by {$this->adminName()}. No real payment was taken.",
        ]);

        ActivityLog::record(
            'order.test_created',
            "Test order #{$order->id} created for {$user->name} ({$package->name}) via the admin Document Generator, for testing purposes only.",
            user: auth()->user(),
            order: $order,
        );

        $this->orderId = $order->id;
        $this->reset('userId', 'packageId', 'userSearch');

        $this->dispatch('toast', message: 'Test order created.', type: 'success');
    }

    public function submitUploads(): void
    {
        $order = $this->order;

        abort_unless($order, 404);

        $this->uploadNotice = null;

        $this->validate([
            'questionnaireFiles.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png,docx|max:20480',
        ]);

        $filledFiles = collect($this->questionnaireFiles)->filter();

        if ($filledFiles->isEmpty()) {
            $this->addError('questionnaireFiles', 'Choose at least one filled form below before submitting. The file picker does not keep showing a file after it has already been submitted.');
            $this->dispatch('toast', message: 'Choose at least one file to upload.', type: 'error');

            return;
        }

        $submission = IntakeSubmission::updateOrCreate(
            ['order_id' => $order->id],
            ['status' => IntakeSubmissionStatus::Submitted, 'reviewer_notes' => null, 'submitted_at' => now()]
        );

        $uploads = $filledFiles->map(function ($file, $key) use ($order, $submission) {
            return IntakeUpload::updateOrCreate(
                ['intake_submission_id' => $submission->id, 'upload_type' => IntakeUploadType::from($key)],
                [
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_path' => $file->store("uploads/admin-test/{$order->id}"),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'ai_extraction_status' => AiExtractionStatus::Pending,
                    'ai_extracted_data' => null,
                    'ai_error_message' => null,
                    'processed_at' => null,
                ]
            );
        });

        $order->update(['status' => OrderStatus::IntakeSubmitted]);

        ActivityLog::record(
            'submission.admin_test_uploaded',
            "Filled forms uploaded on behalf of {$order->user->name} for test order #{$order->id} via the admin Document Generator.",
            user: auth()->user(),
            order: $order,
            subject: $submission,
        );

        $uploads->each(fn (IntakeUpload $upload) => ProcessIntakeUpload::dispatch($upload));

        $this->ensureExpectedDocumentsExist($order, $submission);

        $this->questionnaireFiles = [];
        $this->uploadNotice = $filledFiles->count() === 1
            ? 'Uploaded 1 file. AI processing has started; see status below.'
            : "Uploaded {$filledFiles->count()} files. AI processing has started; see status below.";
        unset($this->order, $this->documentsForReview, $this->applicableQuestionnaires, $this->existingUploadsByType);

        $this->dispatch('toast', message: 'Files uploaded for AI processing.', type: 'success');
    }

    /**
     * Materializes a Pending GeneratedDocument row for every document type the uploaded
     * questionnaires entitle this order to, so the review section shows every expected document
     * immediately instead of only once the AI pipeline has run. Mirrors
     * ⚡admin/submission-detail.blade.php's ensureExpectedDocumentsExist() exactly, using the same
     * key tuple GenerateComplianceDocument::handle() looks up, so the real job finds this row
     * instead of creating a duplicate.
     */
    private function ensureExpectedDocumentsExist(Order $order, IntakeSubmission $submission): void
    {
        $oshaLocations = $order->user->practice?->oshaLocations ?? collect();
        $uploadedTypes = $submission->intakeUploads()->get()->map(fn (IntakeUpload $u) => $u->upload_type)->unique();

        foreach ($uploadedTypes as $uploadType) {
            $docType = DocumentType::forQuestionnaireType($uploadType);

            if ($docType === null) {
                continue;
            }

            if ($docType->isPerUpload()) {
                $submission->intakeUploads()->where('upload_type', $uploadType)->get()
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

    /** Approves + releases one document for client download. Deliberately does NOT email the
     *  client — this tool is for internal testing, not the real approval flow. */
    public function approveDocument(int $documentId): void
    {
        $order = $this->order;

        abort_unless($order, 404);

        $document = GeneratedDocument::where('id', $documentId)->where('order_id', $order->id)->firstOrFail();

        if (! $document->canBeApproved()) {
            $this->dispatch('toast', message: "{$document->document_type->label()} can't be approved yet.", type: 'error');

            return;
        }

        $document->update(['reviewed_at' => now(), 'reviewed_by' => auth()->id(), 'revoked_at' => null]);

        ActivityLog::record(
            'document.test_approved',
            "{$document->document_type->label()} approved for download on test order #{$order->id} via the admin Document Generator (no client email sent).",
            user: auth()->user(),
            order: $order,
            subject: $document,
        );

        unset($this->documentsForReview);

        $this->dispatch('toast', message: "{$document->document_type->label()} approved for download.", type: 'success');
    }

    public function revokeDocument(int $documentId): void
    {
        $order = $this->order;

        abort_unless($order, 404);

        $document = GeneratedDocument::where('id', $documentId)->where('order_id', $order->id)->firstOrFail();

        if (! $document->isApproved()) {
            $this->dispatch('toast', message: "{$document->document_type->label()} isn't approved yet.", type: 'error');

            return;
        }

        $document->update(['reviewed_at' => null, 'reviewed_by' => null, 'revoked_at' => now()]);

        ActivityLog::record(
            'document.test_approval_revoked',
            "Approval for {$document->document_type->label()} revoked on test order #{$order->id} via the admin Document Generator.",
            user: auth()->user(),
            order: $order,
            subject: $document,
        );

        unset($this->documentsForReview);

        $this->dispatch('toast', message: "Approval revoked for {$document->document_type->label()}.", type: 'success');
    }

    public function startOver(): void
    {
        $this->reset('orderId', 'userId', 'packageId', 'userSearch', 'questionnaireFiles', 'uploadNotice');
    }

    /** Cleans up the test order and every file/row it created, via the same cascading delete
     *  admin.orders.edit already uses. */
    public function deleteTestOrder(): void
    {
        $order = $this->order;

        abort_unless($order, 404);

        $orderId = $order->id;
        $order->deleteCascadingFiles();
        $order->delete();

        ActivityLog::record('order.deleted', "Test order #{$orderId} was deleted via the admin Document Generator.", user: auth()->user());

        $this->startOver();

        $this->dispatch('toast', message: 'Test order deleted.', type: 'success');
    }

    private function adminName(): string
    {
        return auth()->user()->name;
    }
};
?>

<div class="space-y-4" x-data="{ confirmDeleteOrder: false }">
    <div class="bg-[#fff8e6] border border-[#f0dca0] rounded-2xl p-4 text-sm text-[#6b5410]">
        <strong>Testing tool only.</strong> Creates a simulated (no-payment) test order so you can run a
        real user through intake upload, AI extraction, and document generation without a real
        checkout. Approving a document here does not email the client.
    </div>

    @if (! $this->order)
        {{-- Step 1: pick a user + package, create the test order --}}
        <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 space-y-4">
            <h2 class="text-lg font-semibold text-navy">1. Choose a user &amp; package</h2>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Client</label>
                <input wire:model.live.debounce.300ms="userSearch" type="text" placeholder="Search name or email…"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition mb-2">
                <select wire:model="userId" size="6"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @foreach($this->candidateUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
                @error('userId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Package</label>
                <select wire:model="packageId"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    <option value="">Select a package…</option>
                    @foreach($this->packages as $package)
                        <option value="{{ $package->id }}">{{ $package->name }}</option>
                    @endforeach
                </select>
                @error('packageId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end">
                <button wire:click="createTestOrder" wire:loading.attr="disabled" wire:target="createTestOrder"
                    class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors">
                    Create Test Order &rarr;
                </button>
            </div>
        </div>
    @else
        @php($order = $this->order)

        <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-empower-muted">Test order #{{ $order->id }}</p>
                <p class="text-base font-semibold text-navy">{{ $order->user->name }} &middot; {{ $order->package->name }}</p>
            </div>
            <div class="flex gap-2">
                @if($order->intakeSubmission)
                    <a href="{{ route('admin.submissions.show', $order->intakeSubmission) }}" wire:navigate
                        class="text-sm font-semibold text-[#0b9ed0] hover:underline self-center">Open full submission review &rarr;</a>
                @endif
                <button wire:click="startOver" class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                    Start Over
                </button>
                <button type="button" x-on:click="confirmDeleteOrder = true"
                    class="rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 transition-colors">
                    Delete Test Order
                </button>
            </div>
        </div>

        <div x-show="confirmDeleteOrder" x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
            <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmDeleteOrder = false">
                <h3 class="text-base font-semibold text-navy mb-2">Delete test order #{{ $order->id }}?</h3>
                <p class="text-sm text-empower-muted mb-5">This deletes the order and everything generated for it. This cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button type="button" x-on:click="confirmDeleteOrder = false"
                        class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:target="deleteTestOrder"
                        x-on:click="$wire.deleteTestOrder().then(() => confirmDeleteOrder = false).catch(() => {})"
                        wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="deleteTestOrder"
                        class="inline-flex items-center gap-1 rounded-lg px-5 py-2 text-sm font-bold transition-colors bg-red-600 text-white hover:bg-red-700">
                        <span wire:loading.remove wire:target="deleteTestOrder">Delete</span>
                        <span wire:loading.inline-flex wire:target="deleteTestOrder" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Deleting…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Step 2: download blank forms, upload filled ones --}}
        <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 space-y-4">
            <h2 class="text-lg font-semibold text-navy">2. Download, fill, and upload forms</h2>

            @if($uploadNotice)
                <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-2.5 text-sm text-emerald-700">
                    {{ $uploadNotice }}
                </div>
            @endif

            @error('questionnaireFiles') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            @forelse($this->applicableQuestionnaires as $questionnaire)
                @php($key = $questionnaire['uploadType']->value)
                @php($existingUpload = $this->existingUploadsByType->get($key))
                <div class="border border-empower-border rounded-xl p-4 space-y-2">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <p class="font-semibold text-[#173a59]">{{ $questionnaire['title'] }}</p>
                        <a href="{{ Questionnaires::url($questionnaire['file']) }}" target="_blank"
                            class="text-xs font-bold text-[#0b9ed0] hover:underline">Download Blank Form</a>
                    </div>
                    @if($existingUpload)
                        <p class="text-xs text-emerald-700 font-semibold">&check; Uploaded: {{ $existingUpload->original_filename }}</p>
                        <p class="text-xs text-empower-muted">Choose a new file below to replace it.</p>
                    @endif
                    <input wire:model="questionnaireFiles.{{ $key }}" type="file"
                        class="w-full text-sm text-empower-text file:mr-3 file:rounded-lg file:border-0 file:bg-page file:px-3 file:py-1.5 file:text-sm file:font-semibold">
                    @error("questionnaireFiles.{$key}") <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @empty
                <p class="text-sm text-empower-muted italic">No questionnaires are configured for this package tier.</p>
            @endforelse

            <div class="flex justify-end">
                <button wire:click="submitUploads" wire:loading.attr="disabled" wire:target="submitUploads,questionnaireFiles"
                    class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors">
                    <span wire:loading.remove wire:target="submitUploads">Submit for AI Processing &rarr;</span>
                    <span wire:loading.inline-flex wire:target="submitUploads" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Submitting…</span>
                </button>
            </div>
        </div>

        {{-- Step 2b: wizard-driven manuals (Practice Intake answers, not a questionnaire upload) --}}
        @if($this->wizardDrivenIncludedTypes->isNotEmpty())
            <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 space-y-4">
                <h2 class="text-lg font-semibold text-navy">2b. Practice Intake wizard manuals</h2>
                <p class="text-sm text-empower-muted">
                    This package's {{ $this->wizardDrivenIncludedTypes->map(fn ($t) => $t->label())->implode(', ') }}
                    {{ $this->wizardDrivenIncludedTypes->count() === 1 ? 'is' : 'are' }} generated from Practice Intake
                    wizard answers, not an uploaded questionnaire. Auto-fill placeholder answers below to test
                    generation without walking the real wizard.
                </p>
                <div class="flex flex-wrap gap-3">
                    <button wire:click="autoFillWizardAnswers" wire:loading.attr="disabled" wire:target="autoFillWizardAnswers"
                        class="inline-flex items-center gap-1 rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-text hover:bg-page transition-colors">
                        <span wire:loading.remove wire:target="autoFillWizardAnswers">Auto-fill test answers</span>
                        <span wire:loading.inline-flex wire:target="autoFillWizardAnswers" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Filling…</span>
                    </button>
                    <button wire:click="generateWizardDrivenManuals" wire:loading.attr="disabled" wire:target="generateWizardDrivenManuals"
                        class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors">
                        <span wire:loading.remove wire:target="generateWizardDrivenManuals">Generate manuals &rarr;</span>
                        <span wire:loading.inline-flex wire:target="generateWizardDrivenManuals" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Dispatching…</span>
                    </button>
                </div>
            </div>
        @endif

        {{-- Step 3: generation status + release --}}
        @if($order->intakeSubmission)
            <div wire:poll.5s="$refresh"
                class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 space-y-3">
                <h2 class="text-lg font-semibold text-navy">3. Generated documents</h2>

                @if($this->isProcessing)
                    <div class="rounded-xl bg-page border border-empower-border p-3">
                        <div class="flex items-center gap-2 text-xs font-semibold text-empower-text mb-2">
                            <x-spinner class="h-3.5 w-3.5 text-accent" />
                            AI is extracting your data and generating documents… this updates automatically every few seconds.
                        </div>
                        <div class="relative h-1.5 w-full overflow-hidden rounded-full bg-empower-border">
                            <div class="absolute inset-y-0 rounded-full bg-accent animate-indeterminate"></div>
                        </div>
                    </div>
                @endif

                @forelse($this->documentsForReview as $document)
                    <div class="flex items-center justify-between gap-3 border border-empower-border rounded-xl p-4 flex-wrap">
                        <div>
                            <p class="font-semibold text-[#173a59]">{{ $document->document_type->label() }}</p>
                            <p class="text-xs text-empower-muted flex items-center gap-1.5">
                                @if(in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Generating], true))
                                    <x-spinner class="h-3 w-3 text-accent" />
                                @endif
                                Status: {{ ucfirst($document->status->value) }}
                                @if($document->isApproved())
                                    &middot; <span class="text-emerald-600 font-semibold">Released to client</span>
                                @elseif($document->wasRevoked())
                                    &middot; <span class="text-amber-600 font-semibold">Revoked</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if($document->pdf_storage_path || $document->docx_storage_path)
                                <a href="{{ route('admin.generated-documents.download', $document) }}"
                                    class="text-xs font-bold text-[#0b9ed0] hover:underline">Preview / Download</a>
                            @endif

                            @if($document->isApproved())
                                <button wire:click="revokeDocument({{ $document->id }})"
                                    class="rounded-lg border border-empower-border px-3 py-1.5 text-xs font-semibold text-empower-muted hover:bg-page transition-colors">
                                    Revoke
                                </button>
                            @endif
                            {{-- Approve & Release hidden for now (kept approveDocument() in place to re-enable later).
                            @if($document->canBeApproved())
                                <button wire:click="approveDocument({{ $document->id }})"
                                    class="rounded-lg bg-[#2299dd] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors">
                                    Approve &amp; Release
                                </button>
                            @endif
                            --}}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-empower-muted italic">No documents expected yet.</p>
                @endforelse
            </div>
        @endif
    @endif
</div>
