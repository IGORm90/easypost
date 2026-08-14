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
    <div class="flex flex-col gap-3">
        <div
            class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-6 text-center text-sm transition"
            :class="dragging ? 'border-primary bg-primary-soft text-primary' : 'border-border text-muted'"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <p>{{ t('media.uploader.dropHint') }}</p>
            <Button variant="secondary" type="button" @click="openPicker">{{ t('media.uploader.browse') }}</Button>
            <input
                ref="fileInput"
                type="file"
                multiple
                accept="image/jpeg,image/png,image/gif,image/webp,video/mp4"
                class="hidden"
                @change="onFileChange"
            />
            <p class="text-xs text-muted">{{ t('media.uploader.constraints') }}</p>
        </div>

        <form class="flex gap-2" @submit.prevent="submitUrl">
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

        <Toast v-if="errors.length" variant="danger" @dismiss="errors = []">
            <p v-for="(message, index) in errors" :key="index">{{ message }}</p>
        </Toast>
    </div>
</template>
