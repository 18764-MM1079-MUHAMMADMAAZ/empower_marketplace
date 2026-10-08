<?php

namespace Database\Factories;

use App\Models\IntakeSubmission;
use App\Models\ReviewerQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewerQuestion>
 */
class ReviewerQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'intake_submission_id' => IntakeSubmission::factory(),
            'asked_by' => null,
            'question' => fake()->sentence().'?',
            'reply' => null,
            'replied_at' => null,
        ];
    }

    public function answered(): static
    {
        return $this->state(fn () => ['reply' => fake()->sentence(), 'replied_at' => now()]);
    }
}
