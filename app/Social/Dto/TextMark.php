<?php

namespace App\Social\Dto;

/**
 * One inline text mark a post's body may carry — the closed vocabulary
 * shared by the editor's format toolbar, the previews, and every
 * provider's own translation of a post into its network's syntax.
 *
 * The canonical form stored in `posts.content` is a small Markdown
 * subset, one for the whole application regardless of destination:
 *
 *   BOLD    **text**
 *   ITALIC  *text*
 *   STRIKE  ~~text~~
 *   CODE    `text`
 *   LINK    [text](https://example.test)
 *
 * What the user typed is what is stored: nothing rewrites the body on
 * the way into the database, so a draft reopened later reads back
 * exactly as it was written. Turning that canonical form into whatever
 * a given network actually accepts on the wire (Telegram's parse_mode
 * HTML, or nothing at all) happens inside the provider
 * at publish time — see App\Social\Providers\TranslatesMarkup. That is
 * not a stylistic choice: the knowledge "this network writes bold with
 * one asterisk" is provider-specific behaviour, and the project's
 * isolation rule (tests/Unit/Architecture/ProviderIsolationTest.php)
 * keeps it inside app/Social/Providers.
 *
 * An enum rather than a list of strings so that a provider cannot
 * declare a mark that does not exist ('stike'), and so PHPStan and the
 * frontend both get a closed set to check against.
 *
 * Values are UPPERCASE to match this project's other string-backed
 * enums (PostState, OrganizationRole); they cross the wire as-is in
 * `GET /api/integrations/providers` and are the same literals the SPA's
 * `TextMark` union type carries.
 */
enum TextMark: string
{
    case BOLD = 'BOLD';
    case ITALIC = 'ITALIC';
    case STRIKE = 'STRIKE';
    case CODE = 'CODE';
    case LINK = 'LINK';
}
