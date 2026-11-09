<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import Modal from '../ui/Modal.vue';
import type { CalendarEntry } from '../../stores/posts';

/**
 * The enlarged view of a calendar card. In the month grid a card is cut
 * down to one line of everything (CalendarEvent.vue) so it never leaves
 * its day cell; clicking it opens this dialog, which grows out of the
 * card (`origin`) and shows the whole post: every channel with its full
 * text, state, tags, indicators and the card's actions.
 *
 * Pure presentation, like CalendarEvent — it only emits intent; the grid
 * relays it to the page.
 */
const props = defineProps<{
    entry: CalendarEntry | null;
    locked: boolean;
    timezone: string;
    origin?: DOMRect | null;
}>();

const emit = defineEmits<{
    close: [];
    edit: [string];
    share: [string];
    duplicate: [string];
    remove: [string];
}>();

const { t, locale } = useI18n();

const badgeStatus = computed(() => {
    switch (props.entry?.state) {
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

const dateLabel = computed(() => {
    if (!props.entry) {
        return '';
    }

    return new Intl.DateTimeFormat(locale.value, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: props.timezone,
    }).format(new Date(props.entry.publish_date));
});

function act(kind: 'edit' | 'share' | 'duplicate' | 'remove'): void {
    if (!props.entry) {
        return;
    }

    const id = props.entry.id;

    switch (kind) {
        case 'edit':
            emit('edit', id);
            break;
        case 'share':
            emit('share', id);
            break;
        case 'duplicate':
            emit('duplicate', id);
            break;
        case 'remove':
            emit('remove', id);
            break;
    }
}
</script>

<template>
    <Modal :open="entry !== null" :title="t('calendar.details.title')" :origin="origin" @close="emit('close')">
        <div v-if="entry" class="flex flex-col gap-4" data-test="event-details">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <Badge :status="badgeStatus">{{ t(`calendar.state.${entry.state}`) }}</Badge>
                <span class="text-muted">{{ dateLabel }}</span>
            </div>

            <ul class="flex flex-col gap-1 text-xs text-muted">
                <li v-if="locked" class="flex items-center gap-1.5">
                    <svg class="h-3 w-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            fill-rule="evenodd"
                            d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 6V5a2 2 0 10-4 0v2h4z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    {{ t('calendar.locked') }}
                </li>
                <li v-if="entry.share_token !== null">{{ t('calendar.share.indicator') }}</li>
                <li v-if="entry.repeats">{{ t('calendar.repeatIndicator') }}</li>
            </ul>

            <div v-if="entry.tags.length" class="flex flex-wrap items-center gap-1">
                <span
                    v-for="tag in entry.tags"
                    :key="tag.id"
                    class="inline-flex items-center gap-1 rounded-xl border border-border px-2 py-0.5 text-xs text-text"
                >
                    <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: tag.color }" aria-hidden="true" />
                    {{ tag.name }}
                </span>
            </div>

            <div class="flex max-h-[50vh] flex-col gap-3 overflow-y-auto">
                <section v-for="post in entry.posts" :key="post.id" class="rounded-xl border border-border bg-surface-2 p-3">
                    <h3 class="mb-2 flex items-center gap-1.5 text-sm font-medium text-text">
                        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent" aria-hidden="true">
                            <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 2a6 6 0 00-6 6c0 4.5 6 10 6 10s6-5.5 6-10a6 6 0 00-6-6zm0 8a2 2 0 110-4 2 2 0 010 4z" />
                            </svg>
                        </span>
                        <span class="truncate">{{ post.integration.name }}</span>
                    </h3>
                    <p v-if="post.content" class="whitespace-pre-wrap break-words text-sm text-text">{{ post.content }}</p>
                    <p v-else class="text-sm italic text-muted">{{ t('calendar.details.empty') }}</p>
                </section>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                <Button v-if="!locked" variant="danger-text" class="mr-auto" @click="act('remove')">{{ t('calendar.remove') }}</Button>
                <Button variant="secondary" @click="act('share')">{{ t('calendar.share.menuItem') }}</Button>
                <Button variant="secondary" @click="act('duplicate')">{{ t('calendar.duplicate.menuItem') }}</Button>
                <Button variant="primary" @click="act('edit')">{{ t('calendar.details.edit') }}</Button>
            </div>
        </div>
    </Modal>
</template>
