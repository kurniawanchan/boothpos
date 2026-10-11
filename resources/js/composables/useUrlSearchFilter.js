import { ref, watch } from 'vue';
import { useQueryState } from './useQueryState';
import { useDebouncedFn } from './useDebouncedFn';

/**
 * 041-back-office-ui-redesign (US2, kelompok billing-admin) — pencarian sisi-server yang dicerminkan ke
 * address bar (?search=…) untuk daftar berpaginasi Company / Lisensi / Jenis Bisnis. Semantiknya sama dengan
 * toolbar lama CompaniesView: ketikan di-debounce 300 ms lalu menjadi parameter `search` ke API
 * (kosong = parameter dihilangkan, halaman kembali ke 1 lewat setFilter).
 *
 * Satu jalur saja: ketikan -> URL -> watcher -> daftar. Dengan begitu tombol back/forward dan tautan yang
 * dibagikan memuat ulang daftar lewat jalur yang sama, tanpa dua sumber kebenaran.
 *
 * Pemakaian (urutan penting: parameter awal harus tersedia sebelum daftar dibuat):
 *   const search = useUrlSearchFilter();
 *   const list = usePaginatedList(fetcher, search.initialParams());
 *   search.onCommitted((v) => list.setFilter({ search: v || undefined }));
 */
export function useUrlSearchFilter(delay = 300) {
  const qs = useQueryState({ search: '' });
  const input = ref(qs.state.search);
  const commit = useDebouncedFn(() => qs.set({ search: input.value }), delay);

  function onInput(value) {
    input.value = value;
    commit();
  }

  function initialParams() {
    return qs.state.search ? { search: qs.state.search } : {};
  }

  function onCommitted(callback) {
    watch(
      () => qs.state.search,
      (value) => {
        input.value = value; // URL berubah dari luar (back/forward): kotak cari ikut.
        callback(value);
      }
    );
  }

  function reset() {
    input.value = '';
    return qs.reset();
  }

  return { input, committed: () => qs.state.search, onInput, initialParams, onCommitted, reset };
}
