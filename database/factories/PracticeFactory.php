<?php

namespace Database\Factories;

use App\Models\Practice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Practice>
 */
class PracticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' Medical Practice',
            'logo_path' => null,
            'address' => fake()->streetAddress().', '.fake()->city().', '.fake()->stateAbbr(),
            'specialty' => fake()->randomElement(Practice::SPECIALTIES),
            // Fixed rather than random: this now drives checkout pricing (⚡portal.blade.php's
            // billableProviders), so a random default made price-asserting tests flaky depending
            // on what the factory happened to roll. Tests that want a specific count still pass
            // it explicitly via ->create(['billable_providers_count' => N]).
            'billable_providers_count' => 1,
            'is_profile_locked' => false,
            'locked_at' => null,
        ];
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_profile_locked' => true,
            'locked_at' => now(),
        ]);
    }
}
