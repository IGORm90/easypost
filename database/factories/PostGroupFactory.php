<?php

namespace Database\Factories;

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostGroup>
 */
class PostGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'state' => PostState::DRAFT,
            'publish_date' => now()->addDay(),
            'created_by' => User::factory(),
            'error' => null,
            'notified_at' => null,
            'share_token' => null,
            'queue_version' => 0,
        ];
    }
}
