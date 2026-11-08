<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../layouts/AppLayout.vue';
import Button from '../../components/ui/Button.vue';
import Toast from '../../components/ui/Toast.vue';
import ContentTextarea from '../../components/editor/ContentTextarea.vue';
import { api } from '../../api/client';
import { useOrganizationStore } from '../../stores/organization';
import type { SignatureDto } from '../../components/editor/types';

/**
 * Create, edit and delete signatures (Task 1.5). The backend for this
 * shipped with Task 23 and the post editor has been reading it ever since
 * — this screen is the half that was missing, so until now a signature
 * could only be created through the API by hand.
 *
 * Like TagManager.vue and Sets.vue it owns its own list rather than
 * reaching for a store: PostEditorModal.vue already fetches `/signatures`
 * independently for its per-channel picker, and a third source of truth
 * would be one more thing to keep in step for no gain — the editor
 * refetches when it opens anyway.
 *
 * Three properties of the API shape everything below:
 *
 *  - PUT is a full replace: `content` and `is_default` are both
 *    `required`, so editing the text has to carry the flag and promoting
 *    a signature has to carry the text, or the write comes back 422 on
 *    whichever field was omitted (the same trap TagManager.vue documents
 *    for colours).
 *  - `is_default` is exclusive per organization — a partial unique index
 *    (`signatures_one_default_per_org`) plus
 *    SignatureService::clearDefault() demote the previous holder inside
 *    the same transaction. The server therefore answers a promotion with
 *    one row, having silently changed another, so this list demotes the
 *    old one itself instead of showing two defaults until the next load.
 *  - There is no name column. Every row is labelled by its own text, the
 *    way the editor's picker already labels its options.
 */
const { t } = useI18n();
const organization = useOrganizationStore();

/** `StoreSignatureRequest`/`UpdateSignatureRequest`: `content` is `max:1000`. */
const CONTENT_LIMIT = 1000;
/** How much of a signature fits on one row before it stops being a label. */
const PREVIEW_LENGTH = 80;

const list = ref<SignatureDto[]>([]);
const loading = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
/** Set when the list itself could not be read, so an empty list is not mistaken for "none yet". */
const loadFailed = ref(false);

const createForm = ref({ content: '', isDefault: false });
const createErrors = ref<Record<string, string[]>>({});

/** Only one row is editable at a time; `null` means none. */
const editingId = ref<string | null>(null);
const editForm = ref({ content: '', isDefault: false });
const editErrors = ref<Record<string, string[]>>({});

/**
 * Code points, not UTF-16 units — the server validates with
 * `mb_strlen`, and ContentTextarea.vue's counter already counts this way,
 * so a Cyrillic signature has to agree on both sides of the wire.
 */
function length(content: string): number {
    return [...content].length;
}

function withinLimits(content: string): boolean {
    const size = length(content.trim());

    return size > 0 && size <= CONTENT_LIMIT;
}

const canCreate = computed(() => withinLimits(createForm.value.content));
const canSaveEdit = computed(() => withinLimits(editForm.value.content));

/**
 * A signature is free-form multi-line text, so its raw content is not a
 * row label: the newlines would either stretch the row or be swallowed
 * silently. Collapsing the whitespace first keeps every row one line and
 * makes the truncation honest about where the text was cut.
 */
function preview(content: string): string {
    const flat = content.replace(/\s+/g, ' ').trim();

    return length(flat) > PREVIEW_LENGTH ? `${[...flat].slice(0, PREVIEW_LENGTH).join('')}…` : flat;
}

async function load(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await api.get<SignatureDto[]>('/signatures');
        list.value = data;
        loadFailed.value = false;
        error.value = null;
    } catch {
        loadFailed.value = true;
        error.value = t('signatures.loadError');
    } finally {
        loading.value = false;
    }
}

async function loadAll(): Promise<void> {
    editingId.value = null;
    createForm.value = { content: '', isDefault: false };
    createErrors.value = {};

    await load();
}

onMounted(loadAll);
// Re-fetch on an organization switch, so this page never shows one
// tenant's signatures under another — same reason Settings/Sets.vue does it.
watch(
    () => organization.current?.id,
    (id, previousId) => {
        if (id && id !== previousId) {
            loadAll();
        }
    },
);

