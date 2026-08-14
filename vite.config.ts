import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        // Docker runs this with `--host 0.0.0.0` so the dev server accepts
        // connections from outside the container (the browser reaches it
        // through docker/compose.yaml's `5173:5173` port mapping to the
        // host machine). Without `origin`, laravel-vite-plugin writes
        // whatever address Vite bound to into `public/hot` — `0.0.0.0`
        // with `--host 0.0.0.0`, or worse `[::]` with bare `--host` — and
        // browsers refuse to navigate to either. `origin` pins the URL
        // written there (and used for HMR/asset requests) to the address
        // the host machine's browser actually uses.
        origin: 'http://localhost:5173',
        // Since Vite 6.0.9 the dev server only sends CORS headers for the
        // `origin` above, so the app served from APP_URL (port 8080) gets
        // its `app.ts` / `@vite/client` requests blocked. Allow it explicitly.
        cors: {
            origin: ['http://localhost:8080', 'http://127.0.0.1:8080'],
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
