<?php

namespace App\Social\Providers;

use App\Social\Dto\MarkupNode;
use App\Social\Dto\TextMark;

/**
 * Translates the canonical inline markup a post body is stored in
 * (**bold**, *italic*, ~~strike~~, `code`, [text](url) — see TextMark)
 * into the syntax a given network actually accepts on the wire.
 *
 * Why this lives in app/Social/Providers, next to BoundsHttpTimeouts and
 * MapsHttpErrors, and not in a service: "bold is one asterisk here and a
 * <b> tag there" is per-network behaviour, and the project's isolation
 * rule (tests/Unit/Architecture/ProviderIsolationTest.php) keeps that
 * inside the provider layer. PublishService hands every provider the
 * same canonical PostPayloadDto::$content and knows nothing about how
 * each one dresses it up.
 *
 * The contract that protects existing posts: both renderers return NULL
 * when the body carries no markup at all. A provider must treat that as
 * "send exactly what you always sent" — no escaping, no parse mode, no
 * new request fields. Every post published before Block 3 existed, and
 * every plain post published after it, therefore goes out byte-for-byte
 * as before; the tests in tests/Unit/Social/*ProviderTest.php pin that
 * per provider. Escaping unconditionally would have been the obvious
 * implementation and the wrong one: it silently changes the text of
 * every post containing an ampersand.
 *
 * Parsing rules, all of them chosen to protect text the user did NOT
 * mean as markup, because the cost of a false positive here is a
 * mangled or unpublishable post:
 *
 *  - An opening delimiter must be followed by a non-space, and its
 *    closing one preceded by a non-space. That is what keeps `2 * 3 * 4`
 *    arithmetic and `50% off ~~ maybe` a sale.
 *  - A delimiter with no partner stays literal. `unfinished **bold`
 *    publishes with its asterisks, rather than swallowing them and
 *    bolding the rest of the post.
 *  - Underscores are not markup in the canonical form at all, so
 *    `snake_case_name` is never at risk. (Slack's own dialect uses them
 *    for italics — which is precisely why the translation to it happens
 *    here rather than by asking users to type mrkdwn.)
 *  - Markup inside `code` is text.
 *  - A link's URL must be http/https. Anything else (javascript:,
 *    data:, a bare word) leaves the whole `[text](url)` as literal
 *    characters: the networks reject such an href anyway, and failing to
 *    publish is a worse outcome than publishing the brackets the user
 *    typed.
 */
trait TranslatesMarkup
{
    /**
     * Telegram-flavoured HTML (`parse_mode=HTML`): the tag set Telegram
     * documents — b, i, s, code, a — and nothing else.
     *
     * Escaping happens per text run AFTER parsing, never before: a body
     * escaped up front would have its `&` turned into `&amp;` inside the
     * markup delimiters too, and the reader would see the entity.
     */
    protected function markupAsHtml(string $content): ?string
    {
        $nodes = $this->parseMarkup($content);

        if (! $this->carriesMarks($nodes)) {
            return null;
        }

        return $this->renderHtml($nodes);
    }

    /**
     * Slack's mrkdwn — *bold*, _italic_, ~strike~, `code`, <url|text>.
     * Its own dialect, not Markdown: one asterisk, not two, and links
     * are angle-bracketed rather than parenthesised.
     */
    protected function markupAsMrkdwn(string $content): ?string
    {
        $nodes = $this->parseMarkup($content);

        if (! $this->carriesMarks($nodes)) {
            return null;
        }

        return $this->renderMrkdwn($nodes);
    }

    /**
     * @return list<MarkupNode>
     */
    private function parseMarkup(string $content): array
    {
        /** @var list<MarkupNode> $nodes */
        $nodes = [];
        $buffer = '';
        $length = strlen($content);
        $position = 0;

        while ($position < $length) {
            $consumed = $this->matchMark($content, $position);

            if ($consumed === null) {
                $buffer .= $content[$position];
                $position++;

                continue;
            }

            [$node, $position] = $consumed;

            if ($buffer !== '') {
                $nodes[] = MarkupNode::text($buffer);
                $buffer = '';
            }

            $nodes[] = $node;
        }

        if ($buffer !== '') {
            $nodes[] = MarkupNode::text($buffer);
        }

        return $nodes;
    }

