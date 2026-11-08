<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Toast from '../ui/Toast.vue';
import Button from '../ui/Button.vue';
import Input from '../ui/Input.vue';
import { useMediaStore, type MediaItem } from '../../stores/media';

// Same constraints UploadMediaRequest/MediaUploadService enforce
// server-side (Task 27) — checked here only to give the user instant
// feedback without a round trip; the server's content-sniffed check is
// the actual source of truth, so a file that slips past this (a
// mislabelled extension, say) still gets a clean rejection through
// `uploadFiles()`'s catch below rather than an unhandled failure.
const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4'];
const MAX_SIZE_BYTES = 100 * 1024 * 1024;

const store = useMediaStore();
const { t } = useI18n();

const emit = defineEmits<{ uploaded: [MediaItem] }>();

const dragging = ref(false);
const errors = ref<string[]>([]);
const urlValue = ref('');
const urlSubmitting = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

function openPicker(): void {
    fileInput.value?.click();
}

function validate(file: File): string | null {
    if (!ALLOWED_MIME_TYPES.includes(file.type)) {
        return t('media.uploader.unsupportedType', { name: file.name });
    }

    if (file.size > MAX_SIZE_BYTES) {
        return t('media.uploader.tooLarge', { name: file.name });
    }

    return null;
}

/**
 * Uploads every file in the batch independently — one bad file (wrong
 * type, too large, or a genuine server-side failure) is reported
 * without blocking or hiding the outcome of the others. Task 27 made
 * server-side write failures loud (500 on a storage failure, 422 on a
 * disallowed/undetected mime); this is where the client surfaces them,
 * per file, instead of leaving a phantom "uploading forever" tile — see
 * `useMediaStore().upload()`'s own rollback for why no tile survives a
 * rejected file.
 */
async function uploadFiles(files: FileList | File[]): Promise<void> {
    errors.value = [];

    await Promise.all(
        Array.from(files).map(async (file) => {
            const validationError = validate(file);

            if (validationError) {
                errors.value.push(validationError);

                return;
            }

            try {
                const media = await store.upload(file);
                emit('uploaded', media);
            } catch (e) {
                // Task 36 fix round 1, Minor 5: a 402 already shows the
                // global warning toast (api/client.ts's response
                // interceptor) — adding this local --danger line too
                // would put a red failure message right next to the
                // --warning toast for the exact same event (color.md
                // §3.8). Every other failure keeps its local line.
                if (isAxiosError(e) && e.response?.status === 402) {
                    return;
                }

                errors.value.push(t('media.uploader.uploadFailed', { name: file.name }));
            }
        }),
    );
}

function onDrop(event: DragEvent): void {
    dragging.value = false;

    if (event.dataTransfer?.files.length) {
        void uploadFiles(event.dataTransfer.files);
    }
}

function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files?.length) {
        void uploadFiles(input.files);
    }

    input.value = '';
}

async function submitUrl(): Promise<void> {
    const value = urlValue.value.trim();

    if (!value) {
        return;
    }

    urlSubmitting.value = true;
    errors.value = [];

    try {
        const media = await store.uploadFromUrl(value);
        urlValue.value = '';
        emit('uploaded', media);
    } catch (e) {
        // See uploadFiles()'s catch above — same 402/toast suppression.
        if (!(isAxiosError(e) && e.response?.status === 402)) {
            errors.value.push(t('media.uploader.urlImportFailed'));
        }
    } finally {
        urlSubmitting.value = false;
    }
}
</script>

<template>
    <!-- «Кобальт»: the drop zone and the URL import side by side, each a
         card of its own; they stack on narrow screens. -->
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap gap-3">
        <div
            class="flex min-w-[280px] flex-[2_1_420px] flex-wrap items-center gap-4 rounded-lg border-2 border-dashed p-5 text-sm transition"
            :class="dragging ? 'border-primary bg-primary-soft text-primary' : 'border-border-strong bg-surface text-muted'"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-hover" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 16V4M7 9l5-5 5 5M4 20h16" /></svg>
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="text-text">{{ t('media.uploader.dropHint') }}</span>
                <span class="text-xs text-muted">{{ t('media.uploader.constraints') }}</span>
            </span>
            <Button variant="secondary" type="button" @click="openPicker">{{ t('media.uploader.browse') }}</Button>
            <input
                ref="fileInput"
                type="file"
                multiple
                accept="image/jpeg,image/png,image/gif,image/webp,video/mp4"
                class="hidden"
                @change="onFileChange"
            />
        </div>

        <form class="flex min-w-[280px] flex-[1_1_300px] items-end gap-2 rounded-lg border border-border bg-surface p-4" @submit.prevent="submitUrl">
            <!--
                The `flex-1` sits on this wrapper, not on the field: Input sets
                `inheritAttrs: false` and binds $attrs to the inner <input>,
                so a class passed to it lands there and never stretches the
                component's own wrapping <div> in this flex row.
            -->
            <div class="flex-1">
                <Input
                    :model-value="urlValue"
                    type="url"
                    :aria-label="t('media.uploader.urlPlaceholder')"
                    :placeholder="t('media.uploader.urlPlaceholder')"
                    @update:model-value="urlValue = $event"
                />
            </div>
            <Button variant="secondary" type="submit" :loading="urlSubmitting" :disabled="!urlValue.trim()">
                {{ t('media.uploader.importUrl') }}
            </Button>
        </form>
        </div>

        <Toast v-if="errors.length" variant="danger" @dismiss="errors = []">
            <p v-for="(message, index) in errors" :key="index">{{ message }}</p>
        </Toast>
    </div>
</template>
