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
import { useLicenseStore } from './stores/license';
import { startSessionSync } from './utils/tabSession';
import { i18n } from './i18n';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.use(i18n);

// Restore the session (if any) from sessionStorage before the first route
// resolves, so navigation guards see accurate auth state on a hard refresh.
const auth = useAuthStore();
// 076 (US1) — jawab permintaan token dari tab baru yang dibuka dari tab ini, dan
// ikut berakhir bila tab lain logout. Dipasang SEBELUM restore() supaya dua tab
// yang dibuka bersamaan tidak saling menunggu sampai timeout.
startSessionSync(() => {
  auth.endSessionFromAnotherTab();
  // Hard redirect, sama seperti penanganan 401 di api/client.js: state bersih total.
  if (!window.location.pathname.startsWith('/login')) {
    window.location.href = '/login';
  }
});
// 018-license-activation — checked alongside auth, before the app
// mounts, for the same reason: without this, the router's first
// navigation could resolve before the license status is known.
const license = useLicenseStore();
Promise.all([auth.restore(), license.restore()]).finally(() => {
  app.mount('#app');
});
