<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Button from '../ui/Button.vue';
import { api } from '../../api/client';

/**
 * The failed-publication banner (docs/ACCEPTANCE.md §8).
 *
 * `GET /api/posts/errors` and `POST /api/posts/{group}/retry` were both
 * built in Task 32 and had zero callers anywhere in the SPA — so the
 * acceptance checklist instructed a human to "find the failed post in the
 * UI, click retry" against a UI that did not exist. Final review, batch 4
 * (coverage and gates).
 *
 * Scoped to the calendar's currently visible range, deliberately: the
 * endpoint takes from/to, and a banner about failures the user cannot see
 * on screen would be a notification system, which is what the digest mail
 * already is. This answers "something on THIS screen did not go out".
 */
const props = defineProps<{
    /** The calendar's visible range; null before FullCalendar reports one. */
    range: { from: string; to: string } | null;
}>();

const emit = defineEmits<{ retried: [] }>();

interface FailedChannel {
    integration: { id: string; name: string };
    error: string | null;
}

interface FailedGroup {
    id: string;
    publish_date: string | null;
    error: string | null;
    channels: FailedChannel[];
}

const { t } = useI18n();

const failures = ref<FailedGroup[]>([]);
const retrying = ref<string | null>(null);
const retryError = ref<string | null>(null);

/**
 * Deliberately component-local and deliberately not persisted.
 *
 * A failed publication is not a notice the user gets to silence for good:
 * a reload, or coming back to the calendar later, must show it again. So
 * no localStorage and no Pinia store here — closing means "I have seen
 * this, get it off my screen for now", and `load()` does not reset it,
 * because paging the calendar is not a new decision about the banner.
 */
const dismissed = ref(false);

async function load(): Promise<void> {
    if (!props.range) {
        return;
    }

    try {
        const { data } = await api.get<FailedGroup[]>('/posts/errors', { params: props.range });
        failures.value = data;
    } catch {
        // Non-fatal by design: this banner is additional information
        // about the calendar, and a calendar that loaded fine must not
        // be replaced by an error because its *annotation* failed.
        failures.value = [];
    }
}

async function retry(groupId: string): Promise<void> {
    retrying.value = groupId;
    retryError.value = null;

    try {
        await api.post(`/posts/${groupId}/retry`);
        // Drop it locally rather than waiting for the refetch, so the row
        // cannot be clicked twice while the request is in flight.
        failures.value = failures.value.filter((failure) => failure.id !== groupId);
        emit('retried');
    } catch (e) {
        // 409 is the one an operator will actually hit: the group stopped
        // being retryable between render and click (someone else retried
        // it, or it has since published). Saying which is the difference
        // between a usable message and "something went wrong".
        retryError.value = isAxiosError(e) && e.response?.status === 409
            ? t('calendar.errors.retryConflict')
            : t('calendar.errors.retryError');
        await load();
    } finally {
        retrying.value = null;
    }
}

watch(() => props.range, load, { immediate: true, deep: true });
</script>

<template>
    <section
        v-if="failures.length > 0 && !dismissed"
        role="alert"
        data-test="publish-error-banner"
        class="flex flex-col gap-3 rounded-xl border border-danger/40 bg-danger-soft p-3 text-sm text-danger"
    >
        <div class="flex items-start justify-between gap-2">
            <h2 class="font-semibold">{{ t('calendar.errors.title', { count: failures.length }) }}</h2>

            <button
                type="button"
                class="-m-1 rounded-xl p-1 text-danger/70 hover:text-danger focus:outline-none focus:ring-2 focus:ring-primary"
                :aria-label="t('ui.close')"
                data-test="dismiss-publish-errors"
                @click="dismissed = true"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                    />
                </svg>
            </button>
        </div>

        <div v-for="failure in failures" :key="failure.id" class="flex flex-wrap items-start justify-between gap-2">
            <div class="flex flex-col gap-1">
                <!--
                    The group-level summary MissedPostsService wrote, with
                    the per-channel detail under it: "which post" and
                    "which channel of it" are different questions, and a
                    partially-failed group needs both answered.
                -->
                <p>{{ failure.error ?? t('calendar.errors.unknownReason') }}</p>
                <ul class="flex flex-col gap-0.5 text-xs">
                    <li v-for="channel in failure.channels" :key="channel.integration.id">
                        <span class="font-medium">{{ channel.integration.name }}</span>
                        — {{ channel.error ?? t('calendar.errors.unknownReason') }}
                    </li>
                </ul>
            </div>

            <Button
                variant="secondary"
                :loading="retrying === failure.id"
                :data-test="`retry-${failure.id}`"
                @click="retry(failure.id)"
            >
                {{ t('calendar.errors.retry') }}
            </Button>
        </div>

        <p v-if="retryError" data-test="retry-error">{{ retryError }}</p>
    </section>
</template>
