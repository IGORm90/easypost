<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/integrations/{id}. Authorization (must be at least
 * ADMIN in the integration's organization, and the id must resolve within
 * the caller's organization at all) is enforced by
 * IntegrationController — via the `organization` route middleware plus
 * `Gate::authorize('manage', $integration)` — not here; see
 * OrganizationController's UpdateOrganizationRequest for the same split.
 *
 * `time_slots` is a flat list of `minutes_from_week_start` values
 * (0..10079, i.e. within one week — see the
 * `integration_time_slots` migration): `distinct` rejects duplicate
 * slots in the same request, matching the brief's requirement.
 */
class UpdateIntegrationRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'group_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'time_slots' => ['sometimes', 'array'],
            'time_slots.*' => ['integer', 'min:0', 'max:10079', 'distinct'],
        ];
    }
}
