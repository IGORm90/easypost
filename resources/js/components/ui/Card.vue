<script setup lang="ts">
withDefaults(defineProps<{ title?: string }>(), { title: undefined });
</script>

<template>
    <div class="rounded-xl border border-border bg-surface p-4 shadow-sm">
        <h3 v-if="title" class="mb-2 text-sm font-semibold text-text">{{ title }}</h3>
        <slot />
    </div>
</template>
