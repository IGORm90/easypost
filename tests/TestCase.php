<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test-only provider wiring. Deliberately not in
        // config/social.php (which ships to production and must never
        // reference an autoload-dev-only class) — see FakeProvider's
        // docblock. Tasks 30/37 resolve it via
        // app(ProviderRegistry::class)->get('fake').
        config()->set('social.providers.fake', FakeProvider::class);
    }
}
