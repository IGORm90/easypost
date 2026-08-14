<?php

use App\Models\Organization;
use App\Support\OrganizationContext;

/**
 * Final review, batch 4 (tenancy). `OrganizationContext` is a static, so
 * it survives for the whole PHP process — every test in the same worker,
 * not just the one that set it. `TestCase::setUp()` never cleared it,
 * even though `OrganizationContext::clear()`'s own docblock says it is
 * "used between tests"; a handful of test files call it defensively in
 * their own `beforeEach`, which is precisely the pattern that hides the
 * gap for everyone who does not.
 *
 * The damage is quiet. `RefreshDatabase` rolls the org's ROW back, but
 * not the static holding it, so a later test inherits a context pointing
 * at an organization that no longer exists — and an assertion like "this
 * fails closed when there is no organization in context" then passes
 * because the query matched zero rows for a completely different reason.
 * A tenancy guard proved by a stale pointer is not proved at all.
 *
 * These two tests are order-dependent on purpose: the first leaves
 * context set exactly as a careless test would, and the second is the
 * assertion that the harness cleaned up after it.
 */
it('leaves a context set, as any test calling a service directly does', function () {
    OrganizationContext::set(Organization::factory()->create());

    expect(OrganizationContext::id())->not->toBeNull();
});

it('starts with no organization in context, whatever the previous test did', function () {
    expect(OrganizationContext::get())->toBeNull();
});
