import axios, { isAxiosError } from 'axios';
import { showLimitToast } from '../stores/limitToast';
import { i18n } from '../i18n';

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
// failure: a global toast surfaces an i18n message built from the body
// (color.md §1's `--warning`, via LimitToast.vue/Toast.vue's
// `variant="warning"` — never `--danger`, which color.md reserves for
// "this failed and nothing was saved").
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

// Round 1 review, Important 3: the project's i18n constraint (`ru` + `en`
// in resources/js/i18n) applies to every user-facing string, and the 402
// body's `message` is server-built English only — passing it straight to
// the toast, as this interceptor used to, left it untranslated regardless
// of the viewer's locale. `limits.*` in en.json/ru.json is keyed off the
// body's own `limit` field (`channels` / `posts_per_month` /
// `media_storage_mb`) and interpolates `{current}`/`{max}` exactly like
// PlanLimitExceededException::describe() does server-side. The server's
// `message` stays as the fallback ONLY when `limit` names a key this
// frontend doesn't (yet) recognise — `i18n.global.te()` decides that,
// rather than a bare object-key lookup, so a translation that resolves to
// vue-i18n's own "missing key" placeholder never slips through as if it
// were real copy.
const LIMIT_I18N_KEYS: Record<string, string> = {
    channels: 'limits.channels',
    posts_per_month: 'limits.postsPerMonth',
    media_storage_mb: 'limits.mediaStorageMb',
};

interface PlanLimitBody {
    message?: unknown;
    limit?: unknown;
    current?: unknown;
    max?: unknown;
}

function planLimitToastMessage(data: PlanLimitBody | undefined): string {
    const key = typeof data?.limit === 'string' ? LIMIT_I18N_KEYS[data.limit] : undefined;

    if (key && typeof data?.current === 'number' && typeof data?.max === 'number' && i18n.global.te(key)) {
        return i18n.global.t(key, { current: data.current, max: data.max });
    }

    return typeof data?.message === 'string' ? data.message : 'This organization has reached a plan limit.';
}

api.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (isAxiosError(error) && error.response?.status === 402) {
            showLimitToast(planLimitToastMessage(error.response.data as PlanLimitBody | undefined));
        }

        return Promise.reject(error);
    },
);