/**
 * A 422 belongs on the field that caused it — a bare toast would leave
 * the user mapping "something is wrong" onto the only text box on screen.
 * Anything else is a real failure and gets the toast.
 */
function applyFailure(e: unknown, target: typeof createErrors): void {
    if (isAxiosError(e) && e.response?.status === 422) {
        target.value = e.response.data?.errors ?? {};

        return;
    }

    error.value = t('signatures.saveError');
}

/**
 * Fold a saved row back into the list. When it arrives as the default,
 * every other row loses the flag here as well: the server has already
 * cleared it (see this file's docblock) and only says so about the row it
 * returns.
 */
function absorb(saved: SignatureDto): void {
    list.value = list.value.map((item) => {
        if (item.id === saved.id) {
            return { ...item, ...saved };
        }

        return saved.is_default ? { ...item, is_default: false } : item;
    });
}

async function create(): Promise<void> {
    if (!canCreate.value) {
        return;
    }

    busy.value = true;
    createErrors.value = {};
    error.value = null;

    try {
        const { data } = await api.post<SignatureDto>('/signatures', {
            content: createForm.value.content.trim(),
            is_default: createForm.value.isDefault,
        });

        // Demote the incumbent before appending, for the same reason
        // absorb() does — a new default clears the old one server-side.
        if (data.is_default) {
            list.value = list.value.map((item) => ({ ...item, is_default: false }));
        }

        list.value = [...list.value, data];
        createForm.value = { content: '', isDefault: false };
    } catch (e) {
        applyFailure(e, createErrors);
    } finally {
        busy.value = false;
    }
}

function startEdit(signature: SignatureDto): void {
    editingId.value = signature.id;
    editForm.value = { content: signature.content, isDefault: signature.is_default };
    editErrors.value = {};
}

async function save(signature: SignatureDto): Promise<void> {
    if (!canSaveEdit.value) {
        return;
    }

    busy.value = true;
    editErrors.value = {};
    error.value = null;

    try {
        // Both fields every time — see this file's docblock: PUT is a
        // full replace, so an edit that omitted the flag would 422.
        const { data } = await api.put<SignatureDto>(`/signatures/${signature.id}`, {
            content: editForm.value.content.trim(),
            is_default: editForm.value.isDefault,
        });

        absorb(data);
        editingId.value = null;
    } catch (e) {
        applyFailure(e, editErrors);
    } finally {
        busy.value = false;
    }
}

/**
 * Promoting a row from the list, without opening the editor first. The
 * text goes along untouched because PUT replaces the whole row — sending
 * the flag alone would come back 422 on the missing content.
 */
async function makeDefault(signature: SignatureDto): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        const { data } = await api.put<SignatureDto>(`/signatures/${signature.id}`, {
            content: signature.content,
            is_default: true,
        });

        absorb(data);
    } catch {
        error.value = t('signatures.saveError');
    } finally {
        busy.value = false;
    }
}

