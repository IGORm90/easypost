<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/organizations/current. Authorization (must be at
 * least ADMIN in the current organization) is enforced by the `role:ADMIN`
 * route middleware, not here — see EnsureOrganizationRole.
 *
 * `shortlink_provider` has no fixed enum yet (see the migration that adds
 * it), so it's validated only as an optional short string, not against a
 * closed value list a later task would otherwise have to update here too.
 */
class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'shortlink_provider' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
