import { markupRuns } from '../markup';

/**
 * Block 3. The frontend half of the canonical parser: what the previews
 * use to show a post the way the network will render it, rather than the
 * way it is stored. Its rules must agree with the backend's
 * TranslatesMarkup — a preview that bolds something the provider will
 * publish as asterisks is worse than no preview.
 */
it('splits a body into plain and marked runs', () => {
    expect(markupRuns('a **bold** word', ['BOLD'])).toEqual([
        { text: 'a ', marks: [], href: null },
        { text: 'bold', marks: ['BOLD'], href: null },
        { text: ' word', marks: [], href: null },
    ]);
});

it('recognises every mark it is given', () => {
    const all = ['BOLD', 'ITALIC', 'STRIKE', 'CODE', 'LINK'] as const;

    expect(markupRuns('**b**', [...all])[0].marks).toEqual(['BOLD']);
    expect(markupRuns('*i*', [...all])[0].marks).toEqual(['ITALIC']);
    expect(markupRuns('~~s~~', [...all])[0].marks).toEqual(['STRIKE']);
    expect(markupRuns('`c`', [...all])[0].marks).toEqual(['CODE']);
    expect(markupRuns('[t](https://example.test)', [...all])[0]).toEqual({
        text: 't',
        marks: ['LINK'],
        href: 'https://example.test',
    });
});

it('keeps a mark the network does not support as literal text', () => {
    // This is the whole reason `marks` is a parameter: the preview for a
    // network with no bold must show the asterisks the reader will see.
    expect(markupRuns('a **bold** word', [])).toEqual([{ text: 'a **bold** word', marks: [], href: null }]);
});

it('keeps a link literal on a network that prints the brackets', () => {
    expect(markupRuns('[t](https://example.test)', ['BOLD'])).toEqual([
        { text: '[t](https://example.test)', marks: [], href: null },
    ]);
});

it('carries nested marks on the same run', () => {
    expect(markupRuns('**bold *and* more**', ['BOLD', 'ITALIC'])).toEqual([
        { text: 'bold ', marks: ['BOLD'], href: null },
        { text: 'and', marks: ['BOLD', 'ITALIC'], href: null },
        { text: ' more', marks: ['BOLD'], href: null },
    ]);
});

it('does not read markup inside code', () => {
    expect(markupRuns('`**x**`', ['CODE', 'BOLD'])).toEqual([{ text: '**x**', marks: ['CODE'], href: null }]);
});

it('leaves unpaired markers alone', () => {
    expect(markupRuns('2 * 3 * 4', ['ITALIC'])).toEqual([{ text: '2 * 3 * 4', marks: [], href: null }]);
    expect(markupRuns('unfinished **bold', ['BOLD'])).toEqual([{ text: 'unfinished **bold', marks: [], href: null }]);
});

it('refuses a non-http link target', () => {
    expect(markupRuns('[tap](javascript:alert(1))', ['LINK'])).toEqual([
        { text: '[tap](javascript:alert(1))', marks: [], href: null },
    ]);
});
