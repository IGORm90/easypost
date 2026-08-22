<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tag
 */
class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            // Conditional on purpose. This resource is shared with
            // CalendarEntryResource, where the count is never loaded: an
            // unconditional field would query a missing attribute there
            // and fatten every calendar card for nothing.
            'post_groups_count' => $this->whenCounted('postGroups'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
