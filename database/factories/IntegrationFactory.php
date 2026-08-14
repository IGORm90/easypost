<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider_identifier' => 'fake',
            'internal_id' => (string) Str::ulid(),
            'name' => fake()->userName(),
            'picture' => null,
            'profile' => null,
            'token' => 'factory-token-'.Str::random(16),
            'refresh_token' => 'factory-refresh-'.Str::random(16),
            'token_expires_at' => null,
            'settings' => [],
            'customer_name' => null,
            'group_name' => null,
            'disabled' => false,
            'in_between_steps' => false,
            'refresh_needed' => false,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['disabled' => true]);
    }

    public function refreshNeeded(): static
    {
        return $this->state(fn (array $attributes) => ['refresh_needed' => true]);
    }
}
