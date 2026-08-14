<?php

namespace App\Casts;

use App\Enums\Plan;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts `organizations.plan` to a real `Plan` instance, the same way
 * Laravel's built-in backed-enum cast would — except that an unrecognised
 * raw value (a manual DB edit, a retired plan slug) degrades to `null`
 * instead of throwing `ValueError` on every read. `null` is exactly
 * `PlanRegistry::forOrganization()`'s "no plan recorded" case, so an
 * organization stuck with a bad value in this column behaves the same as
 * one with no plan set at all — free tier, not a 500.
 *
 * @implements CastsAttributes<Plan|null, Plan|string|null>
 */
class PlanCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Plan
    {
        if (! is_string($value)) {
            return null;
        }

        return Plan::tryFrom($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof Plan) {
            return $value->value;
        }

        return $value;
    }
}
