import { defineStore } from 'pinia';
import { listStations } from '../api/stations';
import { getStationTickets, setTicketItemStatus } from '../api/kitchen';

/**
 * 028 US4 — keadaan layar dapur/bar. Server satu-satunya sumber kebenaran; token
 * `version` mencegah render ulang saat polling tidak menemukan perubahan.
 */
export const useKitchenStore = defineStore('kitchen', {
  state: () => ({
    stations: [],
    stationId: null,
    tickets: [],
    version: null,
    loaded: false,
  }),

  getters: {
    // Tiket yang masih punya item belum selesai vs. yang seluruhnya sudah selesai/batal (riwayat singkat).
    activeTickets: (state) => state.tickets.filter((t) => t.items.some((i) => ['new', 'preparing', 'ready'].includes(i.status))),
    recentTickets: (state) => state.tickets.filter((t) => t.items.every((i) => ['served', 'cancelled'].includes(i.status))),
  },

  actions: {
    async loadStations() {
      this.stations = (await listStations({ active: 1 })).data;
      if (!this.stations.some((s) => s.id === this.stationId)) this.stationId = this.stations[0]?.id ?? null;
    },

    /** Ganti stasiun: buang token & data lama supaya layar tak menampilkan tiket stasiun sebelumnya. */
    selectStation(id) {
      if (id === this.stationId) return;
      this.stationId = id;
      this.tickets = [];
      this.version = null;
      this.loaded = false;
    },

    /** @param {{ full?: boolean }} [opts] full=true membuang token (setelah galat / tab kembali tampil) */
    async fetch({ full = false } = {}) {
      if (this.stationId == null) return;
      const requested = this.stationId;
      const data = await getStationTickets(requested, full ? null : this.version);
      if (requested !== this.stationId) return; // stasiun berganti selagi menunggu → abaikan
      if (data.unchanged) {
        this.version = data.version;
        return;
      }
      this.tickets = data.tickets ?? [];
      this.version = data.version;
      this.loaded = true;
    },

    /** Ubah status satu item lalu ambil keadaan terbaru (server yang memutuskan). */
    async setStatus(item, status) {
      await setTicketItemStatus(item.id, status);
      await this.fetch({ full: true });
    },
  },
});
