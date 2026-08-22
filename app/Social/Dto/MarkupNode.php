<?php

namespace App\Social\Dto;

/**
 * One node of the tree App\Social\Providers\TranslatesMarkup parses a
 * post body into: either a literal run of text, or a mark wrapping
 * further nodes.
 *
 * A tree rather than a flat list of "text + set of marks" because marks
 * nest — `**bold with *italic* inside**` has to come out as
 * `<b>bold with <i>italic</i> inside</b>`, and a flat list can only
 * produce the closed-and-reopened `<b>…</b><b><i>…</i></b><b>…</b>`,
 * which some networks reject outright and all of them render worse.
 *
 * CODE is a leaf on purpose: its $children always hold a single text
 * node carrying the code verbatim. Markup inside code is not markup —
 * a pasted snippet must survive as typed, not be rewritten by the very
 * characters it is made of.
 */
final readonly class MarkupNode
{
    /**
     * @param  TextMark|null  $mark  Null for a literal text run.
     * @param  string  $text  The literal text; empty for a marked node.
     * @param  list<MarkupNode>  $children  Nodes this mark wraps; empty for a text run.
     * @param  string|null  $href  Target of a LINK node; always null otherwise. Already checked to be http/https by the parser — see TranslatesMarkup.
     */
    private function __construct(
        public ?TextMark $mark,
        public string $text,
        public array $children,
        public ?string $href,
    ) {}

    public static function text(string $text): self
    {
        return new self(null, $text, [], null);
    }

    /**
     * @param  list<MarkupNode>  $children
     */
    public static function marked(TextMark $mark, array $children, ?string $href = null): self
    {
        return new self($mark, '', $children, $href);
    }
}
