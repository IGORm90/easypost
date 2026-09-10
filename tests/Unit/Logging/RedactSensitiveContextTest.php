<?php

use App\Logging\RedactSensitiveContext;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * Defence in depth for the standing rule at Integration's docblock: a raw
 * Log::info() call with a token/secret/password in the context array must
 * not leave this process readable, even though the primary control is
 * simply never logging the value at all. Keyed on the CONTEXT KEY, not the
 * value shape — a value can be anything (a JWT, a plain password, a bare
 * number), but the key naming it is predictable.
 */
/**
 * @param  array<array-key, mixed>  $context
 * @param  array<array-key, mixed>  $extra
 */
function makeRecord(array $context, array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'app',
        level: Level::Info,
        message: 'test.event',
        context: $context,
        extra: $extra,
    );
}

test('redacts a top-level key matching the sensitive pattern', function () {
    $record = makeRecord(['access_token' => 'secret-value', 'foo' => 'bar']);

    $result = (new RedactSensitiveContext)($record);

    expect($result->context['access_token'])->toBe('[redacted]')
        ->and($result->context['foo'])->toBe('bar');
});

test('redacts a nested key at any depth', function () {
    $record = makeRecord(['settings' => ['nested' => ['secret' => 'deep-value']]]);

    $result = (new RedactSensitiveContext)($record);

    expect($result->context['settings']['nested']['secret'])->toBe('[redacted]');
});

test('is case-insensitive and matches password, authorization and cookie', function () {
    $record = makeRecord([
        'Password' => 'x',
        'AUTHORIZATION' => 'Bearer x',
        'Cookie' => 'session=x',
        'api_key' => 'x',
        'apiKey' => 'x',
    ]);

    $result = (new RedactSensitiveContext)($record);

    foreach (array_keys($record->context) as $key) {
        expect($result->context[$key])->toBe('[redacted]');
    }
});

test('also redacts the extra array', function () {
    $record = makeRecord(context: [], extra: ['refresh_token' => 'x']);

    $result = (new RedactSensitiveContext)($record);

    expect($result->extra['refresh_token'])->toBe('[redacted]');
});

test('leaves an unrelated context untouched', function () {
    $record = makeRecord(['organization_id' => '01ABC', 'count' => 3]);

    $result = (new RedactSensitiveContext)($record);

    expect($result->context)->toBe(['organization_id' => '01ABC', 'count' => 3]);
});
