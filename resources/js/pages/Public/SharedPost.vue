<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { api } from '../../api/client';

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
    <!-- «Кобальт» public preview: a slim header that says this is a
         read-only preview, then each channel's version as a card. -->
    <div class="flex min-h-screen flex-col bg-bg text-text">
        <header class="flex flex-wrap items-center gap-3 border-b border-border bg-surface px-6 py-3.5 sm:px-8">
            <span class="flex h-7 w-7 items-center justify-center rounded bg-nav text-[11px] font-bold text-on-nav" aria-hidden="true">EP</span>
            <span class="font-semibold">{{ t('app.name') }}</span>
            <span class="rounded bg-accent-soft px-2 py-0.5 text-xs text-accent">{{ t('sharedPost.readOnly') }}</span>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-5 px-6 py-8">
            <p v-if="status === 'loading'" class="text-sm text-muted">{{ t('ui.loading') }}</p>
            <p v-else-if="status === 'not-found'" role="alert" class="rounded-lg border border-danger-border bg-danger-soft px-4 py-3 text-sm text-danger">
                {{ t('sharedPost.notFound') }}
            </p>
            <template v-else-if="post">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1">
                        <h1 class="text-2xl font-semibold tracking-tight">{{ t('sharedPost.title') }}</h1>
                        <p class="text-sm text-muted">{{ t('sharedPost.subtitle') }}</p>
                    </div>
                    <dl v-if="publishedAt" class="ml-auto">
                        <dt class="text-xs text-muted">{{ t('sharedPost.publishDate') }}</dt>
                        <dd class="mt-0.5 font-mono text-[13px]">{{ publishedAt }}</dd>
                    </dl>
                </div>

                <div class="grid items-start gap-4 [grid-template-columns:repeat(auto-fit,minmax(300px,1fr))]">
                    <article
                        v-for="(channel, index) in post.channels"
                        :key="index"
                        class="overflow-hidden rounded-xl border border-border bg-surface"
                    >
                        <div class="flex items-center gap-2.5 border-b border-border px-4 py-3">
                            <img v-if="channel.picture" :src="channel.picture" alt="" class="h-7 w-7 rounded-full object-cover" />
                            <span class="text-sm font-semibold">{{ channel.name }}</span>
                            <span class="text-xs text-muted">({{ channel.provider_identifier }})</span>
                        </div>
                        <div class="flex flex-col gap-3 p-4">
                            <p v-for="(segment, segmentIndex) in channel.content" :key="segmentIndex" class="whitespace-pre-wrap text-sm leading-relaxed">
                                {{ segment }}
                            </p>
                        </div>
                    </article>
                </div>
                <p v-if="post.channels.length === 0" class="text-sm text-muted">{{ t('sharedPost.empty') }}</p>
            </template>
        </main>
        <footer class="p-4 text-center text-xs text-muted">{{ t('sharedPost.footer') }}</footer>
    </div>
</template>
