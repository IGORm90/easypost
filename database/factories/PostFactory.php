<?php

namespace Database\Factories;

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Builds its own `PostGroup` (rather than leaving `post_group_id` to
     * a lazily-resolved `PostGroup::factory()`) so `organization_id` can
     * be read directly off it and kept consistent — a mismatch here
     * would fail `posts_organization_matches_group_fk` at insert time
     * anyway, but resolving it explicitly makes every caller's default
     * `Post::factory()->create()` valid without extra state. The default
     * `Integration` is created under the SAME organization for the same
     * reason (not database-enforced, but a factory default that produces
     * cross-tenant nonsense would be worse than useless for later
     * tasks' tests).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $group = PostGroup::factory()->create();

        return [
            'post_group_id' => $group->id,
            'organization_id' => $group->organization_id,
            'integration_id' => Integration::factory()->create(['organization_id' => $group->organization_id])->id,
            'parent_post_id' => null,
            'order' => 0,
            'content' => fake()->realText(200),
            'settings' => [],
            'state' => PostState::DRAFT,
            'release_url' => null,
            'release_id' => null,
            'error' => null,
            'published_at' => null,
        ];
    }
}
