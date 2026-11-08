<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * "all" plus every `PostState`, in the order a person reads a post's
 * life: not started, waiting, claimed, done, broken. `ERROR` is a
 * segment of its own rather than something folded into "all" — finding
 * the posts that failed is the question the calendar grid cannot answer
 * at all (its error banner only ever speaks about the visible month),
 * and it is a large part of why this list exists.
 *
 * `SCHEDULED` vs `QUEUE` (durable-scheduling rewrite): `SCHEDULED` is a
 * post durably waiting for its due date with nothing on the queue yet;
 * `QUEUE` is claimed — a publish job genuinely exists for it right now,
 * normally for only the few seconds/minutes until it actually runs. See
 * `App\Enums\PostState`'s docblock on the backend.
 */
export type PostStateFilter = 'all' | 'DRAFT' | 'SCHEDULED' | 'QUEUE' | 'PUBLISHED' | 'ERROR';

defineProps<{ modelValue: PostStateFilter }>();
const emit = defineEmits<{ 'update:modelValue': [PostStateFilter] }>();

const { t } = useI18n();

const states: PostStateFilter[] = ['all', 'DRAFT', 'SCHEDULED', 'QUEUE', 'PUBLISHED', 'ERROR'];
</script>

<template>
    <!-- «Кобальт» draws the state filter as tabs over the table. The active
     tab is underlined in --text and set in semibold, not in primary:
     color.md §3.1 keeps blue for the screen's one action, and a filter
     reports state. `aria-pressed` carries it for assistive technology. -->
    <div class="flex flex-wrap border-b border-border" role="group" :aria-label="t('calendar.list.stateFilter')">
        <button
            v-for="state in states"
            :key="state"
            type="button"
            class="-mb-px border-b-2 px-3 py-2 text-[13px] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
            :class="modelValue === state ? 'border-text font-semibold text-text' : 'border-bg font-normal text-muted hover:text-text'"
            :aria-pressed="modelValue === state"
            @click="emit('update:modelValue', state)"
        >
            {{ t(`calendar.list.states.${state}`) }}
        </button>
    </div>
</template>
