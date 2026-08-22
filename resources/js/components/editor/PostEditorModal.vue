<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Modal from '../ui/Modal.vue';
import Toast from '../ui/Toast.vue';
import Button from '../ui/Button.vue';
import TagFilter from '../calendar/TagFilter.vue';
import TagManager from '../tags/TagManager.vue';
import ChannelPicker from './ChannelPicker.vue';
import ChannelTab from './ChannelTab.vue';
import ThreadEditor from './ThreadEditor.vue';
import EmojiPicker from './EmojiPicker.vue';
import { useCaretInsertion } from '../../composables/useCaretInsertion';
import ScheduleControls from './ScheduleControls.vue';
import PreviewPane from '../previews/PreviewPane.vue';
import ShareLinkModal from '../posts/ShareLinkModal.vue';
import CommentThread from '../posts/CommentThread.vue';
import { useIntegrationsStore, type ProviderCapabilities } from '../../stores/integrations';
import { useAuthStore } from '../../stores/auth';
import { useMediaStore } from '../../stores/media';
import { api } from '../../api/client';
import type { CalendarTag } from '../../stores/posts';
import type { ChannelDraft, PostGroupApi, SaveType, SetDto, SignatureDto } from './types';

/**
 * The post composer (Task 25): pick channels (individually or via a
 * Set), write shared text, vary it per channel on that channel's own
 * tab, thread within a channel, and save as draft / scheduled / now.
 *
 * `groupId === null` composes a brand-new group (`POST /api/posts`);
 * a non-null id loads and edits an existing one (`PUT
 * /api/posts/{group}`) — the exact seam `Calendar/Index.vue`'s
 * `onEdit()` was left for.
 *
 * Task 26 fills the preview seam: `PreviewPane` (below) renders the
 * active channel's draft — signature included, since that is what
 * actually publishes, see `activePreviewSegments` — through
 * `resolvePreview()`'s registry, never by branching on
 * `provider_identifier` itself here.
 *
 * Task 29 fills the media seam: `ThreadEditor.vue` opens
 * `MediaPicker.vue` per segment, populating that segment's own
 * `media_ids` — this component only hydrates `useMediaStore()` from an
 * existing group's already-attached media (see `loadGroup()`) so those
 * thumbnails/selection resolve without a picker fetch. Nothing here
 * builds the publish job (Task 30).
 */
