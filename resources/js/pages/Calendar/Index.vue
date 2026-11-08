<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import AppLayout from '../../layouts/AppLayout.vue';
import Toast from '../../components/ui/Toast.vue';
import Button from '../../components/ui/Button.vue';
import CalendarGrid from '../../components/calendar/CalendarGrid.vue';
import ViewSwitcher, { type CalendarView } from '../../components/calendar/ViewSwitcher.vue';
import DisplayModeSwitcher, { type DisplayMode } from '../../components/calendar/DisplayModeSwitcher.vue';
import StateFilter, { type PostStateFilter } from '../../components/calendar/StateFilter.vue';
import PostList from '../../components/calendar/PostList.vue';
import TagFilter from '../../components/calendar/TagFilter.vue';
import TagManager from '../../components/tags/TagManager.vue';
import PublishErrorBanner from '../../components/calendar/PublishErrorBanner.vue';
import ChannelLanes from '../../components/calendar/ChannelLanes.vue';
import CalendarStats from '../../components/calendar/CalendarStats.vue';
import PostEditorModal from '../../components/editor/PostEditorModal.vue';
import ShareLinkModal from '../../components/posts/ShareLinkModal.vue';
import { usePostsStore, type CalendarTag } from '../../stores/posts';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';
import { useIntegrationsStore } from '../../stores/integrations';
import { zonedDayKey } from '../../utils/zonedDateTime';
import { addDays, weekDays, weekRange, weekStartKey } from '../../utils/week';
import { api } from '../../api/client';

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const postsStore = usePostsStore();
const auth = useAuthStore();
const organization = useOrganizationStore();
const integrations = useIntegrationsStore();

const view = ref<CalendarView>('month');

/*
 * Block 2's second axis of display. `mode` is which surface renders at
 * all; `view` (month/week/day) is a zoom level that only means anything
 * inside the grid, which is why the switcher for it is hidden in list
 * mode rather than left on screen doing nothing.
 *
 * Both `mode` and `state` are read from — and written back to — the
 * query string, so "look at everything that failed" is a link a
 * teammate can be sent. Unknown values fall back to the defaults rather
 * than rendering an empty screen: a mistyped URL should show the
 * calendar, not nothing. The page NUMBER deliberately stays out of the
 * URL — see usePostsStore.fetchList().
 */
const MODES: DisplayMode[] = ['channels', 'grid', 'list'];
const STATES: PostStateFilter[] = ['all', 'DRAFT', 'SCHEDULED', 'QUEUE', 'PUBLISHED', 'ERROR'];

// «Кобальт»: the week-by-channel lanes are the calendar's home view; the
// FullCalendar grid and the list stay one click (and one `?mode=`) away.
const mode = ref<DisplayMode>(MODES.includes(route.query.mode as DisplayMode) ? (route.query.mode as DisplayMode) : 'channels');
const stateFilter = ref<PostStateFilter>(
    STATES.includes(route.query.state as PostStateFilter) ? (route.query.state as PostStateFilter) : 'all',
);
const listLoadingMore = ref(false);
/**
 * Whether the last list load failed. Kept apart from `notice` because it
 * decides something `notice` cannot: a failed load leaves `list` empty,
 * and rendering the list's empty state on top of that would tell the
 * user "no drafts" when the truth is "we do not know". The list is
 * withheld entirely until a load succeeds.
 */
const listFailed = ref(false);
const customer = ref('');
const selectedTags = ref<string[]>([]);
const availableTags = ref<CalendarTag[]>([]);
const range = ref<{ from: string; to: string } | null>(null);
const loading = ref(false);
// One toast slot for both errors and neutral notices (e.g. the
// editor-not-built-yet seam below) — `variant` decides which Toast
// styling applies, `text` decides what it says.
const notice = ref<{ text: string; variant: 'danger' | 'neutral' } | null>(null);

// Renders in the signed-in user's own profile timezone, not the
// browser's — same source CalendarGrid.vue's timeZone option consumes,
// same field ChannelSettingsDrawer/TimeSlotEditor already use.
const timezone = computed(() => auth.user?.timezone ?? 'UTC');

