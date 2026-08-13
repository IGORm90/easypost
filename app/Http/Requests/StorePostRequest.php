<?php

namespace App\Http\Requests;

use App\Http\Requests\Posts\Concerns\ValidatesPostChains;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/posts. Structural rules and the semantic checks
 * (publish_date sanity, per-channel content limits via
 * PostValidationService) live in ValidatesPostChains, shared verbatim
 * with UpdatePostRequest since both accept the exact same payload shape
 * (see the task-20 brief).
 *
 * `authorize()` is trivially true, matching this project's existing
 * split (see UpdateIntegrationRequest): whether the caller may schedule
 * into a given `integration_id` is an authorization decision that needs
 * the resolved Integration and the current organization, and is made by
 * `PostGroupService::create()` (403, not 422 — see ValidatesPostChains'
 * docblock), not here.
 */
class StorePostRequest extends FormRequest
{
    use ValidatesPostChains;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->chainRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->applyChainValidation($validator);
    }
}
