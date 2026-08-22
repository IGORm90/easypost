<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../layouts/AppLayout.vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import Input from '../../components/ui/Input.vue';
import Toast from '../../components/ui/Toast.vue';
import { api } from '../../api/client';
import { useIntegrationsStore } from '../../stores/integrations';
import { useOrganizationStore } from '../../stores/organization';
import type { SetDto } from '../../components/editor/types';

/**
 * Create, rename and delete channel sets (Task 1.4). The backend for
 * this shipped with Task 23 and the post editor has been reading it ever
 * since — this screen is the half that was missing, so until now a set
 * could only be created through the API by hand.
 *
 * Like TagManager.vue it owns its own list rather than reaching for a
 * store: PostEditorModal.vue already fetches `/sets` independently for
 * its own picker, and a third source of truth would be one more thing to
 * keep in step for no gain — the editor refetches when it opens anyway.
 *
 * Three properties of the API shape everything below:
 *
 *  - PUT is a full replace: `name` and `integration_ids` are both
 *    `required`, so a rename that sent only the name comes back 422 on
 *    the channels (the same trap TagManager.vue documents for colours).
 *  - `integration_ids` may not be empty, so the submit buttons are
 *    disabled on an empty selection instead of letting the user find out
 *    from a validation error.
 *  - SetResource re-resolves the stored ids against the organization's
 *    live channels on every read. Disconnect a channel and it silently
 *    leaves every set holding it; disconnect the last one and the set
 *    arrives empty and can no longer be saved at all until a channel is
 *    added back. That state gets said out loud rather than hidden.
 */
const { t } = useI18n();
const integrations = useIntegrationsStore();
const organization = useOrganizationStore();

const sets = ref<SetDto[]>([]);
const loading = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

const createForm = ref({ name: '', integrationIds: [] as string[] });
const createErrors = ref<Record<string, string[]>>({});

/** Only one row is editable at a time; `null` means none. */
const editingId = ref<string | null>(null);
const editForm = ref({ name: '', integrationIds: [] as string[] });
const editErrors = ref<Record<string, string[]>>({});

const hasChannels = computed(() => integrations.list.length > 0);
const canCreate = computed(() => createForm.value.name.trim() !== '' && createForm.value.integrationIds.length > 0);
const canSaveEdit = computed(() => editForm.value.name.trim() !== '' && editForm.value.integrationIds.length > 0);

async function load(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await api.get<SetDto[]>('/sets');
        sets.value = data;
        error.value = null;
    } catch {
        error.value = t('sets.loadError');
    } finally {
        loading.value = false;
    }
}

async function loadAll(): Promise<void> {
    editingId.value = null;
    createForm.value = { name: '', integrationIds: [] };
    createErrors.value = {};

    await Promise.all([load(), integrations.fetch()]);
}

onMounted(loadAll);
// Re-fetch on an organization switch, so this page never shows one
// tenant's sets under another — same reason Settings/Team.vue does it.
watch(
    () => organization.current?.id,
    (id, previousId) => {
        if (id && id !== previousId) {
            loadAll();
        }
    },
);

function channelName(id: string): string {
    return integrations.list.find((integration) => integration.id === id)?.name ?? id;
}

/**
 * A 422 belongs on the field that caused it — a bare toast would leave
 * the user mapping "something is wrong" onto a name and a channel list.
 * Anything else is a real failure and gets the toast.
 */
function applyFailure(e: unknown, target: typeof createErrors): void {
    if (isAxiosError(e) && e.response?.status === 422) {
        target.value = e.response.data?.errors ?? {};

        return;
    }

    error.value = t('sets.saveError');
}

async function create(): Promise<void> {
    if (!canCreate.value) {
        return;
    }

    busy.value = true;
    createErrors.value = {};
    error.value = null;

    try {
        const { data } = await api.post<SetDto>('/sets', {
            name: createForm.value.name.trim(),
            integration_ids: createForm.value.integrationIds,
        });

        sets.value = [...sets.value, data];
        createForm.value = { name: '', integrationIds: [] };
    } catch (e) {
        applyFailure(e, createErrors);
    } finally {
        busy.value = false;
    }
}

function startEdit(set: SetDto): void {
    editingId.value = set.id;
    editForm.value = { name: set.name, integrationIds: [...set.integration_ids] };
    editErrors.value = {};
}

async function save(set: SetDto): Promise<void> {
    if (!canSaveEdit.value) {
        return;
    }

    busy.value = true;
    editErrors.value = {};
    error.value = null;

    try {
        // Both fields every time — see this file's docblock: PUT is a
        // full replace, so a rename that omitted the channels would 422.
        const { data } = await api.put<SetDto>(`/sets/${set.id}`, {
            name: editForm.value.name.trim(),
            integration_ids: editForm.value.integrationIds,
        });

        sets.value = sets.value.map((item) => (item.id === set.id ? { ...item, ...data } : item));
        editingId.value = null;
    } catch (e) {
        applyFailure(e, editErrors);
    } finally {
        busy.value = false;
    }
}