const props = defineProps<{ open: boolean; groupId: string | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const { t } = useI18n();
const integrations = useIntegrationsStore();
const auth = useAuthStore();
const media = useMediaStore();

// Same source CalendarGrid.vue's `timeZone` option and Channels'
// TimeSlotEditor.vue already use — see zonedDateTime.ts's docblock for
// why ScheduleControls must convert against this and never the
// browser's own OS timezone.
const timezone = computed(() => auth.user?.timezone ?? 'UTC');

const loading = ref(false);
const saving = ref(false);
const networkError = ref(false);
/**
 * True once this group is known to be un-editable via PUT — either it
 * loaded already published (state PUBLISHED, or any segment already has
 * a `published_at`), or a 409 arrived from a save attempt during this
 * session (PostGroupService::guardEditable()'s race: the group
 * published while the editor was open). Either way the composed model
 * is NOT cleared and saving is NOT disabled: `effectiveGroupId` gets
 * reset to `null` so the very next save re-targets `POST /api/posts` (a
 * new group) instead of retrying the now-locked `PUT`, so the user's
 * work still reaches the server instead of being stranded behind a dead
 * end. See Task 25 review, Finding 2.
 */
const groupLocked = ref(false);
/** `null` composes via POST; a group id composes via PUT — starts as `props.groupId`, but a 409 (see `groupLocked` above) resets it to `null` so the retry becomes a create. */
const effectiveGroupId = ref<string | null>(null);
/**
 * The save type awaiting an explicit "yes, create a second post"
 * confirmation — set only while `groupLocked` is true (see
 * `requestSave()` below). Task 25 review, Finding 3: after the 409
 * re-target (or a group that loaded already published), a single click
 * on the PRIMARY button used to silently create a second, live copy of
 * an already-published post — the locked banner explained the
 * consequence, but explaining is not a guard. Every save action now
 * routes through `requestSave()` first while locked, requiring a
 * deliberate confirm click before `executeSave()` ever runs.
 *
 * Declared here (rather than next to `requestSave()`/`executeSave()`
 * further down) because `resetForm()` reads it, and `resetForm()` runs
 * synchronously inside the `watch(() => props.open, ..., { immediate:
 * true })` below — which fires during this very setup() execution, so
 * every ref it touches must already be initialised above that point.
 */
const pendingSaveType = ref<SaveType | null>(null);
/**
 * True once `loadGroup()` has populated the drafts from server-loaded
 * content — set there and ONLY there, deliberately never touched by the
 * 409 re-target (`groupLocked`/`effectiveGroupId` reset to `null`).
 *
 * This is a separate flag from `effectiveGroupId` on purpose (Task 25
 * review, round 3): the signature selector's "already-loaded text may
 * carry a baked-in signature" warning is about the PROVENANCE of the
 * currently-composed text, not about which HTTP verb the next save will
 * use. `modalTitle` correctly keys off `effectiveGroupId` — but reusing
 * that same flag for the signature UI silently reopened the exact
 * double-append bug round 1 closed: after a 409 re-target,
 * `effectiveGroupId` goes back to `null`, but the composed text is
 * UNCHANGED — still the server-loaded text that may already carry a
 * signature — so the selector must keep warning about it even though
 * the modal now correctly calls itself "New post".
 */
const loadedFromServer = ref(false);
/**
 * Task 1.1's share link, keyed off `props.groupId` rather than
 * `effectiveGroupId`. That distinction matters: a group that loaded
 * already published resets `effectiveGroupId` to null (see its docblock)
 * — and a published post is exactly the one people most want to share.
 * `shareToken` is seeded by `loadGroup()` from PostGroupResource, which
 * has always carried the field.
 */
const shareToken = ref<string | null>(null);
const shareOpen = ref(false);

/**
 * The tag manager opens on top of this modal. Modal.vue keeps a stack of
 * open modals so Escape only reaches the topmost — the same mechanism
 * ShareLinkModal below already relies on.
 */
const tagManagerOpen = ref(false);

/**
 * A tag created from here is almost always meant for the post being
 * written, so it goes straight into the selection rather than leaving
 * the user to hunt for it in the list they just added to.
 */
function onTagCreated(tag: CalendarTag): void {
    if (!selectedTags.value.includes(tag.id)) {
        selectedTags.value = [...selectedTags.value, tag.id];
    }
}

const canShare = computed(() => props.groupId !== null && loadedFromServer.value);
const generalErrors = ref<string[]>([]);
const channelErrors = reactive<Record<string, Record<number, string[]>>>({});

const sets = ref<SetDto[]>([]);
const signatures = ref<SignatureDto[]>([]);
const availableTags = ref<CalendarTag[]>([]);

const selectedChannelIds = ref<string[]>([]);
const channelDrafts = reactive<Record<string, ChannelDraft>>({});
const activeChannelId = ref<string | null>(null);
const commonContent = ref('');
const selectedTags = ref<string[]>([]);
const publishDate = ref<string | null>(null);

function providerCapabilities(identifier: string): ProviderCapabilities | undefined {
    return integrations.providers.find((provider) => provider.identifier === identifier)?.capabilities;
}

function integrationById(id: string) {
    return integrations.list.find((integration) => integration.id === id);
}

function integrationName(id: string): string {
    return integrationById(id)?.name ?? id;
}

function providerNameFor(id: string): string {
    const identifier = integrationById(id)?.provider_identifier;
    return integrations.providers.find((provider) => provider.identifier === identifier)?.name ?? identifier ?? '';
}

const selectedIntegrations = computed(() =>
    selectedChannelIds.value.map((id) => integrationById(id)).filter((integration): integration is NonNullable<typeof integration> => Boolean(integration)),
);

const activeIntegration = computed(() => (activeChannelId.value ? integrationById(activeChannelId.value) : undefined));
const activeCapabilities = computed(() => (activeIntegration.value ? providerCapabilities(activeIntegration.value.provider_identifier) : undefined));
const activeDraft = computed(() => (activeChannelId.value ? channelDrafts[activeChannelId.value] : undefined));

const SIGNATURE_SEPARATOR = '\n\n';

function signatureFor(channelId: string): SignatureDto | undefined {
    const signatureId = channelDrafts[channelId]?.signatureId;
    return signatureId ? signatures.value.find((signature) => signature.id === signatureId) : undefined;
}

function signatureLength(channelId: string): number {
    const signature = signatureFor(channelId);
    return signature ? [...SIGNATURE_SEPARATOR, ...signature.content].length : 0;
}

/**
 * The active channel's thread as it will actually be PUBLISHED, for
 * `PreviewPane` (Task 26): mirrors `buildPayload()`'s own signature-append
 * step (last segment only) rather than showing the raw, signature-less
 * draft — a preview that hid the signature would misrepresent what the
 * user is about to send.
 */
const activePreviewSegments = computed(() => {
    if (!activeChannelId.value || !activeDraft.value) {
        return [];
    }

    const signature = signatureFor(activeChannelId.value);

    return activeDraft.value.segments.map((segment, index, all) => ({
        content: signature && index === all.length - 1 ? `${segment.content}${SIGNATURE_SEPARATOR}${signature.content}` : segment.content,
    }));
});

/**
 * Drives the "!" tab badge: a channel is in error if the server already
 * rejected it, or if the client can already tell it would be rejected
 * (over the channel's own `maxCharacters` incl. its signature, a thread
 * on a network without `supportsThreads`, or past `maxThreadLength`) —
 * all sourced from that channel's own `ProviderCapabilities`, never a
 * shared constant.
 */
function channelHasError(id: string): boolean {
    if (Object.keys(channelErrors[id] ?? {}).length > 0) {
        return true;
    }

    const draft = channelDrafts[id];
    const integration = integrationById(id);
    const capabilities = integration ? providerCapabilities(integration.provider_identifier) : undefined;

    if (!draft || !capabilities) {
        return false;
    }

    if (draft.segments.length > 1 && !capabilities.supportsThreads) {
        return true;
    }

    if (capabilities.maxThreadLength !== null && draft.segments.length > capabilities.maxThreadLength) {
        return true;
    }

    const extra = signatureLength(id);

    return draft.segments.some((segment, index, all) => {
        const count = [...segment.content].length + (index === all.length - 1 ? extra : 0);
        return count > capabilities.maxCharacters;
    });
}

/**
 * The shared field gets the emoji picker but deliberately NO format
 * toolbar (Block 3). One body is mirrored into several channels whose
 * `supportedMarks` differ, so a bold typed here would be bold on one
 * channel and a pair of asterisks on the next — formatting belongs on
 * the per-channel tabs, where the target is unambiguous. Emoji are just
 * characters and behave the same everywhere, so they belong here.
 */
const commonField = ref<HTMLTextAreaElement | null>(null);

const { insertAtCaret: insertIntoCommon } = useCaretInsertion(
    commonField,
    () => commonContent.value,
    (value) => onCommonContentInput(value),
);

function onCommonContentInput(value: string): void {
    commonContent.value = value;

    for (const id of selectedChannelIds.value) {
        const draft = channelDrafts[id];
        if (draft && !draft.dirty) {
            draft.segments = [{ content: value, media_ids: draft.segments[0]?.media_ids ?? [] }];
        }
    }
}

/**
 * Only a genuine TEXT edit (typing, or adding/removing a thread
 * segment) marks a channel as diverged from the shared `commonContent`
 * field — never a media-only change. Task 29 review, finding 5:
 * previously this set `dirty = true` on ANY `update:modelValue` from
 * ThreadEditor, including one produced purely by attaching/removing
 * media through MediaPicker (media_ids changed, content untouched).
 * That silently froze `onCommonContentInput()`'s live mirroring for
 * that one channel — select two channels, type "Hello", attach an
 * image to channel A, keep typing "Hello world": B kept syncing and
 * would publish "Hello world", A silently stopped syncing right when
 * media was attached and would still publish "Hello", with nothing on
 * screen indicating the two had diverged. `dirty` is only ever set to
 * `true` here, never back to `false` — once genuinely edited, a
 * channel stays independent even if a later update happens to be
 * media-only again.
 */
function contentChanged(previous: ChannelDraft['segments'], next: ChannelDraft['segments']): boolean {
    return previous.length !== next.length || next.some((segment, index) => segment.content !== previous[index]?.content);
}

function onSegmentsUpdate(channelId: string, segments: ChannelDraft['segments']): void {
    const draft = channelDrafts[channelId];
    if (!draft) {
        return;
    }

    if (contentChanged(draft.segments, segments)) {
        draft.dirty = true;
    }

    draft.segments = segments;
}

function newDraft(): ChannelDraft {
    return { segments: [{ content: commonContent.value, media_ids: [] }], signatureId: null, settings: {}, dirty: false };
}

watch(selectedChannelIds, (ids) => {
    for (const id of ids) {
        channelDrafts[id] ??= newDraft();
    }

    for (const id of Object.keys(channelDrafts)) {
        if (!ids.includes(id)) {
            delete channelDrafts[id];
        }
    }

    if (!activeChannelId.value || !ids.includes(activeChannelId.value)) {
        activeChannelId.value = ids[0] ?? null;
    }
});

async function fetchSets(): Promise<void> {
    const { data } = await api.get<SetDto[]>('/sets');
    sets.value = data;
}

/**
 * Task 1.4. ChannelPicker collects the name only — it stays store-less
 * and HTTP-less — so the request lives here, next to the `sets` list it
 * feeds. The created set is appended rather than triggering a refetch:
 * `POST /api/sets` returns the same SetResource shape `GET /api/sets`
 * does, and a round trip would only be a second chance to fail.
 */
async function saveSet(name: string): Promise<void> {
    try {
        const { data } = await api.post<SetDto>('/sets', {
            name,
            integration_ids: selectedChannelIds.value,
        });

        sets.value = [...sets.value, data];
    } catch {
        // One line in the modal's existing error block rather than a
        // field-level message: the picker's form is already closed by
        // the time this resolves, and the only field it had — the name —
        // was non-empty or the submit button would not have fired.
        generalErrors.value.push(t('sets.saveError'));
    }
}

async function fetchSignatures(): Promise<void> {
    const { data } = await api.get<SignatureDto[]>('/signatures');
    signatures.value = data;
}

async function fetchTags(): Promise<void> {
    const { data } = await api.get<CalendarTag[]>('/tags');
    availableTags.value = data;
}

/**
 * Clears every field of the composed model. Called unconditionally at
 * the top of `init()` — see Task 25 review, Finding 1: previously this
 * only ran for a brand-new post (`groupId === null`), so `commonContent`
 * (and, transitively, any undirtied channel's root segment — see
 * `newDraft()`) survived a save/close and leaked into the NEXT group
 * this modal was opened against, silently seeding it with a different
 * post's text.
 */
function resetForm(): void {
    selectedChannelIds.value = [];
    for (const key of Object.keys(channelDrafts)) {
        delete channelDrafts[key];
    }
    activeChannelId.value = null;
    commonContent.value = '';
    selectedTags.value = [];
    publishDate.value = null;
    groupLocked.value = false;
    pendingSaveType.value = null;
    loadedFromServer.value = false;
    shareToken.value = null;
    shareOpen.value = false;
}

/**
 * Loads an existing group for editing. Every channel comes back marked
 * `dirty` immediately — its content already diverges by definition (it
 * is real, previously-saved text), so it must never be silently
 * overwritten by the (empty, at load time) shared `commonContent` field.
 * `signatureId` always starts unset: the API has nowhere to record
 * which signature (if any) produced the stored text, so re-selecting one
 * here appends fresh rather than guessing at, or doubling, what is
 * already baked into `content` (see PostGroupApi's docblock, and
 * `loadedFromServer`/the signature `<select>`'s relabelled default in
 * the template, for how the UI now makes that an explicit choice rather
 * than implying "none applied").
 */
async function loadGroup(id: string): Promise<void> {
    const { data } = await api.get<PostGroupApi>(`/posts/${id}`);

    loadedFromServer.value = true;
    shareToken.value = data.share_token;
    selectedTags.value = [...data.tags];
    publishDate.value = data.publish_date;

    const alreadyPublished = data.state === 'PUBLISHED' || data.posts.some((post) => post.value.some((segment) => segment.published_at !== null));
    if (alreadyPublished) {
        // Loaded already un-editable: skip straight to "save = create a
        // new group" rather than waiting for PUT to 409 first — same
        // end state `save()`'s 409 branch reaches, just without the
        // wasted round trip. See `groupLocked`'s docblock.
        groupLocked.value = true;
        effectiveGroupId.value = null;
    }

    selectedChannelIds.value = data.posts.map((post) => post.integration_id);

    // Seeds the media store from what's already attached, so
    // ThreadEditor.vue's thumbnails and MediaPicker's "already
    // selected" highlighting resolve immediately — without this, media
    // this session's picker has never itself fetched a page containing
    // would show as a bare, unresolved id. See MediaItem[] `media` on
    // PostGroupApi's docblock.
    media.hydrate(data.posts.flatMap((post) => post.value.flatMap((segment) => segment.media ?? [])));

    for (const post of data.posts) {
        channelDrafts[post.integration_id] = {
            segments: post.value.map((segment) => ({ content: segment.content, media_ids: segment.media_ids ?? [] })),
            signatureId: null,
            settings: post.settings ?? {},
            dirty: true,
        };
    }

    activeChannelId.value = selectedChannelIds.value[0] ?? null;
}

async function init(): Promise<void> {
    loading.value = true;
    networkError.value = false;
    generalErrors.value = [];
    for (const key of Object.keys(channelErrors)) {
        delete channelErrors[key];
    }

    // Unconditional — see resetForm()'s docblock (Finding 1).
    resetForm();
    effectiveGroupId.value = props.groupId;

    try {
        await Promise.all([integrations.fetch(), integrations.fetchProviders(), fetchSets(), fetchSignatures(), fetchTags()]);

        if (props.groupId) {
            await loadGroup(props.groupId);
        }
    } catch {
        networkError.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            void init();
        }
    },
    { immediate: true },
);

