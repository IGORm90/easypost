import { nextTick, ref } from 'vue';
import { useCaretInsertion } from '../useCaretInsertion';

/**
 * Block 3: the shared caret mechanics behind the emoji picker and the
 * format toolbar. Everything these components do is "change the text
 * around the selection and leave the caret somewhere sensible", and
 * getting the second half wrong is what makes an emoji picker feel
 * broken: the first emoji lands correctly, and every one after it lands
 * at the end of the post.
 */
function textareaWith(value: string, start: number, end = start): HTMLTextAreaElement {
    const element = document.createElement('textarea');
    document.body.append(element);
    element.value = value;
    element.setSelectionRange(start, end);

    return element;
}

it('inserts at the caret, not at the end', () => {
    const element = ref(textareaWith('hello world', 5));
    const emitted: string[] = [];
    const { insertAtCaret } = useCaretInsertion(element, () => 'hello world', (value) => emitted.push(value));

    insertAtCaret(' there');

    expect(emitted).toEqual(['hello there world']);
});

it('replaces the selection when there is one', () => {
    const element = ref(textareaWith('hello world', 6, 11));
    const emitted: string[] = [];
    const { insertAtCaret } = useCaretInsertion(element, () => 'hello world', (value) => emitted.push(value));

    insertAtCaret('there');

    expect(emitted).toEqual(['hello there']);
});

it('leaves the caret just past what it inserted', async () => {
    const element = ref(textareaWith('ab', 1));
    const { insertAtCaret } = useCaretInsertion(element, () => 'ab', (value) => {
        // A controlled <textarea> gets its value back from the parent, so
        // the spec has to play the parent's part for the caret assertion
        // to mean anything.
        element.value.value = value;
    });

    insertAtCaret('XY');
    await nextTick();

    expect(element.value.selectionStart).toBe(3);
    expect(element.value.selectionEnd).toBe(3);
});

it('wraps the selection in a pair of markers', () => {
    const element = ref(textareaWith('make me bold', 8, 12));
    const emitted: string[] = [];
    const { wrapSelection } = useCaretInsertion(element, () => 'make me bold', (value) => emitted.push(value));

    wrapSelection('**', '**');

    expect(emitted).toEqual(['make me **bold**']);
});

it('unwraps a selection that is already wrapped', () => {
    // Pressing bold twice must return the original text — otherwise the
    // button only ever adds markers and there is no way back except
    // deleting them by hand.
    const element = ref(textareaWith('make me **bold**', 10, 14));
    const emitted: string[] = [];
    const { wrapSelection } = useCaretInsertion(element, () => 'make me **bold**', (value) => emitted.push(value));

    wrapSelection('**', '**');

    expect(emitted).toEqual(['make me bold']);
});

it('unwraps when the markers are inside the selection too', () => {
    const element = ref(textareaWith('make me **bold**', 8, 16));
    const emitted: string[] = [];
    const { wrapSelection } = useCaretInsertion(element, () => 'make me **bold**', (value) => emitted.push(value));

    wrapSelection('**', '**');

    expect(emitted).toEqual(['make me bold']);
});

it('inserts an empty pair and puts the caret between the markers', async () => {
    const element = ref(textareaWith('', 0));
    const { wrapSelection } = useCaretInsertion(element, () => '', (value) => {
        element.value.value = value;
    });

    wrapSelection('**', '**');
    await nextTick();

    expect(element.value.value).toBe('****');
    expect(element.value.selectionStart).toBe(2);
});

it('selects what it wrapped so a second key press can unwrap it', async () => {
    const element = ref(textareaWith('bold', 0, 4));
    const { wrapSelection } = useCaretInsertion(element, () => 'bold', (value) => {
        element.value.value = value;
    });

    wrapSelection('**', '**');
    await nextTick();

    expect(element.value.selectionStart).toBe(2);
    expect(element.value.selectionEnd).toBe(6);
});

it('does nothing when there is no textarea to act on', () => {
    const element = ref<HTMLTextAreaElement | null>(null);
    const emitted: string[] = [];
    const { insertAtCaret } = useCaretInsertion(element, () => 'text', (value) => emitted.push(value));

    insertAtCaret('x');

    expect(emitted).toEqual([]);
});
