<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ulid = (string) Str::ulid();

        return [
            'organization_id' => Organization::factory(),
            'disk' => 'media',
            // Not necessarily a real org id: this factory is for tests
            // exercising Media rows in isolation (search/listing/delete),
            // where the exact path shape doesn't matter — real path
            // construction is MediaUploadService::store()'s job.
            'path' => "org/{$ulid}/{$ulid}.jpg",
            'thumbnail_path' => null,
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1_000, 5_000_000),
            'original_filename' => fake()->word().'.jpg',
        ];
    }
}
