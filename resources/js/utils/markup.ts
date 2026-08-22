import type { TextMark } from '../stores/integrations';

/**
 * The frontend reader of the canonical markup a post body is stored in
 * (`**bold**`, `*italic*`, `~~strike~~`, `` `code` ``, `[text](url)`) —
 * the twin of the backend's App\Social\Providers\TranslatesMarkup, and
 * it has to agree with it. A preview that bolds something the provider
 * will publish as literal asterisks is worse than no preview at all,
 * which is why `marks` is a parameter: a network that cannot render a
 * mark shows its markers as the characters the reader will actually
 * get.
 *
 * The result is a flat list of runs, each carrying the set of marks
 * active over it, rather than a tree. Nesting is real (`**bold *and*
 * more**`) but a run with two marks renders identically to a span
 * inside a span, and flat means the preview components need no
 * recursive component to draw it.
 *
 * Parsing rules mirror the backend's exactly:
 *  - an opening marker must be followed by a non-space and its closing
 *    one preceded by a non-space, so `2 * 3 * 4` stays arithmetic;
 *  - an unpaired marker is literal text;
 *  - markup inside code is text;
 *  - a link URL must be http/https.
 */
export interface MarkupRun {
    text: string;
    marks: TextMark[];
    href: string | null;
}

const DELIMITERS: Array<{ token: string; mark: TextMark }> = [
    { token: '**', mark: 'BOLD' },
    { token: '~~', mark: 'STRIKE' },
    { token: '*', mark: 'ITALIC' },
];

function isSpace(character: string): boolean {
    return character.trim() === '';
}

/** Index just past the closing delimiter, or null if this is not a real pair. */
function findClose(text: string, start: number, token: string): number | null {
    let search = start;

    for (;;) {
        const close = text.indexOf(token, search);

        if (close === -1) {
            return null;
        }

        // A '*' that is part of a '**' does not close an italic run.
        if (token === '*' && text.slice(close, close + 2) === '**') {
            search = close + 2;
            continue;
        }

        if (close === start || isSpace(text[close - 1])) {
            search = close + token.length;
            continue;
        }

        return close;
    }
}

const LINK = /^\[([^\]\n]+)\]\(([^)\s]+)\)/;

export function markupRuns(text: string, marks: TextMark[]): MarkupRun[] {
    const runs: MarkupRun[] = [];
    let buffer = '';

    function flush(active: TextMark[]): void {
        if (buffer !== '') {
            runs.push({ text: buffer, marks: [...active], href: null });
            buffer = '';
        }
    }

    function walk(source: string, active: TextMark[]): void {
        let index = 0;

        while (index < source.length) {
            const rest = source.slice(index);

            if (marks.includes('CODE') && rest.startsWith('`')) {
                const close = findClose(source, index + 1, '`');

                if (close !== null) {
                    flush(active);
                    // Verbatim: whatever is between the backticks is code.
                    runs.push({ text: source.slice(index + 1, close), marks: [...active, 'CODE'], href: null });
                    index = close + 1;
                    continue;
                }
            }

            const delimiter = DELIMITERS.find(
                (candidate) => marks.includes(candidate.mark) && rest.startsWith(candidate.token),
            );

            if (delimiter) {
                const start = index + delimiter.token.length;
                const close = isSpace(source[start] ?? '') ? null : findClose(source, start, delimiter.token);

                if (close !== null) {
                    flush(active);
                    walk(source.slice(start, close), [...active, delimiter.mark]);
                    index = close + delimiter.token.length;
                    continue;
                }
            }

            if (marks.includes('LINK') && rest.startsWith('[')) {
                const match = LINK.exec(rest);

                if (match && /^https?:\/\//i.test(match[2])) {
                    flush(active);
                    runs.push({ text: match[1], marks: [...active, 'LINK'], href: match[2] });
                    index += match[0].length;
                    continue;
                }
            }

            buffer += source[index];
            index++;
        }

        flush(active);
    }

    walk(text, []);

    return runs;
}
