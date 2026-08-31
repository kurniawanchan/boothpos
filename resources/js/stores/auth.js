import { defineStore } from 'pinia';
import * as authApi from '../api/auth';
import { getToken, setToken, clearToken } from '../utils/storage';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    ready: false,
  }),
  getters: {
    isAuthenticated: (state) => !!state.user,
    role: (state) => state.user?.role ?? null,
    isOwnerOrAdmin: (state) => ['owner', 'admin'].includes(state.user?.role),
    // Mirrors User::canManageMasterData() server-side (owner/admin/inventory).
    canManageMasterData: (state) => ['owner', 'admin', 'inventory'].includes(state.user?.role),
  },
  actions: {
    async login(username, password) {
      const { token, user } = await authApi.login(username, password);
      setToken(token);
      this.user = user;
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
