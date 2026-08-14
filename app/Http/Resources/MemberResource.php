<?php

namespace App\Http\Resources;

use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a User loaded via Organization::members() (a BelongsToMany with
 * ->withPivot('id', 'role')) — $this->pivot is the OrganizationUser row for
 * that specific organization, which is where `role` and the membership's
 * own timestamps live.
 *
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User&object{pivot: OrganizationUser} $resource */
        $resource = $this->resource;
        $membership = $resource->pivot;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $membership->role->value,
            'joined_at' => $membership->created_at,
        ];
    }
}
