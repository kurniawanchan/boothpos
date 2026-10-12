import { defineStore } from 'pinia';
import * as authApi from '../api/auth';
import { getToken, setToken, clearToken } from '../utils/storage';
import { i18n, loadLocale } from '../i18n';
import { clearOutletSelection } from '../utils/outletSelection';

// Satu-satunya tempat locale FRONTEND diputuskan dari data user (cermin
// dari SetLocaleFromUser di backend) — dipanggil setiap kali `user`
// berubah lewat login/restore/me, supaya tidak ada jalur kedua yang
// menyetel i18n.global.locale secara independen (Constitution Principle I).
// 043 — async: pesan bahasa dimuat DULU (chunk terpisah) baru locale dipindah, supaya layar tidak sempat
// menampilkan teks fallback. Pemanggil menunggunya SEBELUM menyimpan `user`.
async function syncLocale(user) {
  if (user?.language) {
    await loadLocale(user.language);
    i18n.global.locale.value = user.language;
  }
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    ready: false,
  }),
  getters: {
    isAuthenticated: (state) => !!state.user,
    role: (state) => state.user?.role ?? null,
    /**
     * Cosmetic-only mirror of User::canAccessMenu() server-side — every
     * real enforcement decision still happens on the backend (router
     * guard here just avoids flashing a screen the API would 403 on).
     * Reads menu_keys resolved once at login/GET /auth/me, never
     * re-derived from a hardcoded role list (that's the whole point of
     * this feature — see specs/001-user-store-settings).
     */
    canAccessMenu: (state) => (menuKey) => (state.user?.menu_keys ?? []).includes(menuKey),
    /**
     * 043 — cermin kosmetik hak aksi (mis. 'menu.item.86') dari `user.permissions` yang dikirim login/me.
     * Hanya untuk menyembunyikan kendali; server tetap yang menolak. Tanpa daftar (peran lama / respons lama) = false,
     * jadi kendali tersembunyi, bukan terbuka. Halaman item lebih mengandalkan objek `can` dari GET /products/{id}.
     */
    hasPermission: (state) => (key) => (state.user?.permissions ?? []).includes(key),
  },
  actions: {
    async login(username, password) {
      const { token, user } = await authApi.login(username, password);
      setToken(token);
      await syncLocale(user);
      this.user = user;
    },
    async setLanguage(language) {
      const updated = await authApi.updateLanguage(language);
      await syncLocale(updated);
      this.user = updated;
    },
    // 005-ux-enhancements-dashboard (US3) — dipakai ProfileView setelah
    // ganti password/foto supaya avatar di header (UserMenu) langsung
    // ikut berubah tanpa perlu reload penuh.
    async changePassword(payload) {
      return authApi.updatePassword(payload);
    },
    async changePhoto(file) {
      const updated = await authApi.updatePhoto(file);
      this.user = updated;
      return updated;
    },
    async logout() {
      try {
        await authApi.logout();
      } catch {
        // Token may already be invalid/expired server-side — clear local
        // state regardless, logout must always succeed from the user's POV.
      }
      clearToken();
      clearOutletSelection();
      this.user = null;
    },
    /**
     * Restores a sessionStorage-backed session. Called eagerly from
     * main.js on boot AND defensively from the router guard (see
     * router/index.js) — Vue Router can resolve its very first navigation
     * before an awaited call in main.js finishes, which would otherwise
     * bounce an already-logged-in user to /login on every hard refresh.
     * Cached so concurrent callers share one in-flight /auth/me request
     * instead of firing it twice.
     */
    restore() {
      if (this.ready) return Promise.resolve();
      if (this._restorePromise) return this._restorePromise;

      this._restorePromise = (async () => {
        if (!getToken()) {
          this.ready = true;
          return;
        }
        try {
          const me = await authApi.me();
          await syncLocale(me);
          this.user = me;
        } catch {
          clearToken();
          this.user = null;
        } finally {
          this.ready = true;
        }
      })();

      return this._restorePromise;
    },
  },
});
