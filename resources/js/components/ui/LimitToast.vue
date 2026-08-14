<script setup lang="ts">
// The global "plan limit reached" warning (Task 36) — see
// stores/limitToast.ts's docblock for why this is one singleton,
// mounted once in AppLayout.vue, rather than something each page wires
// up itself the way ChannelsIndex/PostEditorModal do for their own
// local errors. `variant="warning"`, never `danger` (color.md §1 — "at
// your limit" is not "this action failed": the request was rejected
// before anything was attempted, and the toast's own message already
// tells the user the one or two things they can do about it).
//
// Round 1 re-review, residual 1: a 402 raised from INSIDE a modal
// (ConnectProviderModal, or MediaUploader mounted inside MediaPicker's
// modal) used to still show a red local error line at the same z-index
// tier as this toast — Minor 5 removed that line, which uncovered a
// pre-existing layering bug this fix also closes. `Modal.vue` teleports
// to `<body>` with `fixed inset-0 z-50` and a 40%-opacity scrim; this
// component previously stayed inside `AppLayout`'s own tree at the SAME
// `z-50`, so — later in DOM order — the modal's scrim painted over it,
// leaving only a dimmed toast behind an overlay for exactly the case
// Minor 5's fix most needed it visible. `<Teleport to="body">` puts this
// in the same stacking context as every `Modal.vue` instance regardless
// of what ancestor `AppLayout` gets in the future, and `z-[60]` — one
// step above `Modal.vue`'s `z-50` — guarantees this always paints on
// top of any open modal's scrim, never the reverse.
import Toast from './Toast.vue';
import { useLimitToastStore } from '../../stores/limitToast';

const store = useLimitToastStore();
</script>

<template>
    <Teleport to="body">
        <div v-if="store.message" class="fixed inset-x-0 top-4 z-[60] mx-auto flex w-full max-w-md px-4">
            <Toast variant="warning" class="w-full" @dismiss="store.dismiss()">
                {{ store.message }}
            </Toast>
        </div>
    </Teleport>
</template>
