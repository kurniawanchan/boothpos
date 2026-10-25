import { computed, ref, watch } from 'vue';
import { useOutletStore } from '../stores/outlet';

/**
 * 071 — filter Outlet sebuah halaman (Restaurant Setup). Terisi dari outlet yang dipilih di bilah atas dan MENGIKUTI saat
 * bilah atas berganti, tetapi tidak dibatasi olehnya: pengguna boleh memilih outlet lain atau "Semua outlet" langsung dari
 * halaman (daftar diminta tanpa header outlet — lihat `skipOutletHeader` di api/client.js). Hanya outlet yang boleh dilihat
 * pengguna (`outletStore.outlets`) yang berlaku; server tetap memotong ulang. Keadaan hidup di memori (halaman bertab ini
 * tidak menyimpan tab di URL), jadi "semua" yang disengaja tidak perlu penanda khusus seperti di halaman Produk.
 *
 * `ids` = outlet yang disaring ([] = semua). `active` = berbeda dari titik awal (bilah atas, atau semua).
 */
export function useOutletPageFilter() {
  const store = useOutletStore();
  const startingPoint = () => (store.selected != null ? [store.selected] : []);
  const picked = ref(startingPoint());

  watch(() => store.selected, () => {
    picked.value = startingPoint();
  });

  const visible = computed(() => store.multi === true);
  const options = computed(() => store.outlets.map((o) => ({ value: o.id, label: o.name })));
  const ids = computed(() => (visible.value ? picked.value.filter((id) => store.outlets.some((o) => o.id === id)) : []));
  const active = computed(() => ids.value.join(',') !== startingPoint().join(','));

  const set = (next) => {
    picked.value = next.map(Number);
  };
  const reset = () => {
    picked.value = startingPoint();
  };

  return { ids, visible, options, active, set, reset };
}
