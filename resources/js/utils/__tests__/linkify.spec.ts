import { linkify } from '../linkify';

it('returns a single text part for text with no URL', () => {
    expect(linkify('just some plain text')).toEqual([{ type: 'text', value: 'just some plain text' }]);
});

it('linkifies an http(s) URL with a matching href', () => {
    expect(linkify('see https://example.com/path for details')).toEqual([
        { type: 'text', value: 'see ' },
        { type: 'link', value: 'https://example.com/path', href: 'https://example.com/path' },
        { type: 'text', value: ' for details' },
    ]);
});

it('linkifies a bare www. host by assuming https', () => {
    const parts = linkify('visit www.example.com now');
    expect(parts[1]).toEqual({ type: 'link', value: 'www.example.com', href: 'https://www.example.com/' });
});

it('handles multiple URLs in the same string', () => {
    const parts = linkify('http://a.com and http://b.com');
    const links = parts.filter((p) => p.type === 'link');
    expect(links).toHaveLength(2);
    expect(links[0].href).toBe('http://a.com/');
    expect(links[1].href).toBe('http://b.com/');
});

it('returns the whole string as one part when it is entirely a URL', () => {
    expect(linkify('https://example.com')).toEqual([{ type: 'link', value: 'https://example.com', href: 'https://example.com/' }]);
});

it('empty string yields no parts', () => {
    expect(linkify('')).toEqual([]);
});

// --- Hostile input --------------------------------------------------
// The whole reason this module exists instead of a naive regex-to-v-html
// linkifier: a preview must never let customer-authored text become an
// executable href.

it('never linkifies a bare javascript: URL — it has no http/www prefix to match on', () => {
    const parts = linkify('click here: javascript:alert(document.cookie)');
    expect(parts.some((p) => p.type === 'link')).toBe(false);
    expect(parts.map((p) => p.value).join('')).toBe('click here: javascript:alert(document.cookie)');
});

it('never linkifies a bare data: URL', () => {
    const parts = linkify('data:text/html,<script>alert(1)</script>');
    expect(parts.some((p) => p.type === 'link')).toBe(false);
});

it('a URL followed immediately by markup-looking text does not pull the markup into href', () => {
    const parts = linkify('https://good.com"><script>alert(1)</script>');
    const link = parts.find((p) => p.type === 'link');
    expect(link).toBeDefined();
    expect(link!.href).toBe('https://good.com/');
    expect(link!.href).not.toContain('<script>');
    // the markup-looking remainder is preserved as plain text, to be
    // rendered via escaped interpolation by the caller — never dropped,
    // never treated as HTML.
    const rest = parts
        .filter((p) => p !== link)
        .map((p) => p.value)
        .join('');
    expect(rest).toBe('"><script>alert(1)</script>');
});

it('a malformed http-prefixed candidate that URL() cannot parse falls back to plain text', () => {
    // "http://" followed by nothing valid enough to form a host — the
    // regex still matches something, but safeHref()'s URL() parse (or
    // its protocol check) must reject it rather than emit a broken href.
    const parts = linkify('http://[::not-a-valid-host');
    expect(parts.every((p) => p.type === 'text')).toBe(true);
});
