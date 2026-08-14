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
