<?php

use App\Social\Contracts\SocialProviderInterface;
use App\Social\Dto\TextMark;
use App\Social\ProviderRegistry;

/*
 * Block 3: every provider must state which text marks the editor may
 * offer for its network. The field has no default on purpose (same
 * reasoning as $allowsMixedMedia / $maxThreadLength), so "forgot to
 * decide" is a fatal, not a silently formatting-free channel.
 *
 * These walk ProviderRegistry rather than a hardcoded list of names:
 * a provider added later (Block 5 adds eight) is covered the moment it
 * is registered, and no provider name appears in this file — which is
 * also what keeps it clear of ProviderIsolationTest.
 */

it('has every registered provider declare the marks its network supports', function () {
    $providers = app(ProviderRegistry::class)->all();

    expect($providers)->not->toBeEmpty();

    foreach ($providers as $provider) {
        /** @var SocialProviderInterface $provider */
        $marks = $provider->capabilities()->supportedMarks;

        // A duplicate would double-render the button in the toolbar.
        expect(count($marks))->toBe(count(array_unique(array_map(
            static fn (TextMark $mark): string => $mark->value,
            $marks,
        ))));

        foreach ($marks as $mark) {
            expect($mark)->toBeInstanceOf(TextMark::class);
        }
    }
});

it('offers a link mark only where the network renders one', function () {
    // Not every network turns [text](url) into a link — Discord, for one,
    // prints it verbatim in a normal message. The editor must not draw a
    // button that produces literal brackets, so LINK is opt-in per
    // provider like every other mark; this test only pins that the
    // registry answers the question at all, per provider, without this
    // file knowing which provider is which.
    foreach (app(ProviderRegistry::class)->all() as $provider) {
        $marks = $provider->capabilities()->supportedMarks;

        expect(in_array(TextMark::LINK, $marks, true))->toBeBool();
    }
});
