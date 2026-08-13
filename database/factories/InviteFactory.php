<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Invite;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
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
            'email' => fake()->safeEmail(),
            'token' => (string) Str::ulid(),
            'role' => OrganizationRole::USER,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDay()]);
    }
}
