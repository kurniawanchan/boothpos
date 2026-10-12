import { computed, getCurrentInstance, onMounted, ref } from 'vue';
import { pollAvailability } from '../api/menu';
import { usePolling } from './usePolling';

/**
 * 043 (US3, research D6) — ketersediaan menu di layar pemesanan: POS dan tab lantai memanggil
 * GET /menu/availability tiap ~5 detik supaya item yang ditandai 86 (atau didiskontinu) langsung redup dan
 * tak bisa ditambahkan, tanpa menunggu muat ulang halaman.
 *
 * Dibangun di atas usePolling (028) agar perilaku lantai/dapur sama: berhenti saat tab tersembunyi dan langsung
 * mengambil lagi saat tampil, tak pernah dua permintaan bersamaan, jeda membesar saat galat, berhenti otomatis saat
 * komponen dibuang. Tambahan di sini:
 *  - `since=<etag terakhir>` dikirim agar server menjawab 304 (pollAvailability memetakannya ke { notModified });
 *    setelah galat atau tab kembali tampil (`full`) etag dibuang dan keadaan penuh diambil ulang;
 *  - SATU poll yang gagal TIDAK menghapus keadaan terakhir: layar tetap menampilkan item 86 yang terakhir diketahui,
 *    daripada tiba-tiba menawarkan semuanya lagi;
 *  - ini hanya kenyamanan. Penjaganya tetap server (409 item_unavailable saat order), jadi tidak ada keputusan
 *    keamanan di sini.
 *
 * `isUnavailable(entry)` menerima kartu/entri yang membawa `product_id` (kartu POS/tab) atau `variant_id` (hasil
 * pencarian); `id` polos sengaja TIDAK ditebak karena bisa id produk atau id varian — pakai
 * `isProductUnavailable(id)` / `isVariantUnavailable(id)` untuk itu.
 *
 * 045 Stage 4: `pos: true` (POS dan tab) meminta tanda 86 menurut outlet SHIFT pengguna, bukan pilihan header.
 *
 * @param {{ interval?: number, autoStart?: boolean, pos?: boolean }} [opts]
 */
export function useMenuAvailability({ interval = 5000, autoStart = true, pos = false } = {}) {
  const etag = ref(null);
  const menuVersion = ref(0);
  const soldOutProductIds = ref([]);
  const soldOutVariantIds = ref([]);
  const discontinuedIds = ref([]);
  const loaded = ref(false);

  const soldOutProducts = computed(() => new Set(soldOutProductIds.value));
  const soldOutVariants = computed(() => new Set(soldOutVariantIds.value));
  const discontinued = computed(() => new Set(discontinuedIds.value));

  async function fetchOnce({ full = false } = {}) {
    const since = full ? null : etag.value;
    const res = await (pos ? pollAvailability(since, { pos: true }) : pollAvailability(since));
    if (res.notModified) return;
    etag.value = res.etag ?? null;
    menuVersion.value = res.menu_version ?? 0;
    const marks = res.sold_out ?? [];
    soldOutProductIds.value = marks.map((m) => m.product_id);
    soldOutVariantIds.value = marks.flatMap((m) => m.variant_ids ?? []);
    discontinuedIds.value = res.discontinued_ids ?? [];
    loaded.value = true;
  }

  // jitter 0: ritme 5 dtk yang dijanjikan dialog 86 tidak boleh melar.
  const polling = usePolling(fetchOnce, { interval, jitter: 0 });

  const isProductUnavailable = (id) => id != null && (soldOutProducts.value.has(id) || discontinued.value.has(id));
  const isVariantUnavailable = (id) => id != null && soldOutVariants.value.has(id);

  /** 'sold_out' | 'discontinued' | null — untuk memilih label/pesan toast. */
  function unavailableReason(entry) {
    if (!entry) return null;
    if (entry.product_id != null) {
      if (discontinued.value.has(entry.product_id)) return 'discontinued';
      if (soldOutProducts.value.has(entry.product_id)) return 'sold_out';
    }
    if (entry.variant_id != null && soldOutVariants.value.has(entry.variant_id)) return 'sold_out';
    return null;
  }
  const isUnavailable = (entry) => unavailableReason(entry) !== null;

  if (autoStart && getCurrentInstance()) onMounted(() => polling.start());

  return {
    soldOutProductIds,
    soldOutVariantIds,
    discontinuedIds,
    menuVersion,
    loaded,
    isUnavailable,
    isProductUnavailable,
    isVariantUnavailable,
    unavailableReason,
    start: polling.start,
    stop: polling.stop,
    refresh: polling.refresh,
  };
}
