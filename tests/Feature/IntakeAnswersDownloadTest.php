<?php

namespace Tests\Feature;

use App\Enums\AiExtractionStatus;
use App\Enums\IntakeUploadType;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\IntakeAnswersPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeAnswersDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_their_intake_answers_as_a_pdf(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'name' => 'Riverside Family Medicine']);
        $package = Package::factory()->create(['slug' => 'professional']);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id]);

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => 'Our board reviews the program quarterly.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('intake-submissions.answers', $submission));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('intake-answers-'.$submission->id.'.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_other_users_cannot_download_someone_elses_answers(): void
    {
        $owner = User::factory()->create();
        Practice::factory()->create(['user_id' => $owner->id]);
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id]);

        $other = User::factory()->create();

        $this->actingAs($other)->get(route('intake-submissions.answers', $submission))->assertForbidden();
    }

    // ── IntakeAnswersPdfGenerator::buildViewData() ──────────────────────────────────────────
    // The PDF itself is a compressed binary, so the tier-gating/data-shaping logic that used to
    // be asserted via assertSee() on the old plain-text download is tested here directly instead.

    public function test_view_data_includes_practice_basics_and_workflow_answers(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'name' => 'Riverside Family Medicine']);
        $package = Package::factory()->create(['slug' => 'professional']);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id]);

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => 'Our board reviews the program quarterly.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        $data = app(IntakeAnswersPdfGenerator::class)->buildViewData($submission);

        $this->assertSame('Riverside Family Medicine', $data['practice']->name);
        $this->assertTrue($data['includesWorkflowQuestionnaire']);
        $this->assertSame('Compliance program', $data['workflowSections'][0]['label']);
        $this->assertSame('Owner & board oversight', $data['workflowSections'][0]['questions'][0]['title']);
        $this->assertSame('Our board reviews the program quarterly.', $data['workflowSections'][0]['questions'][0]['value']);
        $this->assertSame('Practice response', $data['workflowSections'][0]['questions'][0]['badge']['label']);
    }

    public function test_view_data_marks_undocumented_answers_with_the_policy_default_badge(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'professional']);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id]);

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $question = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => null,
            'has_documented_process' => false,
            'answered_at' => now(),
        ]);

        $data = app(IntakeAnswersPdfGenerator::class)->buildViewData($submission);

        $this->assertSame('Policy default', $data['workflowSections'][0]['questions'][0]['badge']['label']);
    }

    public function test_essential_tier_excludes_team_and_workflow_sections(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'name' => 'Riverside Family Medicine']);
        $package = Package::factory()->create(['slug' => 'essential']);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id]);

        $submission->intakeUploads()->create([
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'document_category' => 'compliance_ethics',
            'original_filename' => 'handbook.pdf',
            'storage_path' => 'uploads/test/handbook.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'ai_extraction_status' => AiExtractionStatus::NotApplicable,
        ]);

        // Essential has no workflow questionnaire, but a section/question could still exist
        // globally (seeded once for the whole app) — it must never leak into an Essential
        // submission's download since Essential never collects answers for it.
        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);

        $data = app(IntakeAnswersPdfGenerator::class)->buildViewData($submission);

        $this->assertFalse($data['includesWorkflowQuestionnaire']);
        $this->assertSame([], $data['teamRows']);
        $this->assertCount(0, $data['workflowSections']);
        $this->assertSame('Uploaded: handbook.pdf', collect($data['documentRows'])->firstWhere('label', 'Compliance & Ethics Program')['status']);
    }
}
