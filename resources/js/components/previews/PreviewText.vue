<script setup lang="ts">
import { computed } from 'vue';
import { linkify } from '../../utils/linkify';

/**
 * Renders customer-authored post text safely. NEVER uses `v-html`: URLs
 * are detected by `linkify()` and rendered as real `<a>` elements bound
 * via `:href` (Vue escapes attribute bindings), everything else goes
 * through plain `{{ }}` interpolation (Vue escapes text nodes too). This
 * is the one place in every preview component that touches raw post
 * content — see linkify.ts's docblock for how it stops an injected
 * `javascript:`/`data:` URL from ever reaching `href`.
 */
const props = defineProps<{ text: string }>();

const parts = computed(() => linkify(props.text));
</script>

<template>
    <span class="whitespace-pre-wrap break-words">
        <template v-for="(part, index) in parts" :key="index">
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
