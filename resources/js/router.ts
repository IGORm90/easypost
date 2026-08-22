import { createRouter, createWebHistory } from 'vue-router';
import AppLayout from './layouts/AppLayout.vue';
import Login from './pages/Auth/Login.vue';
import Register from './pages/Auth/Register.vue';
import Forgot from './pages/Auth/Forgot.vue';
import Reset from './pages/Auth/Reset.vue';
import Profile from './pages/Settings/Profile.vue';
import Team from './pages/Settings/Team.vue';
import Sets from './pages/Settings/Sets.vue';
import AcceptInvite from './pages/Invites/Accept.vue';
import Channels from './pages/Channels/Index.vue';
import Media from './pages/Media/Index.vue';
import Calendar from './pages/Calendar/Index.vue';
import IntegrationsCallback from './pages/Integrations/Callback.vue';
import SharedPost from './pages/Public/SharedPost.vue';
import { useAuthStore } from './stores/auth';
import { useOrganizationStore } from './stores/organization';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', name: 'login', component: Login, meta: { requiresAuth: false, guestOnly: true } },
        { path: '/register', name: 'register', component: Register, meta: { requiresAuth: false, guestOnly: true } },
        { path: '/forgot-password', name: 'forgot-password', component: Forgot, meta: { requiresAuth: false, guestOnly: true } },
        { path: '/reset-password', name: 'reset-password', component: Reset, meta: { requiresAuth: false, guestOnly: true } },
        // Task 22's public share page: no session required, and — unlike
        // the guest-only auth pages above — a signed-in member must NOT
        // be bounced away from it either (a teammate opening their own
        // share link is a completely normal case). See the guard below:
        // it only redirects away routes with `guestOnly`, which this
        // route deliberately does not set.
        { path: '/share/:token', name: 'shared-post', component: SharedPost, meta: { requiresAuth: false } },
        // Task 24: the calendar is the app's day-to-day home screen, not
        // the placeholder AppLayout shell the root path rendered before
        // it existed.
        { path: '/', name: 'home', component: Calendar, meta: { requiresAuth: true } },
        { path: '/channels', name: 'channels', component: Channels, meta: { requiresAuth: true } },
        // Task 29: the media library page — also reachable as the
        // picker's "manage everything" companion via the sidebar link.
        { path: '/media', name: 'media', component: Media, meta: { requiresAuth: true } },
        // `INTEGRATIONS_REDIRECT_URL` (config/services.php) points every
        // OAuth provider's redirect at this exact path — see
        // pages/Integrations/Callback.vue's docblock. requiresAuth: true
        // is correct, not incidental: connecting a channel only ever
        // starts from an already-authenticated session (auth-url is
        // gated by auth:sanctum + role:ADMIN), so the callback always
        // arrives with that same session's cookie still valid.
        { path: '/integrations/callback', name: 'integrations-callback', component: IntegrationsCallback, meta: { requiresAuth: true } },
        { path: '/settings/profile', name: 'settings-profile', component: Profile, meta: { requiresAuth: true } },
        { path: '/settings/team', name: 'settings-team', component: Team, meta: { requiresAuth: true } },
        // Task 1.4: channel sets were creatable only through the API
        // until this screen existed — the editor could apply them but
        // never make one.
        { path: '/settings/sets', name: 'settings-sets', component: Sets, meta: { requiresAuth: true } },
        { path: '/invites/:token', name: 'invites-accept', component: AcceptInvite, meta: { requiresAuth: true } },
    ],
});

/**
 * Real session state, not Task 3's `localStorage`-only placeholder: this
 * consults useAuthStore(), whose `user`/`ready` are populated by
 * GET /api/auth/me (see stores/auth.ts). `auth.ready` is fetched at most
 * once per app load — every navigation after the first reuses the
 * in-memory result instead of round-tripping to the server again — and is
 * flipped to true by fetchMe() whether the call succeeds or fails (a 401
 * is a normal "not signed in" outcome, not an error to retry on).
 */
router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.ready) {
        await auth.fetchMe();
    }

    if (auth.user) {
        useOrganizationStore().syncFromAuth();
    }

    if (to.meta.requiresAuth && !auth.user) {
        return { name: 'login' };
    }

    // Signed-in users hitting /login, /register, etc. get bounced home
    // instead of seeing the auth forms again. `guestOnly`, not merely
    // `!requiresAuth`: a public, no-auth route like /share/:token must
    // stay reachable for a signed-in member too (see that route's meta).
    if (to.meta.guestOnly && auth.user) {
        return { name: 'home' };
    }

    return true;
});

export default router;
