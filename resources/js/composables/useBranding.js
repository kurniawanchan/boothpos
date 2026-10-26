import { computed, watch } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useSettingsStore } from '../stores/settings';
import { DEFAULT_APP_NAME } from '../utils/appName';
import { logoToFavicon } from '../utils/logoFavicon';

// 076-settlement-tab-session-branding (US4) — satu-satunya tempat judul tab
// dan ikon tab ditentukan. Dipasang SEKALI di App.vue supaya berlaku di setiap
// rute, termasuk layar login.
//
// Layar login tidak punya sesi, jadi pengaturan toko belum tersedia: di sana
// judul/ikon tetap bawaan (nama aplikasi bawaan, favicon.ico). Begitu login,
// judul = "<nama toko> · <nama aplikasi>" dan ikon = logo toko; mengubahnya di
// Pengaturan langsung terlihat tanpa muat ulang.
export const DEFAULT_FAVICON = '/favicon.ico';

export function buildTitle(storeName, appName) {
  const store = (storeName ?? '').trim();
  return store ? `${store} · ${appName}` : appName;
}

function applyFavicon(href) {
  let link = document.querySelector('link[rel~="icon"]');
  if (!link) {
    link = document.createElement('link');
    link.rel = 'icon';
    document.head.appendChild(link);
  }
  link.type = href.startsWith('data:image/png') ? 'image/png' : '';
  link.href = href;
}

export function useBranding() {
  const auth = useAuthStore();
  const settings = useSettingsStore();

  const identity = computed(() => {
    if (!auth.isAuthenticated) return { store: '', app: DEFAULT_APP_NAME, logo: null };
    return { store: settings.storeName, app: settings.appName, logo: settings.storeLogoUrl };
  });

  watch(
    () => buildTitle(identity.value.store, identity.value.app),
    (title) => {
      document.title = title;
    },
    { immediate: true },
  );

  // Pemuatan logo asinkron: hasil yang datang terlambat dari logo sebelumnya dibuang.
  let sequence = 0;
  watch(
    () => identity.value.logo,
    async (logo) => {
      const mine = ++sequence;
      if (!logo) {
        applyFavicon(DEFAULT_FAVICON);
        return;
      }
      const href = await logoToFavicon(logo);
      if (mine === sequence) applyFavicon(href ?? DEFAULT_FAVICON);
    },
    { immediate: true },
  );
}
