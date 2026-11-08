<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '../ui/Badge.vue';
import PostActionsMenu from './PostActionsMenu.vue';
import type { CalendarEntry } from '../../stores/posts';

/**
 * Renders one calendar card. This is a pure presentational component fed
 * by CalendarGrid.vue's `eventContent` slot (see that file) — it never
 * touches the store itself, only emits intent (`remove`, `share`) back up.
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

const emit = defineEmits<{ remove: [string]; share: [string]; duplicate: [string] }>();

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

/**
 * «Кобальт» tints the whole card for the two outcomes — failed and
 * published — so a failed post can be spotted across a busy week without
 * reading every badge. In-flight states stay on the plain surface: a week
 * where every card is coloured stops saying anything (color.md §3.2).
 */
const cardTone = computed(() => {
    switch (props.entry.state) {
        case 'ERROR':
            return 'border-danger-border bg-danger-soft';
        case 'PUBLISHED':
            return 'border-success-border bg-success-soft';
        case 'DRAFT':
            return 'border-border-strong bg-surface';
        default:
            return 'border-border bg-surface';
    }
});

const isShared = computed(() => props.entry.share_token !== null);

/**
 * Sharing and duplicating both stay available on a locked (PUBLISHED)
 * card — for sharing that is in fact the common case, since the link
 * shows the post as published, and duplicating a post that published
 * well is the main reason the action exists. Neither touches the
 * original: duplicate writes a brand-new DRAFT group. `locked` only
 * takes away the destructive action, which is exactly what
 * PostState::PUBLISHED being terminal means here.
 */
const menuItems = computed(() => {
    const items: Array<{ key: string; label: string; variant?: 'default' | 'danger' }> = [
        { key: 'share', label: t('calendar.share.menuItem') },
        { key: 'duplicate', label: t('calendar.duplicate.menuItem') },
    ];

    if (!props.locked) {
        items.push({ key: 'remove', label: t('calendar.remove'), variant: 'danger' as const });
    }

    return items;
});

/**
 * Dispatch is exhaustive on purpose. This used to fall through — anything
 * that was not 'share' emitted 'remove' — which quietly turned every menu
 * item added later into a delete button, with the worst failure mode this
 * card has. An unknown key is now a no-op.
 */
function onMenuSelect(key: string): void {
    switch (key) {
        case 'share':
            emit('share', props.entry.id);
            break;
        case 'duplicate':
            emit('duplicate', props.entry.id);
            break;
        case 'remove':
            emit('remove', props.entry.id);
            break;
    }
}

// One line combining every channel targeted by this group — the
// calendar shows one card per PostGroup (per CalendarEntryResource), not
// one per channel-specific Post segment.
const channelNames = computed(() => props.entry.posts.map((post) => post.integration.name).join(', '));

const preview = computed(() => props.entry.posts[0]?.content ?? '');
</script>

<template>
    <div
        class="flex w-full flex-col gap-1 rounded-xl border px-2 py-1.5 text-left text-xs"
        :class="[cardTone, { 'border-dashed': isDraft }]"
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
            <span class="flex shrink-0 items-center gap-1">
                <!--
                    An active public link is a state the owner must be able
                    to notice, and color.md §4 forbids conveying it by
                    colour alone — so it gets the same icon + real text
                    treatment the lock below already has.
                -->
                <span v-if="isShared" class="sr-only">{{ t('calendar.share.indicator') }}</span>
                <svg
                    v-if="isShared"
                    class="h-3 w-3 shrink-0 text-muted"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        d="M8.5 11.5a3.5 3.5 0 005 0l2.5-2.5a3.536 3.536 0 10-5-5l-1 1a.75.75 0 101.06 1.06l1-1a2.036 2.036 0 112.88 2.88l-2.5 2.5a2 2 0 01-2.88 0 .75.75 0 10-1.06 1.06zm3-3a3.5 3.5 0 00-5 0L4 11a3.536 3.536 0 105 5l1-1a.75.75 0 10-1.06-1.06l-1 1a2.036 2.036 0 11-2.88-2.88l2.5-2.5a2 2 0 012.88 0 .75.75 0 101.06-1.06z"
                    />
                </svg>

                <!--
                    Block 4: a repeating post, marked the same way — icon
                    plus real text, never colour alone (color.md §4).
                -->
                <span v-if="entry.repeats" class="sr-only">{{ t('calendar.repeatIndicator') }}</span>
                <svg
                    v-if="entry.repeats"
                    class="h-3 w-3 shrink-0 text-muted"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M4 5.5A2.5 2.5 0 016.5 3h7.19l-1.22-1.22a.75.75 0 111.06-1.06l2.5 2.5a.75.75 0 010 1.06l-2.5 2.5a.75.75 0 11-1.06-1.06L13.69 4.5H6.5A1 1 0 005.5 5.5v3a.75.75 0 01-1.5 0v-3zm11.25 4a.75.75 0 01.75.75v3a2.5 2.5 0 01-2.5 2.5H6.31l1.22 1.22a.75.75 0 11-1.06 1.06l-2.5-2.5a.75.75 0 010-1.06l2.5-2.5a.75.75 0 111.06 1.06L6.31 14.5h7.19a1 1 0 001-1v-3a.75.75 0 01.75-.75z"
                        clip-rule="evenodd"
                    />
                </svg>

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

                <PostActionsMenu :items="menuItems" :label="t('calendar.actions')" @select="onMenuSelect" />
            </span>
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
