import { defineStore } from 'pinia';
import { getScope } from '../api/me';
import {
  activateOutletSelection,
  clearOutletSelection,
  selectedOutletId,
  setSelectedOutlet,
} from '../utils/outletSelection';

/**
 * 045 Stage 2 — keadaan cakupan outlet di SPA. Server tetap satu-satunya sumber izin: `outlets` berasal
 * dari GET /me/scope, dan pemilihan hanya dikirim sebagai X-Outlet-Id yang disempitkan server. Kendali
 * pemilihan tampil hanya bila `multi` (lebih dari satu outlet yang terlihat) — di satu outlet ia
 * disembunyikan, bukan dinonaktifkan.
 */
export const useOutletStore = defineStore('outlet', {
  state: () => ({
    outlets: [],
    selected: null,
    canSeeAll: false,
    multi: false,
    loaded: false,
  }),
  actions: {
    /** Memuat cakupan pengguna dan memulihkan pilihan tersimpannya bila outlet itu masih boleh dilihat. */
    async load(userId) {
      activateOutletSelection(userId);
      const scope = await getScope();
      this.outlets = scope.outlets ?? [];
      this.canSeeAll = scope.can_see_all === true;
      this.multi = scope.multi === true;

      const stored = selectedOutletId();
      const stillPermitted = stored != null && this.outlets.some((outlet) => outlet.id === stored);
      if (stored != null && !stillPermitted) {
        // Outlet yang dulu dipilih sudah dicabut dari penugasan: jangan kirim header yang pasti ditolak.
        setSelectedOutlet(null);
      }
      this.selected = stillPermitted ? stored : null;
      this.loaded = true;
    },

    /** `null` = semua outlet yang boleh dilihat. */
    select(outletId) {
      const next = outletId ?? null;
      setSelectedOutlet(next);
      this.selected = next;
    },

    /** Keluar dari sesi: lepaskan pilihan dari memori; pilihan tersimpan per pengguna tetap utuh. */
    reset() {
      clearOutletSelection();
      this.$reset();
    },
  },
});
