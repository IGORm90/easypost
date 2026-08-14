<script setup lang="ts">
import { computed, h, ref, watch } from 'vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import type { DatesSetArg, EventApi, EventClickArg, EventDropArg, EventInput } from '@fullcalendar/core';
import { isAxiosError } from 'axios';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import CalendarEvent from './CalendarEvent.vue';
import { usePostsStore, type CalendarEntry } from '../../stores/posts';
import type { CalendarView } from './ViewSwitcher.vue';

/**
 * The grid itself: wraps @fullcalendar/vue3 and owns date navigation
 * (prev/today/next) plus the drag-and-drop wiring. `view`/`TagFilter`
 * selection live one level up in Calendar/Index.vue — this component
 * only renders what it's given.
 *
 * TIMEZONE: `entries[].publish_date` arrives as a UTC ISO string (a
 * Laravel `Carbon` cast — see CalendarEntryResource). It is passed to
 * FullCalendar completely unconverted as each event's `start`; the
 * `timeZone` option below (bound to the signed-in user's profile
 * timezone, passed in as `timezone`) is what makes FullCalendar *render*
 * that instant in the user's local wall-clock time — the conversion is
 * FullCalendar's, not ours, same "the instant never changes, only its
 * display does" rule utils/timeSlots.ts documents for time-of-day slots.
 * The reverse direction (drag a card, send the new time back) needs no
 * conversion either: `eventDrop`'s `info.event.start` is a real JS
 * `Date` (an absolute instant), and `Date#toISOString()` always yields
 * UTC regardless of `timeZone` — see `postsStore.moveTo()`.
 */
const props = defineProps<{
    entries: CalendarEntry[];
    view: CalendarView;
    timezone: string;
}>();

const emit = defineEmits<{
    'range-change': [{ from: string; to: string }];
    edit: [string];
    blocked: [{ groupId: string; status?: number }];
    error: [unknown];
}>();

const { t, locale } = useI18n();
const postsStore = usePostsStore();

const calendarRef = ref<InstanceType<typeof FullCalendar> | null>(null);
const title = ref('');

const FC_VIEW: Record<CalendarView, string> = {
    month: 'dayGridMonth',
    week: 'timeGridWeek',
    day: 'timeGridDay',
};

/**
 * PUBLISHED is checked at both the group level (`entry.state`) and the
 * per-segment level (`posts[].state`): PostGroupService::guardEditable()
 * (Task 20) 409s on either "the whole group is PUBLISHED" or "any single
 * segment already carries a release_id" (a partially-released group), so
 * the UI must treat both the same way — a group can look QUEUE overall
 * while one of its channel-specific segments has already gone out.
 */
function isLocked(entry: CalendarEntry): boolean {
    return entry.state === 'PUBLISHED' || entry.posts.some((post) => post.state === 'PUBLISHED');
}

function entryOf(event: EventApi): CalendarEntry {
    return (event.extendedProps as { entry: CalendarEntry }).entry;
}

const fcEvents = computed<EventInput[]>(() =>
    props.entries.map((entry) => ({
        id: entry.id,
        start: entry.publish_date,
        allDay: false,
        // Belt-and-suspenders with `eventAllow` below: `editable: false`
        // stops FullCalendar from ever starting a drag on a PUBLISHED
        // card in the first place — no drag means no snap-back, which is
        // strictly better UX than allowing the drag and reverting it.
        editable: !isLocked(entry),
        extendedProps: { entry },
    })),
);

function onDatesSet(arg: DatesSetArg): void {
    title.value = arg.view.title;
    // `arg.start`/`arg.end` are real Date instants (not the timeZone-
    // local *Str variants) — exactly what GET /api/calendar's `from`/`to`
    // (parsed by Carbon::parse on the backend) need, regardless of which
    // display timezone the grid is currently rendering in.
    emit('range-change', { from: arg.start.toISOString(), to: arg.end.toISOString() });
}

function onEventClick(arg: EventClickArg): void {
    // Seam for Task 25: clicking a card should open the post editor
    // modal for this group. That modal doesn't exist yet — Index.vue's
    // `onEdit` handler is a documented no-op until then.
    emit('edit', arg.event.id);
}

async function onEventDrop(info: EventDropArg): Promise<void> {
    const entry = entryOf(info.event);

    if (isLocked(entry) || !info.event.start) {
        info.revert();
        emit('blocked', { groupId: info.event.id });

        return;
    }

    try {
        await postsStore.moveTo(info.event.id, info.event.start);
    } catch (error) {
        // The store has already rolled `publish_date` back to its exact
        // original value (see postsStore.moveTo's docblock) — `revert()`
        // here undoes FullCalendar's own optimistic DOM move to match,
        // so the card visibly snaps back to where it started rather than
        // waiting a tick for the `entries` prop to flow back down.
        info.revert();
        const status = isAxiosError(error) ? error.response?.status : undefined;
        emit('blocked', { groupId: info.event.id, status });
    }
}

