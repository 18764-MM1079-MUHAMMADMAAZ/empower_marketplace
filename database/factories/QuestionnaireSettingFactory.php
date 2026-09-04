<?php

namespace Database\Factories;

use App\Enums\IntakeUploadType;
use App\Models\QuestionnaireSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionnaireSetting>
 */
class QuestionnaireSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'upload_type' => fake()->randomElement(IntakeUploadType::cases()),
            'is_visible' => true,
            'is_required' => null,
        ];
    }
}
