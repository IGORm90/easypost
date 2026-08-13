<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/integrations/{provider}/callback. Only `state` is
 * validated here — it is the one field every provider's callback shares
 * (see OAuthStateService). Everything else in the request body
 * (`code`, `token`, provider-chosen extras, ...) is provider-specific and
 * passed through untouched to
 * `SocialProviderInterface::authenticate($params, ...)`, which is where
 * that shape is actually understood; validating it generically here
 * would either reject legitimate provider-specific fields or require
 * this class to know what each provider expects, both violating the
 * "no provider-specific logic outside app/Social/Providers" constraint.
 *
 * Authorization is deliberately trivial (`true`): the real authorization
 * decision — does `state` belong to the caller — is `OAuthStateService`'s
 * job, since it requires comparing against a value stored server-side at
 * `auth-url` time, not something a FormRequest's `authorize()` (which
 * only sees the current request) can express.
 */
class ConnectIntegrationRequest extends FormRequest
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
            'state' => ['required', 'string'],
        ];
    }
}
