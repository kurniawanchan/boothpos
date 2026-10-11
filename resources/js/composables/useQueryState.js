import { reactive, toValue, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * 041-back-office-ui-redesign — menyalin keadaan daftar (tab, filter, urutan, halaman) ke address bar
 * supaya tampilan bisa dibagikan/di-refresh/di-back tanpa kehilangan filter. Generalisasi pola yang
 * sudah ada di useSalesFilters (?cashier=2&sort=total_amount…) untuk semua halaman yang memakai kit.
 *
 * Aturan (sama dengan useSalesFilters supaya halaman lama dan baru berperilaku seragam):
 *  - hanya nilai yang BEDA dari bawaan yang masuk URL, sehingga URL tetap bersih;
 *  - nilai tak dikenal/tak valid pada URL diabaikan (jatuh ke bawaan), tidak pernah fatal;
 *  - kunci query lain yang bukan milik daftar ini dibiarkan apa adanya.
 *
 * Tipe nilai ditentukan dari bawaan: string, number, boolean ('1'), atau array (dipisah koma).
 *
 * @template {Record<string, string|number|boolean|string[]>} T
 * @param {T} defaults
 * @param {{route?: import('vue-router').RouteLocationNormalizedLoaded|import('vue').Ref<import('vue-router').RouteLocationNormalizedLoaded>, router?: import('vue-router').Router}} [deps]
 */
export function useQueryState(defaults, deps = {}) {
  // `route` boleh objek reaktif (useRoute()) ATAU ref (router.currentRoute) — keduanya dibaca lewat toValue.
  const route = deps.route ?? useRoute();
  const router = deps.router ?? useRouter();
  const currentQuery = () => toValue(route).query;
  const keys = Object.keys(defaults);

  const first = (v) => (Array.isArray(v) ? v[0] : v);

  function parse(key, raw) {
    const def = defaults[key];
    const value = first(raw);
    if (value === undefined || value === null || value === '') return Array.isArray(def) ? [...def] : def;
    if (Array.isArray(def)) {
      return String(value)
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    }
    if (typeof def === 'number') {
      const n = Number(value);
      return Number.isFinite(n) ? n : def;
    }
    if (typeof def === 'boolean') return value === '1' || value === 'true';
    return String(value);
  }

  function serialize(key, value) {
    const def = defaults[key];
    if (Array.isArray(def)) {
      const same = value.length === def.length && value.every((v, i) => v === def[i]);
      return same || value.length === 0 ? undefined : value.join(',');
    }
    if (value === def || value === '' || value === null || value === undefined) return undefined;
    if (typeof def === 'boolean') return value ? '1' : undefined;
    return String(value);
  }

  const state = reactive(Object.fromEntries(keys.map((k) => [k, parse(k, currentQuery()[k])])));

  function readFromRoute() {
    for (const k of keys) state[k] = parse(k, currentQuery()[k]);
  }

  function pushToRoute() {
    const query = { ...currentQuery() };
    for (const k of keys) {
      const out = serialize(k, state[k]);
      if (out === undefined) delete query[k];
      else query[k] = out;
    }
    return router.replace({ query });
  }

  /** Terapkan sebagian nilai lalu sinkronkan ke URL. Mengembalikan promise navigasi (boleh di-await). */
  function set(patch) {
    Object.assign(state, patch);
    return pushToRoute();
  }

  /** Kembalikan semua nilai ke bawaan. */
  function reset() {
    for (const k of keys) state[k] = Array.isArray(defaults[k]) ? [...defaults[k]] : defaults[k];
    return pushToRoute();
  }

  // Tombol back/forward atau tautan yang dibagikan mengubah URL dari luar.
  watch(currentQuery, () => readFromRoute());

  return { state, set, reset };
}
