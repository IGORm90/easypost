<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationUser>
 */
class OrganizationUserFactory extends Factory
{
    protected $model = OrganizationUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::USER,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => OrganizationRole::ADMIN]);
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => OrganizationRole::SUPERADMIN]);
    }
}
