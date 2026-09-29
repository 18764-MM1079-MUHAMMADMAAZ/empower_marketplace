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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeAnswersDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_their_intake_answers(): void
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
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Riverside Family Medicine', false);
        $response->assertSee('PRACTICE BASICS', false);
        $response->assertSee("Let's start with your practice", false);
        $response->assertSee('UPLOADED DOCUMENTS', false);
        $response->assertSee('YOUR TEAM', false);
        $response->assertSee('COMPLIANCE PROGRAM', false);
        $response->assertSee('Owner & board oversight', false);
        $response->assertSee('Our board reviews the program quarterly.', false);
    }

    public function test_essential_tier_download_includes_basics_and_documents_but_no_workflow_questions(): void
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

        $response = $this->actingAs($user)->get(route('intake-submissions.answers', $submission));

        $response->assertOk();
        $response->assertSee('PRACTICE BASICS', false);
        $response->assertSee('UPLOADED DOCUMENTS', false);
        $response->assertSee('Uploaded: handbook.pdf', false);
        $response->assertDontSee('YOUR TEAM', false);
        $response->assertDontSee('COMPLIANCE PROGRAM', false);
        $response->assertDontSee('Owner & board oversight', false);
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
}