/*
 * The lanes page through whole weeks on their own — FullCalendar is not
 * mounted in that mode, so nothing else reports a range. Day keys, not
 * Dates, so the week is the profile's week (utils/week.ts explains why).
 */
const todayKey = computed(() => zonedDayKey(new Date().toISOString(), timezone.value));
const weekStart = ref(weekStartKey(todayKey.value));
const laneDays = computed(() => weekDays(weekStart.value));
const laneChannels = computed(() => integrations.list.filter((channel) => !channel.disabled));
const weekLabel = computed(() =>
    new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).formatRange(
        new Date(`${weekStart.value}T00:00:00Z`),
        new Date(`${addDays(weekStart.value, 6)}T00:00:00Z`),
    ),
);

/** ISO week number of the Monday on screen — the mono line above the title says "Week 46". */
const weekNumber = computed(() => {
    const monday = new Date(`${weekStart.value}T00:00:00Z`);
    const thursday = new Date(monday);
    thursday.setUTCDate(monday.getUTCDate() + 3);
    const yearStart = new Date(Date.UTC(thursday.getUTCFullYear(), 0, 1));

    return Math.ceil(((thursday.getTime() - yearStart.getTime()) / 86_400_000 + 1) / 7);
});

/** The failure counter's "show" link: the list, filtered to failures, is where a failed post is acted on. */
function showFailed(): void {
    stateFilter.value = 'ERROR';
    mode.value = 'list';
}

function showWeek(startKey: string): void {
    weekStart.value = startKey;
    range.value = weekRange(startKey, timezone.value);
    void load();
}

function loadChannels(): void {
    // The lanes are drawn from the channel list, so a failure here only
    // costs the quiet channels their empty lanes — channels with posts in
    // range still get one from the entries themselves.
    integrations.fetch().catch(() => undefined);
}

async function loadTags(): Promise<void> {
    try {
        const { data } = await api.get<CalendarTag[]>('/tags');
        availableTags.value = data;

        // A tag can be deleted from the manager this page opens, and the
        // filter holds ids, not objects. Left alone, a stale id would go
        // on filtering the calendar down to nothing with no visible
        // reason — an empty month that looks like a bug.
        const live = new Set(data.map((tag) => tag.id));
        const kept = selectedTags.value.filter((id) => live.has(id));

        if (kept.length !== selectedTags.value.length) {
            selectedTags.value = kept;
        }
    } catch {
        // Non-fatal: the calendar itself still works with an empty tag
        // filter, so a failed tag fetch doesn't block the page.
    }
}

async function load(): Promise<void> {
    if (!range.value) {
        return;
    }

    loading.value = true;

    try {
        await postsStore.fetchRange(range.value.from, range.value.to, {
            customer: customer.value.trim() || undefined,
            tags: selectedTags.value.length ? selectedTags.value : undefined,
        });
        notice.value = null;
    } catch {
        notice.value = { text: t('calendar.loadError'), variant: 'danger' };
    } finally {
        loading.value = false;
    }
}

/**
 * The list's own fetch. It shares the customer/tag filters with the grid
 * — they are the same two controls, and the API takes the same parameter
 * names for exactly that reason — and adds the state filter the grid has
 * no equivalent for.
 *
 * `{ reset: true }` on every filter change, because the store appends
 * pages: without it a narrower filter would show its results below the
 * wider filter's leftovers.
 */
async function loadList(): Promise<void> {
    postsStore.listFilters = {
        state: stateFilter.value === 'all' ? undefined : stateFilter.value,
        customer: customer.value.trim() || undefined,
        tags: selectedTags.value.length ? selectedTags.value : undefined,
    };

    loading.value = true;

    try {
        await postsStore.fetchList({ reset: true });
        notice.value = null;
        listFailed.value = false;
    } catch {
        notice.value = { text: t('calendar.list.loadError'), variant: 'danger' };
        listFailed.value = true;
    } finally {
        loading.value = false;
    }
}

