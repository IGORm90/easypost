<?php

use App\Http\Middleware\ResolveOrganization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Membership against organization_user is stubbed until Task 5 creates that
 * pivot table (see ResolveOrganization::userBelongsToOrganization). Until
 * then the middleware must fail closed: no request in these tests can be a
 * verified member of any organization, so every request without a real
 * membership check is rejected. That is the behaviour under test here.
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