function eventAllow(_span: unknown, draggedEvent: EventApi | null): boolean {
    return draggedEvent ? !isLocked(entryOf(draggedEvent)) : true;
}

async function onRemove(groupId: string): Promise<void> {
    if (!window.confirm(t('calendar.removeConfirm'))) {
        return;
    }

    try {
        await postsStore.remove(groupId);
    } catch (error) {
        emit('error', error);
    }
}

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: FC_VIEW[props.view],
    headerToolbar: false as const,
    height: 'auto' as const,
    timeZone: props.timezone,
    firstDay: 1,
    locale: locale.value,
    editable: true,
    eventStartEditable: true,
    eventDurationEditable: false,
    dayMaxEvents: true,
    events: fcEvents.value,
    eventAllow,
    eventContent: (arg: { event: EventApi }) => {
        const entry = entryOf(arg.event);

        return h(CalendarEvent, { entry, locked: isLocked(entry), onRemove });
    },
    eventClick: onEventClick,
    eventDrop: onEventDrop,
    datesSet: onDatesSet,
}));

// `view` is only consulted by FullCalendar at creation (`initialView`);
// switching it afterwards goes through the imperative API, same as
// prev/today/next below.
watch(
    () => props.view,
    (view) => {
        calendarRef.value?.getApi().changeView(FC_VIEW[view]);
    },
);

function go(delta: number): void {
    const api = calendarRef.value?.getApi();

    if (!api) {
        return;
    }

    if (delta < 0) {
        api.prev();
    } else {
        api.next();
    }
}

function goToday(): void {
    calendarRef.value?.getApi().today();
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-center gap-2">
            <Button variant="ghost" type="button" :aria-label="t('calendar.today')" @click="go(-1)">‹</Button>
            <Button variant="secondary" type="button" @click="goToday">{{ t('calendar.today') }}</Button>
            <Button variant="ghost" type="button" @click="go(1)">›</Button>
            <span class="text-sm font-medium text-text">{{ title }}</span>
        </div>

        <div class="calendar-shell rounded-xl border border-border bg-surface p-2">
            <FullCalendar ref="calendarRef" :options="calendarOptions" />
        </div>
    </div>
</template>

<style scoped>
/* FullCalendar v6 injects its own stylesheet at runtime using --fc-*
   custom properties (no separate CSS file to import/override in a
   .vue-scanned way). Re-pointing those at our own tokens keeps the grid
   on-palette without ever writing a literal hex value here — see
   color.md and resources/css/app.css for what each var() resolves to. */
.calendar-shell :deep(.fc) {
    --fc-border-color: var(--border);
    --fc-page-bg-color: var(--surface);
    --fc-neutral-bg-color: var(--surface-2);
    --fc-list-event-hover-bg-color: var(--surface-2);
    --fc-today-bg-color: var(--primary-soft);
    --fc-event-bg-color: var(--surface);
    --fc-event-border-color: var(--border);
    --fc-event-text-color: var(--text);
    --fc-button-bg-color: var(--surface);
    --fc-button-border-color: var(--border);
    --fc-button-text-color: var(--text);
    --fc-button-hover-bg-color: var(--surface-2);
    --fc-button-active-bg-color: var(--primary-soft);
    /* Final review, Critical 5. Everything below was left at
       FullCalendar's own hardcoded defaults, which the palette guard
       cannot see (it greps .vue files for hex; these literals live in
       node_modules).

       The one that mattered: --fc-more-link-bg-color defaults to #d0d0d0
       and --fc-more-link-text-color to `inherit`, which resolves to
       --text (#E4EFE9) in the dark theme — 1.31:1 on the "+N more" chip,
       and `dayMaxEvents` above means any busy day renders it as the only
       way to reach the posts it hides. The rest were merely off-palette:
       a pure #FF0000 now-indicator reading as a sixth colour next to
       --danger, and a pale blue drag highlight. */
    --fc-more-link-bg-color: var(--primary-soft);
    --fc-more-link-text-color: var(--primary);
    --fc-now-indicator-color: var(--danger);
    --fc-highlight-color: var(--primary-soft);
    --fc-non-business-color: var(--surface-2);
    --fc-neutral-text-color: var(--muted);
    font-family: inherit;
}

.calendar-shell :deep(.fc .fc-daygrid-day-number),
.calendar-shell :deep(.fc .fc-col-header-cell-cushion),
.calendar-shell :deep(.fc .fc-timegrid-slot-label-cushion) {
    color: var(--text);
}

.calendar-shell :deep(.fc-event) {
    cursor: grab;
}

.calendar-shell :deep(.fc-event-dragging) {
    cursor: grabbing;
}
</style>