async function loadMore(): Promise<void> {
    listLoadingMore.value = true;

    try {
        await postsStore.fetchList();
    } catch {
        notice.value = { text: t('calendar.list.loadError'), variant: 'danger' };
    } finally {
        listLoadingMore.value = false;
    }
}

/**
 * Whichever surface is showing gets refreshed; the other one is refetched
 * when it comes back, so there is no point paying for it now.
 */
function reload(): void {
    if (mode.value === 'list') {
        void loadList();

        return;
    }

    void load();
}

/**
 * `replace`, not `push`: the filter is a view of one screen, not a place.
 * With `push`, every click of a segment would add a history entry and the
 * browser's Back button would walk through filter states instead of
 * leaving the calendar.
 */
function syncQuery(): void {
    void router.replace({
        query: {
            ...route.query,
            mode: mode.value === 'channels' ? undefined : mode.value,
            state: stateFilter.value === 'all' ? undefined : stateFilter.value,
        },
    });
}

watch(mode, (next) => {
    syncQuery();

    if (next === 'list') {
        void loadList();

        return;
    }

    if (next === 'channels') {
        loadChannels();
        showWeek(weekStart.value);
    }

    // Going back to the grid remounts CalendarGrid (v-if, not v-show),
    // which re-emits range-change and reloads `entries` on its own.
});

watch(stateFilter, () => {
    syncQuery();
    void loadList();
});

onMounted(() => {
    if (mode.value === 'list') {
        void loadList();
    }

    if (mode.value === 'channels') {
        loadChannels();
        showWeek(weekStart.value);
    }
});

// CalendarGrid emits range-change exactly when FullCalendar's visible
// range actually changes (navigation or view switch) — never on a plain
// render — so this is the only place fetchRange() gets called for a new
// range, per the brief's "don't refetch on every render" requirement.
function onRangeChange(next: { from: string; to: string }): void {
    range.value = next;
    load();
}

let customerDebounce: ReturnType<typeof setTimeout> | undefined;
watch(customer, () => {
    clearTimeout(customerDebounce);
    customerDebounce = setTimeout(reload, 300);
});

watch(selectedTags, reload, { deep: true });

// Same "switching orgs refreshes dependent data" pattern as
// Channels/Index.vue and Settings/Team.vue — the calendar is
// per-organization.
watch(
    () => organization.current?.id,
    (id, previousId) => {
        if (id && id !== previousId) {
            loadTags();
            reload();
        }
    },
);

onMounted(loadTags);

function onBlocked(payload: { groupId: string; status?: number }): void {
    notice.value = {
        text: payload.status === 409 ? t('calendar.toast.moveConflict') : t('calendar.toast.moveError'),
        variant: 'danger',
    };

    if (payload.status === 409) {
        // The 409 means the group was published in the moments between
        // load and drop (PostGroupService::guardEditable) — a confirmed
        // race, not a validation error. postsStore.moveTo() already
        // rolled `publish_date` back to its exact original value on its
        // own (see that method's docblock); this bounded refetch of only
        // the *current* range is a separate step, to bring `entries[].state`
        // itself up to date (now PUBLISHED) so the card also stops
        // looking draggable, not a substitute for that rollback.
        load();
    }
}

function onGridError(): void {
    notice.value = { text: t('calendar.toast.removeError'), variant: 'danger' };
}

// Task 25's editor. `editorGroupId === null` composes a brand-new post
// (opened from the "New post" button below); a non-null id edits the
// group `onEdit()` was called with (a calendar card click).
const editorOpen = ref(false);
const editorGroupId = ref<string | null>(null);

function onEdit(groupId: string): void {
    editorGroupId.value = groupId;
    editorOpen.value = true;
}

/**
 * The share link modal is owned here rather than by the card, so that a
 * single instance serves every card and sits above the grid instead of
 * inside a FullCalendar-rendered cell. The token comes straight from the
 * store entry (CalendarEntryResource carries `share_token`), so opening
 * it costs no request; `changed` needs no handler because the store has
 * already updated that same entry.
 */
