<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Validates POST /api/auth/register.
 *
 * Only {name, email, password} are ever read via `validated()`, so a caller
 * that also posts `is_superadmin`, `api_key`, `email_verified_at` or any
 * other column has those fields silently dropped — mass-assignment
 * protection against privilege escalation at registration.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalises the email to lowercase before validation runs, so
     * uniqueness is enforced case-insensitively in effect (the `email`
     * column itself is a plain case-sensitive unique index) and the stored
     * value is always lowercase.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
        ];
    }
}