function buildPayload(type: SaveType): Record<string, unknown> {
    return {
        type,
        publish_date: type === 'now' ? null : publishDate.value,
        tags: selectedTags.value,
        // `.filter(Boolean)`: a selected id with no draft yet (e.g. the
        // `selectedChannelIds` watcher hasn't run for it — should not
        // normally happen, but nothing here should crash outside the
        // try/catch in save() if it ever does) is skipped rather than
        // dereferenced. See Task 25 review, Minor 2.
        posts: selectedChannelIds.value
            .filter((id) => Boolean(channelDrafts[id]))
            .map((id) => {
                const draft = channelDrafts[id];
                const signature = signatureFor(id);

                const value = draft.segments.map((segment, index, all) => ({
                    content: signature && index === all.length - 1 ? `${segment.content}${SIGNATURE_SEPARATOR}${signature.content}` : segment.content,
                    media_ids: segment.media_ids,
                }));

                return { integration_id: id, settings: draft.settings, value };
            }),
    };
}

/**
 * Maps Task 19's `errors` map (paths like `posts.0.value.0.content`)
 * back onto the channel/segment they came from, using
 * `selectedChannelIds`'s order — the exact order `buildPayload()` just
 * sent `posts[]` in, so index 0 there is index 0 here too. Anything
 * that doesn't fit that shape (a top-level `publish_date`/`tags` error,
 * or a `posts.N...` index that no longer maps to a selected channel)
 * falls into `generalErrors` instead of being silently dropped.
 */