/**
 * Tag management lives here rather than inside TagFilter: that component
 * is mounted twice — here and inside PostEditorModal — and a trigger in
 * it would put a third modal on top of the editor.
 */
const tagManagerOpen = ref(false);

const shareGroupId = ref<string | null>(null);

const shareToken = computed(() => postsStore.entries.find((entry) => entry.id === shareGroupId.value)?.share_token ?? null);

function onShare(groupId: string): void {
    shareGroupId.value = groupId;
}

/**
 * Duplicating: copy, refetch, then open the copy for editing.
 *
 * The refetch is what makes the copy appear — the store deliberately
 * writes nothing locally (see its docblock), and the copy always
 * inherits the original's publish_date, so it always lands inside the
 * range `load()` refetches. Opening the editor afterwards is the point
 * of the flow: the copy is a DRAFT sitting on the original's date, so it
 * almost always needs its text or its time changed before it is useful.
 * `onEdit()` is reused verbatim; PostEditorModal loads the group from
 * the server by id and does not read the store, so the order of these
 * two steps only affects what is behind the modal.
 *
 * A plan-limit rejection is a 402, and the api client's interceptor has
 * already raised the LimitToast by the time this catch runs — saying it
 * again in `notice` would be the same refusal told twice, so only other
 * failures get a message. Same rule MediaUploader.vue:123 follows.
 */
async function onDuplicate(groupId: string): Promise<void> {
    try {
        const newId = await postsStore.duplicate(groupId);

        reload();
        onEdit(newId);
    } catch (error) {
        if (!(isAxiosError(error) && error.response?.status === 402)) {
            notice.value = { text: t('calendar.toast.duplicateError'), variant: 'danger' };
        }
    }
}

/**
 * Deleting from the list. The grid does this inside CalendarGrid (it owns
 * the card), the list does not — so the confirmation and the failure
 * message live here. The store takes the row out of BOTH collections and
 * puts it back at its index if the request fails.
 */
async function onListRemove(groupId: string): Promise<void> {
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
    } catch {
        notice.value = { text: t('calendar.toast.removeError'), variant: 'danger' };
    }
}

function onNewPost(): void {
    editorGroupId.value = null;
    editorOpen.value = true;
}

