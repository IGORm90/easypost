<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * One tab in the per-channel variation strip. `hasError` marks a
 * channel whose thread currently fails validation (over the character
 * limit, an unsupported thread, or a server-returned error) — shown as
 * a small "!" badge with an `aria-label`, never border colour alone
 * (color.md §3.3/§4).
 */
defineProps<{
    name: string;
    providerName: string;
    active: boolean;
    hasError?: boolean;
}>();

defineEmits<{ click: [] }>();

const { t } = useI18n();
</script>

<template>
    <button
        type="button"
        class="flex shrink-0 items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        :class="active ? 'border-primary text-text' : 'border-border text-muted hover:text-text'"
        :aria-pressed="active"
        @click="$emit('click')"
    >
        <span class="truncate">{{ name }}</span>
        <span class="text-xs text-muted">{{ providerName }}</span>
        <span
            v-if="hasError"
            class="flex h-4 w-4 items-center justify-center rounded-full bg-danger-soft text-[10px] font-bold text-danger"
            :aria-label="t('editor.tabs.hasError')"
        >
            !
        </span>
    </button>
</template>
