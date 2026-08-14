<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/organizations. Authorization is deliberately trivial
 * (any authenticated user may create a new organization and become its
 * SUPERADMIN) — the `auth:sanctum` route middleware is the only gate.
 */
class CreateOrganizationRequest extends FormRequest
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
        ];
    }
}
