<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../layouts/AppLayout.vue';
import Toast from '../../components/ui/Toast.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import CalendarGrid from '../../components/calendar/CalendarGrid.vue';
import ViewSwitcher, { type CalendarView } from '../../components/calendar/ViewSwitcher.vue';
import TagFilter from '../../components/calendar/TagFilter.vue';
import PostEditorModal from '../../components/editor/PostEditorModal.vue';
import { usePostsStore, type CalendarTag } from '../../stores/posts';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';
import { api } from '../../api/client';

const { t } = useI18n();
const postsStore = usePostsStore();
const auth = useAuthStore();
const organization = useOrganizationStore();

const view = ref<CalendarView>('month');
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

async function loadTags(): Promise<void> {
    try {
        const { data } = await api.get<CalendarTag[]>('/tags');
        availableTags.value = data;
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
    customerDebounce = setTimeout(load, 300);
});

watch(selectedTags, load, { deep: true });

// Same "switching orgs refreshes dependent data" pattern as
// Channels/Index.vue and Settings/Team.vue — the calendar is
// per-organization.
watch(
    () => organization.current?.id,
    (id, previousId) => {
        if (id && id !== previousId) {
            loadTags();
            load();
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

function onNewPost(): void {
    editorGroupId.value = null;
    editorOpen.value = true;
}

function onEditorSaved(): void {
    // Re-run the same load() this page already uses for a filter/range
    // change — the saved group may be new, moved, or have changed
    // channels, so a full refetch of the current range is simpler and
    // safer than trying to patch a single entry in place.
    load();
}
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-baseline gap-2">
                    <h1 class="text-lg font-semibold text-text">{{ t('calendar.title') }}</h1>
                    <span v-if="loading" class="text-xs text-muted">{{ t('ui.loading') }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <ViewSwitcher v-model="view" />
                    <Button variant="primary" @click="onNewPost">{{ t('calendar.newPost') }}</Button>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-4">
                <div class="w-56">
                    <Input
                        v-model="customer"
                        :label="t('calendar.filters.customer')"
                        :placeholder="t('calendar.filters.customerPlaceholder')"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-text">{{ t('calendar.filters.tags') }}</span>
                    <TagFilter v-model="selectedTags" :tags="availableTags" />
                </div>
            </div>

            <Toast v-if="notice" :variant="notice.variant" @dismiss="notice = null">{{ notice.text }}</Toast>

            <CalendarGrid
                :entries="postsStore.entries"
                :view="view"
                :timezone="timezone"
                @range-change="onRangeChange"
                @edit="onEdit"
                @blocked="onBlocked"
                @error="onGridError"
            />
        </div>

        <PostEditorModal
            :open="editorOpen"
            :group-id="editorGroupId"
            @close="editorOpen = false"
            @saved="onEditorSaved"
        />
    </AppLayout>
</template>
