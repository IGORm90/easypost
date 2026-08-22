<script setup lang="ts">
import { isAxiosError } from 'axios';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '../../api/client';
import Button from '../ui/Button.vue';
import Input from '../ui/Input.vue';
import Modal from '../ui/Modal.vue';
import Toast from '../ui/Toast.vue';
import type { CalendarTag } from '../../stores/posts';

/**
 * Create, rename and delete tags. One component for both entry points —
 * the calendar's filter block and the post editor — so the copy, the
 * validation handling and the delete warning exist once.
 *
 * It owns its own list rather than reading a store: the two callers
 * already keep their own `availableTags` refs, fetched independently, and
 * a third source of truth would be one more thing to keep in step. What
 * goes out instead is `changed`, on which each caller re-runs the fetch
 * it already had.
 *
 * The list is refetched on every open, not cached. Usage counts are the
 * whole basis of the delete warning, and a stale count would make that
 * warning lie about how many posts lose the tag.
 */
const props = defineProps<{ open: boolean }>();

const emit = defineEmits<{ close: []; changed: []; created: [CalendarTag] }>();

const { t } = useI18n();

/**
 * `--accent` from color.md. Any other literal would fail palette.spec.ts,
 * which only tolerates hexes that document lists — and the accent is
 * already what DemoSeeder gives its tag, for the same reason.
 */
const DEFAULT_COLOR = '#A94E08';

const tags = ref<CalendarTag[]>([]);
const loading = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

const createForm = ref({ name: '', color: DEFAULT_COLOR });
const createErrors = ref<Record<string, string[]>>({});

/** Only one row is editable at a time; `null` means none. */
const editingId = ref<string | null>(null);
const editForm = ref({ name: '', color: DEFAULT_COLOR });
const editErrors = ref<Record<string, string[]>>({});

watch(
    () => props.open,
    (open) => {
        if (open) {
            resetForms();
            load();
        }
    },
    { immediate: true },
);

function resetForms(): void {
    createForm.value = { name: '', color: DEFAULT_COLOR };
    createErrors.value = {};
    editingId.value = null;
    editErrors.value = {};
    error.value = null;
}

async function load(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await api.get<CalendarTag[]>('/tags');
        tags.value = data;
        error.value = null;
    } catch {
        error.value = t('tags.loadError');
    } finally {
        loading.value = false;
    }
}

/**
 * A 422 is the expected outcome of a duplicate name, and its message
 * belongs on the field that caused it — a generic toast would leave the
 * user mapping "something is wrong" back onto one of two inputs. Anything
 * else is a real failure and gets the toast.
 */
function applyFailure(e: unknown, target: typeof createErrors): boolean {
    if (isAxiosError(e) && e.response?.status === 422) {
        target.value = e.response.data?.errors ?? {};

        return true;
    }

    error.value = t('tags.saveError');

    return false;
}

async function create(): Promise<void> {
    busy.value = true;
    createErrors.value = {};
    error.value = null;

    try {
        const { data } = await api.post<CalendarTag>('/tags', {
            name: createForm.value.name,
            color: createForm.value.color,
        });

        tags.value = [...tags.value, data];
        createForm.value = { name: '', color: DEFAULT_COLOR };
        emit('created', data);
        emit('changed');
    } catch (e) {
        applyFailure(e, createErrors);
    } finally {
        busy.value = false;
    }
}

function startEdit(tag: CalendarTag): void {
    editingId.value = tag.id;
    editForm.value = { name: tag.name, color: tag.color };
    editErrors.value = {};
}

async function save(tag: CalendarTag): Promise<void> {
    busy.value = true;
    editErrors.value = {};
    error.value = null;

    try {
        // Both fields every time: PUT /api/tags/{tag} is a full replace,
        // with `name` and `color` each `required`, so a rename that sent
        // only the name would come back 422 on the colour.
        const { data } = await api.put<CalendarTag>(`/tags/${tag.id}`, {
            name: editForm.value.name,
            color: editForm.value.color,
        });

        tags.value = tags.value.map((item) => (item.id === tag.id ? { ...item, ...data } : item));
        editingId.value = null;
        emit('changed');
    } catch (e) {
        applyFailure(e, editErrors);
    } finally {
        busy.value = false;
    }
}

async function remove(tag: CalendarTag): Promise<void> {
    const count = tag.post_groups_count ?? 0;
    // Deleting a tag cascades `post_group_tag` silently and never fails,
    // so this sentence is the only warning the user gets. Zero gets its
    // own wording rather than "will come off 0 post(s)".
    const message =
        count > 0
            ? t('tags.removeConfirm', { name: tag.name, count })
            : t('tags.removeConfirmUnused', { name: tag.name });

    if (!window.confirm(message)) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await api.delete(`/tags/${tag.id}`);
        tags.value = tags.value.filter((item) => item.id !== tag.id);
        emit('changed');
    } catch {
        error.value = t('tags.removeError');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal :open="open" :title="t('tags.title')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <Toast v-if="error" variant="danger" @dismiss="error = null">{{ error }}</Toast>

            <p v-if="!loading && tags.length === 0" class="text-sm text-muted">{{ t('tags.empty') }}</p>

            <ul v-else class="flex flex-col gap-2">
                <li v-for="tag in tags" :key="tag.id" class="rounded-xl border border-border p-3">
                    <div v-if="editingId === tag.id" class="flex flex-col gap-2">
                        <div data-test="tag-edit-name">
                            <Input v-model="editForm.name" :label="t('tags.nameLabel')" :error="editErrors.name?.[0]" />
                        </div>
                        <div data-test="tag-edit-color">
                            <Input
                                v-model="editForm.color"
                                type="color"
                                :label="t('tags.colorLabel')"
                                :error="editErrors.color?.[0]"
                            />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="primary" :loading="busy" @click="save(tag)">{{ t('tags.save') }}</Button>
                            <Button variant="ghost" @click="editingId = null">{{ t('tags.cancel') }}</Button>
                        </div>
                    </div>

                    <div v-else class="flex flex-wrap items-center justify-between gap-2">
                        <span class="flex items-center gap-2 text-sm text-text">
                            <!--
                                The dot is identity, not status, and it never
                                carries meaning on its own — the name sits
                                right beside it (color.md §4), same as in
                                TagFilter.vue and on the calendar card.
                            -->
                            <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: tag.color }" aria-hidden="true" />
                            {{ tag.name }}
                            <span class="text-muted">
                                {{ (tag.post_groups_count ?? 0) > 0 ? t('tags.usage', { count: tag.post_groups_count }) : t('tags.unused') }}
                            </span>
                        </span>
                        <span class="flex gap-2">
                            <Button variant="ghost" @click="startEdit(tag)">{{ t('tags.rename') }}</Button>
                            <Button variant="danger" :loading="busy" @click="remove(tag)">{{ t('tags.remove') }}</Button>
                        </span>
                    </div>
                </li>
            </ul>

            <form class="flex flex-col gap-2 border-t border-border pt-4" @submit.prevent="create">
                <div data-test="tag-create-name">
                    <Input v-model="createForm.name" :label="t('tags.nameLabel')" :error="createErrors.name?.[0]" />
                </div>
                <div data-test="tag-create-color">
                    <Input
                        v-model="createForm.color"
                        type="color"
                        :label="t('tags.colorLabel')"
                        :error="createErrors.color?.[0]"
                    />
                </div>
                <div>
                    <Button type="submit" variant="primary" :loading="busy">{{ t('tags.create') }}</Button>
                </div>
            </form>
        </div>
    </Modal>
</template>
