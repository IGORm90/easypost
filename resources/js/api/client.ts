import axios, { isAxiosError } from 'axios';
import { showLimitToast } from '../stores/limitToast';

export const api = axios.create({ baseURL: '/api', withCredentials: true });

let csrfReady: Promise<unknown> | null = null;

api.interceptors.request.use(async (config) => {
    if (['post', 'put', 'patch', 'delete'].includes(config.method ?? '')) {
        csrfReady ??= axios.get('/sanctum/csrf-cookie');

        try {
            await csrfReady;
        } catch (error) {
            // Don't let a failed priming request (network blip, 5xx) poison
            // every write request that follows: drop the memoised promise so
            // the next request retries priming from scratch. The request
            // that hit the failure still rejects — only the *next* one gets
            // a fresh chance.
            csrfReady = null;
            throw error;
        }
    }

    const org = localStorage.getItem('organization_id');
    if (org) {
        config.headers['X-Organization'] = org;
    }

    return config;
});

// Task 36: a 402 means "this organization's plan has no room for that" —
// EnforcePlanLimit's fast pre-check or one of the withLimit()-guarded
// creates rejected the request; see PlanLimitExceededException's
// docblock for the {message, limit, current, max} body shape. This is a
// warning ("approaching/at your limit"), never treated as a generic
// failure: a global toast surfaces the server's own already-actionable
// `message` (color.md §1's `--warning`, via LimitToast.vue/Toast.vue's
// `variant="warning"` — never re-worded here, and never `--danger`,
// which color.md reserves for "this failed and nothing was saved").
//
// The error is still rejected, unchanged, after showing the toast: every
// caller that has its own status-specific handling (a 409 re-target, a
// 422 field-error map, ...) keeps working exactly as it did before this
// interceptor existed. This is deliberately the ONLY thing this
// interceptor does for a 402 — it never swallows the rejection or
// replaces it with some other error shape, precisely so a caller
// checking `error.response?.status` still sees the real 402, not
// something this interceptor turned into an "unknown error" by losing
// the original response along the way.
api.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (isAxiosError(error) && error.response?.status === 402) {
            const message = error.response.data?.message;
            showLimitToast(typeof message === 'string' ? message : 'This organization has reached a plan limit.');
        }

        return Promise.reject(error);
    },
);