function applyServerErrors(errors: Record<string, string[]>): void {
    const segmentPattern = /^posts\.(\d+)\.value\.(\d+)(?:\.(content|media_ids))?$/;

    for (const [path, messages] of Object.entries(errors)) {
        const match = path.match(segmentPattern);

        if (match) {
            const channelId = selectedChannelIds.value[Number(match[1])];
            const segmentIndex = Number(match[2]);

            if (channelId) {
                channelErrors[channelId] ??= {};
                channelErrors[channelId][segmentIndex] = [...(channelErrors[channelId][segmentIndex] ?? []), ...messages];
                continue;
            }
        }

        generalErrors.value.push(...messages);
    }
}

// Saving is only ever blocked by "nothing to save" — a 409/already-
// published group (`groupLocked`) no longer disables it, see
// `groupLocked`'s docblock (Finding 2): the save actions stay live so
// the composed text can still reach the server, just as a new group.
const saveDisabled = computed(() => selectedChannelIds.value.length === 0);

const scheduleNotice = computed(() => (groupLocked.value ? t('editor.lockedRetry') : null));

function requestSave(type: SaveType): void {
    if (saveDisabled.value) {
        return;
    }

    if (groupLocked.value) {
        pendingSaveType.value = type;

        return;
    }

    void executeSave(type);
}

