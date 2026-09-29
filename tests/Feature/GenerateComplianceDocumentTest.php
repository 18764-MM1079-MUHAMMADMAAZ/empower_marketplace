<?php

namespace Tests\Feature;

use App\Enums\AiExtractionStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeUploadType;
use App\Enums\PaymentStatus;
use App\Jobs\GenerateComplianceDocument;
use App\Mail\ClientDocumentsApprovedMail;
use App\Models\CompliancePolicy;
use App\Models\GeneratedDocument;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\OshaLocation;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\CompliancePdfGenerator;
use Database\Seeders\QuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateComplianceDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(QuestionnaireSeeder::class);
    }

    private function mockPdfGenerator(): void
    {
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn('%PDF-1.4 fake-content');
        });
    }

    private function makeOrder(string $packageSlug = 'essential'): Order
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'slug' => $packageSlug,
            'annual_price' => 999,
            'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);

        return $order;
    }

    // ── Happy path ────────────────────────────────────────────────────────

    public function test_generates_pdf_and_creates_completed_document_record(): void
    {
        Storage::fake('local');
        $this->mockPdfGenerator();

        $order = $this->makeOrder();

        GenerateComplianceDocument::dispatchSync($order, DocumentType::EmployeeHandbookBasic);

        $this->assertDatabaseHas('generated_documents', [
            'order_id' => $order->id,
            'document_type' => DocumentType::EmployeeHandbookBasic->value,
            'status' => DocumentStatus::Completed->value,
        ]);

        $doc = GeneratedDocument::where('order_id', $order->id)->first();
        $this->assertNotNull($doc->pdf_storage_path);
        $this->assertNotNull($doc->pdf_owner_password);
        $this->assertNotNull($doc->generated_at);
        Storage::disk('local')->assertExists($doc->pdf_storage_path);
    }

    public function test_generates_osha_location_report_with_location(): void
    {
        Storage::fake('local');
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('%PDF-1.4 fake');
        });

        $order = $this->makeOrder('advanced');
        $location = OshaLocation::factory()->create([
            'practice_id' => $order->user->practice->id,
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::OshaLocationReport, $location);

        $this->assertDatabaseHas('generated_documents', [
            'order_id' => $order->id,
            'document_type' => DocumentType::OshaLocationReport->value,
            'osha_location_id' => $location->id,
            'status' => DocumentStatus::Completed->value,
        ]);
    }

    public function test_pdf_stored_at_correct_path(): void
    {
        Storage::fake('local');
        $this->mockPdfGenerator();

        $order = $this->makeOrder();

        GenerateComplianceDocument::dispatchSync($order, DocumentType::OshaSafetyPlan);

        $doc = GeneratedDocument::where([
            'order_id' => $order->id,
            'document_type' => DocumentType::OshaSafetyPlan->value,
        ])->firstOrFail();

        $this->assertStringStartsWith("private/compliance/{$order->id}/", $doc->pdf_storage_path);
        $this->assertStringEndsWith('.pdf', $doc->pdf_storage_path);
    }

    public function test_regenerating_an_approved_document_revokes_its_approval(): void
    {
        Storage::fake('local');
        $this->mockPdfGenerator();

        $order = $this->makeOrder();
        $admin = User::factory()->create();
        $document = GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
            'reviewed_by' => $admin->id,
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::EmployeeHandbookBasic);

        $document->refresh();
        $this->assertNull($document->reviewed_at);
        $this->assertNull($document->reviewed_by);
        $this->assertEquals(DocumentStatus::Completed, $document->status);
    }

    /**
     * Reproduces a real production bug: an admin approves a submission while one of its
     * documents is still generating (or generation just hadn't started yet). The submission-level
     * Approve button is the only approval action and it's gone once the submission is Approved,
     * so without this fix the document — despite finishing successfully moments later — was
     * stranded "Pending Review" forever and never delivered to the client.
     */
    public function test_a_document_that_finishes_after_its_submission_was_already_approved_is_auto_approved(): void
    {
        Storage::fake('local');
        $this->mockPdfGenerator();
        Mail::fake();

        $order = $this->makeOrder();
        $admin = User::factory()->create();
        $order->intakeSubmission->update(['reviewed_by' => $admin->id]);

        // Never generated before — this is exactly the "still generating at approval time" case,
        // not a regeneration of something already delivered.
        $this->assertDatabaseMissing('generated_documents', ['order_id' => $order->id]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::EmployeeHandbookBasic);

        $document = GeneratedDocument::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(DocumentStatus::Completed, $document->status);
        $this->assertNotNull($document->reviewed_at);
        $this->assertEquals($admin->id, $document->reviewed_by);
        $this->assertTrue($document->isReady());

        Mail::assertSent(ClientDocumentsApprovedMail::class, fn ($mail) => $mail->order->id === $order->id
            && $mail->documents->contains('id', $document->id));
    }

    public function test_idempotent_upsert_does_not_create_duplicate_records(): void
    {
        Storage::fake('local');
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->twice()->andReturn('%PDF-1.4 fake');
        });

        $order = $this->makeOrder();

        GenerateComplianceDocument::dispatchSync($order, DocumentType::EmployeeHandbookBasic);
        GenerateComplianceDocument::dispatchSync($order, DocumentType::EmployeeHandbookBasic);

        $this->assertDatabaseCount('generated_documents', 1);
    }

    // ── Docx-only documents (Complete tier, merged from a real .docx template) ─

    public function test_docx_only_document_completes_with_merged_docx_and_no_pdf(): void
    {
        $order = $this->makeOrder('complete');

        GenerateComplianceDocument::dispatchSync($order, DocumentType::RevenueCycleBillingManual);

        $doc = GeneratedDocument::where('order_id', $order->id)->firstOrFail();

        $this->assertEquals(DocumentStatus::Completed, $doc->status);
        $this->assertNull($doc->pdf_storage_path);
        $this->assertNull($doc->pdf_owner_password);
        $this->assertNotNull($doc->docx_storage_path);
        Storage::disk('local')->assertExists($doc->docx_storage_path);

        $bytes = Storage::disk('local')->get($doc->docx_storage_path);
        $this->assertStringStartsWith('PK', $bytes);

        Storage::disk('local')->deleteDirectory("private/compliance/{$order->id}");
    }

    // ── Questionnaire-linked manuals (real answers merged into the template, then
    //    converted to a protected PDF) ────────────────────────────────────────────

    public function test_compliance_ethics_manual_merges_real_answers_and_produces_a_protected_pdf(): void
    {
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('%PDF-1.4 fake protected pdf');
        });

        $order = $this->makeOrder('complete');
        $submission = $order->intakeSubmission;

        $answers = [
            'compliance_officer_name' => 'Dr. Jane Rivera',
            'compliance_officer_email' => 'jane.rivera@example.com',
            'compliance_officer_phone' => '(555) 010-2200',
            'governing_body' => 'The Board of Partners',
            'compliance_committee_members' => 'Dr. Rivera, T. Lee, R. Chen',
        ];
        for ($i = 1; $i <= 17; $i++) {
            $answers[sprintf('cmp_%02d_answer', $i)] = "Answer for question {$i}.";
        }

        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => $answers,
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::ComplianceEthicsManual);

        $doc = GeneratedDocument::where('order_id', $order->id)
            ->where('document_type', DocumentType::ComplianceEthicsManual)
            ->firstOrFail();

        $this->assertEquals(DocumentStatus::Completed, $doc->status);
        $this->assertNotNull($doc->docx_storage_path);
        $this->assertNotNull($doc->pdf_storage_path);
        $this->assertNotNull($doc->pdf_owner_password);
        Storage::disk('local')->assertExists($doc->docx_storage_path);
        Storage::disk('local')->assertExists($doc->pdf_storage_path);
        $this->assertSame('%PDF-1.4 fake protected pdf', Storage::disk('local')->get($doc->pdf_storage_path));

        // The merged docx must contain the client's real answers, not the template's blanks.
        $absoluteDocxPath = Storage::disk('local')->path($doc->docx_storage_path);
        $zip = new \ZipArchive;
        $zip->open($absoluteDocxPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('Answer for question 1.', $xml);
        $this->assertStringContainsString('Answer for question 17.', $xml);
        $this->assertStringContainsString('Dr. Jane Rivera', $xml);
        $this->assertStringNotContainsString('Click or tap here to enter text.', $xml);
        $this->assertStringNotContainsString('COMPANY', $xml);

        Storage::disk('local')->deleteDirectory("private/compliance/{$order->id}");
    }

    public function test_hipaa_business_associate_manual_merges_real_answers_and_produces_a_protected_pdf(): void
    {
        $this->assertQuestionnaireManualMergesRealAnswers(
            documentType: DocumentType::HipaaBusinessAssociateManual,
            uploadType: IntakeUploadType::HipaaBusinessAssociateQuestionnaire,
            extraAnswers: [
                'ba_officer_name' => 'Dr. Jane Rivera',
                'ba_officer_email' => 'jane.rivera@example.com',
                'ba_officer_phone' => '(555) 010-2200',
            ],
            questionPrefix: 'ba',
            questionCount: 46,
        );
    }

    public function test_hipaa_security_manual_merges_real_answers_and_produces_a_protected_pdf(): void
    {
        $this->assertQuestionnaireManualMergesRealAnswers(
            documentType: DocumentType::HipaaSecurityManual,
            uploadType: IntakeUploadType::HipaaSecurityQuestionnaire,
            extraAnswers: [
                'security_officer_name' => 'Dr. Jane Rivera',
                'security_officer_email' => 'jane.rivera@example.com',
                'security_officer_phone' => '(555) 010-2200',
            ],
            questionPrefix: 'sec',
            questionCount: 46,
        );
    }

    public function test_hipaa_privacy_policy_merges_real_answers_and_produces_a_protected_pdf(): void
    {
        $this->assertQuestionnaireManualMergesRealAnswers(
            documentType: DocumentType::HipaaPrivacyPolicy,
            uploadType: IntakeUploadType::HipaaPrivacyQuestionnaire,
            extraAnswers: [
                'privacy_officer_name' => 'Dr. Jane Rivera',
                'privacy_officer_email' => 'jane.rivera@example.com',
                'privacy_officer_phone' => '(555) 010-2200',
            ],
            questionPrefix: 'prv',
            questionCount: 38,
        );
    }

    /** @param array<string, string> $extraAnswers */
    private function assertQuestionnaireManualMergesRealAnswers(
        DocumentType $documentType,
        IntakeUploadType $uploadType,
        array $extraAnswers,
        string $questionPrefix,
        int $questionCount,
    ): void {
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('%PDF-1.4 fake protected pdf');
        });

        $order = $this->makeOrder('complete');
        $submission = $order->intakeSubmission;

        $answers = $extraAnswers;
        for ($i = 1; $i <= $questionCount; $i++) {
            $answers[sprintf('%s_%02d_answer', $questionPrefix, $i)] = "Answer for question {$i}.";
        }

        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => $uploadType,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => $answers,
        ]);

        GenerateComplianceDocument::dispatchSync($order, $documentType);

        $doc = GeneratedDocument::where('order_id', $order->id)
            ->where('document_type', $documentType)
            ->firstOrFail();

        $this->assertEquals(DocumentStatus::Completed, $doc->status);
        $this->assertNotNull($doc->docx_storage_path);
        $this->assertNotNull($doc->pdf_storage_path);
        $this->assertNotNull($doc->pdf_owner_password);
        Storage::disk('local')->assertExists($doc->docx_storage_path);
        Storage::disk('local')->assertExists($doc->pdf_storage_path);
        $this->assertSame('%PDF-1.4 fake protected pdf', Storage::disk('local')->get($doc->pdf_storage_path));

        $absoluteDocxPath = Storage::disk('local')->path($doc->docx_storage_path);
        $zip = new \ZipArchive;
        $zip->open($absoluteDocxPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('Answer for question 1.', $xml);
        $this->assertStringContainsString("Answer for question {$questionCount}.", $xml);
        $this->assertStringContainsString(reset($extraAnswers), $xml);
        $this->assertStringNotContainsString('Click or tap here to enter text.', $xml);
        $this->assertStringNotContainsString('COMPANY', $xml);

        Storage::disk('local')->deleteDirectory("private/compliance/{$order->id}");
    }

    public function test_compliance_ethics_manual_defaults_missing_answers_when_questionnaire_not_uploaded(): void
    {
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('%PDF-1.4 fake');
        });

        $order = $this->makeOrder('complete');

        GenerateComplianceDocument::dispatchSync($order, DocumentType::ComplianceEthicsManual);

        $doc = GeneratedDocument::where('order_id', $order->id)
            ->where('document_type', DocumentType::ComplianceEthicsManual)
            ->firstOrFail();

        $this->assertEquals(DocumentStatus::Completed, $doc->status);

        $absoluteDocxPath = Storage::disk('local')->path($doc->docx_storage_path);
        $zip = new \ZipArchive;
        $zip->open($absoluteDocxPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('[No response provided]', $xml);
        $this->assertStringNotContainsString('Click or tap here to enter text.', $xml);

        Storage::disk('local')->deleteDirectory("private/compliance/{$order->id}");
    }

    // ── Practice Intake wizard-driven manuals (policy/answer based) ──────────

    public function test_compliance_ethics_manual_merges_intake_answers_and_removes_unanswered_policy_sections(): void
    {
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('%PDF-1.4 fake protected pdf');
        });

        $order = $this->makeOrder('complete');
        $submission = $order->intakeSubmission;

        $order->user->practice->update([
            'compliance_officer_name' => 'Dr. Jane Rivera',
            'compliance_officer_email' => 'jane.rivera@example.com',
            'compliance_officer_phone' => '(555) 010-2200',
        ]);

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $policyAnswered = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => []]);
        $policyUnanswered = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-02', 'title' => 'Management', 'requirements' => []]);

        $questionAnswered = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $questionAnswered->policies()->attach($policyAnswered->id);
        $questionUnanswered = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 2, 'title' => "Management's role"]);
        $questionUnanswered->policies()->attach($policyUnanswered->id);

        $submission->intakeAnswers()->create([
            'intake_question_id' => $questionAnswered->id,
            'response' => 'The board reviews the compliance program every quarter.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $questionUnanswered->id,
            'response' => null,
            'has_documented_process' => false,
            'answered_at' => now(),
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::ComplianceEthicsManual);

        $doc = GeneratedDocument::where('order_id', $order->id)
            ->where('document_type', DocumentType::ComplianceEthicsManual)
            ->firstOrFail();
        $this->assertEquals(DocumentStatus::Completed, $doc->status);

        $absoluteDocxPath = Storage::disk('local')->path($doc->docx_storage_path);
        $zip = new \ZipArchive;
        $zip->open($absoluteDocxPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('The board reviews the compliance program every quarter.', $xml);
        $this->assertStringContainsString('Dr. Jane Rivera', $xml);

        // The unanswered policy's whole section is gone — not left with placeholder text.
        $this->assertStringNotContainsString('cmp_02_block', $xml);
        $this->assertStringNotContainsString('cmp_02_answer', $xml);
        $this->assertStringNotContainsString('[No response provided]', $xml);

        Storage::disk('local')->deleteDirectory("private/compliance/{$order->id}");
    }

    public function test_a_policy_fed_by_multiple_questions_concatenates_every_answered_response(): void
    {
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('%PDF-1.4 fake protected pdf');
        });

        $order = $this->makeOrder('complete');
        $submission = $order->intakeSubmission;

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $sharedPolicy = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-05', 'title' => 'Staff sign-offs', 'requirements' => []]);

        $questionA = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Compliance & HIPAA training']);
        $questionA->policies()->attach($sharedPolicy->id);
        $questionB = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 2, 'title' => 'Reporting concerns']);
        $questionB->policies()->attach($sharedPolicy->id);

        $submission->intakeAnswers()->create([
            'intake_question_id' => $questionA->id,
            'response' => 'Staff complete annual HIPAA training.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $questionB->id,
            'response' => 'Concerns are reported to the compliance hotline.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::ComplianceEthicsManual);

        $doc = GeneratedDocument::where('order_id', $order->id)
            ->where('document_type', DocumentType::ComplianceEthicsManual)
            ->firstOrFail();

        $absoluteDocxPath = Storage::disk('local')->path($doc->docx_storage_path);
        $zip = new \ZipArchive;
        $zip->open($absoluteDocxPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        // Both source questions' answers appear in the shared policy's merge field, each
        // labeled by its own question title, and the section is kept (not removed).
        $this->assertStringContainsString('Compliance &amp; HIPAA training', $xml);
        $this->assertStringContainsString('Staff complete annual HIPAA training.', $xml);
        $this->assertStringContainsString('Reporting concerns', $xml);
        $this->assertStringContainsString('Concerns are reported to the compliance hotline.', $xml);
        $this->assertStringNotContainsString('cmp_05_block', $xml);

        Storage::disk('local')->deleteDirectory("private/compliance/{$order->id}");
    }

    public function test_docx_only_document_fails_cleanly_when_template_is_missing(): void
    {
        $order = $this->makeOrder('complete');

        $templatePath = storage_path('app/templates/revenue_cycle_billing_manual.docx');
        $backupPath = $templatePath.'.bak';
        rename($templatePath, $backupPath);

        try {
            GenerateComplianceDocument::dispatchSync($order, DocumentType::RevenueCycleBillingManual);
        } finally {
            rename($backupPath, $templatePath);
        }

        $doc = GeneratedDocument::where('order_id', $order->id)->firstOrFail();

        $this->assertEquals(DocumentStatus::Failed, $doc->status);
        $this->assertNotNull($doc->failure_reason);
        $this->assertNull($doc->docx_storage_path);
    }

    // ── AI-polished client documents (upload for review) ────────────────────

    public function test_generates_a_pdf_only_polished_document_from_the_uploads_ai_html(): void
    {
        Storage::fake('local');
        $this->mockPdfGenerator();

        $order = $this->makeOrder();
        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => ['html' => '<p>Polished content.</p>'],
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::PolishedClientDocument, null, $upload);

        $doc = GeneratedDocument::where('order_id', $order->id)->firstOrFail();

        $this->assertEquals(DocumentStatus::Completed, $doc->status);
        $this->assertSame($upload->id, $doc->intake_upload_id);
        $this->assertNotNull($doc->pdf_storage_path);
        $this->assertNull($doc->docx_storage_path);
        $this->assertNotNull($doc->pdf_owner_password);
        Storage::disk('local')->assertExists($doc->pdf_storage_path);
    }

    public function test_two_uploads_of_the_same_type_produce_two_distinct_generated_document_rows(): void
    {
        Storage::fake('local');
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->twice()->andReturn('%PDF-1.4 fake');
        });

        $order = $this->makeOrder();
        $uploadA = IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => ['html' => '<p>First.</p>'],
        ]);
        $uploadB = IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => ['html' => '<p>Second.</p>'],
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::PolishedClientDocument, null, $uploadA);
        GenerateComplianceDocument::dispatchSync($order, DocumentType::PolishedClientDocument, null, $uploadB);

        $this->assertDatabaseCount('generated_documents', 2);
        $this->assertDatabaseHas('generated_documents', ['intake_upload_id' => $uploadA->id]);
        $this->assertDatabaseHas('generated_documents', ['intake_upload_id' => $uploadB->id]);
    }

    public function test_regenerating_a_polished_document_reuses_its_existing_row_via_intake_upload_id(): void
    {
        Storage::fake('local');
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->twice()->andReturn('%PDF-1.4 fake');
        });

        $order = $this->makeOrder();
        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => ['html' => '<p>Polished.</p>'],
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::PolishedClientDocument, null, $upload);
        GenerateComplianceDocument::dispatchSync($order, DocumentType::PolishedClientDocument, null, $upload);

        $this->assertDatabaseCount('generated_documents', 1);
    }

    public function test_polished_document_generation_fails_gracefully_when_source_upload_has_no_html_yet(): void
    {
        Storage::fake('local');

        $order = $this->makeOrder();
        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Pending,
            'ai_extracted_data' => null,
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::PolishedClientDocument, null, $upload);

        $doc = GeneratedDocument::where('order_id', $order->id)->firstOrFail();

        $this->assertEquals(DocumentStatus::Failed, $doc->status);
        $this->assertNotNull($doc->failure_reason);
        $this->assertNull($doc->pdf_storage_path);
    }

    // ── Advanced tier: AI-synthesized reports ───────────────────────────────

    public function test_security_risk_assessment_synthesizes_a_report_from_security_section_answers(): void
    {
        Storage::fake('local');
        $this->mockPdfGenerator();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                'choices' => [['message' => ['content' => '<h2>Security Risk Assessment</h2><p>Report body.</p>']]],
            ]),
        ]);

        $order = $this->makeOrder();
        $section = IntakeSection::create(['key' => 'security_people_access', 'label' => 'Security: people & access', 'sort_order' => 1]);
        $question = IntakeQuestion::create([
            'intake_section_id' => $section->id,
            'sort_order' => 1,
            'title' => 'Security Officer role',
            'prompt_summary' => 'How does your Security Officer carry out the role?',
            'why_we_ask' => 'HIPAA requires a named Security Officer.',
        ]);
        $order->intakeSubmission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => 'Our IT director, Sam Lee, holds this role and reports to the practice owner quarterly.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::SecurityRiskAssessment);

        $doc = GeneratedDocument::where('order_id', $order->id)->where('document_type', DocumentType::SecurityRiskAssessment)->firstOrFail();

        $this->assertEquals(DocumentStatus::Completed, $doc->status);
        $this->assertNotNull($doc->pdf_storage_path);
        Storage::disk('local')->assertExists($doc->pdf_storage_path);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com')
            && str_contains(json_encode($request->data()), 'Security Officer role')
            && str_contains(json_encode($request->data()), 'Sam Lee'));
    }

    public function test_coding_mini_audit_report_synthesizes_a_report_from_the_encounter_list_upload(): void
    {
        Storage::fake('local');
        $this->mockPdfGenerator();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                'choices' => [['message' => ['content' => '<h2>Coding & Documentation Mini Audit Report</h2><p>Report body.</p>']]],
            ]),
        ]);

        $order = $this->makeOrder();
        IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'document_category' => 'encounter_list',
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => ['raw_text' => '2026-01-05 | Dr. Lee | 99213 | Z00.00'],
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::CodingMiniAuditReport);

        $doc = GeneratedDocument::where('order_id', $order->id)->where('document_type', DocumentType::CodingMiniAuditReport)->firstOrFail();

        $this->assertEquals(DocumentStatus::Completed, $doc->status);
        $this->assertNotNull($doc->pdf_storage_path);
        Storage::disk('local')->assertExists($doc->pdf_storage_path);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com')
            && str_contains(json_encode($request->data()), '99213'));
    }

    public function test_mini_audit_report_fails_cleanly_when_no_encounter_list_was_uploaded(): void
    {
        Storage::fake('local');

        $order = $this->makeOrder();

        GenerateComplianceDocument::dispatchSync($order, DocumentType::CodingMiniAuditReport);

        $doc = GeneratedDocument::where('order_id', $order->id)->where('document_type', DocumentType::CodingMiniAuditReport)->firstOrFail();

        $this->assertEquals(DocumentStatus::Failed, $doc->status);
        $this->assertNotNull($doc->failure_reason);
    }

    // ── Failure handling ──────────────────────────────────────────────────

    public function test_marks_document_failed_when_view_not_found(): void
    {
        Storage::fake('local');
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new \RuntimeException('view not found'));
        });

        $order = $this->makeOrder();

        GenerateComplianceDocument::dispatchSync($order, DocumentType::EmployeeHandbookBasic);

        $this->assertDatabaseHas('generated_documents', [
            'order_id' => $order->id,
            'status' => DocumentStatus::Failed->value,
        ]);

        $doc = GeneratedDocument::where('order_id', $order->id)->first();
        $this->assertNotNull($doc->failure_reason);
    }

    // ── Manual History table ────────────────────────────────────────────────

    public function test_manual_history_row_is_stamped_new_on_first_generation_and_revision_on_regeneration(): void
    {
        Storage::fake('local');
        $this->mock(CompliancePdfGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->twice()->andReturn('%PDF-1.4 fake protected pdf');
        });

        $order = $this->makeOrder();
        $submission = $order->intakeSubmission;

        $answers = ['compliance_officer_name' => 'Dr. Jane Rivera'];
        for ($i = 1; $i <= 17; $i++) {
            $answers[sprintf('cmp_%02d_answer', $i)] = "Answer for question {$i}.";
        }

        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => $answers,
        ]);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::ComplianceEthicsManual);

        $doc = GeneratedDocument::where('order_id', $order->id)
            ->where('document_type', DocumentType::ComplianceEthicsManual)
            ->firstOrFail();

        $xml = $this->readDocxXml($doc->docx_storage_path);
        $this->assertStringContainsString('Initial policy generated.', $xml);
        $this->assertStringNotContainsString('Policy regenerated following an update.', $xml);

        GenerateComplianceDocument::dispatchSync($order, DocumentType::ComplianceEthicsManual);

        $doc->refresh();
        $xml = $this->readDocxXml($doc->docx_storage_path);
        $this->assertStringContainsString('Policy regenerated following an update.', $xml);
        $this->assertStringNotContainsString('Initial policy generated.', $xml);

        Storage::disk('local')->deleteDirectory("private/compliance/{$order->id}");
    }

    private function readDocxXml(string $storagePath): string
    {
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($storagePath));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }
}
