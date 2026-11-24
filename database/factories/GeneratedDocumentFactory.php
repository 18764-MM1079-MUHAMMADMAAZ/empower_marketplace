<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\GeneratedDocument;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'osha_location_id' => null,
            // Only types that get generated: a manual-upload-only report can't be approved without
            // an admin's file, so picking one at random would make approval tests flaky.
            'document_type' => fake()->randomElement(array_values(array_filter(
                DocumentType::cases(),
                fn (DocumentType $type) => ! $type->isManualUploadOnly(),
            ))),
            'status' => DocumentStatus::Pending,
            'pdf_storage_path' => null,
            'docx_storage_path' => null,
            'pdf_owner_password' => null,
            'is_stale' => false,
            'stale_reason' => null,
            'failure_reason' => null,
            'generated_at' => null,
        ];
    }

    public function completed(): static
    {
        $type = fake()->randomElement(DocumentType::cases())->value;

        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Completed,
            'pdf_storage_path' => 'compliance/'.fake()->uuid().'/'.$type.'.pdf',
            'docx_storage_path' => 'compliance/'.fake()->uuid().'/'.$type.'.docx',
            'pdf_owner_password' => Str::random(32),
            'generated_at' => now(),
        ]);
    }

    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_stale' => true,
            'stale_reason' => 'practice_profile_updated',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'reviewed_at' => now(),
            'reviewed_by' => User::factory(),
        ]);
    }
}
