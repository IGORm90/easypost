<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/organizations/current. Authorization (must be at
 * least ADMIN in the current organization) is enforced by the `role:ADMIN`
 * route middleware, not here — see EnsureOrganizationRole.
 *
 * Only `name` is accepted. The brief's endpoint contract also lists
 * `shortlink_provider`, but that's a post-MVP feature (short links aren't
 * built yet) with no defined value space and no consumer — persisting an
 * externally-writable column nobody validates the meaning of would just
 * hand whoever builds short links later a pile of undefined production
 * data to sort out. Deliberately deferred; see task-9-report.md.
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
        ];
    }
}
