<?php

namespace Tests\Feature;

use App\Enums\AiExtractionStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateComplianceDocument;
use App\Jobs\ProcessIntakeUpload;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class AutoUnderReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Order} */
    private function clientSubmitting(string $slug): array
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'slug' => $slug,
            'annual_price' => 1299,
            'is_active' => true,
            'included_document_types' => [DocumentType::ComplianceEthicsManual->value, DocumentType::HipaaPrivacyPolicy->value],
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::NotApplicable,
        ]);

        return [$user, $order];
    }

    private function submit(User $user): void
    {
        Livewire::actingAs($user)
            ->test('portal')
            ->set('certifiedByName', 'Jane Provider')
            ->set('certifiedByTitle', 'Owner')
            ->set('certifiedSignature', 'Jane Provider')
            ->set('certifyChecked', true)
            ->call('finalizeIntake')
            ->assertHasNoErrors();
    }

    public function test_a_professional_submission_goes_straight_to_under_review_and_starts_ai_work(): void
    {
        Bus::fake([GenerateComplianceDocument::class, ProcessIntakeUpload::class]);
        [$user, $order] = $this->clientSubmitting('professional');

        $this->submit($user);

        $submission = $order->intakeSubmission->fresh();
        $this->assertSame(IntakeSubmissionStatus::UnderReview, $submission->status);
        $this->assertNotNull($submission->under_review_started_at);

        Bus::assertDispatched(ProcessIntakeUpload::class);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->documentType === DocumentType::ComplianceEthicsManual);
        Bus::assertDispatched(GenerateComplianceDocument::class, fn ($job) => $job->documentType === DocumentType::HipaaPrivacyPolicy);
    }

    public function test_the_admin_page_no_longer_asks_for_a_manual_start(): void
    {
        Bus::fake();
        [$user, $order] = $this->clientSubmitting('advanced');
        $this->submit($user);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $order->intakeSubmission->fresh()])
            ->assertDontSee('Mark as Under Review');
    }

    public function test_an_essential_submission_still_waits_for_the_admin(): void
    {
        Bus::fake([GenerateComplianceDocument::class, ProcessIntakeUpload::class]);
        [$user, $order] = $this->clientSubmitting('essential');

        $this->submit($user);

        $this->assertSame(IntakeSubmissionStatus::Submitted, $order->intakeSubmission->fresh()->status);
        Bus::assertNotDispatched(GenerateComplianceDocument::class);
        Bus::assertDispatched(ProcessIntakeUpload::class);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $order->intakeSubmission->fresh()])
            ->assertSee('Mark as Under Review');
    }
}
