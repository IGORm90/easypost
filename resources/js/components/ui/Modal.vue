<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * `ignoreBackdropClick` defaults to `false`, preserving every existing
 * caller's behaviour exactly (a stray click outside still closes them).
 * PostEditorModal.vue (Task 25) is the first caller to opt in — an
 * irreplaceable, multi-minute composition sitting behind a full-screen
 * backdrop should not be discardable by a single misplaced click, only
 * by the explicit close button or a deliberate in-form action.
 */
const props = withDefaults(defineProps<{ open?: boolean; title?: string; ignoreBackdropClick?: boolean }>(), {
    open: false,
    title: undefined,
    ignoreBackdropClick: false,
});

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

function onBackdropClick(): void {
    if (!props.ignoreBackdropClick) {
        emit('close');
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-text/40" @click="onBackdropClick" />
            <div class="relative w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-lg">
                <div class="mb-4 flex items-center justify-between">
                    <h2 v-if="title" class="text-base font-semibold text-text">{{ title }}</h2>
                    <button
                        type="button"
                        class="ml-auto rounded-xl p-1 text-muted hover:text-text focus:outline-none focus:ring-2 focus:ring-primary"
                        :aria-label="t('ui.close')"
                        @click="emit('close')"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path
                                d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                            />
                        </svg>
                    </button>
                </div>
                <slot />
            </div>
        </div>
    </Teleport>
</template>
