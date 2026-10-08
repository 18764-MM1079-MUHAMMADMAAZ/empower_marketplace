<?php

namespace Tests\Feature;

use App\Enums\IntakeSubmissionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\ReviewerQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewerQuestionsClientTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: IntakeSubmission} */
    private function underReviewSubmission(): array
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::firstOrCreate(['slug' => 'professional'], Package::factory()->make(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true])->getAttributes());
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::UnderReview,
        ]);
        $submission = IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::UnderReview,
            'submitted_at' => now()->subDay(),
        ]);

        return [$user, $submission];
    }

    public function test_client_sees_every_question_with_answered_ones_read_only(): void
    {
        [$user, $submission] = $this->underReviewSubmission();
        ReviewerQuestion::factory()->answered()->create(['intake_submission_id' => $submission->id, 'question' => 'First question?', 'reply' => 'My first answer.']);
        ReviewerQuestion::factory()->create(['intake_submission_id' => $submission->id, 'question' => 'Second question?']);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 4)
            ->assertSeeInOrder(['First question?', 'My first answer.', 'Second question?', 'Send reply']);
    }

    public function test_client_can_reply_to_a_specific_question_and_admins_are_notified(): void
    {
        [$user, $submission] = $this->underReviewSubmission();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $first = ReviewerQuestion::factory()->create(['intake_submission_id' => $submission->id, 'question' => 'First question?']);
        $second = ReviewerQuestion::factory()->create(['intake_submission_id' => $submission->id, 'question' => 'Second question?']);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 4)
            ->set("reviewerReplyText.{$second->id}", '<b>Yes</b>, it is current.')
            ->call('replyToReviewerQuestion', $second->id)
            ->assertHasNoErrors();

        $this->assertNull($first->fresh()->reply);
        $this->assertSame('Yes, it is current.', $second->fresh()->reply);
        $this->assertNotNull($second->fresh()->replied_at);
        $this->assertCount(1, $admin->notifications);
    }

    public function test_a_blank_reply_is_rejected(): void
    {
        [$user, $submission] = $this->underReviewSubmission();
        $question = ReviewerQuestion::factory()->create(['intake_submission_id' => $submission->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 4)
            ->set("reviewerReplyText.{$question->id}", '   ')
            ->call('replyToReviewerQuestion', $question->id)
            ->assertHasErrors(["reviewerReplyText.{$question->id}"]);

        $this->assertNull($question->fresh()->reply);
    }

    public function test_a_client_cannot_answer_another_clients_question_or_re_answer(): void
    {
        [$user, $submission] = $this->underReviewSubmission();
        [, $otherSubmission] = $this->underReviewSubmission();
        $others = ReviewerQuestion::factory()->create(['intake_submission_id' => $otherSubmission->id]);
        $answered = ReviewerQuestion::factory()->answered()->create(['intake_submission_id' => $submission->id, 'reply' => 'Original.']);

        Livewire::actingAs($user)->test('portal')->set('step', 4)
            ->set("reviewerReplyText.{$others->id}", 'Sneaky')->call('replyToReviewerQuestion', $others->id)->assertNotFound();
        Livewire::actingAs($user)->test('portal')->set('step', 4)
            ->set("reviewerReplyText.{$answered->id}", 'Changed')->call('replyToReviewerQuestion', $answered->id)->assertNotFound();

        $this->assertNull($others->fresh()->reply);
        $this->assertSame('Original.', $answered->fresh()->reply);
    }
}
