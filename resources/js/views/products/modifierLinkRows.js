// 043 — logika baris "tautkan grup modifier" saat item BARU dibuat (formulir Menu item). Aturannya mencerminkan
// ModifierLinksDrawer (0 ≤ min ≤ max; maks kosong = tanpa batas, atau ≥ 1; grup pilihan tunggal tertahan di maks 1)
// karena server yang sama memvalidasi `PUT /products/{id}/modifier-links`. Server tetap pemutus akhir.

export const blankLinkRow = () => ({ group_id: '', is_required: false, min: '', max: '' });

const isWhole = (s) => /^\d+$/.test(String(s).trim());

/** Baris baru yang nilainya diturunkan dari aturan grupnya sendiri (grup tunggal: selalu 0/1–1). */
export function rowFromGroup(group) {
  const single = group.selection_type === 'single';
  const required = !!group.is_required;
  return {
    group_id: group.id,
    is_required: required,
    min: single ? (required ? '1' : '0') : String(group.min_select ?? 0),
    max: single ? '1' : group.max_select === null || group.max_select === undefined ? '' : String(group.max_select),
  };
}

/** Menandai wajib mengangkat minimum ke ≥ 1 — angka di layar sama dengan yang berlaku di server. */
export function applyRequired(row, value) {
  row.is_required = value;
  if (value && (row.min === '' || Number(row.min) < 1)) row.min = '1';
}

/** Baris yang benar-benar dipilih grupnya (baris kosong pembuka tabel diabaikan, bukan galat). */
export const chosenRows = (rows) => rows.filter((r) => r.group_id !== '' && r.group_id !== null && r.group_id !== undefined);

/**
 * @returns {Record<number, {min?: string, max?: string}>} galat per INDEKS di `rows` (kosong = valid).
 * `messages` = { min, max, single } teks terjemahan, `isSingle(groupId)` dari daftar grup.
 */
export function validateRows(rows, isSingle, messages) {
  const errors = {};
  rows.forEach((r, i) => {
    if (r.group_id === '' || r.group_id === null || r.group_id === undefined) return;
    const e = {};
    const minText = String(r.min).trim() === '' ? '0' : r.min;
    const minOk = isWhole(minText);
    const min = minOk ? Number(minText) : NaN;
    const maxEmpty = String(r.max).trim() === '';
    const maxOk = maxEmpty || (isWhole(r.max) && Number(r.max) >= 1);
    if (!minOk) e.min = messages.min;
    if (!maxOk || (!maxEmpty && minOk && Number(r.max) < min)) e.max = messages.max;
    if (isSingle(r.group_id) && !e.min && !e.max && (min > 1 || maxEmpty || Number(r.max) > 1)) e.max = messages.single;
    if (e.min || e.max) errors[i] = e;
  });
  return errors;
}

/** Badan `PUT /products/{id}/modifier-links`; urutan baris = `display_order`. */
export function toLinksPayload(rows) {
  return chosenRows(rows).map((r, i) => ({
    group_id: r.group_id,
    is_required: r.is_required,
    min_select: String(r.min).trim() === '' ? 0 : Number(r.min),
    max_select: String(r.max).trim() === '' ? null : Number(r.max),
    display_order: i + 1,
  }));
}
