<?php

use App\Social\Exceptions\UnknownProviderException;
use App\Social\ProviderRegistry;
use App\Social\Providers\TelegramProvider;
use Tests\Support\FakeProvider;

it('resolves a registered provider by identifier', function () {
    $provider = app(ProviderRegistry::class)->get('fake');

    expect($provider)->toBeInstanceOf(FakeProvider::class)
        ->and($provider->identifier())->toBe('fake');
});

it('resolves the real telegram provider by identifier', function () {
    // Deferred from Task 11's original registry test: no real provider
    // existed yet then. Task 14 is the first to register one.
    $provider = app(ProviderRegistry::class)->get('telegram');

    expect($provider)->toBeInstanceOf(TelegramProvider::class)
        ->and($provider->identifier())->toBe('telegram');
});

it('lists every registered provider keyed by identifier', function () {
    $all = app(ProviderRegistry::class)->all();

    expect($all)->toHaveKey('fake')
        ->and($all['fake'])->toBeInstanceOf(FakeProvider::class);
});

it('reports whether an identifier is registered', function () {
    $registry = app(ProviderRegistry::class);

    expect($registry->has('fake'))->toBeTrue()
        ->and($registry->has('myspace'))->toBeFalse();
});

it('throws for an unknown provider', function () {
    app(ProviderRegistry::class)->get('myspace');
})->throws(UnknownProviderException::class);

it('resolves the same instance for repeated calls within a registry', function () {
    $registry = app(ProviderRegistry::class);

    expect($registry->get('fake'))->toBe($registry->get('fake'));
});
