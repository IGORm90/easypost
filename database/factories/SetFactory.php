<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Set;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Set>
 */
class SetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            // Task 23 defines the shape: {integration_ids: string[]}. See
            // SetService/SetResource — the raw jsonb column stores exactly
            // this array under this key.
            'content' => ['integration_ids' => []],
        ];
    }
}
