<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { api } from '../../api/client';
import Card from '../../components/ui/Card.vue';

/**
 * The public, unauthenticated landing page for a share link
 * (`GET /share/:token`, minted by `POST /api/posts/{group}/share`) —
 * Task 22. No AppLayout: this page must render for a visitor with no
 * session at all, so it never touches useAuthStore()/useOrganizationStore()
 * or anything that assumes a logged-in member (the sidebar/org switcher
 * AppLayout wraps every internal page in).
 *
 * Calls GET /api/public/posts/:token directly — that endpoint carries no
 * auth:sanctum/organization middleware (see routes/api.php), so this
 * works whether or not the visitor happens to also be a signed-in
 * member.
 *
 * Injection: every piece of user-authored content rendered below
 * (`content`, `name`) goes through plain `{{ }}` interpolation, which
 * Vue escapes by default — there is no `v-html` anywhere on this page,
 * and `picture` is bound only as an `<img src>` attribute (browsers do
 * not execute an `src` value as markup/script). A customer's post text
 * can contain anything (`<script>`, event handler strings, ...) and it
 * still renders as inert text, never executes.
 *
 * Deliberately simple, per the brief: this renders each channel's raw
 * content list, not a real per-network preview. Task 26 owns the actual
 * character-accurate, per-provider preview rendering (thread layout,
 * media, provider-specific chrome) — it slots in by replacing the
 * `<p>` loop below with its real preview components, fed by this same
 * `channels[].content` shape; nothing else on this page needs to change
 * for that.
 */
interface SharedChannel {
    provider_identifier: string;
    name: string;
    picture: string | null;
    content: string[];
}

interface SharedPost {
    publish_date: string | null;
    /** The zone the post was scheduled in — see PublicPostResource. */
    timezone: string;
    channels: SharedChannel[];
}

const { t, locale } = useI18n();
const route = useRoute();

const status = ref<'loading' | 'ready' | 'not-found'>('loading');
const post = ref<SharedPost | null>(null);

/**
 * Final review, Minor: this used `new Date(iso).toLocaleString()`, which
 * takes both the locale and the timezone from the viewer's browser — so
 * a client in another country was told a time the post does not go out
 * at, on the one page whose entire purpose is telling them when it does.
 * The zone comes from the payload (the zone it was scheduled in) and the
 * locale from the app, and the zone is named in the output so the reader
 * can see which clock it is on.
 */
const publishedAt = computed<string | null>(() => {
    if (!post.value?.publish_date) {
        return null;
    }

    // Spelled out field by field rather than with `dateStyle`/`timeStyle`:
    // Intl rejects combining those with `timeZoneName`, and naming the
    // zone is the whole point — otherwise the reader cannot tell whose
    // clock the time is on.
    return new Intl.DateTimeFormat(locale.value, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: post.value.timezone,
        timeZoneName: 'short',
    }).format(new Date(post.value.publish_date));
});

onMounted(async () => {
    const token = String(route.params.token ?? '');

    try {
        const response = await api.get<SharedPost>(`/public/posts/${token}`);
        post.value = response.data;
        status.value = 'ready';
    } catch {
        // A revoked token and one that never existed both 404 (see
        // ShareService::findForPublic()) — this page shows the exact
        // same "not found" state for either, on purpose, never
        // distinguishing them.
        status.value = 'not-found';
    }
});
</script>

<template>
    <div class="flex min-h-screen justify-center bg-bg p-4 text-text">
        <div class="w-full max-w-lg py-8">
            <p class="mb-4 text-sm font-semibold">{{ t('app.name') }}</p>

            <p v-if="status === 'loading'" class="text-sm text-muted">{{ t('ui.loading') }}</p>

            <p v-else-if="status === 'not-found'" role="alert" class="text-sm text-danger">
                {{ t('sharedPost.notFound') }}
            </p>

            <div v-else-if="post" class="flex flex-col gap-4">
                <p v-if="publishedAt" class="text-sm text-muted">{{ t('sharedPost.publishDate') }}: {{ publishedAt }}</p>

                <Card v-for="(channel, index) in post.channels" :key="index">
                    <div class="mb-3 flex items-center gap-2">
                        <img
                            v-if="channel.picture"
                            :src="channel.picture"
                            alt=""
                            class="h-8 w-8 rounded-xl object-cover"
                        />
                        <span class="text-sm font-semibold text-text">{{ channel.name }}</span>
                        <span class="text-xs text-muted">({{ channel.provider_identifier }})</span>
                    </div>

                    <p v-for="(segment, segmentIndex) in channel.content" :key="segmentIndex" class="whitespace-pre-wrap text-sm text-text">
                        {{ segment }}
                    </p>
                </Card>

                <p v-if="post.channels.length === 0" class="text-sm text-muted">{{ t('sharedPost.empty') }}</p>
            </div>
        </div>
    </div>
</template>
