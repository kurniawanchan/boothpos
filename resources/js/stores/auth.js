import { defineStore } from 'pinia';
import * as authApi from '../api/auth';
import { getToken, setToken, clearToken } from '../utils/storage';
import { adoptTokenFromOtherTabs, broadcastLogout } from '../utils/tabSession';
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

// 058 — menu yang rute servernya menolak pengguna ber-seller (seller.forbidden); satu daftar, cermin server.
// 'reports' TIDAK di sini (laporan di-scope, bukan ditolak); Log Aktivitas disembunyikan lewat flag item sidebar.
export const SELLER_SCOPED_HIDDEN_MENUS = ['users', 'roles', 'settings', 'companies', 'licenses', 'invoices', 'artists', 'events', 'categories', 'vendors', 'materials'];

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
    canAccessMenu: (state) => (menuKey) => (state.user?.menu_keys ?? []).includes(menuKey)
      // 058 — pengguna ber-seller: menu administratif/lintas-seller disembunyikan (kosmetik; server menolak 403).
      && !(state.user?.seller_scoped && SELLER_SCOPED_HIDDEN_MENUS.includes(menuKey)),
    /** 058 — pengguna terikat ke satu seller (Owner selalu false; ditentukan server). */
    isSellerScoped: (state) => !!state.user?.seller_scoped,
    /** 058 — seller pengguna dinonaktifkan: layar kosong + pemberitahuan, bukan tampilan penuh. */
    sellerInactive: (state) => !!state.user?.seller_scoped && state.user?.seller_active === false,
    /**
     * 064 — punya seller yang bisa disetujui (dasar tombol "Setujui massal"). Dihitung server (satu definisi di
     * OrderApprovalService); payload tanpa bidang ini (server lama) = tak ada informasi ⇒ tampilkan, server tetap menolak.
     */
    canApproveSellers: (state) => state.user?.can_approve_sellers !== false,
    /**
     * 057 — izin AKSI per menu (kosmetik; server menegakkan). Dibaca dari `permissions` hasil GET /auth/me.
     * Payload tanpa `permissions` (server lama / sebelum fitur ini) = tak ada informasi pembatasan ⇒ tampilkan
     * (perilaku lama, semua tombol); server tetap menolak yang tak diizinkan. Layar hanya dirender untuk pengguna yang
     * sudah masuk, jadi "tanpa pengguna" diperlakukan sama (tak ada informasi pembatasan).
     */
    canDo: (state) => (menuKey, action = 'view') => {
      const perms = state.user?.permissions;
      if (!perms) return true;
      return (perms[menuKey] ?? []).includes(action);
    },
  },
  actions: {
    async login(username, password) {
      const { token, user } = await authApi.login(username, password);
      setToken(token);
      this.user = user;
      syncLocale(user);
    },
    // 057 — muat ulang izin (mis. peran diubah saat pengguna sedang masuk, atau 403 `action_not_allowed`).
    async refreshPermissions() {
      try {
        this.user = await authApi.me();
      } catch {
        // Gagal memuat ulang: biarkan izin lama; permintaan berikutnya tetap ditegakkan server.
      }
    },
    async setLanguage(language) {
      const updated = await authApi.updateLanguage(language);
      this.user = updated;
      syncLocale(updated);
    },
    // 005-ux-enhancements-dashboard (US3) — dipakai ProfileView setelah
    // ganti password/foto supaya avatar di header (AppSidebar) langsung
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
      this.user = null;
      // 076 (FR-002) — tab lain ikut berakhir; disiarkan SETELAH pembersihan
      // lokal supaya logout tidak pernah bergantung pada kanal tersedia/tidak.
      broadcastLogout();
    },
    /**
     * 076 — dipanggil ketika TAB LAIN mengakhiri sesi: hanya membersihkan
     * state tab ini. Tidak memanggil server (token sudah dicabut oleh tab
     * yang logout) dan tidak menyiarkan ulang (mencegah pantulan pesan).
     */
    endSessionFromAnotherTab() {
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
        // 076 (US1) — tab baru yang dibuka dari tab yang sedang login tidak
        // mewarisi sessionStorage; minta token ke tab lain dulu. Token yang
        // diterima tetap divalidasi lewat authApi.me() di bawah.
        if (!getToken() && !(await adoptTokenFromOtherTabs())) {
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
