import axios from 'axios';

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
