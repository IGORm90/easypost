<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '../ui/Badge.vue';
import type { CalendarEntry } from '../../stores/posts';

/**
 * Renders one calendar card. This is a pure presentational component fed
 * by CalendarGrid.vue's `eventContent` slot (see that file) — it never
 * touches the store itself, only emits intent (`remove`) back up.
 *
 * State is never conveyed by colour alone (color.md §3.3/§4): every
 * state below pairs a colour with a text label (the Badge) and, for the
 * one state that changes *behaviour* (PUBLISHED → locked), an icon and a
 * dashed-vs-solid border on top of that — three independent signals, not
 * one.
 *
 * DRAFT is deliberately `neutral`, not `warning`, per color.md §3.8's
 * own worked example: every card already carries an accent-coloured
 * channel avatar, and accent/warning must never sit together in one
 * component. "Черновик" stays distinguishable by the dashed border and
 * the Badge's text alone — §3.8 explicitly reserves `--warning` for a
 * state the user actually needs to act on, which a draft is not.
 */
const props = defineProps<{
    entry: CalendarEntry;
    locked: boolean;
}>();

const emit = defineEmits<{ remove: [string] }>();

const { t } = useI18n();

const badgeStatus = computed(() => {
    switch (props.entry.state) {
        case 'PUBLISHED':
            return 'success' as const;
        case 'ERROR':
            return 'danger' as const;
        case 'DRAFT':
            return 'neutral' as const;
        default:
            return 'scheduled' as const;
    }
});

const isDraft = computed(() => props.entry.state === 'DRAFT');

// One line combining every channel targeted by this group — the
// calendar shows one card per PostGroup (per CalendarEntryResource), not
// one per channel-specific Post segment.
const channelNames = computed(() => props.entry.posts.map((post) => post.integration.name).join(', '));

const preview = computed(() => props.entry.posts[0]?.content ?? '');

function onRemoveClick(event: MouseEvent): void {
    event.stopPropagation();
    emit('remove', props.entry.id);
}
</script>

<template>
    <div
        class="flex w-full flex-col gap-1 rounded-xl border border-border px-2 py-1.5 text-left text-xs"
        :class="{ 'border-dashed': isDraft }"
    >
        <div class="flex items-center justify-between gap-1">
            <span class="flex items-center gap-1 truncate font-medium text-text">
                <!-- Accent is used here per color.md §1: "аватары соцсетей" is
                     an explicit accent use-case, not a primary action. -->
                <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent" aria-hidden="true">
                    <svg class="h-2.5 w-2.5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 2a6 6 0 00-6 6c0 4.5 6 10 6 10s6-5.5 6-10a6 6 0 00-6-6zm0 8a2 2 0 110-4 2 2 0 010 4z" />
                    </svg>
                </span>
                <span class="truncate">{{ channelNames }}</span>
            </span>

            <!--
                Final review, Important 11: this icon carried `aria-hidden`
                and `:aria-label` at once, and aria-hidden wins — the label
                was dead and locked state was announced nowhere. The icon
                stays decorative; the name it was meant to carry now lives
                in real text beside it, visually hidden.
            -->
            <span v-if="locked" class="sr-only">{{ t('calendar.locked') }}</span>
            <svg
                v-if="locked"
                class="h-3 w-3 shrink-0 text-muted"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 6V5a2 2 0 10-4 0v2h4z"
                    clip-rule="evenodd"
                />
            </svg>
            <button
                v-else
                type="button"
                class="shrink-0 text-muted hover:text-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                :aria-label="t('calendar.remove')"
                @click="onRemoveClick"
            >
                &times;
            </button>
        </div>

        <p class="truncate text-muted">{{ preview }}</p>

        <div class="flex flex-wrap items-center gap-1">
            <Badge :status="badgeStatus">{{ t(`calendar.state.${entry.state}`) }}</Badge>
            <span
                v-for="tag in entry.tags"
                :key="tag.id"
                class="inline-flex items-center gap-1 rounded-xl border border-border px-1.5 py-0.5 text-[10px] text-text"
            >
                <!-- Tag colours are user input, validated server-side as
                     ^#[0-9a-fA-F]{6}$ (Task 21's StoreTagRequest) — binding
                     them here is safe. The name is always shown as text
                     alongside the dot, so the tag is never identified by
                     colour alone. -->
                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: tag.color }" aria-hidden="true" />
                {{ tag.name }}
            </span>
        </div>
    </div>
</template>
