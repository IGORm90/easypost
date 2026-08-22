<script setup lang="ts">
import { computed } from 'vue';
import { linkify } from '../../utils/linkify';
import { markupRuns } from '../../utils/markup';
import type { TextMark } from '../../stores/integrations';

/**
 * Renders customer-authored post text safely. NEVER uses `v-html`: URLs
 * are detected by `linkify()` and rendered as real `<a>` elements bound
 * via `:href` (Vue escapes attribute bindings), everything else goes
 * through plain `{{ }}` interpolation (Vue escapes text nodes too). This
 * is the one place in every preview component that touches raw post
 * content — see linkify.ts's docblock for how it stops an injected
 * `javascript:`/`data:` URL from ever reaching `href`.
 *
 * Block 3 added `marks`: which inline marks the destination network can
 * actually render. Each preview passes its own — that is legitimate
 * per-provider knowledge, and `components/previews/` is the one place
 * allowed to hold it (registry.ts's docblock, and
 * resources/js/__tests__/providerIsolation.spec.ts). A network that
 * cannot render bold gets `[]` and its preview shows the asterisks the
 * reader will really see, which is the entire point: the preview must
 * agree with what the provider will publish, not with what the editor
 * offered.
 *
 * Formatting changes nothing about the safety property above. Marked
 * runs become real elements (`<strong>`, `<em>`, `<s>`, `<code>`, `<a>`)
 * with the text still interpolated, so there is no path from post
 * content to raw HTML anywhere in this component.
 */
const props = withDefaults(defineProps<{ text: string; marks?: TextMark[] }>(), {
    marks: () => [],
});

/**
 * Marked runs first, then link detection inside each unmarked run: a
 * bare URL should still become a link, but a URL inside `code` must
 * stay text, and one that is already a markup link must not be linkified
 * twice.
 */
const runs = computed(() =>
    markupRuns(props.text, props.marks).map((run) => ({
        ...run,
        parts: run.href === null && !run.marks.includes('CODE') ? linkify(run.text) : null,
    })),
);

function classesFor(marks: TextMark[]): string[] {
    const classes: string[] = [];

    if (marks.includes('BOLD')) {
        classes.push('font-semibold');
    }

    if (marks.includes('ITALIC')) {
        classes.push('italic');
    }

    if (marks.includes('STRIKE')) {
        classes.push('line-through');
    }

    return classes;
}
</script>

<template>
    <span class="whitespace-pre-wrap break-words">
        <template v-for="(run, runIndex) in runs" :key="runIndex">
            <a
                v-if="run.href"
                :href="run.href"
                target="_blank"
                rel="noopener noreferrer nofollow"
                class="text-primary underline"
                :class="classesFor(run.marks)"
            >{{ run.text }}</a>
            <code
                v-else-if="run.marks.includes('CODE')"
                class="rounded bg-surface-2 px-1 font-mono text-[0.9em]"
                :class="classesFor(run.marks)"
            >{{ run.text }}</code>
            <span v-else :class="classesFor(run.marks)">
                <template v-for="(part, index) in run.parts ?? []" :key="index">
                    <a
                        v-if="part.type === 'link'"
                        :href="part.href"
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        class="text-primary underline"
                    >{{ part.value }}</a>
                    <template v-else>{{ part.value }}</template>
                </template>
            </span>
        </template>
    </span>
</template>
