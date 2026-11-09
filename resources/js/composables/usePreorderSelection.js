import { computed, ref } from 'vue';
import { matchingPreorderIds } from '../api/preorders';

/**
 * 065 — seleksi baris daftar Pre-orders: per baris, "semua di halaman ini" (kotak header dengan keadaan sebagian),
 * dan "semua N yang cocok dengan filter" lintas halaman. Hanya menyimpan id (Set) — angka terpilih SELALU sama dengan
 * ukuran Set, jadi hitungan tak pernah bohong. Aksi yang sudah ada (unduh/email/duplikat) memakai Set yang sama.
 *
 * Filter/pencarian/urutan berubah → pemanggil memanggil `clear()` (hitungan yang jujur lebih penting daripada
 * kemudahan). Pindah halaman TIDAK mengosongkan: pilihan lintas halaman memang diinginkan.
 *
 * @param {{ items: import('vue').Ref<any[]>, meta: import('vue').Ref<any>, params: object, max: import('vue').Ref<number>|number }} opts
 */
export function usePreorderSelection({ items, meta, params, max }) {
  const selectedIds = ref(new Set());
  const loadingAll = ref(false);
  // Diisi saat "pilih semua yang cocok": berapa yang cocok seluruhnya dan apakah terpotong di batas.
  const matching = ref(null); // { total, capped } | null

  const limit = computed(() => (typeof max === 'object' && max !== null && 'value' in max ? max.value : max));
  const count = computed(() => selectedIds.value.size);
  const pageIds = computed(() => (items.value || []).map((r) => r.id));
  const pageSelectedCount = computed(() => pageIds.value.filter((id) => selectedIds.value.has(id)).length);
  const allOnPage = computed(() => pageIds.value.length > 0 && pageSelectedCount.value === pageIds.value.length);
  const someOnPage = computed(() => pageSelectedCount.value > 0 && !allOnPage.value);
  const total = computed(() => meta.value?.total ?? pageIds.value.length);
  const overLimit = computed(() => count.value > limit.value);

  // Tawaran "pilih semua N yang cocok": halaman penuh terpilih, masih ada yang cocok di halaman lain, dan belum semua terpilih.
  const canOfferAllMatching = computed(
    () => allOnPage.value && total.value > pageIds.value.length && count.value < total.value && matching.value === null,
  );

  function replace(ids) {
    selectedIds.value = new Set(ids);
    matching.value = null;
  }

  function toggle(id) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedIds.value = next;
    matching.value = null;
  }

  function togglePage() {
    const next = new Set(selectedIds.value);
    if (allOnPage.value) pageIds.value.forEach((id) => next.delete(id));
    else pageIds.value.forEach((id) => next.add(id));
    selectedIds.value = next;
    matching.value = null;
  }

  async function selectAllMatching() {
    loadingAll.value = true;
    try {
      const data = await matchingPreorderIds({ ...params });
      selectedIds.value = new Set(data.ids);
      matching.value = { total: data.total, capped: !!data.capped };
    } finally {
      loadingAll.value = false;
    }
  }

  function clear() {
    selectedIds.value = new Set();
    matching.value = null;
  }

  return {
    selectedIds, count, allOnPage, someOnPage, overLimit, limit, loadingAll, matching, canOfferAllMatching, total,
    toggle, togglePage, selectAllMatching, clear, replace,
  };
}
