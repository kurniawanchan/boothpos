/**
 * Satuan bahan baku untuk pemilih di formulir Bahan (menggantikan isian bebas). Daftar ini hanya kemudahan memilih:
 * server tetap menerima teks apa pun (≤ 20 karakter), dan satuan yang sudah tersimpan di bahan lama tetap sah.
 *
 * Satuan ditulis apa adanya (tanpa terjemahan): ia disimpan sebagai teks di `materials.unit` dan dibaca resep, stok,
 * dan laporan. Nilai yang sudah dipakai data nyata ("pcs", "gram") sengaja dipertahankan, bukan diganti "gr".
 */
export const MATERIAL_UNITS = [
  // hitungan
  'pcs', 'butir', 'bungkus', 'sachet', 'porsi', 'lembar', 'ikat', 'pack', 'box', 'botol', 'kaleng',
  // berat
  'gram', 'kg',
  // volume
  'ml', 'liter',
  // panjang
  'meter',
];

/** Nilai khusus pilihan "Lainnya…": membuka isian teks untuk satuan di luar daftar. */
export const OTHER_UNIT = '__other__';

export const isStandardUnit = (unit) => MATERIAL_UNITS.includes(String(unit ?? '').trim());

/**
 * Opsi untuk BaseSelect. `otherLabel` = teks pilihan "Lainnya…". Satuan bahan lama yang di luar daftar TIDAK masuk
 * daftar ini: formulir menampilkannya lewat mode "Lainnya" (isian teks berisi nilai itu), sehingga tidak hilang
 * diam-diam dan tidak ada opsi basi.
 */
export function unitOptions(otherLabel) {
  return [...MATERIAL_UNITS.map((u) => ({ value: u, label: u })), { value: OTHER_UNIT, label: otherLabel }];
}

/** Mode isian teks bebas dipakai bila ada nilai yang bukan satuan standar (bahan lama bersatuan khusus). */
export const needsCustomUnit = (unit) => {
  const u = String(unit ?? '').trim();
  return u !== '' && !MATERIAL_UNITS.includes(u);
};
