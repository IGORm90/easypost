<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '../ui/Badge.vue';
import PostActionsMenu from './PostActionsMenu.vue';
import { zonedDayKey } from '../../utils/zonedDateTime';
import type { PostListRow } from '../../stores/posts';
import type { PostStateFilter } from './StateFilter.vue';

/**
 * Block 2's flat list of posts — the view the grid cannot be once there
 * are more than a screenful of posts, and the only one that can answer
 * "show me everything that failed" regardless of month.
 *
 * Presentational, exactly like CalendarGrid.vue: it renders what it is
 * handed and emits intent. The store, the HTTP and the paging live in
 * Calendar/Index.vue.
 *
 * State is never carried by colour alone (color.md §4): every row's
 * Badge pairs its colour with the state's name, and PUBLISHED — the one
 * state that changes what a row can DO — adds a lock icon with real
 * (visually hidden) text beside it, exactly as CalendarEvent.vue does.
 * The two surfaces show the same post, so they say the same things about
 * it in the same way.
 */
const props = defineProps<{
    entries: PostListRow[];
    /** The signed-in user's profile timezone — never the browser's. */
    timezone: string;
    /** Which filter produced this result, so an empty one can explain itself. */
    stateFilter: PostStateFilter;
}>();

const emit = defineEmits<{ edit: [string]; share: [string]; duplicate: [string]; remove: [string] }>();

const { t, locale } = useI18n();

/**
 * PUBLISHED is terminal, so a published row offers no delete — the same
 * rule CalendarGrid.isLocked()/CalendarEvent.vue apply. Unlike the grid,
 * a list row has no per-segment states to consult: `PostGroupListResource`
 * carries the group's own state only. That is not a gap — the group turns
 * PUBLISHED as soon as it is fully out, and a partially-released group
 * still 409s at the API if anyone tries to act on it. The list simply
 * shows the action and lets the server refuse, rather than pretending to
 * know something it was not sent.
 */
function isLocked(row: PostListRow): boolean {
    return row.state === 'PUBLISHED';
}

function badgeStatus(row: PostListRow) {
    switch (row.state) {
        case 'PUBLISHED':
            return 'success' as const;
        case 'ERROR':
            return 'danger' as const;
        case 'DRAFT':
            return 'neutral' as const;
        default:
            return 'scheduled' as const;
    }
}

function menuItems(row: PostListRow): Array<{ key: string; label: string; variant?: 'default' | 'danger' }> {
    const items: Array<{ key: string; label: string; variant?: 'default' | 'danger' }> = [
        { key: 'share', label: t('calendar.share.menuItem') },
        { key: 'duplicate', label: t('calendar.duplicate.menuItem') },
    ];

    if (!isLocked(row)) {
        items.push({ key: 'remove', label: t('calendar.remove'), variant: 'danger' as const });
    }

    return items;
}

/**
 * Exhaustive on purpose, for the reason CalendarEvent.vue's own dispatch
 * documents: this used to be a fall-through there, which silently turned
 * every later menu item into a delete button.
 */
function onMenuSelect(key: string, id: string): void {
    switch (key) {
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

/**
 * Grouped by the day each post falls on IN THE VIEWER'S timezone (see
 * zonedDayKey) — not the browser's, which would file a 23:30 post under
 * the wrong heading for anyone whose machine is set to a different zone
 * than their profile. Rows arrive newest-first from the API and that
 * order is preserved verbatim: the server owns the sort (it has the tie
 * breaker that makes paging stable), the client only inserts headings.
 *
 * A row whose `publish_date` is null gets its own group rather than
 * being dropped: in practice PostGroupService always fills the column,
 * so such a row means something went wrong, and hiding it would hide the
 * evidence.
 */
const groups = computed(() => {
    const result: Array<{ key: string; label: string; rows: PostListRow[] }> = [];

    for (const row of props.entries) {
        const key = row.publish_date === null ? 'none' : zonedDayKey(row.publish_date, props.timezone);
        const last = result[result.length - 1];

        if (last?.key === key) {
            last.rows.push(row);
            continue;
        }

        result.push({ key, label: row.publish_date === null ? t('calendar.list.noDate') : dayLabel(row.publish_date), rows: [row] });
    }

    return result;
});

/**
 * Spelled out field by field rather than with `dateStyle`, and always
 * against `props.timezone` — same rule and same reason as
 * Public/SharedPost.vue's formatter.
 */
function dayLabel(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        weekday: 'short',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: props.timezone,
    }).format(new Date(iso));
}

function timeLabel(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    return new Intl.DateTimeFormat(locale.value, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: props.timezone,
    }).format(new Date(iso));
}

