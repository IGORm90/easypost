<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\IntegrationTimeSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationTimeSlot>
 */
class IntegrationTimeSlotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integration_id' => Integration::factory(),
            'minutes_from_week_start' => fake()->numberBetween(0, 10079),
        ];
    }
}
