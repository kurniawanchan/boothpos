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
export function isSelectionValid(groups, selection, quantities = {}) {
  return groups.every((g) => {
    const [min, max] = groupRange(g);
    const picked = selection[g.id] ?? [];
    // 2026-11-02 — MINIMUM dihitung dalam satuan jumlah (SOTO ×2 memenuhi "pilih 2"); MAKSIMUM tetap menghitung pilihan
    // berbeda. Sama dengan ModifierResolver::resolve() di server.
    return groupUnits(picked, quantities) >= min && (max === null || picked.length <= max);
  });
}

/** Total satuan jumlah pilihan terpilih di satu grup (tanpa entri jumlah = 1). */
export function groupUnits(pickedIds, quantities = {}) {
  return (pickedIds ?? []).reduce((sum, id) => sum + Math.max(1, Number(quantities[id] ?? 1)), 0);
}

/**
 * 2026-11-02 — grup berganda yang sudah penuh PILIHAN BERBEDA-nya (maks > 1): semua jumlah kembali ke minimum dan pemilih
 * jumlah serta pilihan lain dikunci sampai satu pilihan dilepas. Grup tunggal (maks 1) tak pernah terkunci.
 */
export function isGroupLocked(group, pickedIds) {
  const [, max] = groupRange(group);
  return group.selection_type !== 'single' && max !== null && max > 1 && (pickedIds ?? []).length >= max;
}

/** Pilihan yang BELUM terpilih tak bisa ditambah: kuota pilihan penuh, atau jumlah total sudah di batas "pilih n". */
export function isGroupFull(group, pickedIds, quantities = {}) {
  const [, max] = groupRange(group);
  if (max === null) return false;
  return (pickedIds ?? []).length >= max || (max > 1 && groupUnits(pickedIds, quantities) >= max);
}

/** Jumlah sebuah pilihan boleh naik: grup tak terkunci, di bawah batas pilihan itu, dan total di bawah "pilih n". */
export function canIncreaseQty(group, option, pickedIds, quantities = {}) {
  if (isGroupLocked(group, pickedIds)) return false;
  const [, max] = groupRange(group);
  if (max !== null && max > 1 && groupUnits(pickedIds, quantities) >= max) return false;
  return Math.max(1, Number(quantities[option.id] ?? 1)) < optionQtyCap(option);
}

/** Batas jumlah sebuah pilihan saat dipilih (089): payload lama tanpa kunci = 1..1. */
export const MAX_OPTION_QTY = 50;

/** @returns {[number, number|null]} [min, max]; max null = tanpa batas atas (dibatasi MAX_OPTION_QTY) */
export function optionQtyRange(option) {
  const min = Math.max(1, Number(option.min_qty ?? 1));
  if (option.max_qty === undefined) return [min, Math.max(min, 1)];
  return [min, option.max_qty === null ? null : Number(option.max_qty)];
}

/** Pilihan ini memunculkan pemilih jumlah (stepper) bila boleh dipesan lebih dari sekali. */
export function allowsQuantity(option) {
  const [, max] = optionQtyRange(option);
  return max === null || max > 1;
}

/** Jumlah terbesar yang boleh dipilih untuk pilihan ini (menghormati batas keras). */
export function optionQtyCap(option) {
  const [, max] = optionQtyRange(option);
  return max === null ? MAX_OPTION_QTY : Math.min(max, MAX_OPTION_QTY);
}

/**
 * Total selisih harga dari seluruh pilihan (angka); dipakai untuk menampilkan harga berjalan.
 *
 * 2026-10-31 — grup `portion_per_option` ("tiap pilihan = 1 porsi"): pilihan pertama tertutup harga dasar, tiap
 * pilihan berikutnya menambah SATU `base` lagi. Urutan = urutan opsi grup (bukan urutan klik), sama dengan
 * ModifierResolver::resolve() di server. Tanpa `base` (pemanggil lama) perilakunya tidak berubah.
 *
 * 089 — `quantities` (id opsi → jumlah, bawaan 1): kontribusi sebuah pilihan = selisih × jumlah, dan porsi dihitung
 * per SATUAN jumlah (SOTO ×2 = dua porsi): pilihan pertama menanggung `qty − 1` harga dasar ekstra, berikutnya `qty`.
 */
export function selectionDelta(groups, selection, base = 0, quantities = {}) {
  let delta = 0;
  for (const g of groups) {
    let picked = 0;
    for (const o of g.options) {
      if (!(selection[g.id] ?? []).includes(o.id)) continue;
      const qty = Math.max(1, Number(quantities[o.id] ?? 1));
      const extraPortions = g.portion_per_option ? qty - (picked === 0 ? 1 : 0) : 0;
      delta += (Number(o.price_delta) || 0) * qty + extraPortions * base;
      picked += 1;
    }
  }
  return delta;
}

/** Jumlah porsi pilihan ini: di grup porsi tiap SATUAN jumlah = satu porsi; terbesar antar grup, minimal 1. */
export function portionCount(groups, selection, quantities = {}) {
  return groups.reduce((n, g) => {
    if (!g.portion_per_option) return n;
    const units = (selection[g.id] ?? []).reduce((sum, id) => sum + Math.max(1, Number(quantities[id] ?? 1)), 0);
    return Math.max(n, units);
  }, 1);
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
 * 089 — bentuk permintaan ke server. Semua jumlah 1 → `modifier_option_ids` (jalur lama, kompatibel dengan server dan
 * tablet lama); ada jumlah ≠ 1 → `modifier_selections: [{ option_id, qty }]`. Server menolak keduanya sekaligus.
 */
export function modifierPayload(ids, quantities = {}) {
  const list = ids ?? [];
  if (!list.some((id) => Number(quantities[id] ?? 1) !== 1)) return { modifier_option_ids: list };
  return { modifier_selections: list.map((id) => ({ option_id: id, qty: Math.max(1, Number(quantities[id] ?? 1)) })) };
}

/** Teks tampilan satu pilihan: server mengirim `label` ("Telur ×2"); keranjang web menyusunnya sendiri. */
export function modifierLabel(m) {
  return m.label ?? (Number(m.qty ?? 1) > 1 ? `${m.option} ×${m.qty}` : m.option);
}

/**
 * 084 — daftar `{ group, option }` untuk opsi terpilih, dipakai keranjang dan ringkasan (hanya tampilan;
 * snapshot sebenarnya dibuat server dari id opsi).
 */
export function describeSelection(groups, ids, quantities = {}) {
  const picked = new Set(ids);
  const out = [];
  for (const g of groups) {
    for (const o of g.options) {
      if (!picked.has(o.id)) continue;
      const qty = Math.max(1, Number(quantities[o.id] ?? 1));
      out.push({ group: g.name, option: o.name, qty, label: qty > 1 ? `${o.name} ×${qty}` : o.name });
    }
  }
  return out;
}