const channelNames = (row: PostListRow): string => row.channels.map((channel) => channel.name).join(', ');
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="entries.length === 0" class="rounded-lg border border-border bg-surface px-4 py-6 text-sm text-muted">
            {{ t(`calendar.list.empty.${stateFilter}`) }}
        </p>

        <!--
            «Кобальт»: a table, one row per post, under one heading row per
            day. No `overflow-hidden` on the frame, deliberately: the
            actions menu is absolutely positioned inside a row, and clipping
            the table to its rounded corners cut that menu in half. Narrow
            screens scroll the table sideways instead (`overflow-x-auto`
            sits on the outer box, the menu opens inside the scroll area).
        -->
        <div v-else class="overflow-x-auto rounded-lg border border-border bg-surface">
            <table class="w-full min-w-[820px] border-collapse text-[13px]">
                <thead>
                    <tr class="bg-surface-2 text-left text-xs text-muted">
                        <th scope="col" class="px-3.5 py-2.5 font-medium">{{ t('calendar.list.columns.when') }}</th>
                        <th scope="col" class="px-3.5 py-2.5 font-medium">{{ t('calendar.list.columns.channel') }}</th>
                        <th scope="col" class="px-3.5 py-2.5 font-medium">{{ t('calendar.list.columns.post') }}</th>
                        <th scope="col" class="px-3.5 py-2.5 font-medium">{{ t('calendar.list.columns.tags') }}</th>
                        <th scope="col" class="px-3.5 py-2.5 font-medium">{{ t('calendar.list.columns.status') }}</th>
                        <th scope="col" class="px-3.5 py-2.5"><span class="sr-only">{{ t('calendar.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody v-for="group in groups" :key="group.key">
                    <tr>
                        <th
                            data-test="day-heading"
                            scope="colgroup"
                            colspan="6"
                            class="border-t border-border bg-surface px-3.5 pb-1 pt-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted"
                        >
                            {{ group.label }}
                        </th>
                    </tr>
                    <tr v-for="row in group.rows" :key="row.id" data-test="post-row" class="border-t border-border align-top">
                        <td class="whitespace-nowrap px-3.5 py-3 font-mono text-xs text-muted">{{ timeLabel(row.publish_date) }}</td>
                        <td class="px-3.5 py-3">
                            <span class="flex items-center gap-2">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 2a6 6 0 00-6 6c0 4.5 6 10 6 10s6-5.5 6-10a6 6 0 00-6-6zm0 8a2 2 0 110-4 2 2 0 010 4z" />
                                    </svg>
                                </span>
                                <span class="max-w-[180px] truncate text-text">{{ channelNames(row) }}</span>
                            </span>
                        </td>
                        <td class="max-w-[360px] px-3.5 py-3">
                            <!-- The post cell is the row's button: one
                                 keyboard-reachable target with an accessible
                                 name. The actions menu sits in its own cell,
                                 because a button inside a button is invalid. -->
                            <button type="button" class="flex w-full flex-col gap-0.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="emit('edit', row.id)">
                                <span class="truncate font-medium text-text">{{ row.preview }}</span>
                                <span class="flex flex-wrap items-center gap-2 text-xs text-muted">
                                    <span v-if="row.comments_count > 0">{{ t('calendar.list.comments', { count: row.comments_count }) }}</span>
                                    <span v-if="row.share_token !== null" class="sr-only">{{ t('calendar.share.indicator') }}</span>
                                    <svg v-if="row.share_token !== null" class="h-3 w-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path
                                            d="M8.5 11.5a3.5 3.5 0 005 0l2.5-2.5a3.536 3.536 0 10-5-5l-1 1a.75.75 0 101.06 1.06l1-1a2.036 2.036 0 112.88 2.88l-2.5 2.5a2 2 0 01-2.88 0 .75.75 0 10-1.06 1.06zm3-3a3.5 3.5 0 00-5 0L4 11a3.536 3.536 0 105 5l1-1a.75.75 0 10-1.06-1.06l-1 1a2.036 2.036 0 11-2.88-2.88l2.5-2.5a2 2 0 012.88 0 .75.75 0 101.06-1.06z"
                                        />
                                    </svg>
                                    <!-- Block 4: icon plus real text, the same
                                         rule the share and lock indicators follow. -->
                                    <span v-if="row.repeats" class="sr-only">{{ t('calendar.repeatIndicator') }}</span>
                                    <svg v-if="row.repeats" class="h-3 w-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path
                                            fill-rule="evenodd"
                                            d="M4 5.5A2.5 2.5 0 016.5 3h7.19l-1.22-1.22a.75.75 0 111.06-1.06l2.5 2.5a.75.75 0 010 1.06l-2.5 2.5a.75.75 0 11-1.06-1.06L13.69 4.5H6.5A1 1 0 005.5 5.5v3a.75.75 0 01-1.5 0v-3zm11.25 4a.75.75 0 01.75.75v3a2.5 2.5 0 01-2.5 2.5H6.31l1.22 1.22a.75.75 0 11-1.06 1.06l-2.5-2.5a.75.75 0 010-1.06l2.5-2.5a.75.75 0 111.06 1.06L6.31 14.5h7.19a1 1 0 001-1v-3a.75.75 0 01.75-.75z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    <span v-if="isLocked(row)" class="sr-only">{{ t('calendar.locked') }}</span>
                                    <svg v-if="isLocked(row)" class="h-3 w-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path
                                            fill-rule="evenodd"
                                            d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 6V5a2 2 0 10-4 0v2h4z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </span>
                            </button>
                        </td>
                        <td class="px-3.5 py-3">
                            <span class="flex flex-wrap gap-1">
                                <span
                                    v-for="tag in row.tags"
                                    :key="tag.id"
                                    class="inline-flex items-center gap-1 rounded border border-border px-1.5 py-0.5 text-[11px] text-text"
                                >
                                    <!-- Server-validated ^#[0-9a-fA-F]{6}$ user input
                                         (StoreTagRequest); the name is always shown
                                         beside the dot, never colour alone. -->
                                    <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: tag.color }" aria-hidden="true" />
                                    {{ tag.name }}
                                </span>
                                <span v-if="row.tags.length === 0" class="text-muted">—</span>
                            </span>
                        </td>
                        <td class="px-3.5 py-3">
                            <Badge :status="badgeStatus(row)">{{ t(`calendar.state.${row.state}`) }}</Badge>
                        </td>
                        <td class="px-3.5 py-2 text-right">
                            <PostActionsMenu
                                :items="menuItems(row)"
                                :label="t('calendar.actions')"
                                @select="onMenuSelect($event, row.id)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
