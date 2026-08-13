import axios from 'axios';

export const api = axios.create({ baseURL: '/api', withCredentials: true });

let csrfReady: Promise<unknown> | null = null;

api.interceptors.request.use(async (config) => {
    if (['post', 'put', 'patch', 'delete'].includes(config.method ?? '')) {
        csrfReady ??= axios.get('/sanctum/csrf-cookie');
        await csrfReady;
    }

    const org = localStorage.getItem('organization_id');
    if (org) {
        config.headers['X-Organization'] = org;
    }

    return config;
});
