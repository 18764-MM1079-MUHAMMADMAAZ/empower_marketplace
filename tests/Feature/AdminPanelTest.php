<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateComplianceDocument;
use App\Mail\ClientDocumentsApprovedMail;
use App\Mail\ClientSubmissionStatusMail;
use App\Mail\DiscountCodeSharedMail;
use App\Models\ActivityLog;
use App\Models\DiscountCode;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeSubmission(IntakeSubmissionStatus $status = IntakeSubmissionStatus::Submitted): IntakeSubmission
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);

        return IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    // ── Access control ─────────────────────────────────────────────────────

    public function test_guest_cannot_access_admin_routes(): void
    {
        $this->withoutVite()->get(route('admin.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Please log in to access this page.');
    }

    public function test_client_cannot_access_admin_routes(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->withoutVite()->actingAs($client)->get(route('admin.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Please log in to access this page.');
    }

    public function test_admin_can_view_dashboard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pending Review')
            ->assertSee(route('admin.orders'), false);
    }

    // ── Submissions ─────────────────────────────────────────────────────────

    public function test_admin_can_view_submissions_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->makeSubmission();

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions'))->assertOk();
    }

    public function test_admin_can_view_submission_detail(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))->assertOk();
    }

    public function test_document_review_shows_an_expected_document_before_it_has_been_generated(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Document Review')
            ->assertSee('Compliance & Ethics Manual')
            ->assertSee('Not Started')
            ->assertSee('Upload a custom file below instead');

        $this->assertDatabaseHas('generated_documents', [
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual->value,
            'status' => DocumentStatus::Pending->value,
        ]);
    }

    public function test_document_review_shows_an_empty_state_with_no_expected_documents(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Document Review')
            ->assertSee('No documents are expected yet');
    }

    public function test_admin_can_upload_a_custom_file_for_a_document_that_has_not_generated_yet(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);

        $component = Livewire::actingAs($admin)->test('admin.submission-detail', ['submission' => $submission]);

        $document = GeneratedDocument::where('order_id', $submission->order_id)
            ->where('document_type', DocumentType::ComplianceEthicsManual)
            ->firstOrFail();
        $this->assertSame(DocumentStatus::Pending, $document->status);

        $component->set("customDocumentFiles.{$document->id}", UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'))
            ->assertHasNoErrors();

        $document->refresh();
        $this->assertNotNull($document->custom_storage_path);
        Storage::disk('local')->assertExists($document->custom_storage_path);
    }

    public function test_revisiting_submission_detail_does_not_duplicate_expected_documents(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))->assertOk();
        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))->assertOk();

        $this->assertDatabaseCount('generated_documents', 1);
    }

    public function test_submission_detail_shows_no_ai_extraction_banner_without_uploads(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertDontSee('AI Extraction');
    }

    public function test_submission_detail_shows_a_pending_ai_extraction_banner(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->create(['intake_submission_id' => $submission->id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('AI Extraction In Progress');
    }

    public function test_submission_detail_shows_a_failed_ai_extraction_banner(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->completed()->create(['intake_submission_id' => $submission->id]);
        IntakeUpload::factory()->failed()->create(['intake_submission_id' => $submission->id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('AI Extraction Failed')
            ->assertSee('1 of 2');
    }

    public function test_submission_detail_shows_a_completed_ai_extraction_banner(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->completed()->create(['intake_submission_id' => $submission->id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('AI Extraction Complete');
    }

    public function test_admin_can_approve_a_submission(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        $submission->refresh();
        $this->assertSame(IntakeSubmissionStatus::Approved, $submission->status);
        $this->assertSame($admin->id, $submission->reviewed_by);
        $this->assertSame(OrderStatus::Approved, $submission->order->fresh()->status);

        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'submission.approved',
            'order_id' => $submission->order_id,
        ]);
    }

    public function test_approving_a_submission_also_approves_its_ready_documents(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $document = GeneratedDocument::factory()->completed()->create(['order_id' => $submission->order_id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        $document->refresh();
        $this->assertNotNull($document->reviewed_at);
        $this->assertSame($admin->id, $document->reviewed_by);
        $this->assertTrue($document->isReady());
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'documents.approved']);
    }

    public function test_reject_reopen_and_approve_still_approves_the_ready_document(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $document = GeneratedDocument::factory()->completed()->create(['order_id' => $submission->order_id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->set('reviewerNotes', 'Wrong practice name, please fix.')
            ->call('reject');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('reopen')
            ->call('approve');

        $document->refresh();
        $this->assertTrue($document->isReady());
    }

    public function test_approving_a_submission_emails_the_client(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        Mail::assertSent(ClientSubmissionStatusMail::class, fn ($mail) => $mail->hasTo($submission->order->user->email));
    }

    public function test_admin_can_reject_a_submission_with_notes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $component = Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->set('reviewerNotes', 'Please re-upload a signed copy.')
            ->call('reject');

        $submission->refresh();
        $this->assertSame(IntakeSubmissionStatus::Rejected, $submission->status);
        $this->assertSame('Please re-upload a signed copy.', $submission->reviewer_notes);

        $component->assertDontSee('Review Decision');
        $component->assertSee('Reopen for Review');
    }

    public function test_admin_can_reopen_a_rejected_submission(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->set('reviewerNotes', 'Please re-upload a signed copy.')
            ->call('reject');

        $submission->refresh();
        $this->assertSame(IntakeSubmissionStatus::Rejected, $submission->status);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('reopen')
            ->assertSet('reviewerNotes', '');

        $submission->refresh();
        $this->assertSame(IntakeSubmissionStatus::UnderReview, $submission->status);
        $this->assertNull($submission->reviewer_notes);
        $this->assertNull($submission->reviewed_by);
        $this->assertNull($submission->reviewed_at);
    }

    public function test_reopening_a_submission_that_was_never_rejected_does_nothing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::UnderReview);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('reopen');

        $submission->refresh();
        $this->assertSame(IntakeSubmissionStatus::UnderReview, $submission->status);
    }

    public function test_admin_can_delete_an_intake_upload(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        Storage::disk('local')->put('intake/upload.pdf', 'fake-upload');
        $upload = IntakeUpload::factory()->create(['intake_submission_id' => $submission->id, 'storage_path' => 'intake/upload.pdf']);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('deleteIntakeUpload', $upload->id);

        $this->assertDatabaseMissing('intake_uploads', ['id' => $upload->id]);
        Storage::disk('local')->assertMissing('intake/upload.pdf');
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'upload.deleted']);
    }

    public function test_admin_can_delete_a_submission(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('deleteSubmission')
            ->assertRedirect(route('admin.submissions'));

        $this->assertDatabaseMissing('intake_submissions', ['id' => $submission->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'submission.deleted']);
    }

    /** generated_documents.intake_upload_id is nullOnDelete, not cascade — without explicit
     *  cleanup, deleting an upload would orphan its generated document instead of removing it. */
    public function test_deleting_an_intake_upload_also_deletes_its_generated_document(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $upload->id,
        ]);
        Storage::disk('local')->put($document->pdf_storage_path, 'fake-pdf');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('deleteIntakeUpload', $upload->id);

        $this->assertDatabaseMissing('generated_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->pdf_storage_path);
    }

    /** deleteSubmission() must clean up every generated document for the order too — otherwise
     *  they survive as permanent orphans (nulled intake_upload_id) and keep reappearing in
     *  Document Review with no source file, duplicating whatever a later resubmission creates. */
    public function test_deleting_a_submission_also_deletes_its_generated_documents(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
        ]);
        $perUploadDoc = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $upload->id,
        ]);
        $orderScopedDoc = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);
        Storage::disk('local')->put($perUploadDoc->pdf_storage_path, 'fake-pdf');
        Storage::disk('local')->put($orderScopedDoc->pdf_storage_path, 'fake-pdf');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('deleteSubmission');

        $this->assertDatabaseMissing('generated_documents', ['id' => $perUploadDoc->id]);
        $this->assertDatabaseMissing('generated_documents', ['id' => $orderScopedDoc->id]);
        Storage::disk('local')->assertMissing($perUploadDoc->pdf_storage_path);
        Storage::disk('local')->assertMissing($orderScopedDoc->pdf_storage_path);
    }

    public function test_rejecting_a_submission_emails_the_client(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->set('reviewerNotes', 'Please re-upload a signed copy.')
            ->call('reject');

        Mail::assertSent(ClientSubmissionStatusMail::class, fn ($mail) => $mail->hasTo($submission->order->user->email));
    }

    public function test_rejecting_without_notes_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('reject')
            ->assertHasErrors(['reviewerNotes']);
    }

    public function test_client_can_resubmit_after_rejection_without_duplicate_key_error(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
            'reviewer_notes' => 'Fix the signature.',
            'submitted_at' => now()->subDay(),
        ]);

        $file = UploadedFile::fake()->create('intake.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('orderIds', [$order->id])
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $file)
            ->call('submitIntake')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('intake_submissions', 1);
        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Submitted->value,
            'reviewer_notes' => null,
        ]);
    }

    // ── Documents ───────────────────────────────────────────────────────────

    public function test_admin_can_view_documents_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        GeneratedDocument::factory()->completed()->create();

        $this->withoutVite()->actingAs($admin)->get(route('admin.documents'))->assertOk();
    }

    public function test_admin_can_regenerate_a_document(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $document = GeneratedDocument::factory()->completed()->create([
            'document_type' => DocumentType::OshaSafetyPlan,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.document-list')
            ->call('regenerate', $document->id);

        Bus::assertDispatched(GenerateComplianceDocument::class);

        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'document.regenerate_requested',
        ]);
    }

    // ── Document review (per-document approval) ──────────────────────────────

    public function test_approving_a_submission_emails_the_full_ready_documents_list_including_previously_approved_ones(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $clientEmail = $submission->order->user->email;
        // Already approved from an earlier review cycle — e.g. before this submission was
        // reopened and is being approved again.
        $docOne = GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);
        $docTwo = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::OshaSafetyPlan,
        ]);

        // Approving the submission sends one email listing BOTH documents — the one already
        // approved from before, plus the one finalized by this approval.
        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        Mail::assertSent(ClientDocumentsApprovedMail::class, function ($mail) use ($clientEmail, $docOne, $docTwo) {
            $ids = $mail->documents->pluck('id')->all();

            return $mail->hasTo($clientEmail)
                && in_array($docOne->id, $ids, true)
                && in_array($docTwo->id, $ids, true);
        });
    }

    public function test_approving_a_submission_still_succeeds_when_a_notification_email_fails_to_send(): void
    {
        Mail::shouldReceive('to')->twice()->andReturnSelf();
        Mail::shouldReceive('send')->twice()->andThrow(new \RuntimeException('SMTP rejected the recipient.'));

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);

        $component = Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        $component->assertOk();
        $submission->refresh();
        $this->assertSame(IntakeSubmissionStatus::Approved, $submission->status);
        $this->assertNotNull($document->fresh()->reviewed_at);
        $this->assertStringContainsString('failed to send', $component->get('notice'));
    }

    public function test_approving_a_submission_does_not_approve_a_document_that_has_not_finished_generating(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $document = GeneratedDocument::factory()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
            'status' => 'generating',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        $this->assertNull($document->fresh()->reviewed_at);
        Mail::assertNotSent(ClientDocumentsApprovedMail::class);
    }

    public function test_custom_upload_slot_only_shows_for_a_questionnaire_the_client_actually_uploaded(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);

        $uploadedDoc = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);
        $notUploadedDoc = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::HipaaBusinessAssociateManual,
        ]);
        $noQuestionnaireLinkDoc = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);

        $component = Livewire::actingAs($admin)->test('admin.submission-detail', ['submission' => $submission]);
        $documents = $component->instance()->documentsForReview();

        $this->assertTrue($documents->firstWhere('id', $uploadedDoc->id)->showsCustomUploadSlot);
        $this->assertFalse($documents->firstWhere('id', $notUploadedDoc->id)->showsCustomUploadSlot);
        $this->assertTrue($documents->firstWhere('id', $noQuestionnaireLinkDoc->id)->showsCustomUploadSlot);
    }

    // ── Upload for review (alternate to questionnaire downloads) ────────────

    public function test_uploaded_forms_list_shows_every_client_review_file_individually(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'employee-handbook.pdf',
        ]);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'safety-plan.pdf',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('employee-handbook.pdf')
            ->assertSee('safety-plan.pdf');
    }

    public function test_document_review_grid_shows_a_separate_row_per_uploaded_file_with_its_filename(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $uploadA = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'employee-handbook.pdf',
        ]);
        $uploadB = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'safety-plan.pdf',
        ]);
        GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $uploadA->id,
        ]);
        GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $uploadB->id,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('employee-handbook.pdf')
            ->assertSee('safety-plan.pdf');
    }

    public function test_approving_a_submission_approves_all_of_its_polished_client_documents(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $uploadA = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
        ]);
        $uploadB = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
        ]);
        $docA = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $uploadA->id,
        ]);
        $docB = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $uploadB->id,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        $this->assertNotNull($docA->fresh()->reviewed_at);
        $this->assertNotNull($docB->fresh()->reviewed_at);
    }

    public function test_uploading_a_custom_document_switches_delivery_source_and_revokes_prior_approval(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);

        $file = UploadedFile::fake()->create('corrected.pdf', 100, 'application/pdf');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->set("customDocumentFiles.{$document->id}", $file);

        $document->refresh();
        $this->assertSame('custom', $document->delivery_source->value);
        $this->assertNotNull($document->custom_storage_path);
        $this->assertSame('corrected.pdf', $document->custom_original_filename);
        $this->assertNull($document->reviewed_at);
    }

    public function test_admin_can_delete_a_custom_document_and_falls_back_to_ai_generated(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private/compliance/1/custom/corrected.pdf', 'contents');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
            'custom_storage_path' => 'private/compliance/1/custom/corrected.pdf',
            'custom_original_filename' => 'corrected.pdf',
            'delivery_source' => 'custom',
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('deleteCustomDocument', $document->id);

        $document->refresh();
        $this->assertNull($document->custom_storage_path);
        $this->assertNull($document->custom_original_filename);
        $this->assertSame('ai_generated', $document->delivery_source->value);
        $this->assertNull($document->reviewed_at);
        Storage::disk('local')->assertMissing('private/compliance/1/custom/corrected.pdf');

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.custom_deleted']);
    }

    public function test_admin_can_revoke_an_approved_documents_approval(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $submission->order_id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('revokeApproval', $document->id);

        $document->refresh();
        $this->assertNull($document->reviewed_at);
        $this->assertNull($document->reviewed_by);
        $this->assertNotNull($document->revoked_at);
        $this->assertTrue($document->wasRevoked());
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.approval_revoked']);
    }

    public function test_reapproving_a_revoked_document_clears_the_revoked_flag(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'revoked_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        $document->refresh();
        $this->assertNotNull($document->reviewed_at);
        $this->assertNull($document->revoked_at);
        $this->assertFalse($document->wasRevoked());
    }

    public function test_uploading_a_custom_file_on_an_approved_document_marks_it_revoked(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);
        $file = UploadedFile::fake()->create('corrected.pdf', 100, 'application/pdf');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->set("customDocumentFiles.{$document->id}", $file);

        $document->refresh();
        $this->assertNull($document->reviewed_at);
        $this->assertNotNull($document->revoked_at);
        $this->assertTrue($document->wasRevoked());
    }

    public function test_uploading_a_custom_file_on_a_never_approved_document_does_not_mark_it_revoked(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);
        $file = UploadedFile::fake()->create('corrected.pdf', 100, 'application/pdf');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->set("customDocumentFiles.{$document->id}", $file);

        $document->refresh();
        $this->assertNull($document->revoked_at);
        $this->assertFalse($document->wasRevoked());
    }

    public function test_setting_delivery_source_on_an_approved_document_marks_it_revoked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $submission->order_id,
            'custom_storage_path' => 'private/compliance/1/custom/corrected.pdf',
            'delivery_source' => 'ai_generated',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('setDeliverySource', $document->id, 'custom');

        $document->refresh();
        $this->assertNull($document->reviewed_at);
        $this->assertNotNull($document->revoked_at);
        $this->assertTrue($document->wasRevoked());
    }

    public function test_admin_can_delete_a_generated_document(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('compliance/doc.pdf', 'contents');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->create([
            'order_id' => $submission->order_id,
            'pdf_storage_path' => 'compliance/doc.pdf',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('deleteGeneratedDocument', $document->id);

        $this->assertDatabaseMissing('generated_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing('compliance/doc.pdf');
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.deleted']);
    }

    public function test_deleting_a_custom_document_that_is_not_the_active_delivery_source_keeps_the_prior_approval(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private/compliance/1/custom/corrected.pdf', 'contents');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
            'custom_storage_path' => 'private/compliance/1/custom/corrected.pdf',
            'custom_original_filename' => 'corrected.pdf',
            'delivery_source' => 'ai_generated',
        ]);
        $reviewedAt = $document->reviewed_at;

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('deleteCustomDocument', $document->id);

        $document->refresh();
        $this->assertNull($document->custom_storage_path);
        $this->assertSame('ai_generated', $document->delivery_source->value);
        $this->assertEquals($reviewedAt, $document->reviewed_at);
    }

    public function test_admin_can_switch_delivery_source_back_to_ai_generated(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
            'custom_storage_path' => 'private/compliance/1/custom/corrected.pdf',
            'custom_original_filename' => 'corrected.pdf',
            'delivery_source' => 'custom',
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('setDeliverySource', $document->id, 'ai_generated');

        $document->refresh();
        $this->assertSame('ai_generated', $document->delivery_source->value);
        $this->assertNull($document->reviewed_at);
    }

    public function test_a_document_whose_source_questionnaire_extraction_failed_is_demoted_off_ai_generated(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->failed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);

        $component = Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission]);

        $document->refresh();
        $this->assertSame('custom', $document->delivery_source->value);
        $component->assertSee('The AI extraction for this Compliance & Ethics Manual is failed. You should regenerate or custom upload your file');
    }

    public function test_a_document_whose_source_questionnaire_extraction_succeeded_keeps_ai_generated(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);

        $component = Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission]);

        $document->refresh();
        $this->assertSame('ai_generated', $document->delivery_source->value);
        $component->assertDontSee('AI extraction for this');
    }

    public function test_a_failed_extraction_document_that_is_already_approved_is_not_retroactively_demoted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        IntakeUpload::factory()->failed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        $document = GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);

        Livewire::actingAs($admin)->test('admin.submission-detail', ['submission' => $submission]);

        $document->refresh();
        $this->assertSame('ai_generated', $document->delivery_source->value);
    }

    public function test_a_failed_extraction_document_that_already_has_a_custom_file_is_not_demoted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->failed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual,
            'custom_storage_path' => 'private/compliance/1/custom/corrected.pdf',
            'custom_original_filename' => 'corrected.pdf',
        ]);

        Livewire::actingAs($admin)->test('admin.submission-detail', ['submission' => $submission]);

        $document->refresh();
        $this->assertSame('ai_generated', $document->delivery_source->value);
    }

    public function test_a_per_upload_document_is_demoted_when_its_own_linked_upload_extraction_failed(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $upload = IntakeUpload::factory()->failed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $upload->id,
        ]);

        $component = Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission]);

        $document->refresh();
        $this->assertSame('custom', $document->delivery_source->value);
        $component->assertSee('The AI extraction for this Reviewed & Polished Document is failed. You should regenerate or custom upload your file');
    }

    // ── Leads ───────────────────────────────────────────────────────────────

    public function test_admin_can_view_leads_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Lead::factory()->create(['name' => 'Jane Provider']);

        $this->withoutVite()->actingAs($admin)->get(route('admin.leads'))
            ->assertOk()
            ->assertSee('Jane Provider');
    }

    public function test_admin_can_mark_a_lead_contacted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $lead = Lead::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.lead-list')
            ->call('markContacted', $lead->id);

        $this->assertTrue($lead->fresh()->is_contacted);
    }

    public function test_admin_lead_form_pages_render(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $lead = Lead::factory()->create();

        $this->withoutVite()->actingAs($admin);

        $this->get(route('admin.leads.create'))->assertOk();
        $this->get(route('admin.leads.edit', $lead))->assertOk();
    }

    public function test_admin_can_create_a_lead(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.lead-form')
            ->set('name', 'Manually Added Lead')
            ->set('email', 'manual-lead@example.com')
            ->set('message', 'Interested in the Essential package.')
            ->set('adminNotes', 'Called in, not via the contact form.')
            ->call('save')
            ->assertRedirect(route('admin.leads'));

        $this->assertDatabaseHas('leads', [
            'name' => 'Manually Added Lead',
            'email' => 'manual-lead@example.com',
            'admin_notes' => 'Called in, not via the contact form.',
        ]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lead.created']);
    }

    public function test_admin_can_edit_a_lead(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $lead = Lead::factory()->create(['name' => 'Old Lead Name']);

        Livewire::actingAs($admin)
            ->test('admin.lead-form', ['lead' => $lead])
            ->set('name', 'New Lead Name')
            ->set('adminNotes', 'Followed up by phone.')
            ->call('save')
            ->assertRedirect(route('admin.leads'));

        $lead->refresh();
        $this->assertSame('New Lead Name', $lead->name);
        $this->assertSame('Followed up by phone.', $lead->admin_notes);
    }

    public function test_admin_can_delete_a_lead(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $lead = Lead::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.lead-list')
            ->call('delete', $lead->id);

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lead.deleted']);
    }

    // ── Activity log ────────────────────────────────────────────────────────

    public function test_admin_can_view_the_activity_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ActivityLog::record('package.created', 'Findable Event Description', user: $admin);

        $this->withoutVite()->actingAs($admin)->get(route('admin.activity-log'))
            ->assertOk()
            ->assertSee('Findable Event Description');
    }

    public function test_admin_can_search_the_activity_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ActivityLog::record('package.created', 'A findable package event', user: $admin);
        ActivityLog::record('lead.deleted', 'An unrelated lead event', user: $admin);

        Livewire::actingAs($admin)
            ->test('admin.activity-log-list')
            ->set('search', 'findable package')
            ->assertSee('A findable package event')
            ->assertDontSee('An unrelated lead event');
    }

    // ── Payment logs ────────────────────────────────────────────────────────

    public function test_admin_can_view_the_payment_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PaymentLog::factory()->create(['transaction_id' => 'FINDABLE_TXN_ID']);

        $this->withoutVite()->actingAs($admin)->get(route('admin.payment-logs'))
            ->assertOk()
            ->assertSee('FINDABLE_TXN_ID');
    }

    public function test_admin_can_search_the_payment_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create();
        PaymentLog::factory()->create(['package_id' => $package->id, 'transaction_id' => 'FINDABLE_TXN_ID']);
        PaymentLog::factory()->create(['package_id' => $package->id, 'transaction_id' => 'UNRELATED_TXN_ID']);

        Livewire::actingAs($admin)
            ->test('admin.payment-log-list')
            ->set('search', 'FINDABLE_TXN')
            ->assertSee('FINDABLE_TXN_ID')
            ->assertDontSee('UNRELATED_TXN_ID');
    }

    public function test_admin_can_filter_the_payment_log_by_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create();
        PaymentLog::factory()->create(['package_id' => $package->id, 'transaction_id' => 'SUCCESS_TXN_ID']);
        PaymentLog::factory()->declined()->create(['package_id' => $package->id, 'message' => 'Card declined for testing']);

        Livewire::actingAs($admin)
            ->test('admin.payment-log-list')
            ->set('status', 'declined')
            ->assertSee('Card declined for testing')
            ->assertDontSee('SUCCESS_TXN_ID');
    }

    public function test_admin_can_view_the_full_payment_log_detail(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        $log = PaymentLog::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => 'DETAIL_TXN_ID',
            'billing_address' => ['name' => 'Jane Provider', 'address1' => '7 Clyde Road', 'city' => 'Somerset', 'state' => 'NJ', 'zip' => '08873'],
        ]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.payment-logs.show', $log))
            ->assertOk()
            ->assertSee('DETAIL_TXN_ID')
            ->assertSee($user->email)
            ->assertSee('7 Clyde Road');
    }

    public function test_admin_can_delete_a_payment_log_from_the_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $log = PaymentLog::factory()->create(['transaction_id' => 'DELETE_ME_TXN_ID']);

        Livewire::actingAs($admin)
            ->test('admin.payment-log-list')
            ->call('delete', $log->id)
            ->assertDontSee('DELETE_ME_TXN_ID');

        $this->assertDatabaseMissing('payment_logs', ['id' => $log->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'payment_log.deleted']);
    }

    public function test_admin_can_delete_a_payment_log_from_the_detail_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $log = PaymentLog::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.payment-log-detail', ['paymentLog' => $log])
            ->call('delete')
            ->assertRedirect(route('admin.payment-logs'));

        $this->assertDatabaseMissing('payment_logs', ['id' => $log->id]);
    }

    // ── Packages ────────────────────────────────────────────────────────────

    public function test_admin_can_view_packages_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Package::factory()->create(['slug' => 'essential', 'name' => 'Essential Compliance']);

        $this->withoutVite()->actingAs($admin)->get(route('admin.packages'))
            ->assertOk()
            ->assertSee('Essential Compliance');
    }

    public function test_client_cannot_access_packages_list(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->withoutVite()->actingAs($client)->get(route('admin.packages'))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_a_package_for_an_unused_tier(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Package::factory()->create(['slug' => 'essential']);

        Livewire::actingAs($admin)
            ->test('admin.package-form')
            ->set('slug', 'professional')
            ->set('name', 'Professional Compliance')
            ->set('billingType', 'annual')
            ->set('annualPrice', '2490')
            ->set('featuresText', "Feature One\nFeature Two")
            ->set('sortOrder', 2)
            ->call('save')
            ->assertRedirect(route('admin.packages'));

        $this->assertDatabaseHas('packages', [
            'slug' => 'professional',
            'name' => 'Professional Compliance',
        ]);

        $package = Package::where('slug', 'professional')->first();
        $this->assertSame(['Feature One', 'Feature Two'], $package->features);

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'package.created']);
    }

    public function test_creating_a_package_requires_a_tier_not_already_in_use(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Package::factory()->create(['slug' => 'essential']);

        Livewire::actingAs($admin)
            ->test('admin.package-form')
            ->set('slug', 'essential')
            ->set('name', 'Duplicate')
            ->set('billingType', 'annual')
            ->call('save')
            ->assertHasErrors('slug');

        $this->assertSame(1, Package::where('slug', 'essential')->count());
    }

    public function test_admin_can_edit_an_existing_package(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create(['slug' => 'essential', 'name' => 'Essential Compliance']);

        Livewire::actingAs($admin)
            ->test('admin.package-form', ['package' => $package])
            ->assertSet('slug', 'essential')
            ->set('name', 'Essential Compliance Plus')
            ->set('annualPrice', '1999')
            ->call('save')
            ->assertRedirect(route('admin.packages'));

        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'name' => 'Essential Compliance Plus',
            'annual_price' => 1999.00,
        ]);

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'package.updated']);
    }

    public function test_admin_can_toggle_a_packages_active_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test('admin.package-list')
            ->call('toggleActive', $package->id);

        $this->assertFalse($package->fresh()->is_active);
    }

    public function test_admin_can_delete_a_package_with_no_orders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.package-list')
            ->call('delete', $package->id);

        $this->assertDatabaseMissing('packages', ['id' => $package->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'package.deleted']);
    }

    public function test_admin_cannot_delete_a_package_with_existing_orders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create();
        $user = User::factory()->create();
        Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);

        Livewire::actingAs($admin)
            ->test('admin.package-list')
            ->call('delete', $package->id)
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('packages', ['id' => $package->id]);
    }

    // ── Discount codes ──────────────────────────────────────────────────────

    public function test_admin_can_view_discount_codes_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        DiscountCode::factory()->create(['code' => 'SAVE20']);

        $this->withoutVite()->actingAs($admin)->get(route('admin.discount-codes'))
            ->assertOk()
            ->assertSee('SAVE20');
    }

    public function test_client_cannot_access_discount_codes_list(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->withoutVite()->actingAs($client)->get(route('admin.discount-codes'))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_a_percentage_discount_code(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('code', 'save20')
            ->set('type', DiscountType::Percentage->value)
            ->set('percentage', '20')
            ->call('save')
            ->assertRedirect(route('admin.discount-codes'));

        $this->assertDatabaseHas('discount_codes', [
            'code' => 'SAVE20',
            'type' => DiscountType::Percentage->value,
            'percentage' => 20,
            'trial_days' => null,
        ]);

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'discount_code.created']);
    }

    public function test_admin_can_create_a_free_trial_discount_code(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('code', 'TRIAL30')
            ->set('type', DiscountType::FreeTrial->value)
            ->set('trialDays', '30')
            ->call('save')
            ->assertRedirect(route('admin.discount-codes'));

        $this->assertDatabaseHas('discount_codes', [
            'code' => 'TRIAL30',
            'type' => DiscountType::FreeTrial->value,
            'percentage' => null,
            'trial_days' => 30,
        ]);
    }

    public function test_creating_a_percentage_code_requires_a_percentage(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('code', 'SAVE20')
            ->set('type', DiscountType::Percentage->value)
            ->call('save')
            ->assertHasErrors(['percentage']);

        $this->assertDatabaseMissing('discount_codes', ['code' => 'SAVE20']);
    }

    public function test_creating_a_free_trial_code_requires_trial_days(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('code', 'TRIAL30')
            ->set('type', DiscountType::FreeTrial->value)
            ->call('save')
            ->assertHasErrors(['trialDays']);

        $this->assertDatabaseMissing('discount_codes', ['code' => 'TRIAL30']);
    }

    public function test_creating_a_discount_code_rejects_a_duplicate_code(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        DiscountCode::factory()->create(['code' => 'SAVE20']);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('code', 'save20')
            ->set('type', DiscountType::Percentage->value)
            ->set('percentage', '10')
            ->call('save')
            ->assertHasErrors(['code']);

        $this->assertSame(1, DiscountCode::where('code', 'SAVE20')->count());
    }

    public function test_admin_can_edit_an_existing_discount_code(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $discountCode = DiscountCode::factory()->create(['code' => 'SAVE20', 'percentage' => 20]);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-form', ['discountCode' => $discountCode])
            ->assertSet('code', 'SAVE20')
            ->set('percentage', '30')
            ->call('save')
            ->assertRedirect(route('admin.discount-codes'));

        $this->assertDatabaseHas('discount_codes', ['id' => $discountCode->id, 'percentage' => 30]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'discount_code.updated']);
    }

    public function test_admin_can_toggle_a_discount_codes_active_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $discountCode = DiscountCode::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-list')
            ->call('toggleActive', $discountCode->id);

        $this->assertFalse($discountCode->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'discount_code.deactivated']);
    }

    public function test_admin_can_delete_a_discount_code(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $discountCode = DiscountCode::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.discount-code-list')
            ->call('delete', $discountCode->id);

        $this->assertDatabaseMissing('discount_codes', ['id' => $discountCode->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'discount_code.deleted']);
    }

    public function test_admin_can_send_a_discount_code_to_a_selected_user(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client, 'email' => 'jane@practice.com']);
        $discountCode = DiscountCode::factory()->create(['code' => 'SAVE20']);

        Livewire::actingAs($admin)
            ->test('admin.discount-code-send', ['discountCode' => $discountCode])
            ->set('selectedUserIds', [$client->id])
            ->call('send')
            ->assertHasNoErrors();

        Mail::assertSent(DiscountCodeSharedMail::class, fn ($mail) => $mail->hasTo('jane@practice.com') && $mail->discountCode->is($discountCode));
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'discount_code.shared']);
    }

    public function test_admin_can_send_a_discount_code_to_a_selected_lead(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $lead = Lead::factory()->create(['email' => 'lead@practice.com']);
        $discountCode = DiscountCode::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.discount-code-send', ['discountCode' => $discountCode])
            ->set('selectedLeadIds', [$lead->id])
            ->call('send')
            ->assertHasNoErrors();

        Mail::assertSent(DiscountCodeSharedMail::class, fn ($mail) => $mail->hasTo('lead@practice.com'));
    }

    public function test_admin_can_send_a_discount_code_to_a_freeform_email_address(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $discountCode = DiscountCode::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.discount-code-send', ['discountCode' => $discountCode])
            ->set('additionalEmails', 'custom@practice.com')
            ->call('send')
            ->assertHasNoErrors();

        Mail::assertSent(DiscountCodeSharedMail::class, fn ($mail) => $mail->hasTo('custom@practice.com'));
    }

    public function test_sending_a_discount_code_dedupes_recipients_across_all_sources(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client, 'email' => 'jane@practice.com']);
        $lead = Lead::factory()->create(['email' => 'lead@practice.com']);
        $discountCode = DiscountCode::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.discount-code-send', ['discountCode' => $discountCode])
            ->set('selectedUserIds', [$client->id])
            ->set('selectedLeadIds', [$lead->id])
            ->set('additionalEmails', "jane@practice.com\nextra@practice.com")
            ->call('send')
            ->assertHasNoErrors();

        Mail::assertSentCount(3);
    }

    public function test_sending_a_discount_code_requires_at_least_one_recipient(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $discountCode = DiscountCode::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.discount-code-send', ['discountCode' => $discountCode])
            ->call('send')
            ->assertHasErrors(['recipients']);

        Mail::assertNothingSent();
    }

    // ── Intake upload download ───────────────────────────────────────────────

    public function test_admin_can_download_an_intake_upload(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        Storage::disk('local')->put('uploads/test.pdf', 'fake-pdf-content');
        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/test.pdf',
        ]);

        $this->actingAs($admin)->get(route('admin.uploads.download', $upload))->assertOk();
    }

    public function test_client_cannot_access_admin_upload_download_route(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $submission = $this->makeSubmission();
        $upload = IntakeUpload::factory()->create(['intake_submission_id' => $submission->id]);

        $this->actingAs($client)->get(route('admin.uploads.download', $upload))->assertRedirect(route('login'));
    }
}
