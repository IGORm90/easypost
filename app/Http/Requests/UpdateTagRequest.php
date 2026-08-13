<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesTagUniqueness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/tags/{tag} — same shape/rules as StoreTagRequest
 * (see its docblock for why `color` is restricted to hex), except both
 * fields are required here too since this is a full replace, not a
 * partial patch, matching this project's other PUT endpoints
 * (UpdatePostRequest, UpdateIntegrationRequest's PUT semantics).
 */
class UpdateTagRequest extends FormRequest
{
    use ValidatesTagUniqueness;

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
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule($this->route('tag'))],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
