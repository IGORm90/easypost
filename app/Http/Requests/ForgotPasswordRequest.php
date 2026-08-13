<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates POST /api/auth/password/forgot. Deliberately does not use
 * `exists:users,email` — see RegisterRequest/LoginRequest's sibling for the
 * general convention, and PasswordResetService::sendResetLink() for why: an
 * `exists` rule here would turn a 422 into a user-enumeration oracle, the
 * exact thing this endpoint must not become.
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'email' => ['required', 'string', 'email'],
        ];
    }
}
