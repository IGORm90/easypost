<?php

namespace App\Http\Requests;

use App\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates GET /api/posts/find-slot. `integration_id` is checked for
 * bare existence only (matches ANY tenant) — same split as
 * ValidatesPostChains: whether it belongs to the CURRENT organization is
 * an authorization decision made by SlotFinderService (403, not 422),
 * not a structural validation rule.
 */
class FindSlotRequest extends FormRequest
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
            'integration_id' => ['required', 'string', Rule::exists('integrations', 'id')->where('organization_id', OrganizationContext::id())->whereNull('deleted_at')],
        ];
    }
}
