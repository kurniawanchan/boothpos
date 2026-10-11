import { defineStore } from 'pinia';
import { getFloor } from '../api/floor';
import { getTab } from '../api/tabs';

/**
 * 028 — keadaan lantai restoran. Server adalah satu-satunya sumber kebenaran
 * (tidak ada perhitungan harga/total di klien). Token `version` mencegah render
 * ulang saat polling tidak menemukan perubahan.
 */
export const useFloorStore = defineStore('floor', {
  state: () => ({
    areas: [],
    takeawayTabs: [],
    version: null,
    loaded: false,
    // Tab yang sedang dibuka di layar TabView. Server-owned: seluruh isinya
    // (baris, total, saldo) diganti dengan respons terbaru setelah setiap aksi.
    tab: null,
  }),

  getters: {
    isEmpty: (state) => state.loaded && state.areas.length === 0 && state.takeawayTabs.length === 0,
  },

  actions: {
    /**
     * @param {{ full?: boolean }} [opts] full=true membuang token versi supaya
     *   server mengirim keadaan lengkap (dipakai setelah galat / tab kembali tampil).
     */
    async fetch({ full = false } = {}) {
      const data = await getFloor(full ? null : this.version);
      if (data.unchanged) {
        this.version = data.version;
        return;
      }
      this.areas = data.areas ?? [];
      this.takeawayTabs = data.takeaway_tabs ?? [];
      this.version = data.version;
      this.loaded = true;
    },

    async loadTab(id) {
      this.tab = await getTab(id);
      return this.tab;
    },

    /** Ganti tab aktif dengan respons terbaru dari endpoint tab mana pun. */
    applyTab(tab) {
      this.tab = tab;
    },

    clearTab() {
      this.tab = null;
    },
  },
});
