<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\PostGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_group_id' => PostGroup::factory(),
            'user_id' => User::factory(),
            'content' => fake()->sentence(),
        ];
    }
}
