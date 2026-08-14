<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PUT /api/profile. Authorization is trivial (any authenticated
 * user may edit their own profile) — `auth:sanctum` is the only gate.
 *
 * `timezone` is checked against the real IANA identifier list via
 * Laravel's built-in `timezone` rule (backed by
 * DateTimeZone::listIdentifiers()), not just "any string": `users.timezone`
 * drives Task 21's slot finder, so an unrecognised value here would only
 * surface later as a silently wrong publish time, not a validation error.
 *
 * `locale` is checked against the same two-value set the frontend's i18n
 * bundle actually ships (resources/js/i18n/index.ts's SUPPORTED_LOCALES) —
 * a locale Laravel would accept but vue-i18n has no messages for would
 * fall back silently instead of failing loudly here.
 */
class UpdateProfileRequest extends FormRequest
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
            'timezone' => ['required', 'string', 'timezone:all'],
            'locale' => ['required', 'string', Rule::in(['en', 'ru'])],
            'email_notifications' => ['required', 'boolean'],
        ];
    }
}
