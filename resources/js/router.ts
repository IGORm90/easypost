import { createRouter, createWebHistory } from 'vue-router';
import AppLayout from './layouts/AppLayout.vue';
import Login from './pages/Auth/Login.vue';
import Register from './pages/Auth/Register.vue';
import Forgot from './pages/Auth/Forgot.vue';
import Reset from './pages/Auth/Reset.vue';
import Profile from './pages/Settings/Profile.vue';
import Team from './pages/Settings/Team.vue';
import AcceptInvite from './pages/Invites/Accept.vue';
import { useAuthStore } from './stores/auth';
import { useOrganizationStore } from './stores/organization';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', name: 'login', component: Login, meta: { requiresAuth: false } },
        { path: '/register', name: 'register', component: Register, meta: { requiresAuth: false } },
        { path: '/forgot-password', name: 'forgot-password', component: Forgot, meta: { requiresAuth: false } },
        { path: '/reset-password', name: 'reset-password', component: Reset, meta: { requiresAuth: false } },
        { path: '/', name: 'home', component: AppLayout, meta: { requiresAuth: true } },
        { path: '/settings/profile', name: 'settings-profile', component: Profile, meta: { requiresAuth: true } },
        { path: '/settings/team', name: 'settings-team', component: Team, meta: { requiresAuth: true } },
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
    // instead of seeing the auth forms again.
    if (!to.meta.requiresAuth && auth.user) {
        return { name: 'home' };
    }

    return true;
});

export default router;
