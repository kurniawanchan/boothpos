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

/** Total selisih harga dari seluruh pilihan (angka); dipakai untuk menampilkan harga berjalan. */
export function selectionDelta(groups, selection) {
  let delta = 0;
  for (const g of groups) {
    for (const o of g.options) {
      if ((selection[g.id] ?? []).includes(o.id)) delta += Number(o.price_delta) || 0;
    }
  }
  return delta;
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
