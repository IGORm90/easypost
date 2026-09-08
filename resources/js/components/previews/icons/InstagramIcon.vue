<script setup lang="ts">
/**
 * Instagram's brand mark — see ./TelegramIcon.vue for why every mark
 * lives under components/previews/ and why its fill is the one
 * non-token colour the palette guard allows (color.md §6). Instagram's
 * mark is a gradient in the real brand kit; a flat fill stands in here
 * for the same reason a single hex is used for every other network's
 * mark in this directory — one colour, not a gradient token the palette
 * spec has no entry for.
 */
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <rect x="2" y="2" width="20" height="20" rx="5" stroke="#E1306C" stroke-width="2" />
        <circle cx="12" cy="12" r="4.5" stroke="#E1306C" stroke-width="2" />
        <circle cx="17.5" cy="6.5" r="1.25" fill="#E1306C" />
    </svg>
</template>