    /**
     * Tries to read a marked span starting exactly at $position.
     *
     * @return array{MarkupNode, int}|null The node and the position just past it, or null if nothing starts here.
     */
    private function matchMark(string $content, int $position): ?array
    {
        // Longest delimiters first: '**' must win over '*', or every
        // bold span would be read as an empty italic one.
        foreach ([['**', TextMark::BOLD], ['~~', TextMark::STRIKE], ['*', TextMark::ITALIC]] as [$delimiter, $mark]) {
            if (! str_starts_with(substr($content, $position), $delimiter)) {
                continue;
            }

            $inner = $this->spanBetween($content, $position, $delimiter);

            if ($inner === null) {
                continue;
            }

            [$text, $next] = $inner;

            return [MarkupNode::marked($mark, $this->parseMarkup($text)), $next];
        }

        if (str_starts_with(substr($content, $position), '`')) {
            $inner = $this->spanBetween($content, $position, '`');

            if ($inner !== null) {
                [$text, $next] = $inner;

                // Verbatim: whatever is between the backticks is code,
                // even when it is made of the delimiters themselves.
                return [MarkupNode::marked(TextMark::CODE, [MarkupNode::text($text)]), $next];
            }
        }

        if ($content[$position] === '[') {
            return $this->matchLink($content, $position);
        }

        return null;
    }

    /**
     * The text between a pair of identical delimiters, if this really is
     * a pair: non-empty, opened before a non-space and closed after one.
     *
     * @return array{string, int}|null
     */
    private function spanBetween(string $content, int $position, string $delimiter): ?array
    {
        $width = strlen($delimiter);
        $start = $position + $width;
        $first = $content[$start] ?? '';

        if ($first === '' || $this->isSpace($first)) {
            return null;
        }

        $search = $start;

        while (true) {
            $close = strpos($content, $delimiter, $search);

            if ($close === false) {
                return null;
            }

            // '*' inside a '**' is not a closing italic delimiter.
            if ($delimiter === '*' && substr($content, $close, 2) === '**') {
                $search = $close + 2;

                continue;
            }

            if ($close === $start || $this->isSpace($content[$close - 1])) {
                $search = $close + $width;

                continue;
            }

            return [substr($content, $start, $close - $start), $close + $width];
        }
    }

    /**
     * @return array{MarkupNode, int}|null
     */
    private function matchLink(string $content, int $position): ?array
    {
        $matched = preg_match(
            '/\G\[([^\]\n]+)\]\(([^)\s]+)\)/',
            $content,
            $matches,
            0,
            $position,
        );

        if ($matched !== 1) {
            return null;
        }

        [$whole, $text, $href] = $matches;

        if (preg_match('#^https?://#i', $href) !== 1) {
            return null;
        }

        return [
            MarkupNode::marked(TextMark::LINK, $this->parseMarkup($text), $href),
            $position + strlen($whole),
        ];
    }

    private function isSpace(string $character): bool
    {
        return trim($character) === '';
    }

    /**
     * @param  list<MarkupNode>  $nodes
     */
    private function carriesMarks(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->mark !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<MarkupNode>  $nodes
     */
    private function renderHtml(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if ($node->mark === null) {
                $out .= $this->escapeHtml($node->text);

                continue;
            }

            $inner = $this->renderHtml($node->children);

            $out .= match ($node->mark) {
                TextMark::BOLD => "<b>{$inner}</b>",
                TextMark::ITALIC => "<i>{$inner}</i>",
                TextMark::STRIKE => "<s>{$inner}</s>",
                TextMark::CODE => "<code>{$inner}</code>",
                TextMark::LINK => '<a href="'.$this->escapeHtml((string) $node->href).'">'.$inner.'</a>',
            };
        }

        return $out;
    }

    /**
     * @param  list<MarkupNode>  $nodes
     */
    private function renderMrkdwn(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if ($node->mark === null) {
                $out .= $this->escapeMrkdwn($node->text);

                continue;
            }

            $inner = $this->renderMrkdwn($node->children);

            $out .= match ($node->mark) {
                TextMark::BOLD => "*{$inner}*",
                TextMark::ITALIC => "_{$inner}_",
                TextMark::STRIKE => "~{$inner}~",
                TextMark::CODE => "`{$inner}`",
                TextMark::LINK => '<'.$this->escapeMrkdwn((string) $node->href)."|{$inner}>",
            };
        }

        return $out;
    }

    private function escapeHtml(string $text): string
    {
        // ENT_NOQUOTES, not ENT_QUOTES: an apostrophe in a sentence is
        // far more common than one in an href, and &#039; in published
        // prose is exactly the kind of visible damage this whole file
        // exists to avoid. Quotes cannot break out of the href either --
        // matchLink() rejects any URL containing whitespace or ')', and
        // the only attribute emitted is that href.
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }

    private function escapeMrkdwn(string $text): string
    {
        // The three characters Slack documents as needing escaping in
        // message text -- and only those: mrkdwn has no entity for
        // anything else, so escaping more would publish literal
        // backslashes.
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
