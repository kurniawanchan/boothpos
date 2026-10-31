/**
 * 028 US3 — aturan pilihan modifier di sisi klien. HANYA untuk UX (tombol
 * "Tambah" nonaktif sampai pilihan sah, harga berjalan): server tetap menolak
 * pelanggaran (ModifierResolver) dan tidak mempercayai hasil hitung ini.
 * Harus tetap sejalan dengan ModifierResolver::range().
 */

/** @returns {[number, number|null]} [min, max]; max null = tanpa batas atas */
export function groupRange(group) {
  if (group.selection_type === 'single') return [group.is_required ? 1 : 0, 1];
  const min = group.is_required ? Math.max(1, group.min_select ?? 0) : group.min_select ?? 0;
  return [min, group.max_select ?? null];
}

/** Teks rentang yang sama dengan pesan server: "1", "1–2", atau "≥1". */
export function describeRange([min, max]) {
  if (max === null) return `≥${min}`;
  if (min === max) return String(min);
  return `${min}–${max}`;
}

/**
 * @param {Array} groups grup efektif (dari GET /products?with_modifiers=1)
 * @param {Record<number, number[]>} selection group.id → id opsi terpilih
 */
export function isSelectionValid(groups, selection) {
  return groups.every((g) => {
    const [min, max] = groupRange(g);
    const n = (selection[g.id] ?? []).length;
    return n >= min && (max === null || n <= max);
  });
}

/**
 * Total selisih harga dari seluruh pilihan (angka); dipakai untuk menampilkan harga berjalan.
 *
 * 2026-10-31 — grup `portion_per_option` ("tiap pilihan = 1 porsi"): pilihan pertama tertutup harga dasar, tiap
 * pilihan berikutnya menambah SATU `base` lagi. Urutan = urutan opsi grup (bukan urutan klik), sama dengan
 * ModifierResolver::resolve() di server. Tanpa `base` (pemanggil lama) perilakunya tidak berubah.
 */
export function selectionDelta(groups, selection, base = 0) {
  let delta = 0;
  for (const g of groups) {
    let picked = 0;
    for (const o of g.options) {
      if (!(selection[g.id] ?? []).includes(o.id)) continue;
      delta += (Number(o.price_delta) || 0) + (g.portion_per_option && picked > 0 ? base : 0);
      picked += 1;
    }
  }
  return delta;
}

/** Jumlah porsi pilihan ini: terbesar dari banyaknya pilihan di grup porsi, minimal 1. */
export function portionCount(groups, selection) {
  return groups.reduce((n, g) => (g.portion_per_option ? Math.max(n, (selection[g.id] ?? []).length) : n), 1);
}

/**
 * 2026-10-31 (permintaan pemilik) — stok produk bersumber bahan modifier: untuk tiap grup WAJIB yang memetakan bahan,
 * jumlah porsi semua pilihannya (SOTO 17 + AYAM BAWANG 0 = 17); hasilnya grup terkecil. `null` = tidak diketahui:
 * tidak ada grup wajib berbahan, atau sebuah pilihan wajib tanpa bahan (tak terbatas). `portions` per pilihan dari
 * server (saldo outlet shift ÷ pemakaian per porsi, minus = 0).
 */
export function materialPortions(groups) {
  let result = null;
  for (const g of groups ?? []) {
    if (groupRange(g)[0] < 1 || !g.options.some((o) => (o.materials ?? []).length)) continue;
    if (g.options.some((o) => o.portions === null || o.portions === undefined)) return null;
    const sum = g.options.reduce((n, o) => n + Math.max(0, o.portions), 0);
    result = result === null ? sum : Math.min(result, sum);
  }
  return result;
}

export function selectedOptionIds(selection) {
  return Object.values(selection).flat();
}

/**
 * 084 — daftar `{ group, option }` untuk opsi terpilih, dipakai keranjang dan ringkasan (hanya tampilan;
 * snapshot sebenarnya dibuat server dari id opsi).
 */
export function describeSelection(groups, ids) {
  const picked = new Set(ids);
  const out = [];
  for (const g of groups) {
    for (const o of g.options) {
      if (picked.has(o.id)) out.push({ group: g.name, option: o.name });
    }
  }
  return out;
}
