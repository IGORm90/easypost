<?php

namespace App\Http\Requests;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates POST /api/organizations/current/invites. `email` is nullable:
 * a null-email invite is a shareable link acceptable by anyone (see
 * InviteService::accept()'s email-matching policy). Authorization for
 * "may $actor invite at $role" (including the SUPERADMIN-escalation rule)
 * is deliberately NOT here — it depends on domain state (the actor's
 * current role) that a FormRequest shouldn't reach into; see
 * OrganizationPolicy::inviteAs(), invoked from InviteService::create().
 */
class CreateInviteRequest extends FormRequest
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
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'role' => ['required', new Enum(OrganizationRole::class)],
        ];
    }
}
