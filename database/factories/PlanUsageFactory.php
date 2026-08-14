<?php

namespace Database\Factories;

use App\Enums\PlanLimit;
use App\Models\Organization;
use App\Models\PlanUsage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<PlanUsage>
 */
class PlanUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'metric' => PlanLimit::POSTS_PER_MONTH->value,
            'period' => Date::now('UTC')->format('Y-m'),
            'count' => 0,
        ];
    }
}
