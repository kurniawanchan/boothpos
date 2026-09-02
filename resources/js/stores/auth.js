import { defineStore } from 'pinia';
import * as authApi from '../api/auth';
import { getToken, setToken, clearToken } from '../utils/storage';
import { i18n } from '../i18n';

// Satu-satunya tempat locale FRONTEND diputuskan dari data user (cermin
// dari SetLocaleFromUser di backend) — dipanggil setiap kali `user`
// berubah lewat login/restore/me, supaya tidak ada jalur kedua yang
// menyetel i18n.global.locale secara independen (Constitution Principle I).
function syncLocale(user) {
  if (user?.language) {
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
  },
  actions: {
    async login(username, password) {
      const { token, user } = await authApi.login(username, password);
      setToken(token);
      this.user = user;
      syncLocale(user);
    },
    async setLanguage(language) {
      const updated = await authApi.updateLanguage(language);
      this.user = updated;
      syncLocale(updated);
    },
    async logout() {
      try {
        await authApi.logout();
      } catch {
        // Token may already be invalid/expired server-side — clear local
        // state regardless, logout must always succeed from the user's POV.
      }
      clearToken();
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
          this.user = await authApi.me();
          syncLocale(this.user);
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
