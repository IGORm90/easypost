<?php

namespace App\Http\Requests;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates PUT /api/organizations/current/members/{user}/role.
 * Authorization (ADMIN+ to touch this route at all; SUPERADMIN to touch a
 * SUPERADMIN membership; never demote the last SUPERADMIN) lives in
 * EnsureOrganizationRole, OrganizationPolicy and MembershipService — not
 * here, since it depends on the target member's current role, which this
 * request has no access to.
 */
class UpdateMemberRoleRequest extends FormRequest
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
            'role' => ['required', new Enum(OrganizationRole::class)],
        ];
    }
}
