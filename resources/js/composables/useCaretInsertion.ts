import { nextTick, type Ref } from 'vue';

/**
 * Caret-aware editing of a controlled `<textarea>` — the shared
 * mechanics behind `EmojiPicker.vue` and `FormatToolbar.vue`, which
 * both do the same two things: change the text around the current
 * selection, and leave the caret somewhere the user expects.
 *
 * Why a composable rather than two copies: the fiddly half is not the
 * string surgery, it is restoring the selection afterwards. The
 * textarea's value comes from the parent (`:value` + `@input`), so the
 * moment a new value is emitted Vue re-renders it and the browser
 * collapses the caret to the end. Restoring must therefore happen
 * AFTER that render — hence the `nextTick()` below, which is the single
 * reason this is not three lines inline in each component. Without it
 * the first emoji lands correctly and every one after it lands at the
 * end of the post, which is exactly how a picker earns the reputation
 * of being broken.
 *
 * Focus is restored too: a toolbar button or a picker cell takes focus
 * when clicked, and typing should continue in the field, not nowhere.
 */
export function useCaretInsertion(
    element: Ref<HTMLTextAreaElement | null>,
    currentValue: () => string,
    emitValue: (value: string) => void,
) {
    function selection(): { start: number; end: number } | null {
        const target = element.value;

        if (!target) {
            return null;
        }

        return { start: target.selectionStart ?? 0, end: target.selectionEnd ?? 0 };
    }

    async function restore(start: number, end: number): Promise<void> {
        await nextTick();
        const target = element.value;

        if (!target) {
            return;
        }

        target.focus();
        target.setSelectionRange(start, end);
    }

    /** Replaces the selection (or inserts at the caret when there is none) and puts the caret just past the new text. */
    function insertAtCaret(text: string): void {
        const range = selection();

        if (!range) {
            return;
        }

        const value = currentValue();
        emitValue(value.slice(0, range.start) + text + value.slice(range.end));
        void restore(range.start + text.length, range.start + text.length);
    }

    /**
     * Wraps the selection in a pair of markers — or removes them when
     * they are already there, so pressing bold twice returns the text
     * the user started with.
     *
     * "Already there" is checked on both sides of the boundary: the
     * markers may sit inside the selection (the user selected
     * `**bold**`) or just outside it (the user selected `bold` in
     * `**bold**`, which is what a double key press produces, since the
     * first press leaves the inner text selected).
     */
    function wrapSelection(before: string, after: string): void {
        const range = selection();

        if (!range) {
            return;
        }

        const value = currentValue();
        const selected = value.slice(range.start, range.end);

        if (selected.startsWith(before) && selected.endsWith(after) && selected.length >= before.length + after.length) {
            const stripped = selected.slice(before.length, selected.length - after.length);
            emitValue(value.slice(0, range.start) + stripped + value.slice(range.end));
            void restore(range.start, range.start + stripped.length);

            return;
        }

        const outerStart = range.start - before.length;

        if (outerStart >= 0 && value.slice(outerStart, range.start) === before && value.slice(range.end, range.end + after.length) === after) {
            emitValue(value.slice(0, outerStart) + selected + value.slice(range.end + after.length));
            void restore(outerStart, outerStart + selected.length);

            return;
        }

        emitValue(value.slice(0, range.start) + before + selected + after + value.slice(range.end));
        // The wrapped text stays selected: that is what lets a second
        // press of the same button unwrap it, and it also reads as
        // "this is what I just made bold".
        void restore(range.start + before.length, range.start + before.length + selected.length);
    }

    return { insertAtCaret, wrapSelection };
}
