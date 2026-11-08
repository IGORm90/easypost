<script setup lang="ts">
withDefaults(defineProps<{ title?: string }>(), { title: undefined });
</script>

<template>
    <div class="rounded-lg border border-border bg-surface p-5">
        <h3 v-if="title" class="mb-3 text-base font-semibold text-text">{{ title }}</h3>
        <slot />
    </div>
</template>