function confirmPendingSave(): void {
    if (!pendingSaveType.value) {
        return;
    }

    const type = pendingSaveType.value;
    pendingSaveType.value = null;
    void executeSave(type);
}

function cancelPendingSave(): void {
    pendingSaveType.value = null;
}

async function executeSave(type: SaveType): Promise<void> {
    saving.value = true;
    networkError.value = false;
    generalErrors.value = [];
    for (const key of Object.keys(channelErrors)) {
        delete channelErrors[key];
    }

    const payload = buildPayload(type);

    try {
        if (effectiveGroupId.value) {
            await api.put(`/posts/${effectiveGroupId.value}`, payload);
        } else {
            await api.post('/posts', payload);
        }

        emit('saved');
        emit('close');
    } catch (error) {
        if (isAxiosError(error)) {
            if (error.response?.status === 409) {
                // The group published (or started publishing) while this
                // editor was open — PostGroupService::guardEditable()'s
                // race. The user's text is untouched: nothing above this
                // catch block mutates any draft. Re-targeting the next
                // save as a create (rather than leaving it disabled) is
                // what actually gets the user's work saved — see
                // `groupLocked`'s docblock. The NEXT save attempt now
                // goes through `requestSave()`'s confirmation gate,
                // since `groupLocked` is now true.
                groupLocked.value = true;
                effectiveGroupId.value = null;
            } else if (error.response?.status === 422) {
                applyServerErrors(error.response.data?.errors ?? {});
            } else {
                networkError.value = true;
            }
        } else {
            networkError.value = true;
        }
    } finally {
        saving.value = false;
    }
}

