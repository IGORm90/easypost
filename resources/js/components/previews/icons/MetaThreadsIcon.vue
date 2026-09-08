<script setup lang="ts">
/**
 * Threads' brand mark — see ./TelegramIcon.vue for why every mark lives
 * under components/previews/ and why its fill is the one non-token
 * colour the palette guard allows (color.md §6). Threads' own mark is
 * monochrome (black on white / white on black), which is why this is
 * the one icon in this directory using a colour that also happens to be
 * a neutral rather than a hue — still a HEX literal the guard must be
 * told about explicitly, same as every other network here.
 */
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="#000000" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false">
        <path
            d="M12 3c-4.5 0-7.5 2.8-7.5 9s3 9 7.5 9c3.7 0 6-1.6 6.7-4.1.7-2.5-.5-4.6-3.6-5.1-2-.3-3.6.1-4.3 1.1-.6.9-.3 2 .8 2.5 1 .5 2.2.3 2.9-.6"
        />
    </svg>
</template>
