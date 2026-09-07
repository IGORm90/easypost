<?php

namespace App\Http\Requests;

use App\Models\Integration;
use App\Social\Contracts\DeclaresUpdatableSettings;
use App\Social\Exceptions\UnknownProviderException;
use App\Social\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/integrations/{id}. Authorization (must be at least
 * ADMIN in the integration's organization, and the id must resolve within
 * the caller's organization at all) is enforced by
 * IntegrationController — via the `organization` route middleware plus
 * `Gate::authorize('manage', $integration)` — not here; see
 * OrganizationController's UpdateOrganizationRequest for the same split.
 *
 * `time_slots` is a flat list of `minutes_from_week_start` values
 * (0..10079, i.e. within one week — see the
 * `integration_time_slots` migration): `distinct` rejects duplicate
 * slots in the same request, matching the brief's requirement.
 */
class UpdateIntegrationRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'group_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'time_slots' => ['sometimes', 'array'],
            'time_slots.*' => ['integer', 'min:0', 'max:10079', 'distinct'],
            ...$this->settingsRules(),
        ];
    }

    /**
     * Per-provider `settings` validation — final-review batch 2,
     * Important 4. This used to be `['sometimes','nullable','array']`,
     * written straight through by `IntegrationService::update()`, which
     * meant one PUT could repoint a connected channel's instance host at
     * any server (the cheapest possible entry into this batch's two SSRF
     * findings, with the whole OAuth connect flow skipped) or rewrite a
     * chat target so a stored credential posted into a different room.
     *
     * The allowed keys come from the provider that owns this channel, via
     * `DeclaresUpdatableSettings` — never a list written here, because
     * provider names may not appear outside `app/Social/Providers`
     * (`ProviderIsolationTest`). A provider that does not implement that
     * interface declares nothing updatable, and `settings` is then
     * refused entirely rather than quietly ignored.
     *
     * `array_keys` is validated as a whole (`in:` on `settings` itself is
     * not a thing) via an explicit rule on the parent key, so an unknown
     * key is a 422 naming the key — not a silent no-op, and not a partial
     * save.
     *
     * An unresolvable id or provider yields the no-keys-allowed shape:
     * the controller's own `findOrFail`/authorization is what turns that
     * request into a 404/403, and this class must not become a second
     * existence oracle for it.
     *
     * @return array<string, mixed>
     */
    private function settingsRules(): array
    {
        $allowed = $this->allowedSettingKeys();

        $rules = [
            'settings' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail) use ($allowed): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $unknown = array_diff(array_keys($value), array_keys($allowed));

                    if ($unknown !== []) {
                        $fail(__('This channel does not accept the setting(s): :keys.', [
                            'keys' => implode(', ', array_map('strval', $unknown)),
                        ]));
                    }
                },
            ],
        ];

        foreach ($allowed as $key => $keyRules) {
            $rules["settings.{$key}"] = $keyRules;
        }

        return $rules;
    }

    /**
     * @return array<string, list<string>>
     */
    private function allowedSettingKeys(): array
    {
        $id = $this->route('id');

        if (! is_string($id) || $id === '') {
            return [];
        }

        // Plain `Integration::query()` — BelongsToOrganization's global
        // scope is active here (the `organization` middleware runs before
        // this request is validated), so this can only ever see the
        // caller's own organization's channels.
        $integration = Integration::query()->find($id);

        if (! $integration instanceof Integration) {
            return [];
        }

        try {
            $provider = app(ProviderRegistry::class)->get($integration->provider_identifier);
        } catch (UnknownProviderException) {
            return [];
        }

        return $provider instanceof DeclaresUpdatableSettings
            ? $provider->updatableSettings()
            : [];
    }
}
