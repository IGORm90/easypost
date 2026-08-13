<?php

namespace App\Http\Requests;

use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PUT /api/sets/{set} — same shape/rules as StoreSetRequest
 * (see its docblock for why `integration_ids` is scoped to the current
 * organization), except both fields are required here too since this is
 * a full replace, not a partial patch, matching this project's other PUT
 * endpoints (UpdateTagRequest, UpdatePostRequest).
 */
class UpdateSetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'integration_ids' => ['required', 'array'],
            'integration_ids.*' => [
                'string',
                Rule::exists('integrations', 'id')
                    ->where('organization_id', OrganizationContext::id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
