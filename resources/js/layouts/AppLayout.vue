<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import ThemeToggle from '../components/ui/ThemeToggle.vue';
import OrganizationSwitcher from '../components/OrganizationSwitcher.vue';
import LimitToast from '../components/ui/LimitToast.vue';
import ImpersonationBanner from '../components/admin/ImpersonationBanner.vue';
import MonitoringLinks from '../components/admin/MonitoringLinks.vue';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const route = useRoute();

/**
 * One set of groups, rendered twice: the `md`-and-up sidebar and the
 * below-`md` menu. Final review, Important 11 — the sidebar is
 * `hidden … md:flex`, so on a phone the mobile menu is the only way to
 * reach another page. Sharing the source keeps the two from drifting the
 * next time a destination is added.
 *
 * «Кобальт» splits the list into the daily destinations, the settings
 * group under its own heading, and — for a platform admin only — the
 * instance group. The flattened order is unchanged, so every existing
 * "reachable from both menus" assertion still reads the same list.
 */
const MAIN_ITEMS = [
    { name: 'home', label: 'calendar.title', icon: 'M3 5h18v16H3zM3 10h18M8 3v4M16 3v4' },
    { name: 'channels', label: 'nav.channels', icon: 'M6 14.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM18 8.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM18 20.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM8.2 11l7.6-4M8.2 13l7.6 4' },
    { name: 'media', label: 'nav.media', icon: 'M3 4h18v16H3zM21 16l-5-5-9 9M9 12a2 2 0 100-4 2 2 0 000 4z' },
    { name: 'workspaces', label: 'nav.workspaces', icon: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z' },
] as const;

const SETTINGS_ITEMS = [
    { name: 'settings-profile', label: 'nav.settingsProfile' },
    { name: 'settings-team', label: 'nav.settingsTeam' },
    { name: 'settings-sets', label: 'nav.settingsSets' },
    { name: 'settings-signatures', label: 'nav.settingsSignatures' },
] as const;

const ADMIN_ITEMS = [{ name: 'admin-users', label: 'nav.admin' }] as const;

const adminItems = computed(() => (auth.isSuperadmin ? ADMIN_ITEMS : []));

const NAV_LINK_CLASS =
    'flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm text-nav-text hover:bg-nav-active hover:text-on-nav focus:outline-none focus-visible:ring-2 focus-visible:ring-nav-muted';
/**
 * The active item is the dark navigation's own highlight, not
 * --primary-soft: on the Cobalt menu the blue stays reserved for the
 * page's one primary action (color.md §3.1), so "where am I" and "do
 * this" no longer compete for the same colour.
 */
const NAV_ACTIVE_CLASS = 'bg-nav-active text-on-nav font-medium';
const GROUP_HEADING_CLASS = 'mt-5 mb-1 px-2.5 text-[11px] font-semibold uppercase tracking-wider text-nav-muted';

function isActive(name: string): boolean {
    return route.meta?.navSection === name;
}

const mobileNavOpen = ref(false);

/** Initials for the avatar in the user block — two letters, from the name's first two words. */
const initials = computed(() =>
    (auth.user?.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join(''),
);
</script>

<template>
    <!--
        An outer column so the impersonation banner can span the full
        width above both the sidebar and the content.
    -->
    <div class="flex min-h-screen flex-col bg-bg text-text">
        <!-- Global "plan limit reached" warning (Task 36) — one instance
             for the whole authenticated app, not per-page; see
             LimitToast.vue's docblock. -->
        <LimitToast />

        <!-- Task 1.7: one instance for the whole authenticated app — there
             is no App.vue (app.ts does `createApp(RouterView)`). -->
        <ImpersonationBanner />

        <div class="flex flex-1">
            <!--
                «Кобальт»: the sidebar carries everything a desktop needs —
                brand, workspace switcher, destinations and the signed-in
                user with theme and logout — so there is no top bar above
                the page on md and up.
            -->
            <aside class="sticky top-0 hidden h-screen w-60 shrink-0 overflow-y-auto bg-nav px-3.5 py-5 text-nav-text md:flex md:flex-col md:gap-0.5">
                <div class="mb-4 flex items-center gap-2.5 px-2.5">
                    <span class="flex h-7 w-7 items-center justify-center rounded bg-on-nav text-xs font-bold text-nav" aria-hidden="true">EP</span>
                    <span class="text-[15px] font-semibold text-on-nav">{{ t('app.name') }}</span>
                </div>
                <div v-if="auth.user" class="mb-4">
                    <OrganizationSwitcher tone="nav" />
                </div>
                <router-link
                    v-for="item in MAIN_ITEMS"
                    :key="item.name"
                    :to="{ name: item.name }"
                    :class="[NAV_LINK_CLASS, isActive(item.name) ? NAV_ACTIVE_CLASS : '']"
                    :active-class="NAV_ACTIVE_CLASS"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="item.icon" /></svg>
                    {{ t(item.label) }}
                </router-link>
                <div data-test="nav-group-settings" class="flex flex-col gap-0.5">
                    <p :class="GROUP_HEADING_CLASS">{{ t('nav.settings') }}</p>
                    <router-link
                        v-for="item in SETTINGS_ITEMS"
                        :key="item.name"
                        :to="{ name: item.name }"
                        :class="[NAV_LINK_CLASS, isActive(item.name) ? NAV_ACTIVE_CLASS : '']"
                        :active-class="NAV_ACTIVE_CLASS"
                    >
                        {{ t(item.label) }}
                    </router-link>
                </div>
                <div v-if="auth.isSuperadmin" class="flex flex-col gap-0.5">
                    <p :class="GROUP_HEADING_CLASS">{{ t('nav.instance') }}</p>
                    <router-link
                        v-for="item in adminItems"
                        :key="item.name"
                        :to="{ name: item.name }"
                        :class="[NAV_LINK_CLASS, isActive(item.name) ? NAV_ACTIVE_CLASS : '']"
                        :active-class="NAV_ACTIVE_CLASS"
                    >
                        {{ t(item.label) }}
                    </router-link>
                    <MonitoringLinks :link-class="NAV_LINK_CLASS" />
                </div>
                <div v-if="auth.user" class="mt-auto flex items-center gap-2.5 border-t border-nav-border px-2.5 pt-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-on-primary" aria-hidden="true">{{ initials }}</span>
                    <span data-test="current-user" class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-[13px] text-on-nav">{{ auth.user.name }}</span>
                        <span class="truncate text-xs text-nav-muted">{{ auth.user.email }}</span>
                    </span>
                    <ThemeToggle tone="nav" />
                    <button
                        data-test="logout"
                        type="button"
                        class="rounded-xl p-2 text-nav-muted hover:bg-nav-active hover:text-on-nav focus:outline-none focus-visible:ring-2 focus-visible:ring-nav-muted"
                        :aria-label="t('nav.logout')"
                        :title="t('nav.logout')"
                        @click="auth.logout()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10" /></svg>
                    </button>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="flex items-center gap-3 bg-nav px-4 py-3 text-on-nav md:hidden">
                    <button
                        data-test="mobile-nav-toggle"
                        type="button"
                        class="-ml-1 shrink-0 rounded-xl p-1.5 text-on-nav hover:bg-nav-active focus:outline-none focus-visible:ring-2 focus-visible:ring-nav-muted md:hidden"
                        :aria-expanded="mobileNavOpen ? 'true' : 'false'"
                        aria-controls="app-mobile-nav"
                        :aria-label="t('nav.menu')"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    <span class="text-[15px] font-semibold">{{ t('app.name') }}</span>
                </header>

                <!--
                    `v-show`, not `v-if`: the toggle's `aria-controls` above
                    has to name an element that exists in both states.
                -->
                <nav
                    v-show="mobileNavOpen"
                    id="app-mobile-nav"
                    data-test="mobile-nav"
                    class="flex flex-col gap-0.5 bg-nav p-4 text-nav-text md:hidden"
                    :aria-label="t('nav.menu')"
                >
                    <div v-if="auth.user" class="mb-3">
                        <OrganizationSwitcher tone="nav" input-id="organization-switcher-mobile" />
                    </div>
                    <router-link
                        v-for="item in MAIN_ITEMS"
                        :key="item.name"
                        :to="{ name: item.name }"
                        :class="[NAV_LINK_CLASS, isActive(item.name) ? NAV_ACTIVE_CLASS : '']"
                        :active-class="NAV_ACTIVE_CLASS" @click="mobileNavOpen = false"
                    >
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="item.icon" /></svg>
                        {{ t(item.label) }}
                    </router-link>
                    <div data-test="nav-group-settings" class="flex flex-col gap-0.5">
                        <p :class="GROUP_HEADING_CLASS">{{ t('nav.settings') }}</p>
                        <router-link
                            v-for="item in SETTINGS_ITEMS"
                            :key="item.name"
                            :to="{ name: item.name }"
                            :class="[NAV_LINK_CLASS, isActive(item.name) ? NAV_ACTIVE_CLASS : '']"
                            :active-class="NAV_ACTIVE_CLASS" @click="mobileNavOpen = false"
                        >
                            {{ t(item.label) }}
                        </router-link>
                    </div>
                    <div v-if="auth.isSuperadmin" class="flex flex-col gap-0.5">
                        <p :class="GROUP_HEADING_CLASS">{{ t('nav.instance') }}</p>
                        <router-link
                            v-for="item in adminItems"
                            :key="item.name"
                            :to="{ name: item.name }"
                            :class="[NAV_LINK_CLASS, isActive(item.name) ? NAV_ACTIVE_CLASS : '']"
                            :active-class="NAV_ACTIVE_CLASS" @click="mobileNavOpen = false"
                        >
                            {{ t(item.label) }}
                        </router-link>
                        <MonitoringLinks :link-class="NAV_LINK_CLASS" />
                    </div>
                    <div v-if="auth.user" class="mt-3 flex items-center gap-2.5 border-t border-nav-border px-2.5 pt-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-on-primary" aria-hidden="true">{{ initials }}</span>
                    <span data-test="current-user" class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-[13px] text-on-nav">{{ auth.user.name }}</span>
                        <span class="truncate text-xs text-nav-muted">{{ auth.user.email }}</span>
                    </span>
                    <ThemeToggle tone="nav" />
                    <button
                        data-test="logout"
                        type="button"
                        class="rounded-xl p-2 text-nav-muted hover:bg-nav-active hover:text-on-nav focus:outline-none focus-visible:ring-2 focus-visible:ring-nav-muted"
                        :aria-label="t('nav.logout')"
                        :title="t('nav.logout')"
                        @click="auth.logout()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10" /></svg>
                    </button>
                </div>
                </nav>

                <main class="flex-1 px-4 py-6 md:px-8 md:py-7">
                    <slot>
                        <p class="text-sm text-muted">{{ t('shell.comingSoon') }}</p>
                    </slot>
                </main>
            </div>
        </div>
    </div>
</template>
