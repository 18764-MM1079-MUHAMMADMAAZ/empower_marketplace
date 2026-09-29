<?php

namespace Database\Factories;

use App\Enums\IntakeSubmissionStatus;
use App\Models\IntakeSubmission;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeSubmission>
 */
class IntakeSubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'status' => IntakeSubmissionStatus::Pending,
            'handbook_answers' => null,
            'reviewer_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'submitted_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntakeSubmissionStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntakeSubmissionStatus::Approved,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
        ]);
    }
}