async function remove(signature: SignatureDto): Promise<void> {
    // Deleting a signature touches no post: the editor appends its text
    // at submit time and the backend keeps no link back, so a published
    // post keeps whatever it was published with. The confirmation says so
    // rather than leaving the user to guess.
    if (!window.confirm(t('signatures.removeConfirm'))) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await api.delete(`/signatures/${signature.id}`);
        list.value = list.value.filter((item) => item.id !== signature.id);

        if (editingId.value === signature.id) {
            editingId.value = null;
        }
    } catch {
        error.value = t('signatures.removeError');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <div class="flex max-w-4xl flex-col gap-5">
            <div class="flex flex-col gap-1">
                <h1 class="text-[26px] font-semibold leading-tight tracking-tight text-text">{{ t('signatures.title') }}</h1>
                <p class="max-w-2xl text-sm leading-relaxed text-muted">{{ t('signatures.intro') }}</p>
            </div>

            <div>
                <div class="flex flex-col gap-4">
                    <Toast v-if="error" variant="danger" @dismiss="error = null">{{ error }}</Toast>

                    <div v-if="!loading && !loadFailed && list.length === 0" class="flex flex-col gap-1">
                        <p class="text-sm text-text">{{ t('signatures.empty') }}</p>
                        <p class="text-sm text-muted">{{ t('signatures.emptyHint') }}</p>
                    </div>

                    <ul v-else-if="list.length > 0" class="flex flex-col gap-2">
                        <li
                            v-for="signature in list"
                            :key="signature.id"
                            data-test="signature-row"
                            class="rounded-lg border border-border bg-surface p-4"
                        >
                            <div v-if="editingId === signature.id" data-test="signature-edit" class="flex flex-col gap-3">
                                <ContentTextarea
                                    v-model="editForm.content"
                                    :id="`signature-edit-${signature.id}`"
                                    :label="t('signatures.contentLabel')"
                                    :limit="CONTENT_LIMIT"
                                    :error="editErrors.content?.[0]"
                                />

                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-border bg-surface px-2.5 py-2 text-[13px] text-text hover:bg-surface-2">
                                    <input
                                        v-model="editForm.isDefault"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary"
                                    />
                                    <span>{{ t('signatures.isDefaultLabel') }}</span>
                                </label>
                                <p class="text-xs text-muted">{{ t('signatures.defaultHint') }}</p>

                                <div class="flex flex-wrap gap-2">
                                    <Button variant="secondary" :loading="busy" :disabled="!canSaveEdit" @click="save(signature)">
                                        {{ t('signatures.save') }}
                                    </Button>
                                    <Button variant="ghost" @click="editingId = null">{{ t('signatures.cancel') }}</Button>
                                </div>
                            </div>

                            <div v-else class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span data-test="signature-preview" class="truncate text-sm text-text">
                                        {{ preview(signature.content) }}
                                    </span>
                                    <!--
                                        Not colour alone (color.md §4): the icon and
                                        the word carry the meaning, so the default
                                        signature is still identifiable to anyone who
                                        cannot tell this tint from the muted line.
                                    -->
                                    <span
                                        v-if="signature.is_default"
                                        data-test="signature-default-badge"
                                        class="flex items-center gap-1 text-xs text-primary"
                                    >
                                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path
                                                fill-rule="evenodd"
                                                d="M10 1.5l2.47 5.005 5.53.803-4 3.898.944 5.507L10 14.113l-4.944 2.6L6 11.206l-4-3.898 5.53-.803L10 1.5z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        {{ t('signatures.defaultBadge') }}
                                    </span>
                                </div>
                                <span class="flex gap-2">
                                    <Button
                                        v-if="!signature.is_default"
                                        variant="ghost"
                                        :loading="busy"
                                        @click="makeDefault(signature)"
                                    >
                                        {{ t('signatures.makeDefault') }}
                                    </Button>
                                    <Button variant="ghost" @click="startEdit(signature)">{{ t('signatures.edit') }}</Button>
                                    <Button variant="danger-text" :loading="busy" @click="remove(signature)">
                                        {{ t('signatures.remove') }}
                                    </Button>
                                </span>
                            </div>
                        </li>
                    </ul>

                    <form
                        data-test="signature-create"
                        class="flex flex-col gap-3.5 rounded-lg border border-border bg-surface p-5"
                        @submit.prevent="create"
                    >
                        <h2 class="text-[15px] font-semibold text-text">{{ t('signatures.create') }}</h2>
                        <ContentTextarea
                            v-model="createForm.content"
                            id="signature-create-content"
                            :label="t('signatures.contentLabel')"
                            :limit="CONTENT_LIMIT"
                            :error="createErrors.content?.[0]"
                        />

                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-border bg-surface px-2.5 py-2 text-[13px] text-text hover:bg-surface-2">
                            <input
                                v-model="createForm.isDefault"
                                type="checkbox"
                                class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary"
                            />
                            <span>{{ t('signatures.isDefaultLabel') }}</span>
                        </label>

                        <div>
                            <Button type="submit" variant="primary" :loading="busy" :disabled="!canCreate">
                                {{ t('signatures.create') }}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
