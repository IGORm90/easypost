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
    <!-- Same segmented-control styling as ViewSwitcher/DisplayModeSwitcher,
     same reason: color.md §3.1 caps the screen at one primary element, and
     the active nav link plus "New post" already hold both. -->
    <div class="inline-flex flex-wrap rounded-xl border border-border bg-surface p-0.5" role="group" :aria-label="t('calendar.list.stateFilter')">
        <button
            v-for="state in states"
            :key="state"
            type="button"
            class="rounded-xl px-3 py-1 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="modelValue === state ? 'bg-surface-2 font-semibold text-text' : 'font-normal text-muted hover:text-text'"
            :aria-pressed="modelValue === state"
            @click="emit('update:modelValue', state)"
        >
            {{ t(`calendar.list.states.${state}`) }}
        </button>
    </div>
</template>
