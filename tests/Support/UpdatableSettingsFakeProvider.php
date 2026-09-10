<?php

namespace Tests\Support;

use App\Social\Contracts\DeclaresUpdatableSettings;

/**
 * Opts into editable channel settings only for the IntegrationApiTest cases
 * that need them. Keeping this separate from FakeProvider preserves the
 * default-deny coverage for providers that declare no editable settings.
 * Registered by those tests only, never in production configuration.
 */
class UpdatableSettingsFakeProvider extends FakeProvider implements DeclaresUpdatableSettings
{
    public function identifier(): string
    {
        return 'fake-updatable';
    }

    /**
     * @return array<string, list<string>>
     */
    public function updatableSettings(): array
    {
        return ['channel_id' => ['sometimes', 'string']];
    }
}
