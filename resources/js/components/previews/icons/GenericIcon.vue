<script setup lang="ts">
/**
 * The mark for a network that has none of its own — every one of the
 * planned networks until its logo is drawn, and every channel connected
 * before its network was removed from the build. `resolveProviderIcon()`
 * falls back here rather than returning nothing, so the available-network
 * grid and the channel list render a network they know nothing about
 * instead of a hole.
 *
 * Painted with `currentColor`, not a brand colour: it inherits whatever
 * token the caller sets, so it is correct in both themes by construction
 * and needs no entry in color.md §6.
 */
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9.25" />
        <path d="M2.75 12h18.5" />
        <path d="M12 2.75c2.2 2.5 3.3 5.6 3.3 9.25S14.2 18.75 12 21.25c-2.2-2.5-3.3-5.6-3.3-9.25S9.8 5.25 12 2.75z" />
    </svg>
</template>
