<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * The User model already hides password/remember_token/api_key (see
     * #[Hidden(...)] on App\Models\User), but that protects only against
     * accidental exposure through Model::toArray()/json casting. Listing
     * fields explicitly here is the resource-layer guarantee: this array is
     * the entire contract of what this endpoint can ever return, so a
     * privileged column added to the model later can't leak through this
     * resource by omission of a #[Hidden] attribute.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
