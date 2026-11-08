/*
 * IBM Plex for the «Кобальт» theme (color.md), self-hosted via @fontsource.
 * Imported here, from JS, rather than with @import in app.css: a bare
 * package @import goes through postcss-import, which in the dev server
 * fails with "ENOENT … '@fontsource/…/400.css'" whenever it cannot resolve
 * the package (e.g. a dev server started before the package was
 * installed), while a JS import goes through Vite's own module resolver.
 */
import '@fontsource/ibm-plex-sans/400.css';
import '@fontsource/ibm-plex-sans/500.css';
import '@fontsource/ibm-plex-sans/600.css';
import '@fontsource/ibm-plex-sans/700.css';
import '@fontsource/ibm-plex-mono/500.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { RouterView } from 'vue-router';
import router from './router';
import { i18n } from './i18n';

// No separate root App.vue — the brief scopes this task to app.ts, the
// router and the two layouts, so the root is just a router-view mount.
const app = createApp(RouterView);

app.use(createPinia());
app.use(router);
app.use(i18n);

app.mount('#app');
