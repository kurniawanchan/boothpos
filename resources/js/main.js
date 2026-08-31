import { createApp } from 'vue';
import { createPinia } from 'pinia';
// The mockup links unpkg's `@phosphor-icons/web@2.1.1/src/duotone/style.css`
// directly; installed as an npm dependency instead, the package's own
// `exports` map only publishes the shorthand `./duotone` subpath (the raw
// `/src/...` path 404s through Vite's resolver) — same stylesheet either way.
import '@phosphor-icons/web/duotone';
import App from './App.vue';
import router from './router';
import { useAuthStore } from './stores/auth';

const app = createApp(App);
app.use(createPinia());
app.use(router);

// Restore the session (if any) from sessionStorage before the first route
// resolves, so navigation guards see accurate auth state on a hard refresh.
const auth = useAuthStore();
auth.restore().finally(() => {
  app.mount('#app');
});
