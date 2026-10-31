/**
 * 087 — bahan per porsi sebuah pilihan modifier.
 *
 * Server memperlakukan kunci `materials` pada sebuah pilihan sebagai PENGGANTIAN PENUH, dan ketiadaan kunci
 * sebagai "tidak berubah". Jadi klien hanya mengirim `materials` untuk pilihan baru atau pilihan yang barisnya
 * benar-benar diubah — pilihan lain dibiarkan, supaya penyimpanan nama/harga tidak menulis ulang pemetaan
 * (dan tidak membuat entri log aktivitas palsu).
 */

/** Baris yang layak dikirim: bahan terpilih dan jumlah > 0. */
export function cleanMaterialRows(rows) {
  return (rows ?? [])
    .filter((r) => r.material_id && Number(r.quantity) > 0)
    .map((r) => ({ material_id: Number(r.material_id), quantity: Number(r.quantity) }));
}

/** Bentuk pembanding: urut per bahan, jumlah dinormalkan ke 4 desimal (presisi server). */
function signature(rows) {
  return JSON.stringify(
    cleanMaterialRows(rows)
      .map((r) => [r.material_id, r.quantity.toFixed(4)])
      .sort((a, b) => a[0] - b[0]),
  );
}

export function materialsChanged(original, current) {
  return signature(original) !== signature(current);
}

/**
 * Potongan payload untuk satu pilihan: `{ materials: [...] }` bila perlu dikirim, `{}` bila tidak.
 * `original` = baris saat formulir dibuka (undefined untuk pilihan baru).
 */
export function materialsPayload(original, current) {
  if (original === undefined) {
    const rows = cleanMaterialRows(current);
    return rows.length ? { materials: rows } : {};
  }
  return materialsChanged(original, current) ? { materials: cleanMaterialRows(current) } : {};
}

/** Ringkasan untuk tabel/kepala baris: "2 bahan" atau "". */
export function materialsSummary(rows, t) {
  const n = (rows ?? []).length;
  return n ? t('dining_setup.option_materials_count', { n }) : '';
}
