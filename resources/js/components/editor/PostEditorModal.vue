<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Modal from '../ui/Modal.vue';
import Toast from '../ui/Toast.vue';
import Button from '../ui/Button.vue';
import TagFilter from '../calendar/TagFilter.vue';
import ChannelPicker from './ChannelPicker.vue';
import ChannelTab from './ChannelTab.vue';
import ThreadEditor from './ThreadEditor.vue';
import ScheduleControls from './ScheduleControls.vue';
import { useIntegrationsStore, type ProviderCapabilities } from '../../stores/integrations';
import { useAuthStore } from '../../stores/auth';
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
 * Nothing here builds a per-network preview (Task 26), a media
 * picker (Tasks 27-29 — `media_ids` is always sent as `[]`), or the
 * publish job (Task 30).
 */
const props = defineProps<{ open: boolean; groupId: string | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const { t } = useI18n();
const integrations = useIntegrationsStore();
const auth = useAuthStore();

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

function onCommonContentInput(value: string): void {
    commonContent.value = value;

    for (const id of selectedChannelIds.value) {
        const draft = channelDrafts[id];
        if (draft && !draft.dirty) {
            draft.segments = [{ content: value, media_ids: draft.segments[0]?.media_ids ?? [] }];
        }
    }
}

function onSegmentsUpdate(channelId: string, segments: ChannelDraft['segments']): void {
    const draft = channelDrafts[channelId];
    if (!draft) {
        return;
    }
    draft.segments = segments;
    draft.dirty = true;
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
 * `isEditingExisting`/the signature `<select>`'s relabelled default in
 * the template, for how the UI now makes that an explicit choice rather
 * than implying "none applied").
 */
async function loadGroup(id: string): Promise<void> {
    const { data } = await api.get<PostGroupApi>(`/posts/${id}`);

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

async function save(type: SaveType): Promise<void> {
    if (saveDisabled.value) {
        return;
    }

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
                // `groupLocked`'s docblock.
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

const modalTitle = computed(() => (props.groupId ? t('editor.titleEdit') : t('editor.titleNew')));
const isEditingExisting = computed(() => Boolean(props.groupId));
</script>

<template>
    <Modal :open="open" :title="modalTitle" :ignore-backdrop-click="true" @close="emit('close')">
        <div v-if="loading" class="py-8 text-center text-sm text-muted">{{ t('ui.loading') }}</div>

        <div v-else class="flex max-h-[75vh] flex-col gap-4 overflow-y-auto">
            <Toast v-if="groupLocked" variant="warning">{{ t('editor.locked') }}</Toast>
            <Toast v-if="networkError" variant="danger" @dismiss="networkError = false">{{ t('editor.networkError') }}</Toast>
            <Toast v-if="generalErrors.length" variant="danger" @dismiss="generalErrors = []">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </Toast>

            <ChannelPicker
                v-model="selectedChannelIds"
                :integrations="integrations.list"
                :providers="integrations.providers"
                :sets="sets"
            />

            <div v-if="selectedChannelIds.length > 0" class="flex flex-col gap-1">
                <label for="common-content" class="text-sm font-medium text-text">{{ t('editor.commonContent') }}</label>
                <textarea
                    id="common-content"
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
                            <option :value="null">{{ isEditingExisting ? t('editor.signatureUnknown') : t('editor.noSignature') }}</option>
                            <option v-for="signature in signatures" :key="signature.id" :value="signature.id">
                                {{ signature.content.slice(0, 40) }}
                            </option>
                        </select>
                    </div>
                    <p v-if="isEditingExisting" class="text-xs text-muted">{{ t('editor.signatureEditHint') }}</p>

                    <ThreadEditor
                        :model-value="activeDraft.segments"
                        :capabilities="activeCapabilities"
                        :signature-length="signatureLength(activeChannelId)"
                        :label="integrationName(activeChannelId)"
                        :errors="channelErrors[activeChannelId] ?? {}"
                        @update:model-value="onSegmentsUpdate(activeChannelId as string, $event)"
                    />
                </div>
            </div>

            <div v-if="availableTags.length > 0" class="flex flex-col gap-1">
                <span class="text-sm font-medium text-text">{{ t('editor.tags') }}</span>
                <TagFilter v-model="selectedTags" :tags="availableTags" />
            </div>

            <ScheduleControls
                v-model="publishDate"
                :integrations="selectedIntegrations"
                :saving="saving"
                :disabled="saveDisabled"
                :timezone="timezone"
                :notice="scheduleNotice"
                @save-draft="save('draft')"
                @save-schedule="save('schedule')"
                @save-now="save('now')"
            />

            <Button variant="ghost" type="button" class="self-start" @click="emit('close')">{{ t('ui.cancel') }}</Button>
        </div>
    </Modal>
</template>
