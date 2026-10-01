<?php

namespace Tests\Feature;

use App\Enums\AiExtractionStatus;
use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateComplianceDocument;
use App\Jobs\ProcessIntakeUpload;
use App\Mail\ClientDocumentsApprovedMail;
use App\Mail\ClientSubmissionStatusMail;
use App\Mail\DiscountCodeSharedMail;
use App\Models\ActivityLog;
use App\Models\AiUsageLog;
use App\Models\CompliancePolicy;
use App\Models\DiscountCode;
use App\Models\GeneratedDocument;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Practice;
use App\Models\Questionnaire;
use App\Models\User;
use Database\Seeders\QuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('manual_templates');
        $this->seed(QuestionnaireSeeder::class);
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

    public function test_dashboard_shows_openai_usage_for_the_last_14_days_only(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        AiUsageLog::factory()->count(3)->create(['created_at' => now()->subDays(2)]);
        AiUsageLog::factory()->count(2)->create(['created_at' => now()->subDays(5)]);
        // Outside the 14-day window — must not be counted.
        AiUsageLog::factory()->count(10)->create(['created_at' => now()->subDays(20)]);

        $response = $this->withoutVite()->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()->assertSee('OpenAI Usage');

        $this->assertMatchesRegularExpression(
            '/text-2xl font-extrabold text-navy">5<\/div>\s*<div class="text-xs text-empower-muted">total calls/',
            $response->getContent(),
        );
    }

    // ── Submissions ─────────────────────────────────────────────────────────

    public function test_admin_can_view_submissions_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->makeSubmission();

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions'))->assertOk();
    }

    public function test_submissions_list_flags_an_approved_submission_with_a_document_awaiting_review(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        // A document can land ready-for-review without the submission's own status changing —
        // e.g. the client sent one more document for AI review well after approval — so the list
        // needs its own signal for this, independent of the status badge.
        GeneratedDocument::factory()->completed()->create(['order_id' => $submission->order_id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-list')
            ->assertSee('1 to review');
    }

    public function test_submissions_list_does_not_flag_a_submission_with_no_pending_documents(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $submission->order_id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-list')
            ->assertDontSee('to review');
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

        // Submission is still Submitted (review hasn't started), so the placeholder row that
        // ensureExpectedDocumentsExist() pre-creates should say generation hasn't begun yet —
        // not claim it's already in progress, since no job has been dispatched for it.
        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Document Review')
            ->assertSee('Compliance & Ethics Manual')
            ->assertSee('Not Started')
            ->assertSee('Generation hasn\'t started yet', false);

        $this->assertDatabaseHas('generated_documents', [
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual->value,
            'status' => DocumentStatus::Pending->value,
        ]);
    }

    public function test_document_review_shows_waiting_message_once_review_has_started(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::UnderReview);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Documents are being generated')
            ->assertSee('Waiting on AI generation')
            ->assertDontSee('Generation hasn\'t started yet');
    }

    public function test_document_review_shows_an_empty_state_with_no_expected_documents(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Document Review')
            ->assertSee("this package doesn't include any auto-generated manuals", false);
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

    public function test_submission_detail_does_not_show_an_ai_extraction_banner_for_reference_only_uploads(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::NotApplicable,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertDontSee('AI Extraction Complete')
            ->assertDontSee('AI Extraction Failed')
            ->assertDontSee('AI Extraction In Progress')
            ->assertSee('Reference document (not AI-processed)');
    }

    public function test_submission_detail_shows_the_practices_intake_wizard_answers(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $answeredQuestion = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $unansweredQuestion = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 2, 'title' => "Management's role"]);
        $noProcessQuestion = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 3, 'title' => 'Compliance Committee']);

        $submission->intakeAnswers()->create([
            'intake_question_id' => $answeredQuestion->id,
            'response' => 'The board reviews the program every quarter.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $noProcessQuestion->id,
            'response' => null,
            'has_documented_process' => false,
            'answered_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('Practice Intake Answers')
            ->assertSee('Compliance program')
            ->assertSee('Owner & board oversight')
            ->assertSee('The board reviews the program every quarter.')
            ->assertSee('Compliance Committee')
            ->assertSee('No documented answer · policy default language applies')
            ->assertSee("Management's role")
            ->assertSee('Not yet answered');
    }

    public function test_submission_detail_hides_the_answers_section_when_there_are_no_answers(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertDontSee('Practice Intake Answers');
    }

    public function test_admin_can_edit_a_practices_answered_intake_response(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => 'Original response.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startEditingAnswer', $question->id)
            ->assertSet('editingAnswerResponse', 'Original response.')
            ->assertSet('editingAnswerHasDocumentedProcess', true)
            ->set('editingAnswerResponse', 'Corrected by admin after a call with the practice.')
            ->call('saveEditedAnswer')
            ->assertSet('editingAnswerQuestionId', null)
            ->assertSee('Corrected by admin after a call with the practice.');

        $this->assertDatabaseHas('intake_answers', [
            'intake_submission_id' => $submission->id,
            'intake_question_id' => $question->id,
            'response' => 'Corrected by admin after a call with the practice.',
            'has_documented_process' => true,
        ]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'submission.answer_edited']);
    }

    public function test_admin_can_answer_a_previously_unanswered_intake_question(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $answeredQuestion = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $openQuestion = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 2, 'title' => "Management's role"]);
        // At least one answer in the section is required for the whole panel (and this question
        // alongside it) to render at all — see intakeAnswersBySection()'s done > 0 filter.
        $submission->intakeAnswers()->create([
            'intake_question_id' => $answeredQuestion->id,
            'response' => 'The board reviews the program every quarter.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startEditingAnswer', $openQuestion->id)
            ->set('editingAnswerResponse', 'Managers escalate issues to the Compliance Officer weekly.')
            ->call('saveEditedAnswer');

        $this->assertDatabaseHas('intake_answers', [
            'intake_submission_id' => $submission->id,
            'intake_question_id' => $openQuestion->id,
            'response' => 'Managers escalate issues to the Compliance Officer weekly.',
            'has_documented_process' => true,
        ]);
    }

    public function test_admin_can_switch_an_answer_to_no_documented_process(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => 'Original response.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startEditingAnswer', $question->id)
            ->set('editingAnswerHasDocumentedProcess', false)
            ->call('saveEditedAnswer');

        $this->assertDatabaseHas('intake_answers', [
            'intake_submission_id' => $submission->id,
            'intake_question_id' => $question->id,
            'response' => null,
            'has_documented_process' => false,
        ]);
    }

    public function test_saving_an_edited_answer_requires_a_response_when_marked_as_documented(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => 'Original response.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startEditingAnswer', $question->id)
            ->set('editingAnswerResponse', '   ')
            ->call('saveEditedAnswer')
            ->assertHasErrors('editingAnswerResponse');

        $this->assertDatabaseHas('intake_answers', [
            'intake_question_id' => $question->id,
            'response' => 'Original response.',
        ]);
    }

    public function test_submission_detail_shows_the_practices_team_and_compliance_contacts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $practice = $submission->order->user->practice;
        $practice->update([
            'legal_practice_name' => 'Riverside Family Medicine LLC',
            'compliance_officer_name' => 'Pat Rivera',
            'compliance_committee_members' => [['name' => 'Pat Rivera', 'title' => 'Compliance Officer']],
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('Practice Team & Compliance Contacts')
            ->assertSee('Riverside Family Medicine LLC')
            ->assertSee('Pat Rivera');
    }

    public function test_admin_can_edit_the_practices_team_and_compliance_contacts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $practice = $submission->order->user->practice;

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startEditingTeam')
            ->set('teamForm.legal_practice_name', 'Riverside Family Medicine LLC')
            ->set('teamForm.main_phone', '555-010-2231')
            ->set('teamForm.main_email', 'admin@riversidefm.test')
            ->set('teamForm.locations', "742 Evergreen Terrace\n\n12 Oak Street")
            ->set('teamForm.compliance_officer_name', 'Pat Rivera')
            ->set('teamForm.compliance_officer_phone', '555-010-2232')
            ->set('teamForm.compliance_officer_email', 'pat@riversidefm.test')
            ->set('teamForm.it_mode', 'vendor')
            ->set('teamForm.it_vendor_name', 'Acme IT Services')
            ->set('teamForm.uses_ehcp_hotline', false)
            ->set('teamForm.compliance_hotline_number', '1-800-555-0100')
            ->set('teamForm.committee_members', "Pat Rivera — Compliance Officer\nSam Lee — Office Manager")
            ->set('teamForm.board_mode', 'board')
            ->set('teamForm.board_members', 'Denise Carter — Owner')
            ->call('saveTeam')
            ->assertSet('editingTeam', false)
            ->assertSee('Pat Rivera');

        $practice->refresh();

        $this->assertSame('Riverside Family Medicine LLC', $practice->legal_practice_name);
        $this->assertSame('555-010-2231', $practice->main_phone);
        $this->assertSame(['742 Evergreen Terrace', '12 Oak Street'], $practice->practice_locations);
        $this->assertSame('Pat Rivera', $practice->compliance_officer_name);
        $this->assertSame('vendor', $practice->it_mode);
        $this->assertSame('Acme IT Services', $practice->it_vendor_name);
        $this->assertFalse($practice->uses_ehcp_hotline);
        $this->assertSame('1-800-555-0100', $practice->compliance_hotline_number);
        $this->assertSame(
            [['name' => 'Pat Rivera', 'title' => 'Compliance Officer'], ['name' => 'Sam Lee', 'title' => 'Office Manager']],
            $practice->compliance_committee_members,
        );
        $this->assertSame('board', $practice->board_mode);
        $this->assertSame([['name' => 'Denise Carter', 'title' => 'Owner']], $practice->compliance_governing_board_members);

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'submission.team_info_edited']);
    }

    public function test_marking_no_compliance_committee_yet_clears_any_committee_members(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $practice = $submission->order->user->practice;
        $practice->update(['compliance_committee_members' => [['name' => 'Pat Rivera', 'title' => 'Compliance Officer']]]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startEditingTeam')
            ->set('teamForm.committee_none', true)
            ->call('saveTeam');

        $practice->refresh();

        $this->assertTrue($practice->committee_none);
        $this->assertSame([], $practice->compliance_committee_members);
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

    public function test_approving_a_professional_submission_dispatches_generation_for_its_included_manuals(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'included_document_types' => ['compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual'],
        ]);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::ComplianceEthicsManual);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::HipaaPrivacyPolicy);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::HipaaSecurityManual);
    }

    public function test_approving_an_essential_submission_dispatches_no_manual_generation(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        // makeSubmission()'s bare Package::factory() defaults to the Essential tier's shape —
        // included_document_types: [].
        $submission = $this->makeSubmission();

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        Bus::assertNotDispatched(GenerateComplianceDocument::class);
    }

    public function test_approving_again_does_not_redispatch_generation_for_an_already_generated_manual(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'included_document_types' => ['compliance_ethics_manual'],
        ]);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);
        GeneratedDocument::factory()->completed()->create(['order_id' => $order->id, 'document_type' => DocumentType::ComplianceEthicsManual]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approve');

        Bus::assertNotDispatched(GenerateComplianceDocument::class);
    }

    public function test_starting_review_dispatches_generation_for_included_manuals(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'included_document_types' => ['compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual'],
        ]);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startReview');

        $this->assertSame(IntakeSubmissionStatus::UnderReview, $submission->fresh()->status);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::ComplianceEthicsManual);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::HipaaPrivacyPolicy);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::HipaaSecurityManual);
    }

    public function test_starting_review_on_an_advanced_submission_also_dispatches_the_sra_and_mini_audit_report(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'included_document_types' => [
                'compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual',
                'security_risk_assessment', 'coding_mini_audit_report',
            ],
        ]);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'document_category' => 'encounter_list',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startReview');

        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::SecurityRiskAssessment);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::CodingMiniAuditReport);
    }

    public function test_starting_review_on_an_advanced_submission_skips_the_mini_audit_report_without_an_encounter_list(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'included_document_types' => [
                'compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual',
                'security_risk_assessment', 'coding_mini_audit_report',
            ],
        ]);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('startReview');

        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::SecurityRiskAssessment);
        Bus::assertNotDispatched(GenerateComplianceDocument::class, fn ($job) => $job->documentType === DocumentType::CodingMiniAuditReport);

        $this->assertDatabaseMissing('generated_documents', [
            'order_id' => $order->id,
            'document_type' => DocumentType::CodingMiniAuditReport->value,
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

    public function test_admin_can_send_back_a_submission_for_resubmission(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $submission->update([
            'status' => IntakeSubmissionStatus::Submitted,
            'submitted_at' => now(),
            'certified_by_name' => 'Jane Provider',
            'certified_by_title' => 'Owner',
            'certified_signature' => 'Jane Provider',
            'certified_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('sendBackForResubmission')
            ->assertRedirect(route('admin.submissions'));

        $submission->refresh();
        $this->assertSame(IntakeSubmissionStatus::Draft, $submission->status);
        $this->assertNull($submission->submitted_at);
        $this->assertNull($submission->certified_by_name);
        $this->assertNull($submission->certified_by_title);
        $this->assertNull($submission->certified_signature);
        $this->assertNull($submission->certified_at);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'submission.sent_back_for_resubmission']);
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

    /** Sending a submission back for resubmission resets the certification/submitted state so
     *  the client can re-certify, but must not touch anything they already answered or uploaded —
     *  unlike the old delete action, nothing here should be destructive. */
    public function test_sending_a_submission_back_preserves_its_answers_uploads_and_documents(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $submission->update(['status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);
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
            ->call('sendBackForResubmission');

        $this->assertDatabaseHas('intake_submissions', ['id' => $submission->id]);
        $this->assertDatabaseHas('intake_uploads', ['id' => $upload->id]);
        $this->assertDatabaseHas('generated_documents', ['id' => $document->id]);
        Storage::disk('local')->assertExists($document->pdf_storage_path);
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
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
            'reviewer_notes' => 'Fix the signature.',
            'submitted_at' => now()->subDay(),
            'wizard_screen' => 'done',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('orderIds', [$order->id])
            ->call('reuploadForOrder', $order->id)
            ->set('certifiedByName', 'Jane Provider')
            ->set('certifiedByTitle', 'Owner')
            ->set('certifiedSignature', 'Jane Provider')
            ->set('certifyChecked', true)
            ->call('finalizeIntake')
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

    public function test_uploaded_forms_list_shows_the_extraction_error_message_when_failed(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->failed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::HipaaSecurityQuestionnaire,
            'ai_error_message' => 'cURL error 28: Operation timed out after 120000 milliseconds',
        ]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('cURL error 28: Operation timed out after 120000 milliseconds');
    }

    public function test_uploaded_forms_list_does_not_show_an_error_message_when_extraction_succeeded(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'ai_error_message' => null,
        ]);

        $response = $this->withoutVite()->actingAs($admin)->get(route('admin.submissions.show', $submission));

        $response->assertOk();
        $this->assertStringNotContainsString('cURL error', $response->getContent());
    }

    // ── Regenerate failed extraction ─────────────────────────────────────────

    public function test_admin_can_regenerate_extraction_for_a_failed_questionnaire_linked_document(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $upload = IntakeUpload::factory()->failed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('regenerateExtraction', $document->id);

        $upload->refresh();
        $this->assertSame(AiExtractionStatus::Pending, $upload->ai_extraction_status);
        $this->assertNull($upload->ai_extracted_data);
        $this->assertNull($upload->ai_error_message);

        Bus::assertDispatched(ProcessIntakeUpload::class, fn ($job) => $job->upload->is($upload));

        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'upload.extraction_regenerate_requested',
        ]);
    }

    public function test_admin_can_regenerate_extraction_for_a_failed_per_upload_document(): void
    {
        Bus::fake();

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

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('regenerateExtraction', $document->id);

        Bus::assertDispatched(ProcessIntakeUpload::class, fn ($job) => $job->upload->is($upload));
    }

    public function test_regenerating_extraction_for_a_document_with_no_resolvable_upload_does_nothing(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission();
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $submission->order_id,
            'document_type' => DocumentType::EmployeeHandbookBasic,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('regenerateExtraction', $document->id);

        Bus::assertNotDispatched(ProcessIntakeUpload::class);
        $this->assertDatabaseMissing('activity_logs', ['event_type' => 'upload.extraction_regenerate_requested']);
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

    public function test_admin_can_approve_a_single_document_without_touching_submission_status(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->completed()->create(['order_id' => $submission->order_id]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approveDocument', $document->id);

        $document->refresh();
        $this->assertNotNull($document->reviewed_at);
        $this->assertSame($admin->id, $document->reviewed_by);
        $this->assertSame(IntakeSubmissionStatus::Approved, $submission->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.approved']);

        Mail::assertSent(ClientDocumentsApprovedMail::class);
    }

    public function test_approving_a_document_that_is_not_ready_does_nothing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->makeSubmission(IntakeSubmissionStatus::Approved);
        $document = GeneratedDocument::factory()->create([
            'order_id' => $submission->order_id,
            'status' => DocumentStatus::Pending,
        ]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('approveDocument', $document->id);

        $this->assertNull($document->fresh()->reviewed_at);
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

    public function test_admin_can_filter_the_activity_log_by_event_type(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ActivityLog::record('package.created', 'A findable package event', user: $admin);
        ActivityLog::record('lead.deleted', 'An unrelated lead event', user: $admin);

        Livewire::actingAs($admin)
            ->test('admin.activity-log-list')
            ->set('eventType', 'package.created')
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

    public function test_admin_can_set_a_packages_auto_generated_manuals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Package::factory()->create(['slug' => 'essential']);

        Livewire::actingAs($admin)
            ->test('admin.package-form')
            ->set('slug', 'professional')
            ->set('name', 'Professional Compliance')
            ->set('billingType', 'annual')
            ->set('annualPrice', '2490')
            ->set('sortOrder', 2)
            ->set('includedDocumentTypes', ['compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual'])
            ->call('save')
            ->assertRedirect(route('admin.packages'));

        $package = Package::where('slug', 'professional')->first();
        $this->assertSame(
            ['compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual'],
            $package->included_document_types,
        );
    }

    public function test_a_new_package_defaults_to_no_auto_generated_manuals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Package::factory()->create(['slug' => 'essential']);

        Livewire::actingAs($admin)
            ->test('admin.package-form')
            ->set('slug', 'professional')
            ->set('name', 'Professional Compliance')
            ->set('billingType', 'annual')
            ->set('annualPrice', '2490')
            ->set('sortOrder', 2)
            ->call('save')
            ->assertRedirect(route('admin.packages'));

        $package = Package::where('slug', 'professional')->first();
        $this->assertSame([], $package->included_document_types);
    }

    public function test_a_forged_document_type_is_rejected_when_saving_a_package(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create(['slug' => 'essential']);

        Livewire::actingAs($admin)
            ->test('admin.package-form', ['package' => $package])
            ->set('includedDocumentTypes', ['employee_handbook_basic'])
            ->call('save')
            ->assertHasErrors('includedDocumentTypes.0');
    }

    public function test_editing_a_package_updates_its_auto_generated_manuals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create(['slug' => 'essential', 'included_document_types' => ['compliance_ethics_manual']]);

        Livewire::actingAs($admin)
            ->test('admin.package-form', ['package' => $package])
            ->assertSet('includedDocumentTypes', ['compliance_ethics_manual'])
            ->set('includedDocumentTypes', ['compliance_ethics_manual', 'hipaa_security_manual'])
            ->call('save')
            ->assertRedirect(route('admin.packages'));

        $this->assertSame(
            ['compliance_ethics_manual', 'hipaa_security_manual'],
            $package->fresh()->included_document_types,
        );
    }

    /** Regression: the Advanced package's own included_document_types (security_risk_assessment,
     *  coding_mini_audit_report) must be editable/re-saveable, not just the 3 original manuals. */
    public function test_editing_the_advanced_package_can_save_its_sra_and_mini_audit_report_types(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create([
            'slug' => 'advanced',
            'included_document_types' => [
                'compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual',
                'security_risk_assessment', 'coding_mini_audit_report',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test('admin.package-form', ['package' => $package])
            ->assertSet('includedDocumentTypes', [
                'compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual',
                'security_risk_assessment', 'coding_mini_audit_report',
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.packages'));

        $this->assertSame(
            ['compliance_ethics_manual', 'hipaa_privacy_policy', 'hipaa_security_manual', 'security_risk_assessment', 'coding_mini_audit_report'],
            $package->fresh()->included_document_types,
        );
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

    // ── Intake Questions ──────────────────────────────────────────────────────

    private function seedIntakeQuestion(): IntakeQuestion
    {
        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $policy = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => []]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $question->policies()->attach($policy->id);

        return $question;
    }

    public function test_admin_can_view_the_intake_question_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->seedIntakeQuestion();

        $this->withoutVite()->actingAs($admin)->get(route('admin.intake-questions'))
            ->assertOk()
            ->assertSee('Compliance program')
            ->assertSee('Owner & board oversight')
            ->assertSee('CMP-01')
            ->assertSee('No prompt summary yet.');
    }

    public function test_admin_can_edit_an_intake_questions_prompt_copy(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $question = $this->seedIntakeQuestion();

        Livewire::actingAs($admin)
            ->test('admin.intake-question-list')
            ->call('edit', $question->id)
            ->assertSet('editTitle', 'Owner & board oversight')
            ->set('editPromptSummary', 'How does your board oversee the compliance program?')
            ->set('editWhyWeAsk', 'OIG guidance expects active oversight.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editingQuestionId', null);

        $this->assertDatabaseHas('intake_questions', [
            'id' => $question->id,
            'prompt_summary' => 'How does your board oversee the compliance program?',
            'why_we_ask' => 'OIG guidance expects active oversight.',
        ]);

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'intake_question.updated']);
    }

    public function test_editing_an_intake_question_requires_a_title(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $question = $this->seedIntakeQuestion();

        Livewire::actingAs($admin)
            ->test('admin.intake-question-list')
            ->call('edit', $question->id)
            ->set('editTitle', '')
            ->call('save')
            ->assertHasErrors('editTitle');
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

    public function test_setting_trial_days_auto_fills_the_validity_window(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $component = Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('type', DiscountType::FreeTrial->value)
            ->set('trialDays', '5');

        $component->assertSet('startsAt', now()->format('Y-m-d'));
        $component->assertSet('expiresAt', now()->addDays(5)->format('Y-m-d'));
    }

    public function test_changing_trial_days_recomputes_expiry_from_the_existing_start_date(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $component = Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('type', DiscountType::FreeTrial->value)
            ->set('startsAt', '2026-01-01')
            ->set('trialDays', '10');

        $component->assertSet('startsAt', '2026-01-01');
        $component->assertSet('expiresAt', '2026-01-11');
    }

    public function test_trial_days_does_not_touch_the_validity_window_for_a_percentage_code(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $component = Livewire::actingAs($admin)
            ->test('admin.discount-code-form')
            ->set('type', DiscountType::Percentage->value)
            ->set('trialDays', '5');

        $component->assertSet('startsAt', '');
        $component->assertSet('expiresAt', '');
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

    // ── Questionnaires ───────────────────────────────────────────────────────

    public function test_guest_cannot_access_the_questionnaires_page(): void
    {
        $this->withoutVite()->get(route('admin.questionnaires'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Please log in to access this page.');
    }

    public function test_client_cannot_access_the_questionnaires_page(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->withoutVite()->actingAs($client)->get(route('admin.questionnaires'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Please log in to access this page.');
    }

    public function test_admin_can_view_the_questionnaires_list_with_default_visibility(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.questionnaires'))
            ->assertOk()
            ->assertSee('Compliance & Ethics Questionnaire')
            ->assertSee('HIPAA Business Associate Questionnaire')
            ->assertSee('HIPAA Privacy Questionnaire')
            ->assertSee('HIPAA Security Questionnaire')
            ->assertSeeInOrder(['Required', 'Visible']);
    }

    public function test_questionnaire_list_flags_manuals_migrated_to_the_intake_wizard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => []]);

        $response = $this->withoutVite()->actingAs($admin)->get(route('admin.questionnaires'))
            ->assertOk()
            ->assertSee('Wizard-driven');

        // "Wizard-driven" appears twice: once in the page's explanatory banner, once as this
        // one row's badge — the other 3 rows (not migrated in this test's DB) get no badge,
        // confirming it's per-row, not a blanket "always show" fallback.
        $response->assertSee('HIPAA Business Associate Questionnaire');
        $this->assertSame(2, substr_count($response->getContent(), 'Wizard-driven'));
    }

    public function test_questionnaire_form_warns_when_editing_a_manual_migrated_to_the_intake_wizard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => []]);
        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::ComplianceEthicsQuestionnaire)->first();

        $this->withoutVite()->actingAs($admin)->get(route('admin.questionnaires.edit', $questionnaire))
            ->assertOk()
            ->assertSee('This manual is now driven by the Practice Intake wizard.', false);
    }

    public function test_admin_can_hide_a_questionnaire_and_it_writes_an_activity_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::HipaaPrivacyQuestionnaire)->firstOrFail();

        Livewire::actingAs($admin)
            ->test('admin.questionnaire-list')
            ->call('toggleVisibility', $questionnaire->id);

        $this->assertDatabaseHas('questionnaires', [
            'upload_type' => 'hipaa_privacy_questionnaire',
            'is_visible' => false,
        ]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'questionnaire.hidden']);
    }

    public function test_toggling_a_questionnaire_twice_returns_it_to_the_default_state(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::HipaaPrivacyQuestionnaire)->firstOrFail();

        $component = Livewire::actingAs($admin)->test('admin.questionnaire-list');
        $component->call('toggleVisibility', $questionnaire->id);
        $component->call('toggleVisibility', $questionnaire->id);

        $this->assertDatabaseHas('questionnaires', [
            'upload_type' => 'hipaa_privacy_questionnaire',
            'is_visible' => true,
        ]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'questionnaire.shown']);
    }

    public function test_hiding_the_required_questionnaire_writes_a_reassignment_activity_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::ComplianceEthicsQuestionnaire)->firstOrFail();

        Livewire::actingAs($admin)
            ->test('admin.questionnaire-list')
            ->call('toggleVisibility', $questionnaire->id);

        $this->assertDatabaseHas('questionnaires', [
            'upload_type' => 'hipaa_business_associate_questionnaire',
            'is_required' => true,
        ]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'questionnaire.required_reassigned']);
    }

    public function test_admin_can_create_a_questionnaire_for_an_unregistered_upload_type(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Questionnaire::where('upload_type', IntakeUploadType::HipaaSecurityQuestionnaire)->delete();

        Livewire::actingAs($admin)
            ->test('admin.questionnaire-form')
            ->set('uploadType', IntakeUploadType::HipaaSecurityQuestionnaire->value)
            ->set('title', 'HIPAA Security Questionnaire')
            ->set('description', 'Workflow details for the HIPAA Security Manual.')
            ->set('allTiers', true)
            ->set('questionnaireFile', UploadedFile::fake()->create('security.docx', 50))
            ->set('manualTemplateFile', UploadedFile::fake()->create('security-manual.docx', 50))
            ->call('save')
            ->assertHasNoErrors();

        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::HipaaSecurityQuestionnaire)->firstOrFail();
        $this->assertSame('HIPAA Security Questionnaire', $questionnaire->title);
        Storage::disk('public')->assertExists($questionnaire->questionnaire_file_path);
        Storage::disk('manual_templates')->assertExists(DocumentType::HipaaSecurityManual->value.'.docx');
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'questionnaire.created']);
    }

    public function test_questionnaire_form_excludes_already_registered_upload_types(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $component = Livewire::actingAs($admin)->test('admin.questionnaire-form');

        $this->assertEmpty($component->instance()->availableUploadTypes());
    }

    public function test_admin_can_edit_a_questionnaires_title_without_reuploading_files(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::HipaaPrivacyQuestionnaire)->firstOrFail();
        Storage::disk('manual_templates')->put(DocumentType::HipaaPrivacyPolicy->value.'.docx', 'existing');

        Livewire::actingAs($admin)
            ->test('admin.questionnaire-form', ['questionnaire' => $questionnaire])
            ->set('title', 'Updated HIPAA Privacy Questionnaire')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Updated HIPAA Privacy Questionnaire', $questionnaire->fresh()->title);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'questionnaire.updated']);
    }

    private function docxContaining(array $mergeFields): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        foreach ($mergeFields as $field) {
            $section->addText('${'.$field.'}');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'questionnaire_test').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $contents = file_get_contents($tempPath);
        unlink($tempPath);

        return $contents;
    }

    public function test_uploading_a_manual_template_detects_its_schema_before_saving(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]])]);

        $file = UploadedFile::fake()->createWithContent(
            'privacy-manual.docx',
            $this->docxContaining(['practice_name', 'prv_01_answer', 'prv_02_answer'])
        );

        $component = Livewire::actingAs($admin)
            ->test('admin.questionnaire-form', ['questionnaire' => Questionnaire::where('upload_type', IntakeUploadType::HipaaPrivacyQuestionnaire)->firstOrFail()])
            ->set('prefix', 'prv')
            ->set('manualTemplateFile', $file);

        $pendingSchema = $component->get('pendingSchema');
        $this->assertSame(2, $pendingSchema['count']);
        $this->assertSame('prv', $pendingSchema['prefix']);

        $component->call('save')->assertHasNoErrors();

        $this->assertSame(2, Questionnaire::where('upload_type', IntakeUploadType::HipaaPrivacyQuestionnaire)->firstOrFail()->schema['count']);
    }

    public function test_regenerating_schema_on_a_questionnaire_with_history_shows_a_warning(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]])]);
        IntakeUpload::factory()->create(['upload_type' => IntakeUploadType::HipaaPrivacyQuestionnaire]);

        $file = UploadedFile::fake()->createWithContent(
            'privacy-manual.docx',
            $this->docxContaining(['prv_01_answer'])
        );

        Livewire::actingAs($admin)
            ->test('admin.questionnaire-form', ['questionnaire' => Questionnaire::where('upload_type', IntakeUploadType::HipaaPrivacyQuestionnaire)->firstOrFail()])
            ->set('manualTemplateFile', $file)
            ->assertSee("won't remap", false);
    }

    public function test_deleting_a_questionnaire_with_upload_history_is_blocked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::HipaaPrivacyQuestionnaire)->firstOrFail();
        IntakeUpload::factory()->create(['upload_type' => IntakeUploadType::HipaaPrivacyQuestionnaire]);

        Livewire::actingAs($admin)
            ->test('admin.questionnaire-list')
            ->call('delete', $questionnaire->id);

        $this->assertModelExists($questionnaire);
    }

    public function test_deleting_a_questionnaire_with_no_history_removes_it(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $questionnaire = Questionnaire::where('upload_type', IntakeUploadType::HipaaSecurityQuestionnaire)->firstOrFail();

        Livewire::actingAs($admin)
            ->test('admin.questionnaire-list')
            ->call('delete', $questionnaire->id);

        $this->assertModelMissing($questionnaire);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'questionnaire.deleted']);
    }

    // ── Document generator (admin testing tool) ───────────────────────────────

    public function test_admin_can_view_the_document_generator_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.document-generator'))
            ->assertOk()
            ->assertSee('Testing tool only.')
            ->assertSee('Choose a user');
    }

    public function test_client_cannot_access_the_document_generator(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->withoutVite()->actingAs($client)->get(route('admin.document-generator'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_create_a_simulated_test_order_with_no_payment(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        $package = Package::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test('admin.document-generator')
            ->set('userId', $client->id)
            ->set('packageId', $package->id)
            ->call('createTestOrder');

        $order = Order::where('user_id', $client->id)->where('package_id', $package->id)->firstOrFail();

        $this->assertSame(PaymentStatus::SimulatedPaid, $order->payment_status);
        $this->assertSame(0.0, (float) $order->amount_paid);
        $this->assertDatabaseHas('practices', ['user_id' => $client->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'order.test_created']);
        $this->assertDatabaseMissing('payment_logs', ['order_id' => $order->id]);
    }

    public function test_admin_can_upload_filled_forms_for_the_test_order(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create(['is_active' => true]);
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        $file = UploadedFile::fake()->create('compliance-ethics.docx', 50);

        $component = Livewire::actingAs($admin)
            ->test('admin.document-generator', ['orderId' => $order->id])
            ->set('questionnaireFiles.'.IntakeUploadType::ComplianceEthicsQuestionnaire->value, $file)
            ->call('submitUploads');

        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Submitted,
        ]);
        $this->assertDatabaseHas('intake_uploads', [
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
            'original_filename' => 'compliance-ethics.docx',
        ]);
        $this->assertSame(OrderStatus::IntakeSubmitted, $order->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'submission.admin_test_uploaded']);
        Bus::assertDispatched(ProcessIntakeUpload::class);

        // The browser's native file input keeps showing the just-picked filename even after a
        // successful submit clears the bound property — without a visible success indicator and
        // an "already uploaded" note, re-clicking Submit with no new file looked like the first
        // attempt silently failed. Guard both here.
        $component->assertSee('Uploaded 1 file')
            ->assertSee('Uploaded: compliance-ethics.docx');
    }

    public function test_submitting_again_with_no_new_file_shows_a_clear_error_not_a_silent_failure(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create(['is_active' => true]);
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        $file = UploadedFile::fake()->create('compliance-ethics.docx', 50);

        $component = Livewire::actingAs($admin)
            ->test('admin.document-generator', ['orderId' => $order->id])
            ->set('questionnaireFiles.'.IntakeUploadType::ComplianceEthicsQuestionnaire->value, $file)
            ->call('submitUploads');

        // Simulates clicking "Submit for AI Processing" a second time with nothing newly chosen
        // (questionnaireFiles was cleared after the first successful submit).
        $component->call('submitUploads')
            ->assertSee('Choose at least one filled form below before submitting');

        // The first submission must not be undone or duplicated by the second, empty attempt.
        $this->assertSame(1, IntakeUpload::where('upload_type', IntakeUploadType::ComplianceEthicsQuestionnaire)->count());
    }

    public function test_admin_can_approve_and_revoke_a_test_document_without_emailing_the_client(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create(['is_active' => true]);
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);
        $document = GeneratedDocument::factory()->completed()->create(['order_id' => $order->id]);

        $component = Livewire::actingAs($admin)
            ->test('admin.document-generator', ['orderId' => $order->id])
            ->call('approveDocument', $document->id);

        $this->assertTrue($document->fresh()->isApproved());
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.test_approved']);
        Mail::assertNothingSent();

        $component->call('revokeDocument', $document->id);

        $this->assertFalse($document->fresh()->isApproved());
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.test_approval_revoked']);
        Mail::assertNothingSent();
    }

    public function test_admin_can_delete_a_test_order_from_the_document_generator(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create(['is_active' => true]);
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        Livewire::actingAs($admin)
            ->test('admin.document-generator', ['orderId' => $order->id])
            ->call('deleteTestOrder')
            ->assertSet('orderId', null);

        $this->assertModelMissing($order);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'order.deleted']);
    }

    public function test_document_generator_offers_to_auto_fill_wizard_driven_manuals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        Practice::factory()->create(['user_id' => $client->id]);
        CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => []]);
        $package = Package::factory()->create(['is_active' => true, 'included_document_types' => ['compliance_ethics_manual']]);
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.document-generator', ['orderId' => $order->id]))
            ->assertOk()
            ->assertSee('Practice Intake wizard manuals')
            ->assertSee('Compliance & Ethics Manual');
    }

    public function test_document_generator_hides_wizard_section_when_package_has_no_wizard_driven_manuals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create(['is_active' => true, 'included_document_types' => []]);
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        $this->withoutVite()->actingAs($admin)->get(route('admin.document-generator', ['orderId' => $order->id]))
            ->assertOk()
            ->assertDontSee('Practice Intake wizard manuals');
    }

    public function test_admin_can_auto_fill_and_generate_wizard_driven_manuals_for_a_test_order(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        Practice::factory()->create(['user_id' => $client->id]);

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $policy = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => []]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $question->policies()->attach($policy->id);

        $package = Package::factory()->create(['is_active' => true, 'included_document_types' => ['compliance_ethics_manual']]);
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        Livewire::actingAs($admin)
            ->test('admin.document-generator', ['orderId' => $order->id])
            ->call('autoFillWizardAnswers');

        $this->assertDatabaseHas('intake_submissions', ['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Draft, 'wizard_screen' => 'done']);
        $this->assertDatabaseHas('intake_answers', ['intake_question_id' => $question->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'submission.admin_test_answers_filled']);

        Livewire::actingAs($admin)
            ->test('admin.document-generator', ['orderId' => $order->id])
            ->call('generateWizardDrivenManuals');

        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->order->id === $order->id && $job->documentType === DocumentType::ComplianceEthicsManual);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.test_generation_requested']);
    }
}
