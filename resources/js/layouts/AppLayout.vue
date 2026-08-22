<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ThemeToggle from '../components/ui/ThemeToggle.vue';
import OrganizationSwitcher from '../components/OrganizationSwitcher.vue';
import LimitToast from '../components/ui/LimitToast.vue';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();

/**
 * One list, rendered twice: the `md`-and-up sidebar and the below-`md`
 * menu. Final review, Important 11 — the sidebar is `hidden … md:flex`
 * and the header held only the org switcher, the theme toggle and
 * Logout, so on a phone a signed-in user could see whichever page they
 * landed on and reach no other. Sharing the source keeps the two from
 * drifting the next time a destination is added.
 */
const NAV_ITEMS = [
    { name: 'home', label: 'calendar.title' },
    { name: 'channels', label: 'nav.channels' },
    { name: 'media', label: 'nav.media' },
    { name: 'settings-profile', label: 'nav.settingsProfile' },
    { name: 'settings-team', label: 'nav.settingsTeam' },
    { name: 'settings-sets', label: 'nav.settingsSets' },
] as const;

const NAV_LINK_CLASS = 'rounded-xl px-2 py-1.5 text-sm text-muted hover:bg-surface hover:text-text';
/**
 * The active nav item is one of the two resting primaries color.md §3.1
 * allows a screen (the other is "New post"); the mobile menu reuses it
 * rather than adding a third, because only one of the two is ever on
 * screen at a time.
 */
const NAV_ACTIVE_CLASS = 'bg-primary-soft text-primary';

const mobileNavOpen = ref(false);
</script>

<template>
    <div class="flex min-h-screen bg-bg text-text">
        <!-- Global "plan limit reached" warning (Task 36) — one instance
             for the whole authenticated app, not per-page; see
             LimitToast.vue's docblock. -->
        <LimitToast />

        <!-- Sidebar sits on the third background level (color.md §3.4). -->
        <aside class="hidden w-56 shrink-0 border-r border-border bg-surface-2 p-4 md:flex md:flex-col md:gap-1">
            <p class="mb-3 text-sm font-semibold">{{ t('app.name') }}</p>
            <router-link
                v-for="item in NAV_ITEMS"
                :key="item.name"
                :to="{ name: item.name }"
                :class="NAV_LINK_CLASS"
                :active-class="NAV_ACTIVE_CLASS"
            >
                {{ t(item.label) }}
            </router-link>
        </aside>

        <div class="flex flex-1 flex-col">
            <header class="flex items-center justify-between gap-4 border-b border-border bg-surface px-4 py-3">
                <button
                    data-test="mobile-nav-toggle"
                    type="button"
                    class="-ml-1 rounded-xl p-1 text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary md:hidden"
                    :aria-expanded="mobileNavOpen ? 'true' : 'false'"
                    aria-controls="app-mobile-nav"
                    :aria-label="t('nav.menu')"
                    @click="mobileNavOpen = !mobileNavOpen"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            fill-rule="evenodd"
                            d="M3 5.75A.75.75 0 013.75 5h12.5a.75.75 0 010 1.5H3.75A.75.75 0 013 5.75zm0 4.25a.75.75 0 01.75-.75h12.5a.75.75 0 010 1.5H3.75A.75.75 0 013 10zm0 4.25a.75.75 0 01.75-.75h12.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
                <OrganizationSwitcher v-if="auth.user" />
                <div class="ml-auto flex items-center gap-3">
                    <ThemeToggle />
                    <button
                        v-if="auth.user"
                        type="button"
                        class="text-sm text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="auth.logout()"
                    >
                        {{ t('nav.logout') }}
                    </button>
                </div>
            </header>

            <!--
                `v-show`, not `v-if`: the toggle's `aria-controls` above
                has to name an element that exists in both states, or the
                reference dangles exactly when it matters — while the menu
                is collapsed and the user is deciding whether to open it.
                `display: none` keeps the links out of the a11y tree and
                the tab order just as removal did.
            -->
            <nav
                v-show="mobileNavOpen"
                id="app-mobile-nav"
                data-test="mobile-nav"
                class="flex flex-col gap-1 border-b border-border bg-surface-2 p-4 md:hidden"
                :aria-label="t('nav.menu')"
            >
                <router-link
                    v-for="item in NAV_ITEMS"
                    :key="item.name"
                    :to="{ name: item.name }"
                    :class="NAV_LINK_CLASS"
                    :active-class="NAV_ACTIVE_CLASS"
                    @click="mobileNavOpen = false"
                >
                    {{ t(item.label) }}
                </router-link>
            </nav>

            <main class="flex-1 p-4">
                <slot>
                    <p class="text-sm text-muted">{{ t('shell.comingSoon') }}</p>
                </slot>
            </main>
        </div>
    </div>
</template>