async function remove(set: SetDto): Promise<void> {
    // Deleting a set touches nothing else — it is a shortcut for picking
    // channels, not a relationship any post depends on — and the
    // confirmation says so, so nobody hesitates over whether their posts
    // are about to lose their channels.
    if (!window.confirm(t('sets.removeConfirm', { name: set.name }))) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await api.delete(`/sets/${set.id}`);
        sets.value = sets.value.filter((item) => item.id !== set.id);

        if (editingId.value === set.id) {
            editingId.value = null;
        }
    } catch {
        error.value = t('sets.removeError');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-3xl flex-col gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-lg font-semibold text-text">{{ t('sets.title') }}</h1>
                <p class="text-sm text-muted">{{ t('sets.intro') }}</p>
            </div>

            <Card>
                <div class="flex flex-col gap-4">
                    <Toast v-if="error" variant="danger" @dismiss="error = null">{{ error }}</Toast>

                    <p v-if="!hasChannels" class="text-sm text-muted">{{ t('sets.noChannels') }}</p>

                    <template v-else>
                        <div v-if="!loading && sets.length === 0" class="flex flex-col gap-1">
                            <p class="text-sm text-text">{{ t('sets.empty') }}</p>
                            <p class="text-sm text-muted">{{ t('sets.emptyHint') }}</p>
                        </div>

                        <ul v-else class="flex flex-col gap-2">
                            <li v-for="set in sets" :key="set.id" class="rounded-xl border border-border p-3">
                                <div v-if="editingId === set.id" class="flex flex-col gap-3">
                                    <div data-test="set-edit-name">
                                        <Input
                                            v-model="editForm.name"
                                            :label="t('sets.nameLabel')"
                                            :error="editErrors.name?.[0]"
                                        />
                                    </div>

                                    <fieldset data-test="set-edit-channels" class="flex flex-col gap-1.5">
                                        <legend class="text-sm font-medium text-text">{{ t('sets.channelsLabel') }}</legend>
                                        <label
                                            v-for="integration in integrations.list"
                                            :key="integration.id"
                                            class="flex cursor-pointer items-center gap-2 text-sm text-text"
                                        >
                                            <input
                                                v-model="editForm.integrationIds"
                                                type="checkbox"
                                                :value="integration.id"
                                                class="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                                            />
                                            <span class="truncate">{{ integration.name }}</span>
                                        </label>
                                        <p v-if="editErrors.integration_ids?.[0]" class="text-sm text-danger">
                                            {{ editErrors.integration_ids[0] }}
                                        </p>
                                    </fieldset>

                                    <div class="flex flex-wrap gap-2">
                                        <Button variant="secondary" :loading="busy" :disabled="!canSaveEdit" @click="save(set)">
                                            {{ t('sets.save') }}
                                        </Button>
                                        <Button variant="ghost" @click="editingId = null">{{ t('sets.cancel') }}</Button>
                                    </div>
                                </div>

                                <div v-else class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex min-w-0 flex-col gap-0.5">
                                        <span class="text-sm text-text">{{ set.name }}</span>
                                        <span v-if="set.integration_ids.length > 0" class="truncate text-xs text-muted">
                                            {{ set.integration_ids.map(channelName).join(', ') }}
                                            ({{ t('sets.channelCount', { count: set.integration_ids.length }) }})
                                        </span>
                                        <!--
                                            Not colour alone (color.md §4): the icon and
                                            the sentence carry the meaning, so this reads
                                            the same to anyone who cannot tell the tint
                                            apart from the muted line above it.
                                        -->
                                        <span v-else class="flex items-start gap-1 text-xs text-warning">
                                            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.516 11.59c.75 1.334-.213 2.98-1.743 2.98H3.483c-1.53 0-2.492-1.646-1.743-2.98l6.517-11.59zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                            {{ t('sets.orphaned') }}
                                        </span>
                                    </div>
                                    <span class="flex gap-2">
                                        <Button variant="ghost" @click="startEdit(set)">{{ t('sets.rename') }}</Button>
                                        <Button variant="danger" :loading="busy" @click="remove(set)">{{ t('sets.remove') }}</Button>
                                    </span>
                                </div>
                            </li>
                        </ul>

                        <form data-test="set-create" class="flex flex-col gap-3 border-t border-border pt-4" @submit.prevent="create">
                            <div data-test="set-create-name">
                                <Input
                                    v-model="createForm.name"
                                    :label="t('sets.nameLabel')"
                                    :error="createErrors.name?.[0]"
                                />
                            </div>

                            <fieldset data-test="set-create-channels" class="flex flex-col gap-1.5">
                                <legend class="text-sm font-medium text-text">{{ t('sets.channelsLabel') }}</legend>
                                <label
                                    v-for="integration in integrations.list"
                                    :key="integration.id"
                                    class="flex cursor-pointer items-center gap-2 text-sm text-text"
                                >
                                    <input
                                        v-model="createForm.integrationIds"
                                        type="checkbox"
                                        :value="integration.id"
                                        class="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                                    />
                                    <span class="truncate">{{ integration.name }}</span>
                                </label>
                                <p v-if="createErrors.integration_ids?.[0]" class="text-sm text-danger">
                                    {{ createErrors.integration_ids[0] }}
                                </p>
                            </fieldset>

                            <div>
                                <Button type="submit" variant="primary" :loading="busy" :disabled="!canCreate">
                                    {{ t('sets.create') }}
                                </Button>
                            </div>
                        </form>
                    </template>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
