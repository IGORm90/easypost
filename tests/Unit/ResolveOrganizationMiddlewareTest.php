<?php

use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Task 5 replaced the stubbed membership check with a real lookup against
 * organization_user. The first three tests below predate that (see git
 * history / task-4-report.md) and still hold: an unsaved User, a random
 * organization id and no authenticated user all still fail closed with a
 * real membership check, exactly as they did against the always-false
 * stub — just now for the honest reason (no matching row) instead of a
 * hardcoded `return false`. The last two tests are new: they prove the
 * middleware actually admits a real member and rejects a real non-member.
 */
afterEach(function () {
    OrganizationContext::clear();
});

it('rejects requests without an X-Organization header', function () {
    $request = Request::create('/api/whatever', 'GET');
    $request->setUserResolver(fn () => new User);

    $middleware = new ResolveOrganization;

    expect(fn () => $middleware->handle($request, fn ($r) => $r))
        ->toThrow(HttpException::class);

    expect(OrganizationContext::id())->toBeNull();
});

it('rejects requests when the user is not (yet, verifiably) a member of the organization', function () {
    $organizationId = (string) Str::ulid();

    $request = Request::create('/api/whatever', 'GET');
    $request->headers->set('X-Organization', $organizationId);
    $request->setUserResolver(fn () => new User);

    $middleware = new ResolveOrganization;

    try {
        $middleware->handle($request, fn ($r) => $r);
        expect(false)->toBeTrue('Expected a 403 HttpException to be thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    expect(OrganizationContext::id())->toBeNull();
});

it('rejects requests with no authenticated user', function () {
    $organizationId = (string) Str::ulid();

    $request = Request::create('/api/whatever', 'GET');
    $request->headers->set('X-Organization', $organizationId);
    $request->setUserResolver(fn () => null);

    $middleware = new ResolveOrganization;

    expect(fn () => $middleware->handle($request, fn ($r) => $r))
        ->toThrow(HttpException::class);
});

it('admits a real member of the organization and puts it in OrganizationContext', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => 'ADMIN']);

    $request = Request::create('/api/whatever', 'GET');
    $request->headers->set('X-Organization', $org->id);
    $request->setUserResolver(fn () => $user);

    $middleware = new ResolveOrganization;
    $middleware->handle($request, fn ($r) => new Response);

    expect(OrganizationContext::id())->toBe($org->id);
    expect(OrganizationContext::get())->not->toBeNull();
    expect(OrganizationContext::get()?->id)->toBe($org->id);
});

it('rejects a real user who is authenticated but not a member of the requested organization', function () {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    $user = User::factory()->create();
    // Member of $otherOrg, not of $org.
    $otherOrg->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => 'USER']);

    $request = Request::create('/api/whatever', 'GET');
    $request->headers->set('X-Organization', $org->id);
    $request->setUserResolver(fn () => $user);

    $middleware = new ResolveOrganization;

    try {
        $middleware->handle($request, fn ($r) => $r);
        expect(false)->toBeTrue('Expected a 403 HttpException to be thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    expect(OrganizationContext::id())->toBeNull();
});
