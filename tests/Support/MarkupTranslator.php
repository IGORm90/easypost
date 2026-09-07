<?php

namespace Tests\Support;

use App\Social\Providers\TranslatesMarkup;

/**
 * Exposes App\Social\Providers\TranslatesMarkup's protected API so
 * MarkupTranslationTest can drive the parser directly.
 *
 * A plain class rather than a provider: the translation needs no
 * Integration, no HTTP and no network identity, and a failure here
 * should point at the parser rather than at whichever provider happened
 * to call it. (It is not an anonymous class either — PHPStan cannot
 * resolve `expect()`'s template types through an `object` return.)
 */
class MarkupTranslator
{
    use TranslatesMarkup;

    public function html(string $content): ?string
    {
        return $this->markupAsHtml($content);
    }
}
