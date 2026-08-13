<?php

namespace App\Http\Requests;

use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/sets. `integration_ids` is checked for ownership
 * right here, not left to the service layer: `Rule::exists('integrations',
 * 'id')` alone (the pattern ValidatesPostChains uses for posts) would only
 * rule out ids that don't exist ANYWHERE — a cross-tenant id would sail
 * through and be silently accepted into another organization's set. Task
 * 20's posts intentionally defer that check to a 403 in the service layer
 * (see ValidatesPostChains's docblock), but this task's brief calls for a
 * 422 instead, so the `where('organization_id', ...)` scope is added
 * directly to the `exists` rule rather than mimicking that later 403 path.
 * `whereNull('deleted_at')` additionally keeps a soft-deleted channel from
 * being addable to a *new* set in the first place (an already-referenced
 * channel that gets deleted later is a different, read-time concern — see
 * SetResource's docblock).
 */
class StoreSetRequest extends FormRequest
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
