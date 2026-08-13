<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import MediaTile from './MediaTile.vue';
import type { MediaItem } from '../../stores/media';

/**
 * Presentational tile grid — no store access of its own, same shape as
 * `ChannelPicker.vue` taking `integrations`/`providers` as props:
 * `Media/Index.vue` and `MediaPicker.vue` each own their own
 * fetch()/pagination and just hand this component the resulting
 * `items`.
 */
const props = withDefaults(
    defineProps<{
        items: MediaItem[];
        selectable?: boolean;
        /** Selection, in order — an item's badge number is its (1-based) index here. */
        selectedIds?: string[];
        deletable?: boolean;
        emptyMessage?: string;
    }>(),
    {
        selectable: false,
        selectedIds: () => [],
        deletable: false,
        emptyMessage: undefined,
    },
);

const emit = defineEmits<{ toggle: [string]; delete: [string] }>();

const { t } = useI18n();

function orderOf(id: string): number | null {
    const index = props.selectedIds.indexOf(id);

    return index === -1 ? null : index + 1;
}
</script>

<template>
    <div>
        <p v-if="items.length === 0" class="text-sm text-muted">{{ emptyMessage ?? t('media.empty') }}</p>
        <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            <MediaTile
                v-for="item in items"
                :key="item.id"
                :item="item"
                :selectable="selectable"
                :selected="selectedIds.includes(item.id)"
                :order="orderOf(item.id)"
                :deletable="deletable"
                @toggle="emit('toggle', item.id)"
                @delete="emit('delete', item.id)"
            />
        </div>
    </div>
</template>