// Keyed off `effectiveGroupId`, not `props.groupId`: after a 409
// re-target (or a group that loaded already published) the next save
// creates a NEW group, so the header must stop claiming this is still
// "editing" the original the moment that becomes true, not just at the
// initial load. Task 25 review, round 2 cosmetic finding. Note this is
// deliberately a DIFFERENT flag from `loadedFromServer` (used by the
// signature selector below) — see that ref's docblock for why the two
// must not be conflated.
const modalTitle = computed(() => (effectiveGroupId.value ? t('editor.titleEdit') : t('editor.titleNew')));

</script>

<template>
    <Modal :open="open" :title="modalTitle" :ignore-backdrop-click="true" @close="emit('close')">
        <template v-if="canShare" #actions>
            <Button variant="secondary" @click="shareOpen = true">{{ t('calendar.share.menuItem') }}</Button>
        </template>

        <div v-if="loading" class="py-8 text-center text-sm text-muted">{{ t('ui.loading') }}</div>

        <div v-else class="flex max-h-[75vh] flex-col gap-4 overflow-y-auto">
            <!-- variant="muted", not "warning" (color.md §3.8, final review
                 Important 9): this screen renders accent-tinted channel
                 avatars, and --warning here would be the third unrelated
                 meaning that colour carried on one screen. It is reserved
                 for the plan-limit toast. -->
            <Toast v-if="groupLocked" variant="muted">{{ t('editor.locked') }}</Toast>
            <Toast v-if="networkError" variant="danger" @dismiss="networkError = false">{{ t('editor.networkError') }}</Toast>
            <Toast v-if="generalErrors.length" variant="danger" @dismiss="generalErrors = []">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </Toast>

            <ChannelPicker
                v-model="selectedChannelIds"
                :integrations="integrations.list"
                :providers="integrations.providers"
                :sets="sets"
                @save-set="saveSet"
            />

            <div v-if="selectedChannelIds.length > 0" class="flex flex-col gap-1">
                <label for="common-content" class="text-sm font-medium text-text">{{ t('editor.commonContent') }}</label>
                <div class="flex flex-wrap items-center gap-1">
                    <EmojiPicker @select="insertIntoCommon" />
                </div>
                <textarea
                    id="common-content"
                    ref="commonField"
                    :value="commonContent"
                    :placeholder="t('editor.commonContentPlaceholder')"
                    rows="3"
                    class="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary"
                    @input="onCommonContentInput(($event.target as HTMLTextAreaElement).value)"
                />
            </div>

            <div v-if="selectedChannelIds.length > 0" class="flex flex-col gap-3">
                <div class="flex flex-wrap gap-2">
                    <ChannelTab
                        v-for="id in selectedChannelIds"
                        :key="id"
                        :name="integrationName(id)"
                        :provider-name="providerNameFor(id)"
                        :active="id === activeChannelId"
                        :has-error="channelHasError(id)"
                        @click="activeChannelId = id"
                    />
                </div>

                <div v-if="activeChannelId && activeDraft && activeCapabilities" class="flex flex-col gap-3 rounded-xl border border-border bg-surface-2 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="channel-signature" class="text-xs font-medium text-muted">{{ t('editor.signature') }}</label>
                        <select
                            id="channel-signature"
                            v-model="activeDraft.signatureId"
                            class="rounded-xl border border-border bg-surface px-2 py-1.5 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                        >
                            <!--
                                Finding 4: editing an existing group can never
                                truthfully claim "no signature" — the API has
                                nowhere to record whether the stored text
                                already carries one (see loadGroup()'s
                                docblock), so a plain "No signature" default
                                reads as "none was applied", inviting the user
                                to "correct" it and append a second, invisible
                                copy. The relabelled option makes picking one
                                here a deliberate, informed act instead.
                            -->
                            <option :value="null">{{ loadedFromServer ? t('editor.signatureUnknown') : t('editor.noSignature') }}</option>
                            <option v-for="signature in signatures" :key="signature.id" :value="signature.id">
                                {{ signature.content.slice(0, 40) }}
                            </option>
                        </select>
                        <!--
                            Task 1.5: a new tab, not a router-link. The
                            signatures screen is a page rather than a
                            stacked modal (unlike TagManager), so
                            navigating to it from inside the editor would
                            unmount this modal and take the unsaved draft
                            with it. Opening it alongside costs the user
                            nothing and loses nothing.
                        -->
                        <a
                            href="/settings/signatures"
                            target="_blank"
                            rel="noopener"
                            class="text-xs text-primary underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        >
                            {{ t('signatures.manage') }}
                        </a>
                    </div>
                    <p v-if="loadedFromServer" class="text-xs text-muted">{{ t('editor.signatureEditHint') }}</p>

                    <ThreadEditor
                        :model-value="activeDraft.segments"
                        :capabilities="activeCapabilities"
                        :signature-length="signatureLength(activeChannelId)"
                        :label="integrationName(activeChannelId)"
                        :errors="channelErrors[activeChannelId] ?? {}"
                        @update:model-value="onSegmentsUpdate(activeChannelId as string, $event)"
                    />

                    <PreviewPane
                        :provider-identifier="activeIntegration?.provider_identifier ?? ''"
                        :channel-name="integrationName(activeChannelId)"
                        :channel-picture="activeIntegration?.picture ?? null"
                        :segments="activePreviewSegments"
                    />
                </div>
            </div>

            <!--
                Deliberately not gated on `availableTags.length`: the block
                used to disappear entirely when the organization had no
                tags, which hid the "New tag" trigger in the one situation
                where it is the only way forward.
            -->
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-text">{{ t('editor.tags') }}</span>
                    <!-- secondary, not primary: color.md §3.1 — the save button owns primary here. -->
                    <Button variant="secondary" @click="tagManagerOpen = true">{{ t('tags.add') }}</Button>
                </div>
                <TagFilter v-model="selectedTags" :tags="availableTags" />
            </div>

            <ScheduleControls
                v-model="publishDate"
                :integrations="selectedIntegrations"
                :saving="saving"
                :disabled="saveDisabled"
                :timezone="timezone"
                :notice="scheduleNotice"
                @save-draft="requestSave('draft')"
                @save-schedule="requestSave('schedule')"
                @save-now="requestSave('now')"
            />

            <!--
                Task 1.6: keyed on `props.groupId`, not `effectiveGroupId`
                — the latter is nulled when a 409 or a published lock
                forces the editor into "this will be a new post" mode,
                and the comments still belong to the group that was
                opened. A brand-new post has no group to comment on at
                all, so the thread is simply absent. Same reasoning as
                `canShare` above.
            -->
            <CommentThread v-if="props.groupId" :group-id="props.groupId" />

            <!--
                Task 25 review, Finding 3: saving while `groupLocked` is
                true would silently create a second, live copy of an
                already-published post. requestSave() routes here
                instead of saving directly whenever that's the case —
                the primary action stops being one click away from a
                duplicate publish.
            -->
            <Toast v-if="pendingSaveType" variant="muted">
                <p>{{ t('editor.confirmNewPost') }}</p>
                <div class="mt-2 flex gap-2">
                    <!--
                        No :loading="saving" here (Task 25 review, round
                        3, trivia 1): confirmPendingSave() sets
                        pendingSaveType to null before executeSave() ever
                        sets saving=true, so this Toast — and this button
                        with it — always unmounts before `saving` could
                        become true. The prop was dead code implying a
                        loading state that can never actually render.
                    -->
                    <Button variant="primary" type="button" @click="confirmPendingSave">
                        {{ t('editor.confirmNewPostYes') }}
                    </Button>
                    <Button variant="ghost" type="button" @click="cancelPendingSave">{{ t('ui.cancel') }}</Button>
                </div>
            </Toast>

            <!--
                Deliberately NOT "Cancel" (Task 25 review, round 3,
                trivia 2): with the confirmation Toast above, two
                simultaneously visible buttons both saying "Cancel" would
                carry different, easy-to-confuse consequences — one
                dismisses the confirmation (composition intact), this one
                closes the whole modal and discards everything typed.
                The label itself must carry that difference, not just
                position on screen.
            -->
            <Button variant="ghost" type="button" class="self-start" @click="emit('close')">{{ t('editor.closeWithoutSaving') }}</Button>
        </div>
    
        <ShareLinkModal
            :open="shareOpen"
            :group-id="groupId"
            :share-token="shareToken"
            @close="shareOpen = false"
            @changed="shareToken = $event"
        />

        <TagManager
            :open="tagManagerOpen"
            @close="tagManagerOpen = false"
            @changed="fetchTags"
            @created="onTagCreated"
        />
    </Modal>
</template>
