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
import CalendarEventDetails from './CalendarEventDetails.vue';
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
    share: [string];
    duplicate: [string];
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

/**
 * A click no longer jumps straight into the editor: the card in the grid
 * is deliberately truncated to fit its day cell, so the first click
 * "opens it up" — CalendarEventDetails grows out of the card's own rect
 * over the calendar and shows the whole post. The editor is one button
 * away from there.
 *
 * Only the id is kept, not the entry: the dialog reads the live entry
 * from `props.entries`, so a share/duplicate made from it shows up at
 * once, and a removed post simply closes it.
 */
const detailsId = ref<string | null>(null);
const detailsOrigin = ref<DOMRect | null>(null);

const detailsEntry = computed(() => (detailsId.value ? (props.entries.find((entry) => entry.id === detailsId.value) ?? null) : null));

function onEventClick(arg: EventClickArg): void {
    arg.jsEvent.preventDefault();
    detailsOrigin.value = arg.el.getBoundingClientRect();
    detailsId.value = arg.event.id;
}

function closeDetails(): void {
    detailsId.value = null;
}

function fromDetails(kind: 'edit' | 'share' | 'duplicate', groupId: string): void {
    closeDetails();

    if (kind === 'edit') {
        emit('edit', groupId);
    } else if (kind === 'share') {
        emit('share', groupId);
    } else {
        emit('duplicate', groupId);
    }
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

/**
 * Sharing is owned by the page, not the grid: the link modal must sit
 * above the calendar, and — unlike remove — it is available on locked
 * (PUBLISHED) cards too, so there is nothing to guard here.
 */
function onShare(groupId: string): void {
    emit('share', groupId);
}

/**
 * Duplicating is the page's business too, and for a stronger reason than
 * share: on success the page has to refetch the visible range and then
 * open the editor on the copy, neither of which the grid can do. So this
 * relays rather than calling the store the way onRemove does.
 */
function onDuplicate(groupId: string): void {
    emit('duplicate', groupId);
}

async function onRemove(groupId: string): Promise<void> {
    // Block 4: with lazy generation there is only ever one future
    // occurrence of a series, so deleting it does not remove "one of
    // many" — it ends the series, and nothing left on screen afterwards
    // would explain where the repeat went. The wording has to say so.
    const repeating = postsStore.findEverywhere(groupId).some((target) => target.repeats);

    if (!window.confirm(repeating ? t('calendar.removeConfirmRepeating') : t('calendar.removeConfirm'))) {
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
    // A cell holds two cards; the rest hide behind "+N" so one busy day
    // never stretches its whole week row. The link opens FullCalendar's
    // own popover — it floats over the grid with no backdrop (styled
    // below), and a card clicked inside it still opens the details dialog.
    dayMaxEvents: 2,
    moreLinkClick: 'popover' as const,
    moreLinkContent: (arg: { num: number }) => t('calendar.moreLink', { n: arg.num }),
    // Every card renders as the same block, one per day cell. Without
    // these, a timed post FullCalendar gave its default 1h duration would
    // straddle midnight when scheduled late in the evening and be drawn
    // as a two-day bar, spilling the card across the next day's column.
    eventDisplay: 'block',
    nextDayThreshold: '23:59:59',
    events: fcEvents.value,
    eventAllow,
    eventContent: (arg: { event: EventApi }) => {
        const entry = entryOf(arg.event);

        return h(CalendarEvent, { entry, locked: isLocked(entry), onRemove, onShare, onDuplicate });
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

        <div class="calendar-shell rounded-lg border border-border bg-surface p-2">
            <FullCalendar ref="calendarRef" :options="calendarOptions" />
        </div>

        <CalendarEventDetails
            :entry="detailsEntry"
            :locked="detailsEntry ? isLocked(detailsEntry) : false"
            :timezone="timezone"
            :origin="detailsOrigin"
            @close="closeDetails"
            @edit="fromDetails('edit', $event)"
            @share="fromDetails('share', $event)"
            @duplicate="fromDetails('duplicate', $event)"
            @remove="onRemove"
        />
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

       The one that mattered: the "+N more" chip defaulted to a light
       grey background with an `inherit` text colour, which resolves to
       --text in the dark theme — a contrast ratio of 1.31:1, on the
       affordance `dayMaxEvents` above makes the only way to reach the
       posts a busy day hides. The rest were merely off-palette: a pure
       red now-indicator reading as a sixth colour next to --danger, and
       a pale blue drag highlight. */
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
    cursor: pointer;
}

/* A card never grows past its day cell: the content is truncated inside
   (CalendarEvent.vue) and the full post opens in CalendarEventDetails. */
.calendar-shell :deep(.fc-daygrid-event-harness),
.calendar-shell :deep(.fc-daygrid-event),
.calendar-shell :deep(.fc-event-main) {
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
}

/*
 * «Кобальт» month/week grid: the toolbar title in the page's type, quiet
 * uppercase weekday headers on --surface-2, day numbers in the corner,
 * and event cards left to CalendarEvent (FullCalendar's own event chrome
 * is flattened so the card's status tint is what shows).
 */
.calendar-shell :deep(.fc .fc-toolbar-title) {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
}

.calendar-shell :deep(.fc .fc-button) {
    border-radius: 6px;
    font-size: 13px;
    padding: 6px 12px;
    box-shadow: none;
    border-color: var(--border-strong);
}

.calendar-shell :deep(.fc .fc-col-header-cell) {
    background: var(--surface-2);
}

.calendar-shell :deep(.fc .fc-col-header-cell-cushion) {
    padding: 9px 12px;
    font-size: 12px;
    font-weight: 500;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.calendar-shell :deep(.fc .fc-daygrid-day-number) {
    padding: 8px 10px 4px;
    font-size: 13px;
    font-weight: 600;
}

.calendar-shell :deep(.fc .fc-day-other .fc-daygrid-day-number) {
    font-weight: 400;
    color: var(--muted);
}

.calendar-shell :deep(.fc .fc-day-today .fc-daygrid-day-number) {
    margin: 6px 8px 2px;
    padding: 1px 7px;
    border-radius: 4px;
    background: var(--primary);
    color: var(--on-primary);
}

.calendar-shell :deep(.fc .fc-daygrid-day-frame) {
    min-height: 104px;
}

.calendar-shell :deep(.fc .fc-daygrid-event),
.calendar-shell :deep(.fc .fc-timegrid-event) {
    margin: 2px 6px;
    border: 0;
    background: transparent;
    box-shadow: none;
}

/* "+N" link and the popover it opens: a floating card on the surface,
   no overlay — the calendar behind stays visible and interactive. */
.calendar-shell :deep(.fc .fc-daygrid-day-bottom) {
    display: flex;
    justify-content: center;
    margin: 4px 6px 6px;
}

.calendar-shell :deep(.fc .fc-daygrid-more-link) {
    float: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 88px;
    margin: 0;
    padding: 5px 14px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
    transition:
        background-color 120ms ease,
        box-shadow 120ms ease;
}

.calendar-shell :deep(.fc .fc-daygrid-more-link:hover) {
    background: var(--primary);
    color: var(--on-primary);
    box-shadow: 0 2px 6px -3px var(--overlay);
}

.calendar-shell :deep(.fc .fc-daygrid-more-link:focus-visible) {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.calendar-shell :deep(.fc .fc-more-popover) {
    z-index: 40;
    width: 300px;
    max-width: calc(100vw - 32px);
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: 0 12px 32px -12px var(--overlay);
    transform-origin: top center;
    /* FullCalendar positions the popover with top/left, never transform,
       so animating transform cannot fight its placement. */
    animation: more-popover-in 180ms cubic-bezier(0.16, 1, 0.3, 1);
}

/* The cards inside follow the panel in a short stagger. */
.calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness) {
    animation: more-popover-item-in 220ms cubic-bezier(0.16, 1, 0.3, 1) both;
}

.calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness:nth-child(2)) { animation-delay: 30ms; }
.calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness:nth-child(3)) { animation-delay: 60ms; }
.calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness:nth-child(4)) { animation-delay: 90ms; }
.calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness:nth-child(5)) { animation-delay: 120ms; }
.calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness:nth-child(n + 6)) { animation-delay: 150ms; }

@keyframes more-popover-in {
    from {
        opacity: 0;
        transform: translateY(-6px) scale(0.96);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

@keyframes more-popover-item-in {
    from {
        opacity: 0;
        transform: translateY(-4px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

/* Same rule as Modal.vue: reduced motion keeps the fade, drops the movement. */
@media (prefers-reduced-motion: reduce) {
    .calendar-shell :deep(.fc .fc-more-popover),
    .calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness) {
        animation-name: more-popover-fade;
        animation-duration: 80ms;
        animation-delay: 0ms;
    }

    @keyframes more-popover-fade {
        from {
            opacity: 0;
        }
    }
}

.calendar-shell :deep(.fc .fc-more-popover .fc-popover-header) {
    padding: 8px 12px;
    background: var(--surface-2);
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
}

.calendar-shell :deep(.fc .fc-more-popover .fc-popover-body) {
    max-height: 60vh;
    overflow-y: auto;
    padding: 6px 0;
}

.calendar-shell :deep(.fc .fc-more-popover .fc-daygrid-event-harness) {
    margin-bottom: 4px;
}

.calendar-shell :deep(.fc-event-dragging) {
    cursor: grabbing;
}
</style>
