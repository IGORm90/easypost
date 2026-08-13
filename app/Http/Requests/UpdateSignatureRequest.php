<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/signatures/{signature} — same shape/rules as
 * StoreSignatureRequest (see its docblock for the 1000-character cap),
 * except both fields are required here too since this is a full
 * replace, not a partial patch, matching this project's other PUT
 * endpoints (UpdateTagRequest, UpdatePostRequest).
 */
class UpdateSignatureRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:1000'],
            'is_default' => ['required', 'boolean'],
        ];
    }
}
