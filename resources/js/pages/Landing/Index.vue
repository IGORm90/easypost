<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../../stores/auth';
import { appUrl } from '../../utils/appOrigin';

/**
 * The public landing page at `/` — design variant A («Кобальт —
 * календарь в герое») from the «EasyPost — лендинг, варианты» canvas,
 * ported one-to-one: sizes, spacing and type are the design's own values,
 * written as arbitrary Tailwind values where the default scale has no
 * exact match, and every colour is a token.
 *
 * It is the only page both a signed-out visitor and a signed-in member
 * see (the route is neither `requiresAuth` nor `guestOnly`), so the one
 * thing that depends on the session is where the calls to action lead:
 * sign-up for a visitor, the calendar for a member.
 *
 * The design exists in the light palette only. `theme-light` on the root
 * keeps it light when the app's theme has put `.dark` on <html> — see
 * the `.theme-light` selector in app.css.
 *
 * color.md §3.1: the header's sign-up link is the secondary style, so the
 * hero button is the single primary element in the first viewport, and
 * the closing call to action is the only other one, a full scroll away.
 */
const { t } = useI18n();
const auth = useAuthStore();

const signedIn = computed(() => auth.user !== null);
// Plain paths, not router targets: in production this page is served from its
// own host, and a client-side route to /login would render the form there, on a
// host with no session. A real navigation reaches Caddy, which sends it to the
// app host (docker/caddy/Caddyfile). Same-host in development, where it just works.
//
// `appOrigin` is the app host when this page is served from another one (it is
// the same host that answers /api/auth/me for `signedIn`), and '' otherwise, so
// the links point straight at the app instead of taking Caddy's redirect hop.
const appOrigin = appUrl();
const ctaHref = computed(() => `${appOrigin}${signedIn.value ? '/calendar' : '/register'}`);
const year = new Date().getFullYear();

const SECTIONS = ['features', 'how', 'statuses', 'teams'] as const;

type MockState = 'DRAFT' | 'SCHEDULED' | 'QUEUE' | 'PUBLISHED' | 'ERROR';

const STATES: MockState[] = ['DRAFT', 'SCHEDULED', 'QUEUE', 'PUBLISHED', 'ERROR'];

/*
 * The mock-up's cards, as the design draws them. A finished outcome
 * (published, failed) tints the whole card and names the state in plain
 * text; an in-flight one stays on the surface with a small 11px tag. The
 * app's own Badge is deliberately not used here: at 12px, semibold, with
 * a border and no wrapping it is wider than a day column of this
 * mock-up, and the long "Ошибка публикации" spilled out of its cell.
 */
const CARD: Record<MockState, string> = {
    PUBLISHED: 'border-success-border bg-success-soft text-success',
    ERROR: 'border-danger-border bg-danger-soft text-danger',
    SCHEDULED: 'border-border bg-surface',
    QUEUE: 'border-warning-border bg-surface',
    DRAFT: 'border-dashed border-border-strong bg-surface',
};

const TAG: Partial<Record<MockState, string>> = {
    SCHEDULED: 'bg-primary-soft text-primary-hover',
    QUEUE: 'bg-warning-soft text-warning',
    DRAFT: 'bg-accent-soft text-accent',
};

/** The status strip's pills: the design's 14px size, bordered for the three color.md gives a border to. */
const PILL: Record<MockState, string> = {
    DRAFT: 'bg-accent-soft py-2 text-accent',
    SCHEDULED: 'bg-primary-soft py-2 text-primary-hover',
    QUEUE: 'border border-warning-border bg-warning-soft py-[7px] text-warning',
    PUBLISHED: 'border border-success-border bg-success-soft py-[7px] text-success',
    ERROR: 'border border-danger-border bg-danger-soft py-[7px] text-danger',
};

/** Thursday — the mock-up's "today" column. */
const TODAY = 3;
const DAYS = [0, 1, 2, 3, 4, 5, 6];

interface MockPost {
    day: number;
    time: string;
    state: MockState;
}

const LANES: Array<{ network: 'tg' | 'bs'; channel: 'news' | 'social' | 'announcements'; posts: MockPost[] }> = [
    {
        network: 'tg',
        channel: 'news',
        posts: [
            { day: 0, time: '10:00', state: 'PUBLISHED' },
            { day: 2, time: '12:30', state: 'PUBLISHED' },
            { day: 3, time: '18:00', state: 'SCHEDULED' },
            { day: 4, time: '10:00', state: 'SCHEDULED' },
        ],
    },
    {
        network: 'bs',
        channel: 'social',
        posts: [
            { day: 0, time: '10:00', state: 'PUBLISHED' },
            { day: 1, time: '15:00', state: 'ERROR' },
            { day: 3, time: '18:00', state: 'QUEUE' },
            { day: 5, time: '—', state: 'DRAFT' },
        ],
    },
    {
        network: 'tg',
        channel: 'announcements',
        posts: [
            { day: 1, time: '09:00', state: 'PUBLISHED' },
            { day: 3, time: '20:00', state: 'SCHEDULED' },
            { day: 5, time: '12:00', state: 'SCHEDULED' },
        ],
    },
];

