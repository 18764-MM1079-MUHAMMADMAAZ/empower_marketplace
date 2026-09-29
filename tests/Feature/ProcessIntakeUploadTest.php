<?php

namespace Tests\Feature;

use App\Enums\AiExtractionStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\GenerateComplianceDocument;
use App\Jobs\ProcessIntakeUpload;
use App\Models\AiUsageLog;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\OshaLocation;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Database\Seeders\QuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessIntakeUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(QuestionnaireSeeder::class);
    }

    private function openaiResponse(string $json): array
    {
        return ['choices' => [['message' => ['content' => $json]]]];
    }

    // ── Vision extraction (PDF / image) ───────────────────────────────────

    public function test_processes_pdf_upload_with_openai_vision(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/1/intake.pdf', '%PDF-1.4 fake content');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/1/intake.pdf',
            'mime_type' => 'application/pdf',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                $this->openaiResponse('{"practice_name":"Sunrise Clinic","npi_number":"1234567890"}')
            ),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Completed, $upload->ai_extraction_status);
        $this->assertEquals('Sunrise Clinic', $upload->ai_extracted_data['practice_name']);
        $this->assertNotNull($upload->processed_at);
    }

    public function test_extracts_json_wrapped_in_markdown_code_fences(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/2/doc.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/2/doc.pdf',
            'mime_type' => 'application/pdf',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                $this->openaiResponse("```json\n{\"specialty\":\"Cardiology\"}\n```")
            ),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Completed, $upload->ai_extraction_status);
        $this->assertEquals('Cardiology', $upload->ai_extracted_data['specialty']);
    }

    // ── Docx text extraction ────────────────────────────────────────────────

    public function test_extracts_text_from_table_based_docx_questionnaire(): void
    {
        Storage::fake('local');

        $phpWord = new PhpWord;
        $table = $phpWord->addSection()->addTable();
        $table->addRow();
        $table->addCell(3000)->addText('Legal Practice Name');
        $table->addCell(6000)->addText('Sunrise Family Medicine');

        $tempPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        Storage::disk('local')->put('uploads/4/questionnaire.docx', file_get_contents($tempPath));
        unlink($tempPath);

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/4/questionnaire.docx',
            'original_filename' => 'questionnaire.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                $this->openaiResponse('{"practice_name":"Sunrise Family Medicine"}')
            ),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'Sunrise Family Medicine'));

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Completed, $upload->ai_extraction_status);
        $this->assertEquals('Sunrise Family Medicine', $upload->ai_extracted_data['practice_name']);
    }

    // ── Structured extraction & AI verification pass (questionnaire-linked types) ──

    /** @return array<string, array{0: IntakeUploadType, 1: string}> */
    public static function questionnaireLinkedTypes(): array
    {
        return [
            'Compliance & Ethics' => [IntakeUploadType::ComplianceEthicsQuestionnaire, 'cmp_01_answer'],
            'HIPAA Business Associate' => [IntakeUploadType::HipaaBusinessAssociateQuestionnaire, 'ba_01_answer'],
            'HIPAA Privacy' => [IntakeUploadType::HipaaPrivacyQuestionnaire, 'prv_01_answer'],
            'HIPAA Security' => [IntakeUploadType::HipaaSecurityQuestionnaire, 'sec_01_answer'],
        ];
    }

    #[DataProvider('questionnaireLinkedTypes')]
    public function test_questionnaire_linked_upload_uses_a_structured_extraction_prompt(
        IntakeUploadType $uploadType,
        string $expectedField,
    ): void {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/7/questionnaire.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/7/questionnaire.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => $uploadType,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        Http::assertSent(function ($request) use ($expectedField) {
            $content = $request['messages'][0]['content'];
            $text = is_array($content) ? ($content[1]['text'] ?? '') : $content;

            return str_contains($text, $expectedField);
        });
    }

    public function test_compliance_ethics_extraction_runs_a_verification_pass_and_saves_its_output(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/8/compliance.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/8/compliance.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->openaiResponse('{"cmp_01_answer":"raw noisy answer"}'))
                ->push($this->openaiResponse('{"cmp_01_answer":"Cleaned answer."}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        Http::assertSentCount(2);

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Completed, $upload->ai_extraction_status);
        $this->assertSame('Cleaned answer.', $upload->ai_extracted_data['cmp_01_answer']);
    }

    public function test_successful_extraction_records_ai_usage_with_token_counts(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/8a/intake.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/8a/intake.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::PracticeIntake,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'choices' => [['message' => ['content' => '{"practice_name":"Sunrise Clinic"}']]],
                'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 150, 'total_tokens' => 1350],
            ]),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $this->assertDatabaseHas('ai_usage_logs', [
            'purpose' => 'intake_extraction_vision',
            'success' => 1,
            'model' => 'gpt-4o',
            'prompt_tokens' => 1200,
            'completion_tokens' => 150,
            'total_tokens' => 1350,
            'intake_upload_id' => $upload->id,
        ]);
    }

    public function test_compliance_ethics_verification_pass_records_its_own_ai_usage_entry(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/8b/compliance.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/8b/compliance.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->openaiResponse('{"cmp_01_answer":"raw noisy answer"}'))
                ->push($this->openaiResponse('{"cmp_01_answer":"Cleaned answer."}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $this->assertSame(
            ['intake_extraction_vision', 'intake_verification'],
            AiUsageLog::orderBy('id')->pluck('purpose')->all(),
        );
    }

    public function test_failed_ai_call_still_records_a_usage_entry(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/8c/intake.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/8c/intake.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::PracticeIntake,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $this->assertDatabaseHas('ai_usage_logs', [
            'purpose' => 'intake_extraction_vision',
            'success' => 0,
        ]);
    }

    public function test_verification_pass_failure_falls_back_to_the_raw_extraction(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/9/compliance.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/9/compliance.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->openaiResponse('{"cmp_01_answer":"raw answer"}'))
                ->push(['error' => 'overloaded'], 529),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Completed, $upload->ai_extraction_status);
        $this->assertSame('raw answer', $upload->ai_extracted_data['cmp_01_answer']);
    }

    // ── Failure handling ──────────────────────────────────────────────────

    public function test_marks_upload_failed_when_openai_returns_http_error(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/3/doc.pdf', 'fake');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/3/doc.pdf',
            'mime_type' => 'application/pdf',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Failed, $upload->ai_extraction_status);
        $this->assertStringContainsString('529', $upload->ai_error_message);
        $this->assertNotNull($upload->processed_at);
    }

    // ── Document generation dispatch ──────────────────────────────────────

    public function test_an_upload_with_no_matching_manual_dispatches_no_generation(): void
    {
        Queue::fake([GenerateComplianceDocument::class]);
        Storage::fake('local');
        Storage::disk('local')->put('uploads/4/intake.pdf', 'fake');

        $user = User::factory()->create();
        $practice = Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->submitted()->create(['order_id' => $order->id]);

        // PracticeIntake is a retired, generic upload type with no matching manual —
        // generation is driven entirely by which of the 4 real questionnaires were
        // uploaded, never by package tier.
        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/4/intake.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::PracticeIntake,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        Queue::assertNotPushed(GenerateComplianceDocument::class);
    }

    public function test_uploading_a_linked_questionnaire_also_dispatches_its_matching_manual(): void
    {
        Queue::fake([GenerateComplianceDocument::class]);
        Storage::fake('local');
        Storage::disk('local')->put('uploads/4b/compliance.pdf', 'fake');

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->submitted()->create(['order_id' => $order->id]);

        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/4b/compliance.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        // Exactly the Compliance & Ethics Manual dispatches, since that's the one
        // questionnaire uploaded — regardless of package tier. The other 3 linked
        // manuals don't dispatch, since they weren't uploaded.
        Queue::assertPushed(GenerateComplianceDocument::class, 1);
        Queue::assertPushed(GenerateComplianceDocument::class, function ($job) {
            return $job->documentType === DocumentType::ComplianceEthicsManual;
        });
        Queue::assertNotPushed(GenerateComplianceDocument::class, function ($job) {
            return $job->documentType === DocumentType::HipaaBusinessAssociateManual;
        });
    }

    public function test_does_not_dispatch_generate_jobs_until_all_uploads_complete(): void
    {
        Queue::fake([GenerateComplianceDocument::class]);
        Storage::fake('local');
        Storage::disk('local')->put('uploads/5/intake.pdf', 'fake');
        Storage::disk('local')->put('uploads/5/osha.pdf', 'fake');

        $submission = IntakeSubmission::factory()->submitted()->create();

        // Two uploads — only processing the first one
        $upload1 = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/5/intake.pdf',
            'mime_type' => 'application/pdf',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);
        // Second upload still pending
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/5/osha.pdf',
            'mime_type' => 'application/pdf',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload1);

        Queue::assertNotPushed(GenerateComplianceDocument::class);
    }

    // ── Sibling propagation (one upload shared across a batch checkout) ─────

    public function test_propagates_extraction_to_sibling_uploads_sharing_the_same_file(): void
    {
        Queue::fake([GenerateComplianceDocument::class]);
        Storage::fake('local');
        Storage::disk('local')->put('uploads/batch/shared.pdf', 'fake pdf');

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        $orderA = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id, 'payment_status' => PaymentStatus::SimulatedPaid]);
        $orderB = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id, 'payment_status' => PaymentStatus::SimulatedPaid]);
        $submissionA = IntakeSubmission::factory()->submitted()->create(['order_id' => $orderA->id]);
        $submissionB = IntakeSubmission::factory()->submitted()->create(['order_id' => $orderB->id]);

        $primaryUpload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submissionA->id,
            'storage_path' => 'uploads/batch/shared.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);
        $siblingUpload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submissionB->id,
            'storage_path' => 'uploads/batch/shared.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                $this->openaiResponse('{"practice_name":"Shared Practice"}')
            ),
        ]);

        ProcessIntakeUpload::dispatchSync($primaryUpload);

        $primaryUpload->refresh();
        $siblingUpload->refresh();

        $this->assertEquals(AiExtractionStatus::Completed, $primaryUpload->ai_extraction_status);
        $this->assertEquals(AiExtractionStatus::Completed, $siblingUpload->ai_extraction_status);
        $this->assertEquals('Shared Practice', $siblingUpload->ai_extracted_data['practice_name']);

        // Two OpenAI calls total for the shared document (extraction + verification pass,
        // since Compliance & Ethics has a structured schema) — not once per order.
        Http::assertSentCount(2);

        // Both orders get their own Compliance & Ethics Manual generated, since both
        // share the uploaded questionnaire.
        Queue::assertPushed(GenerateComplianceDocument::class, 2);
    }

    public function test_marks_sibling_uploads_failed_when_primary_extraction_fails(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/batch/shared.pdf', 'fake pdf');

        $orderA = Order::factory()->create(['package_id' => Package::factory()->create(['slug' => 'essential'])->id]);
        $orderB = Order::factory()->create(['package_id' => Package::factory()->create(['slug' => 'professional'])->id]);
        $submissionA = IntakeSubmission::factory()->submitted()->create(['order_id' => $orderA->id]);
        $submissionB = IntakeSubmission::factory()->submitted()->create(['order_id' => $orderB->id]);

        $primaryUpload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submissionA->id,
            'storage_path' => 'uploads/batch/shared.pdf',
            'mime_type' => 'application/pdf',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);
        $siblingUpload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submissionB->id,
            'storage_path' => 'uploads/batch/shared.pdf',
            'mime_type' => 'application/pdf',
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        ProcessIntakeUpload::dispatchSync($primaryUpload);

        $this->assertEquals(AiExtractionStatus::Failed, $primaryUpload->fresh()->ai_extraction_status);
        $this->assertEquals(AiExtractionStatus::Failed, $siblingUpload->fresh()->ai_extraction_status);
    }

    public function test_configured_osha_locations_do_not_multiply_a_non_per_location_linked_manual(): void
    {
        Queue::fake([GenerateComplianceDocument::class]);
        Storage::fake('local');
        Storage::disk('local')->put('uploads/6/intake.pdf', 'fake');

        $user = User::factory()->create();
        $practice = Practice::factory()->locked()->create(['user_id' => $user->id]);
        OshaLocation::factory()->count(2)->create(['practice_id' => $practice->id]);

        $package = Package::factory()->create(['slug' => 'advanced', 'annual_price' => 1699, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
        ]);
        $submission = IntakeSubmission::factory()->submitted()->create(['order_id' => $order->id]);

        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/6/intake.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        // Exactly one Compliance & Ethics Manual job, regardless of package tier or how
        // many OSHA locations the practice has configured — it isn't a per-location type.
        Queue::assertPushed(GenerateComplianceDocument::class, 1);
        Queue::assertPushed(GenerateComplianceDocument::class, function ($job) {
            return $job->documentType === DocumentType::ComplianceEthicsManual && $job->oshaLocation === null;
        });
    }

    // ── Client document for review (alternate to questionnaire downloads) ──

    public function test_client_document_for_review_upload_uses_the_polish_prompt_and_stores_html(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/10/handbook.pdf', 'fake pdf');

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/10/handbook.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse(json_encode([
                'document_name' => 'Employee Handbook',
                'document_type' => 'Employee Handbook',
                'compliance_framework' => 'HIPAA',
                'compliance_status' => 'PARTIALLY COMPLIANT',
                'overall_summary' => 'Mostly complete, missing a retention policy.',
                'key_findings' => [[
                    'finding' => 'No data retention period specified.',
                    'severity' => 'Medium',
                    'section' => 'Records',
                    'explanation' => 'The handbook does not state how long records are kept.',
                    'recommended_action' => 'Confirm the retention period with the client.',
                ]],
                'revised_document_html' => '<p>Polished content.</p>',
                'change_summary' => [[
                    'section' => 'Records',
                    'change' => 'Added a placeholder for the retention period.',
                    'reason' => 'Missing from the original document.',
                    'compliance_requirement' => 'HIPAA retention requirements.',
                    'client_input_required' => 'Yes',
                ]],
                'outstanding_client_input' => ['Confirm the data retention period.'],
                'final_status' => 'PARTIALLY COMPLIANT',
                'final_status_reason' => 'Awaiting client confirmation on retention period.',
                'final_status_outstanding_items' => ['Confirm the data retention period.'],
            ]))),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        // Exactly one call — unlike the structured questionnaire types, this path has no
        // second "verify and correct" pass.
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $content = $request['messages'][0]['content'];
            $text = is_array($content) ? ($content[1]['text'] ?? '') : $content;

            return str_contains($text, 'Compliance Document Reviewer');
        });

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Completed, $upload->ai_extraction_status);
        $html = $upload->ai_extracted_data['html'];
        $this->assertStringContainsString('A. Document Review Summary', $html);
        $this->assertStringContainsString('PARTIALLY COMPLIANT', $html);
        $this->assertStringContainsString('B. Key Findings', $html);
        $this->assertStringContainsString('No data retention period specified.', $html);
        $this->assertStringContainsString('C. Revised Document', $html);
        $this->assertStringContainsString('<p>Polished content.</p>', $html);
        $this->assertStringContainsString('D. Change Summary', $html);
        $this->assertStringContainsString('E. Outstanding Client Input', $html);
        $this->assertStringContainsString('Confirm the data retention period.', $html);
        $this->assertStringContainsString('F. Final Compliance Status', $html);
    }

    public function test_client_document_for_review_dispatches_one_generation_job_per_upload_not_per_order(): void
    {
        Queue::fake([GenerateComplianceDocument::class]);
        Storage::fake('local');
        Storage::disk('local')->put('uploads/11/handbook.pdf', 'fake');
        Storage::disk('local')->put('uploads/11/safety.pdf', 'fake');

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->submitted()->create(['order_id' => $order->id]);

        $upload1 = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/11/handbook.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);
        $upload2 = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/11/safety.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => ['html' => '<p>Already polished.</p>'],
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{"html":"<p>Polished.</p>"}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload1);

        Queue::assertPushed(GenerateComplianceDocument::class, 2);
        Queue::assertPushed(GenerateComplianceDocument::class, fn ($job) => $job->documentType === DocumentType::PolishedClientDocument
            && $job->intakeUpload?->id === $upload1->id);
        Queue::assertPushed(GenerateComplianceDocument::class, fn ($job) => $job->documentType === DocumentType::PolishedClientDocument
            && $job->intakeUpload?->id === $upload2->id);
    }

    public function test_processing_a_new_upload_does_not_regenerate_an_already_completed_sibling_document(): void
    {
        // Regression: a client sending one MORE document for review, long after their first
        // batch was already generated and approved, must not silently regenerate (and thereby
        // revoke the approval of) that earlier, unrelated document.
        Queue::fake([GenerateComplianceDocument::class]);
        Storage::fake('local');
        Storage::disk('local')->put('uploads/12/handbook.pdf', 'fake');
        Storage::disk('local')->put('uploads/12/extra.pdf', 'fake');

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Approved,
        ]);
        $submission = IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);

        $earlierUpload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/12/handbook.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Completed,
            'ai_extracted_data' => ['html' => '<p>Already polished.</p>'],
        ]);
        GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $earlierUpload->id,
        ]);

        $newUpload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'storage_path' => 'uploads/12/extra.pdf',
            'mime_type' => 'application/pdf',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{"revised_document_html":"<p>Polished.</p>"}')),
        ]);

        ProcessIntakeUpload::dispatchSync($newUpload);

        Queue::assertPushed(GenerateComplianceDocument::class, 1);
        Queue::assertPushed(GenerateComplianceDocument::class, fn ($job) => $job->intakeUpload?->id === $newUpload->id);
        Queue::assertNotPushed(GenerateComplianceDocument::class, fn ($job) => $job->intakeUpload?->id === $earlierUpload->id);
    }

    // ── Image preservation (upload for review) ──────────────────────────────

    public function test_client_document_for_review_docx_preserves_embedded_images(): void
    {
        Storage::fake('local');

        // A real .docx with an image sandwiched between two paragraphs, to prove the image
        // survives extraction -> AI polish -> reinsertion, not just the surrounding text.
        $imagePath = tempnam(sys_get_temp_dir(), 'img').'.png';
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('Before the image.');
        $section->addImage($imagePath, ['width' => 50, 'height' => 50]);
        $section->addText('After the image.');

        $tempPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        Storage::disk('local')->put('uploads/20/handbook.docx', file_get_contents($tempPath));
        unlink($tempPath);
        unlink($imagePath);

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/20/handbook.docx',
            'original_filename' => 'handbook.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                $this->openaiResponse('{"revised_document_html":"<p>Before the image.</p>[[IMAGE_1]]<p>After the image.</p>"}')
            ),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        // The prompt sent to OpenAI must include both the placeholder and the instruction to
        // preserve it — this is what "refining the prompt" actually needs to accomplish.
        Http::assertSent(function ($request) {
            $text = $request['messages'][0]['content'];

            return str_contains($text, '[[IMAGE_1]]') && str_contains($text, 'image placeholders');
        });

        $upload->refresh();
        $this->assertEquals(AiExtractionStatus::Completed, $upload->ai_extraction_status);
        $html = $upload->ai_extracted_data['html'];

        // The final stored HTML has the real embedded image, not the placeholder token.
        $this->assertStringNotContainsString('[[IMAGE_1]]', $html);
        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Before the image.', $html);
        $this->assertStringContainsString('After the image.', $html);
    }

    public function test_client_document_for_review_appends_image_when_ai_drops_the_placeholder(): void
    {
        Storage::fake('local');

        $imagePath = tempnam(sys_get_temp_dir(), 'img').'.png';
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('Some text.');
        $section->addImage($imagePath, ['width' => 50, 'height' => 50]);

        $tempPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        Storage::disk('local')->put('uploads/21/handbook.docx', file_get_contents($tempPath));
        unlink($tempPath);
        unlink($imagePath);

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/21/handbook.docx',
            'original_filename' => 'handbook.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        // The AI ignores the placeholder-preservation instruction entirely.
        Http::fake([
            'https://api.openai.com/*' => Http::response($this->openaiResponse('{"revised_document_html":"<p>Some polished text.</p>"}')),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $html = $upload->fresh()->ai_extracted_data['html'];
        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
    }

    public function test_client_document_for_review_image_upload_embeds_the_original_image(): void
    {
        Storage::fake('local');

        $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Storage::disk('local')->put('uploads/22/scan.png', $imageBytes);

        $upload = IntakeUpload::factory()->create([
            'storage_path' => 'uploads/22/scan.png',
            'original_filename' => 'scan.png',
            'mime_type' => 'image/png',
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::Pending,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                $this->openaiResponse('{"revised_document_html":"<p>Transcribed text from the scan.</p>"}')
            ),
        ]);

        ProcessIntakeUpload::dispatchSync($upload);

        $html = $upload->fresh()->ai_extracted_data['html'];
        $this->assertStringContainsString('Transcribed text from the scan.', $html);
        $this->assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($imageBytes).'"', $html);
    }
}
