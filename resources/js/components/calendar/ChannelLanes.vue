<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CalendarEntry, CalendarIntegration } from '../../stores/posts';
import { resolveProviderIcon } from '../previews/registry';
import { utcIsoToZonedInput, zonedDayKey } from '../../utils/zonedDateTime';
import { stateCardTone } from './stateTone';

/**
 * The «Кобальт» week-by-channel view: one lane per channel, one column per
 * day. It only rearranges what /calendar already returns — one entry per
 * PostGroup, carrying one segment per targeted channel — so a group aimed
 * at three channels shows up once in each of their lanes, and clicking any
 * of those opens the editor on the whole group, exactly as a grid card does.
 *
 * Days are 'YYYY-MM-DD' keys in the profile's timezone (see utils/week.ts),
 * and each post is placed with zonedDayKey() in that same zone — never with
 * Date#getDay(), which would answer in the browser's zone.
 */
const props = defineProps<{
    entries: CalendarEntry[];
    /**
     * Every connected channel, in display order — a quiet channel still
     * gets its (empty) lane. The optional flags are the Integration's own;
     * when present the lane says under the name whether it can publish.
     */
    channels: Array<CalendarIntegration & { refresh_needed?: boolean; disabled?: boolean }>;
    days: string[];
    todayKey: string;
    timezone: string;
}>();

const emit = defineEmits<{ edit: [string] }>();

const { t, locale } = useI18n();

interface LanePost {
    key: string;
    groupId: string;
    time: string;
    content: string;
    state: string;
    tone: string;
}

const lanes = computed(() => {
    // A channel can have posts in range yet be missing from the channel
    // list — disabled, or removed after scheduling. Its posts must not
    // silently vanish from the week, so it gets a lane of its own after
    // the listed ones.
    const order: Array<CalendarIntegration & { refresh_needed?: boolean; disabled?: boolean }> = [...props.channels];
    const known = new Set(order.map((channel) => channel.id));

    for (const entry of props.entries) {
        for (const post of entry.posts) {
            if (!known.has(post.integration.id)) {
                known.add(post.integration.id);
                order.push(post.integration);
            }
        }
    }

    const cells = new Map<string, LanePost[]>();

    for (const entry of props.entries) {
        const day = zonedDayKey(entry.publish_date, props.timezone);
        const time = utcIsoToZonedInput(entry.publish_date, props.timezone).slice(11);

        for (const post of entry.posts) {
            const key = `${post.integration.id}|${day}`;
            const list = cells.get(key) ?? [];
            list.push({
                key: post.id,
                groupId: entry.id,
                time,
                content: post.content,
                state: post.state || entry.state,
                tone: stateCardTone(post.state || entry.state),
            });
            cells.set(key, list);
        }
    }

    return order.map((channel) => ({
        channel,
        status:
            channel.refresh_needed === undefined
                ? null
                : channel.refresh_needed
                  ? { label: t('channels.status.refreshNeeded'), tone: 'text-danger' }
                  : channel.disabled
                    ? { label: t('channels.status.disabled'), tone: 'text-muted' }
                    : { label: t('channels.status.active'), tone: 'text-muted' },
        icon: resolveProviderIcon(channel.provider_identifier),
        days: props.days.map((day) => ({
            day,
            posts: (cells.get(`${channel.id}|${day}`) ?? []).sort((a, b) => a.time.localeCompare(b.time)),
        })),
    }));
});

const dayHeaders = computed(() => {
    const weekday = new Intl.DateTimeFormat(locale.value, { weekday: 'short', timeZone: 'UTC' });

    return props.days.map((day) => {
        const date = new Date(`${day}T00:00:00Z`);

        return { day, weekday: weekday.format(date), number: String(date.getUTCDate()), today: day === props.todayKey };
    });
});
</script>

<template>
    <p v-if="lanes.length === 0" class="rounded-lg border border-border bg-surface px-4 py-6 text-sm text-muted">
        {{ t('calendar.lanes.empty') }}
    </p>
    <div v-else class="overflow-x-auto rounded-lg border border-border bg-surface">
        <div class="min-w-[960px]" role="table" :aria-label="t('calendar.lanes.label')">
            <div class="grid grid-cols-[180px_repeat(7,minmax(0,1fr))] border-b border-border bg-surface-2" role="row">
                <div class="px-3.5 py-2.5 text-xs text-muted" role="columnheader">{{ t('calendar.lanes.channel') }}</div>
                <div
                    v-for="header in dayHeaders"
                    :key="header.day"
                    data-test="lane-day"
                    role="columnheader"
                    :aria-current="header.today ? 'date' : undefined"
                    class="border-l border-border px-3 py-2.5 text-sm"
                    :class="header.today ? 'bg-primary-soft text-primary-hover' : 'text-text'"
                >
                    <span class="text-xs" :class="header.today ? '' : 'text-muted'">{{ header.weekday }}</span>
                    <span class="ml-1 font-semibold">{{ header.number }}</span>
                </div>
            </div>

            <div
                v-for="lane in lanes"
                :key="lane.channel.id"
                data-test="lane"
                :data-channel="lane.channel.id"
                role="row"
                class="grid grid-cols-[180px_repeat(7,minmax(0,1fr))] border-b border-border last:border-b-0"
            >
                <div class="flex min-w-0 items-center gap-2.5 px-3.5 py-3" role="rowheader">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent" aria-hidden="true">
                        <component :is="lane.icon" class="h-4 w-4" />
                    </span>
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate text-[13px] font-medium text-text">{{ lane.channel.name }}</span>
                        <span v-if="lane.status" data-test="lane-status" class="truncate text-[11px]" :class="lane.status.tone">{{ lane.status.label }}</span>
                    </span>
                </div>
                <div
                    v-for="cell in lane.days"
                    :key="cell.day"
                    :data-day="cell.day"
                    role="cell"
                    class="flex min-h-[72px] flex-col gap-1.5 border-l border-border p-2"
                    :class="cell.day === todayKey ? 'bg-surface-2' : ''"
                >
                    <button
                        v-for="post in cell.posts"
                        :key="post.key"
                        type="button"
                        data-test="lane-post"
                        class="flex w-full flex-col gap-0.5 rounded-xl border px-2 py-1.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        :class="[post.tone, { 'border-dashed': post.state === 'DRAFT' }]"
                        @click="emit('edit', post.groupId)"
                    >
                        <span class="flex items-center justify-between gap-1">
                            <span class="font-mono text-[11px] text-muted">{{ post.time }}</span>
                            <span class="truncate text-[10px] font-semibold text-muted">{{ t(`calendar.state.${post.state}`) }}</span>
                        </span>
                        <span class="line-clamp-2 text-xs text-text">{{ post.content }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
