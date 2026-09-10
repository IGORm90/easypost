<?php

use App\Http\Middleware\AttachLogContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/*
 * LOG_PLAN.md Phase 1.3: without a request id, an error line is an
 * orphan — nothing joins it to the nginx access line or to the request
 * that produced it. Laravel's Context facade is merged into every log
 * record automatically, so this middleware only needs to populate it and
 * echo the id back on the response.
 */
afterEach(function () {
    Context::flush();
});

it('generates a request id and adds it to Context when the client sends none', function () {
    $request = Request::create('/api/whatever', 'GET');

    $middleware = new AttachLogContext;
    $response = $middleware->handle($request, fn ($r) => new Response);

    $id = Context::get('request_id');
    expect($id)->toBeString();
    expect($id)->not->toBe('');
    expect($response->headers->get('X-Request-Id'))->toBe($id);
});

it('honours a client-supplied X-Request-Id instead of replacing it', function () {
    $request = Request::create('/api/whatever', 'GET');
    $request->headers->set('X-Request-Id', 'client-supplied-id-123');

    $middleware = new AttachLogContext;
    $response = $middleware->handle($request, fn ($r) => new Response);

    expect(Context::get('request_id'))->toBe('client-supplied-id-123');
    expect($response->headers->get('X-Request-Id'))->toBe('client-supplied-id-123');
});

it('generates a fresh id when the client header is blank', function () {
    $request = Request::create('/api/whatever', 'GET');
    $request->headers->set('X-Request-Id', '');

    $middleware = new AttachLogContext;
    $middleware->handle($request, fn ($r) => new Response);

    expect(Context::get('request_id'))->not->toBe('');
});
