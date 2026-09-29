<?php

namespace Database\Factories;

use App\Enums\PackageTier;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => PackageTier::Essential->value,
            'name' => PackageTier::Essential->label(),
            'tagline' => 'Everything you need to get started with compliance.',
            'monthly_price' => 99.00,
            'annual_price' => 999.00,
            'billing_type' => 'annual',
            'description' => fake()->sentence(),
            'features' => ['Compliance & Ethics Program', 'HIPAA Policies', 'Training Platform', 'Employee Manual Review'],
            // Matches the real Essential tier (this factory's default slug) — no auto-generated
            // manuals, since Essential reviews the practice's own uploaded documents instead.
            'included_document_types' => [],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
