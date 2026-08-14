<script setup lang="ts">
// The global "plan limit reached" warning (Task 36) — see
// stores/limitToast.ts's docblock for why this is one singleton,
// mounted once in AppLayout.vue, rather than something each page wires
// up itself the way ChannelsIndex/PostEditorModal do for their own
// local errors. `variant="warning"`, never `danger` (color.md §1 — "at
// your limit" is not "this action failed": the request was rejected
// before anything was attempted, and the toast's own message already
// tells the user the one or two things they can do about it).
import Toast from './Toast.vue';
import { useLimitToastStore } from '../../stores/limitToast';

const store = useLimitToastStore();
</script>

<template>
    <div v-if="store.message" class="fixed inset-x-0 top-4 z-50 mx-auto flex w-full max-w-md px-4">
        <Toast variant="warning" class="w-full" @dismiss="store.dismiss()">
            {{ store.message }}
        </Toast>
    </div>
</template>
