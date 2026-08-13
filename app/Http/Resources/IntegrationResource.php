<?php

namespace App\Http\Resources;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Integration
 *
 * Deliberately whitelists fields rather than spreading `toArray()`/casting
 * the model wholesale: `token`/`refresh_token` are never referenced here
 * at all, so this resource cannot leak them even if `Integration::$hidden`
 * were ever removed or bypassed — see IntegrationApiTest's
 * "GET /api/integrations never exposes token/refresh_token" case, which
 * asserts against this endpoint's actual HTTP response, not the model.
 */
class IntegrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_identifier' => $this->provider_identifier,
            'name' => $this->name,
            'picture' => $this->picture,
            'profile' => $this->profile,
            'settings' => $this->settings,
            'customer_name' => $this->customer_name,
            'group_name' => $this->group_name,
            'disabled' => $this->disabled,
            'refresh_needed' => $this->refresh_needed,
            'time_slots' => $this->whenLoaded(
                'timeSlots',
                fn () => $this->timeSlots->pluck('minutes_from_week_start')->values(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