const CELLS = LANES.map((lane, row) => ({
    ...lane,
    last: row === LANES.length - 1,
    cells: DAYS.map((day) => ({ day, post: lane.posts.find((post) => post.day === day) })),
}));

/** The design's icons, element for element. Static markup only — nothing user-supplied reaches v-html. */
const FEATURES = [
    { key: 'calendar', svg: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>' },
    { key: 'variants', svg: '<path d="M4 6h16M4 12h10M4 18h7"/><path d="M17 15l3 3-3 3"/>' },
    { key: 'threads', svg: '<rect x="4" y="3" width="16" height="5" rx="1.5"/><rect x="4" y="10" width="16" height="5" rx="1.5"/><rect x="4" y="17" width="10" height="4" rx="1.5"/>' },
    { key: 'slots', svg: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>' },
    { key: 'media', svg: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>' },
    { key: 'presets', svg: '<path d="M4 7h11M4 12h16M4 17h8"/>' },
] as const;

const STEPS = ['connect', 'write', 'schedule'] as const;
const TEAM_POINTS = ['organizations', 'invites', 'filters', 'share'] as const;

const CONTAINER = 'mx-auto w-full max-w-[1200px] px-6';
const EYEBROW = 'font-mono text-xs text-muted';
const H2 = 'mt-2 text-[length:clamp(28px,3.4vw,40px)] font-bold leading-[1.15] tracking-[-0.02em]';
const BODY = 'text-[15px] leading-[1.55] text-muted';
const PRIMARY_LINK =
    'inline-flex items-center rounded-xl bg-primary text-base font-semibold text-on-primary hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2';
</script>

<template>
    <div class="theme-light min-h-screen bg-bg font-sans text-text">
        <header class="border-b border-border bg-surface">
            <div :class="CONTAINER" class="flex flex-wrap items-center gap-x-8 gap-y-4 py-4">
                <a href="#top" class="flex items-center gap-2.5 text-text">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-nav">
                        <svg class="h-[18px] w-[18px] text-on-nav" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 12h10" /><path d="M10 6l6 6-6 6" /><path d="M20 5v14" />
                        </svg>
                    </span>
                    <span class="text-lg font-bold tracking-[-0.01em]">{{ t('app.name') }}</span>
                </a>
                <nav :aria-label="t('landing.nav.label')" class="flex flex-auto flex-wrap gap-x-6 gap-y-1">
                    <a v-for="section in SECTIONS" :key="section" :href="`#${section}`" class="py-3 text-sm text-muted hover:text-text">
                        {{ t(`landing.nav.${section}`) }}
                    </a>
                </nav>
                <div class="flex items-center gap-2">
                    <template v-if="signedIn">
                        <a :href="`${appOrigin}/calendar`" class="rounded-xl border border-border-strong bg-surface px-4 py-[11px] text-sm font-semibold text-text hover:bg-surface-2">
                            {{ t('landing.nav.openApp') }}
                        </a>
                    </template>
                    <template v-else>
                        <a :href="`${appOrigin}/login`" class="px-3.5 py-3 text-sm font-medium text-muted hover:text-text">{{ t('landing.nav.login') }}</a>
                        <a :href="`${appOrigin}/register`" class="rounded-xl border border-border-strong bg-surface px-4 py-[11px] text-sm font-semibold text-text hover:bg-surface-2">
                            {{ t('landing.nav.signup') }}
                        </a>
                    </template>
                </div>
            </div>
        </header>

        <main>
            <!-- Hero -->
            <section id="top" :class="CONTAINER" class="pb-14 pt-20">
                <div class="flex flex-col items-center gap-5 text-center">
                    <p class="flex flex-wrap items-center justify-center gap-2.5 font-mono text-xs text-muted">
                        <span v-for="i in [0, 1]" :key="i" class="rounded border border-border-strong bg-surface px-2 py-1">{{ t(`landing.hero.networks.${i}`) }}</span>
                        <span>{{ t('landing.hero.eyebrow') }}</span>
                    </p>
                    <h1 class="max-w-[900px] text-[length:clamp(36px,5.4vw,64px)] font-bold leading-[1.05] tracking-[-0.025em]">
                        {{ t('landing.hero.title') }}
                    </h1>
                    <p class="max-w-[640px] text-[19px] leading-[1.55] text-muted">{{ t('landing.hero.lead') }}</p>
                    <div class="mt-2 flex flex-wrap justify-center gap-3">
                        <a data-test="hero-cta" :href="ctaHref" :class="PRIMARY_LINK" class="px-[22px] py-3.5">
                            {{ signedIn ? t('landing.hero.ctaApp') : t('landing.hero.cta') }}
                        </a>
                        <a href="#how" class="rounded-xl border border-border-strong bg-surface px-[22px] py-[13px] text-base font-semibold text-text hover:bg-surface-2">
                            {{ t('landing.hero.secondary') }}
                        </a>
                    </div>
                </div>

                <!-- Product mock-up: the calendar's "by channel" view -->
                <div
                    data-test="hero-mockup"
                    role="img"
                    :aria-label="t('landing.mockup.label')"
                    class="mt-14 flex overflow-hidden rounded-2xl border border-border bg-surface text-left shadow-2xl"
                >
                    <div class="hidden w-[220px] flex-none flex-col gap-1 bg-nav px-3.5 py-5 text-[13px] text-nav-text md:flex">
                        <div class="px-2.5 pb-4 text-[15px] font-bold text-on-nav">{{ t('app.name') }}</div>
                        <div class="rounded-xl bg-nav-active px-2.5 py-[9px] text-on-nav">{{ t('landing.mockup.sidebar.calendar') }}</div>
                        <div class="px-2.5 py-[9px]">{{ t('landing.mockup.sidebar.channels') }}</div>
                        <div class="px-2.5 py-[9px]">{{ t('landing.mockup.sidebar.media') }}</div>
                        <div class="px-2.5 py-[9px]">{{ t('landing.mockup.sidebar.team') }}</div>
                        <div class="px-2.5 pb-1.5 pt-[18px] text-[11px] font-semibold uppercase tracking-[0.08em] text-nav-muted">{{ t('landing.mockup.sidebar.settings') }}</div>
                        <div class="px-2.5 py-[9px]">{{ t('landing.mockup.sidebar.sets') }}</div>
                        <div class="px-2.5 py-[9px]">{{ t('landing.mockup.sidebar.signatures') }}</div>
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col gap-4 px-7 py-6">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <div class="font-mono text-xs text-muted">{{ t('landing.mockup.week') }}</div>
                                <div class="mt-1 text-[22px] font-semibold tracking-[-0.01em]">{{ t('landing.mockup.sidebar.calendar') }}</div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="flex overflow-hidden rounded-xl border border-border-strong text-xs">
                                    <span class="bg-surface-2 px-2.5 py-[7px] font-semibold">{{ t('calendar.mode.channels') }}</span>
                                    <span class="border-l border-border-strong px-2.5 py-[7px] text-muted">{{ t('calendar.mode.grid') }}</span>
                                    <span class="border-l border-border-strong px-2.5 py-[7px] text-muted">{{ t('calendar.mode.list') }}</span>
                                </div>
                                <span class="rounded-xl bg-accent-soft px-3 py-[7px] text-xs font-semibold text-accent">+ {{ t('calendar.newPost') }}</span>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <div class="grid min-w-[720px] grid-cols-[150px_repeat(7,minmax(0,1fr))] overflow-hidden rounded-lg border border-border text-xs">
                                <div class="border-b border-border bg-surface-2 px-2.5 py-2" />
                                <div
                                    v-for="day in DAYS"
                                    :key="`d-${day}`"
                                    class="border-b border-l border-border p-2"
                                    :class="day === TODAY ? 'bg-primary-soft font-semibold text-primary-hover' : 'bg-surface-2 text-muted'"
                                >
                                    {{ t(`landing.mockup.days.${day}`) }} <span class="font-mono">{{ 16 + day }}</span>
                                </div>
                                <template v-for="lane in CELLS" :key="lane.channel">
                                    <div class="flex items-center gap-2 p-2.5" :class="lane.last ? '' : 'border-b border-border'">
                                        <span class="flex h-6 w-6 flex-none items-center justify-center rounded bg-accent-soft text-[10px] font-bold text-accent">
                                            {{ t(`landing.mockup.networkShort.${lane.network}`) }}
                                        </span>
                                        <span class="truncate font-medium">{{ t(`landing.mockup.channels.${lane.channel}`) }}</span>
                                    </div>
                                    <div v-for="cell in lane.cells" :key="`${lane.channel}-${cell.day}`" class="border-l border-border p-1.5" :class="lane.last ? '' : 'border-b'">
                                        <div v-if="cell.post" class="rounded-xl border p-1.5" :class="CARD[cell.post.state]">
                                            <div class="font-mono text-[11px]" :class="TAG[cell.post.state] ? 'text-muted' : ''">{{ cell.post.time }}</div>
                                            <span v-if="TAG[cell.post.state]" class="rounded px-1 py-px text-[11px]" :class="TAG[cell.post.state]">
                                                {{ t(`landing.mockup.states.${cell.post.state}`) }}
                                            </span>
                                            <template v-else>{{ t(`landing.mockup.states.${cell.post.state}`) }}</template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section id="features" class="border-y border-border bg-surface">
                <div :class="CONTAINER" class="py-[88px]">
                    <p :class="EYEBROW">{{ t('landing.features.eyebrow') }}</p>
                    <h2 :class="H2" class="max-w-[680px]">{{ t('landing.features.title') }}</h2>
                    <div class="mt-12 grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-4">
                        <div v-for="feature in FEATURES" :key="feature.key" class="flex flex-col gap-2.5 rounded-lg border border-border p-6">
                            <!-- eslint-disable-next-line vue/no-v-html -- static icon markup from FEATURES above -->
                            <svg class="h-6 w-6 text-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="feature.svg" />
                            <h3 class="text-[17px] font-semibold">{{ t(`landing.features.${feature.key}.title`) }}</h3>
                            <p :class="BODY">{{ t(`landing.features.${feature.key}.body`) }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section id="how" :class="CONTAINER" class="py-[88px]">
                <p :class="EYEBROW">{{ t('landing.how.eyebrow') }}</p>
                <h2 :class="H2">{{ t('landing.how.title') }}</h2>
                <ol class="mt-12 grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-8">
                    <li v-for="(step, index) in STEPS" :key="step" class="flex flex-col gap-3 border-t-2 border-text pt-5">
                        <span class="font-mono text-[13px] text-muted">{{ String(index + 1).padStart(2, '0') }}</span>
                        <h3 class="text-xl font-semibold">{{ t(`landing.how.${step}.title`) }}</h3>
                        <p :class="BODY">{{ t(`landing.how.${step}.body`) }}</p>
                    </li>
                </ol>
            </section>

            <!-- Statuses -->
            <section id="statuses" class="border-y border-border bg-surface">
                <div :class="CONTAINER" class="flex flex-wrap items-center gap-10 py-[72px]">
                    <div class="flex-[1_1_380px]">
                        <p :class="EYEBROW">{{ t('landing.statuses.eyebrow') }}</p>
                        <h2 class="mb-3 mt-2 text-[length:clamp(26px,3vw,34px)] font-bold leading-[1.2] tracking-[-0.02em]">{{ t('landing.statuses.title') }}</h2>
                        <p class="text-base leading-[1.6] text-muted">{{ t('landing.statuses.body') }}</p>
                    </div>
                    <ul class="flex flex-[1_1_420px] flex-wrap gap-2.5">
                        <li v-for="state in STATES" :key="state" class="rounded px-3 text-sm font-medium" :class="PILL[state]">
                            {{ t(`calendar.state.${state}`) }}
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Teams -->
            <section id="teams" :class="CONTAINER" class="flex flex-wrap gap-12 py-[88px]">
                <div class="flex-[1_1_360px]">
                    <p :class="EYEBROW">{{ t('landing.teams.eyebrow') }}</p>
                    <h2 :class="H2">{{ t('landing.teams.title') }}</h2>
                </div>
                <div class="grid flex-[1_1_480px] grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-x-8 gap-y-7">
                    <div v-for="point in TEAM_POINTS" :key="point">
                        <h3 class="mb-1.5 text-base font-semibold">{{ t(`landing.teams.${point}.title`) }}</h3>
                        <p :class="BODY">{{ t(`landing.teams.${point}.body`) }}</p>
                    </div>
                </div>
            </section>

            <!-- Closing call to action -->
            <section class="bg-nav">
                <div :class="CONTAINER" class="flex flex-wrap items-center justify-between gap-8 py-20">
                    <div class="flex-[1_1_480px]">
                        <h2 class="text-[length:clamp(28px,3.4vw,40px)] font-bold leading-[1.15] tracking-[-0.02em] text-on-nav">{{ t('landing.cta.title') }}</h2>
                        <p class="mt-3 text-[17px] leading-[1.55] text-nav-text">{{ t('landing.cta.body') }}</p>
                    </div>
                    <a data-test="final-cta" :href="ctaHref" :class="PRIMARY_LINK" class="px-[26px] py-[15px] focus-visible:ring-offset-nav">
                        {{ signedIn ? t('landing.cta.buttonApp') : t('landing.cta.button') }}
                    </a>
                </div>
            </section>
        </main>

        <footer class="border-t border-nav-border bg-nav text-[13px] text-nav-muted">
            <div :class="CONTAINER" class="py-7">
                {{ t('landing.footer.copyright', { year }) }}
            </div>
        </footer>
    </div>
</template>
