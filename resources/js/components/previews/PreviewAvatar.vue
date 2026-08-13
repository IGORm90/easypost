<script setup lang="ts">
/**
 * The channel's avatar, or a monogram fallback when it has none (a
 * manual/freshly-connected integration may never have fetched one).
 * Colour comes only from `--accent`/`--accent-soft` — color.md §1
 * names `--accent` explicitly for "Аватары соцсетей" (social-network
 * avatars), so a monogram fallback is the one place in these previews
 * that legitimately reaches for it, kept to a small area (an avatar,
 * not a card background) per §3.2's 5-10%-of-screen budget.
 *
 * `shape` conveys a network's own avatar convention (circular vs.
 * squarer) without any brand colour — see each Preview component for
 * which shape it passes.
 */
withDefaults(defineProps<{ name: string; picture: string | null; shape?: 'circle' | 'rounded' }>(), {
    shape: 'circle',
});
</script>

<template>
    <img
        v-if="picture"
        :src="picture"
        alt=""
        class="h-9 w-9 shrink-0 object-cover"
        :class="shape === 'circle' ? 'rounded-full' : 'rounded-lg'"
    />
    <span
        v-else
        class="flex h-9 w-9 shrink-0 items-center justify-center bg-accent-soft text-sm font-semibold text-accent"
        :class="shape === 'circle' ? 'rounded-full' : 'rounded-lg'"
    >
        {{ (name.trim().slice(0, 1) || '?').toUpperCase() }}
    </span>
</template>
