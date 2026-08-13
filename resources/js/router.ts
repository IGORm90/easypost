import { createRouter, createWebHistory } from 'vue-router';
import AppLayout from './layouts/AppLayout.vue';
import AuthLayout from './layouts/AuthLayout.vue';

/**
 * Placeholder auth check for the `requiresAuth` guard below.
 *
 * There is no auth API yet — Sanctum login/logout lands in Task 6. Until
 * then we treat the presence of a locally-stored session marker as "signed
 * in". This is intentionally naive (no server round-trip, no expiry check)
 * and MUST be replaced with real Sanctum session state in Task 6.
 */
function isAuthenticated(): boolean {
    return localStorage.getItem('auth_session') !== null;
}

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/login',
            name: 'login',
            component: AuthLayout,
            meta: { requiresAuth: false },
        },
        {
            path: '/',
            name: 'home',
            component: AppLayout,
            meta: { requiresAuth: true },
        },
    ],
});

router.beforeEach((to) => {
    if (to.meta.requiresAuth && !isAuthenticated()) {
        return { name: 'login' };
    }

    return true;
});

export default router;
