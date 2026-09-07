<?php

use Tests\Support\MarkupTranslator;

/*
 * Block 3: the canonical Markdown subset stored in `posts.content`
 * (**bold**, *italic*, ~~strike~~, `code`, [text](url)) translated into
 * what a network actually accepts on the wire. One dialect is left —
 * Telegram's HTML — because removing the Slack provider took its mrkdwn
 * renderer with it. The trait is exercised directly here rather than
 * through a provider, so a failure points at the parser instead of at
 * whichever provider happened to call it.
 *
 * The two properties that matter most are at the bottom of this file:
 * content WITHOUT markup must come back as null (the provider then
 * sends exactly the bytes it always sent -- no escaping, no parse_mode,
 * no behaviour change for the posts users already publish), and a
 * marker the parser cannot pair up must survive as literal text rather
 * than being eaten.
 */

/**
 * The trait under test, exposed through Tests\Support\MarkupTranslator
 * — see that class for why it is not an anonymous one here.
 */
function markup(): MarkupTranslator
{
    return new MarkupTranslator;
}

// --- HTML (Telegram's parse_mode=HTML) ------------------------------------

it('renders every mark as html', function () {
    expect(markup()->html('**bold**'))->toBe('<b>bold</b>')
        ->and(markup()->html('*italic*'))->toBe('<i>italic</i>')
        ->and(markup()->html('~~gone~~'))->toBe('<s>gone</s>')
        ->and(markup()->html('`x = 1`'))->toBe('<code>x = 1</code>')
        ->and(markup()->html('[site](https://example.test)'))->toBe('<a href="https://example.test">site</a>');
});

it('renders marks inside a sentence without touching the rest', function () {
    expect(markup()->html('a **bold** word'))->toBe('a <b>bold</b> word');
});

it('nests marks', function () {
    expect(markup()->html('**bold with *italic* inside**'))
        ->toBe('<b>bold with <i>italic</i> inside</b>');
});

it('does not parse markup inside code', function () {
    // Otherwise pasting a code sample rewrites the sample.
    expect(markup()->html('`**not bold**`'))->toBe('<code>**not bold**</code>');
});

// --- escaping --------------------------------------------------------------

it('escapes html-special characters inside translated content', function () {
    // Not a XSS concern -- nothing renders this in a browser. Telegram
    // answers 400 for a stray '<' under parse_mode=HTML, i.e. the post
    // simply fails to publish, which is the failure this prevents.
    expect(markup()->html('**a < b & c**'))->toBe('<b>a &lt; b &amp; c</b>')
        ->and(markup()->html('plain < text with **bold**'))->toBe('plain &lt; text with <b>bold</b>');
});

it('escapes the link text and url it emits', function () {
    expect(markup()->html('[a & b](https://example.test/?x=1&y=2)'))
        ->toBe('<a href="https://example.test/?x=1&amp;y=2">a &amp; b</a>');
});

// --- links the parser refuses ---------------------------------------------

it('leaves a non-http link as literal text', function () {
    // A javascript: or data: href is rejected by the networks anyway;
    // keeping the literal text means the post still publishes, carrying
    // exactly what the user typed, instead of failing on send.
    expect(markup()->html('[tap](javascript:alert(1))'))->toBeNull();
});

// --- content that must not change -----------------------------------------

it('returns null for content that carries no markup', function () {
    expect(markup()->html('just a normal post'))->toBeNull();
});

it('returns null for unmarked content that contains html-special characters', function () {
    // The whole point of the null: a provider must keep sending this
    // byte-for-byte as it did before Block 3 existed, unescaped and
    // without a parse mode.
    expect(markup()->html('5 < 6 && 7 > 6'))->toBeNull();
});

it('treats an unpaired marker as ordinary text', function () {
    expect(markup()->html('2 * 3 * 4'))->toBeNull()
        ->and(markup()->html('a snake_case_name'))->toBeNull()
        ->and(markup()->html('unfinished **bold'))->toBeNull()
        ->and(markup()->html('50% off ~~ maybe'))->toBeNull();
});

it('keeps an unpaired marker literal alongside a real one', function () {
    expect(markup()->html('**bold** and a lone * star'))->toBe('<b>bold</b> and a lone * star');
});

it('preserves newlines', function () {
    expect(markup()->html("line one\n**line two**"))->toBe("line one\n<b>line two</b>");
});
