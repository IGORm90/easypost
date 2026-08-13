<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates POST /api/auth/login. Credential *matching* (email exists,
 * password correct, rate limiting) is deliberately not here: FormRequest
 * validation failures short-circuit before AuthService runs, and mixing
 * "is this shape valid" with "do these credentials work" would either leak
 * account existence through a different error shape or make the two
 * concerns hard to keep genuinely identical. See AuthService::attemptLogin().
 */
class LoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
        ];
    }
}
