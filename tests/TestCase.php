<?php

namespace Tests;

use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeProvider;
use Tests\Support\PostSettingsFakeProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `OrganizationContext` is a static and outlives any single test:
        // `RefreshDatabase` rolls back the organization's ROW but not the
        // pointer to it, so without this every test after one that set a
        // context inherits an organization that no longer exists. An
        // assertion such as "this fails closed with no context" then
        // passes for entirely the wrong reason — the query matched zero
        // rows because the stale id matches nothing, not because the
        // guard worked. Final review, batch 4 (tenancy); see
        // tests/Feature/OrganizationContextIsolationTest.php.
        //
        // In setUp() rather than tearDown() so it also holds for the
        // first test in a process and after a test that died mid-way.
        OrganizationContext::clear();

        // Test-only provider wiring. Deliberately not in
        // config/social.php (which ships to production and must never
        // reference an autoload-dev-only class) — see FakeProvider's
        // docblock. Tasks 30/37 resolve it via
        // app(ProviderRegistry::class)->get('fake').
        config()->set('social.providers.fake', FakeProvider::class);

        // Block 5.0.6's counterpart: a provider that declares per-post
        // settings, including a field whose option list depends on
        // another field. Registered here rather than ad hoc because both
        // the providers endpoint and post validation exercise it.
        config()->set('social.providers.post-settings-fake', PostSettingsFakeProvider::class);
    }
}