function onEditorSaved(): void {
    // Re-run the same load() this page already uses for a filter/range
    // change — the saved group may be new, moved, or have changed
    // channels, so a full refetch of the current range is simpler and
    // safer than trying to patch a single entry in place.
    reload();
}
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-[18px]">
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex flex-col gap-1">
                    <span class="font-mono text-xs text-muted" aria-live="polite">
                        <template v-if="mode === 'channels'">{{ t('calendar.lanes.weekNumber', { n: weekNumber }) }} · {{ weekLabel }}</template>
                        <template v-else-if="mode === 'grid'">{{ t('calendar.postsInRange', { count: postsStore.entries.length }) }}</template>
                        <template v-else>{{ t('calendar.list.subtitle') }}</template>
                        <span v-if="loading"> · {{ t('ui.loading') }}</span>
                    </span>
                    <h1 class="text-[26px] font-semibold leading-tight tracking-tight text-text">{{ t('calendar.title') }}</h1>
                </div>
                <div v-if="mode === 'channels'" class="inline-flex overflow-hidden rounded-xl border border-border-strong bg-surface">
                    <button
                        type="button"
                        data-test="week-prev"
                        class="px-2.5 py-2 text-text hover:bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                        :aria-label="t('calendar.lanes.previousWeek')"
                        @click="showWeek(addDays(weekStart, -7))"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>
                    </button>
                    <button
                        type="button"
                        data-test="week-today"
                        class="border-x border-border-strong px-3.5 py-2 text-[13px] text-text hover:bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                        @click="showWeek(weekStartKey(todayKey))"
                    >
                        {{ t('calendar.today') }}
                    </button>
                    <button
                        type="button"
                        data-test="week-next"
                        class="px-2.5 py-2 text-text hover:bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                        :aria-label="t('calendar.lanes.nextWeek')"
                        @click="showWeek(addDays(weekStart, 7))"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                    </button>
                </div>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <ViewSwitcher v-if="mode === 'grid'" v-model="view" />
                    <DisplayModeSwitcher v-model="mode" />
                    <Button variant="primary" @click="onNewPost">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        {{ t('calendar.newPost') }}
                    </Button>
                </div>
            </div>

            <CalendarStats v-if="mode !== 'list'" :entries="postsStore.entries" @show-errors="showFailed" />

            <div class="flex flex-wrap items-center gap-2.5">
                <!-- A raw label + input rather than <Input>: the Cobalt filter
                     bar puts the label inside the field's frame. It is still a
                     real <label>, which is what a11y.spec.ts protects. -->
                <label class="flex items-center gap-2 rounded-xl border border-border-strong bg-surface px-2.5 py-1.5 text-[13px] text-muted focus-within:ring-2 focus-within:ring-primary">
                    {{ t('calendar.filters.customer') }}
                    <input
                        v-model="customer"
                        type="search"
                        :placeholder="t('calendar.filters.all')"
                        class="w-32 border-0 bg-surface p-0 text-[13px] text-text placeholder:text-muted focus:outline-none focus:ring-0"
                    />
                </label>
                <TagFilter v-model="selectedTags" :tags="availableTags" />
                <button
                    type="button"
                    class="rounded-xl border border-dashed border-border-strong px-2.5 py-1.5 text-[13px] text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @click="tagManagerOpen = true"
                >
                    {{ t('tags.manage') }}
                </button>
            </div>

            <StateFilter v-if="mode === 'list'" v-model="stateFilter" />

            <Toast v-if="notice" :variant="notice.variant" @dismiss="notice = null">{{ notice.text }}</Toast>

            <!--
                docs/ACCEPTANCE.md §8. Sits above the grid rather than
                inside it: a failed publication is a thing to act on, not
                a card to find — and it is scoped to the same visible
                range the grid is showing, so it never speaks about posts
                that are not on screen.
            -->
            <CalendarStats v-if="mode !== 'list'" :entries="postsStore.entries" />

            <PublishErrorBanner v-if="mode !== 'list'" :range="range" @retried="load" />

            <ChannelLanes
                v-if="mode === 'channels'"
                :entries="postsStore.entries"
                :channels="laneChannels"
                :days="laneDays"
                :today-key="todayKey"
                :timezone="timezone"
                @edit="onEdit"
            />

            <CalendarGrid
                v-else-if="mode === 'grid'"
                :entries="postsStore.entries"
                :view="view"
                :timezone="timezone"
                @range-change="onRangeChange"
                @edit="onEdit"
                @share="onShare"
                @duplicate="onDuplicate"
                @blocked="onBlocked"
                @error="onGridError"
            />

            <template v-else-if="!listFailed">
                <PostList
                    :entries="postsStore.list"
                    :timezone="timezone"
                    :state-filter="stateFilter"
                    @edit="onEdit"
                    @share="onShare"
                    @duplicate="onDuplicate"
                    @remove="onListRemove"
                />

                <Button
                    v-if="postsStore.listHasMore && postsStore.list.length > 0"
                    variant="ghost"
                    type="button"
                    :loading="listLoadingMore"
                    @click="loadMore"
                >
                    {{ t('calendar.list.loadMore') }}
                </Button>
            </template>
        </div>

        <PostEditorModal
            :open="editorOpen"
            :group-id="editorGroupId"
            @close="editorOpen = false"
            @saved="onEditorSaved"
        />

        <ShareLinkModal
            :open="shareGroupId !== null"
            :group-id="shareGroupId"
            :share-token="shareToken"
            @close="shareGroupId = null"
        />

        <TagManager :open="tagManagerOpen" @close="tagManagerOpen = false" @changed="loadTags" />
    </AppLayout>
</template>
