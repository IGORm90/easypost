<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { CalendarTag } from '../../stores/posts';

/**
 * Multi-select tag filter for the calendar toolbar. `modelValue` is the
 * list of selected tag ids, forwarded verbatim as `tags[]` to
 * `GET /api/calendar` by Index.vue. Selection state is shown by an
 * icon + border change, not by the chip's background colour alone —
 * the chip's own colour dot is the tag's *identity* (categorical, user
 * chosen), not a status, but color.md §4 still asks that no meaning be
 * carried by colour alone, so "selected" gets its own, colour-independent
 * signal here too.
 */
const props = defineProps<{
    tags: CalendarTag[];
    modelValue: string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [string[]] }>();

const { t } = useI18n();

function toggle(id: string): void {
    const next = props.modelValue.includes(id)
        ? props.modelValue.filter((tagId) => tagId !== id)
        : [...props.modelValue, id];

    emit('update:modelValue', next);
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('calendar.filters.tags')">
        <p v-if="tags.length === 0" class="text-sm text-muted">{{ t('calendar.filters.noTags') }}</p>
        <button
            v-for="tag in tags"
            :key="tag.id"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border px-2 py-1 text-xs transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="modelValue.includes(tag.id) ? 'border-primary text-text' : 'border-border text-muted hover:text-text'"
            :aria-pressed="modelValue.includes(tag.id)"
            @click="toggle(tag.id)"
        >
            <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: tag.color }" aria-hidden="true" />
            {{ tag.name }}
            <svg v-if="modelValue.includes(tag.id)" class="h-3 w-3 shrink-0 text-primary" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M16.704 5.29a1 1 0 010 1.42l-7.5 7.5a1 1 0 01-1.42 0l-3.5-3.5a1 1 0 111.42-1.42L8.5 12.08l6.79-6.79a1 1 0 011.42 0z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>
    </div>
</template>
