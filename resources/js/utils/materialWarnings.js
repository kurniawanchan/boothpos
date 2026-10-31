/**
 * 087 — peringatan "bahan menjadi minus" dari respons penyelesaian pesanan (POST /orders, pelunasan tab, split).
 * Penjualan TIDAK ditolak karena stok bahan (keputusan pemilik), jadi ini peringatan, bukan galat: kasir diberi
 * tahu supaya pemilik bisa stok opname / membeli bahan. Respons tanpa kekurangan tidak membawa bidang ini.
 */

const num = (s) => Number(s).toLocaleString('id-ID', { maximumFractionDigits: 3 });

/** Teks per peringatan, mis. "Telur: stok 1 butir, dibutuhkan 2, kini −1". */
export function materialWarningMessages(response, t) {
  return (response?.material_warnings ?? []).map((w) =>
    // Saldo bahan per outlet: nama outlet mencegah salah baca "stok 0" saat outlet lain masih punya stok
    // (laporan pemilik 2026-10-31). Respons tanpa `outlet` (server lama) memakai teks lama.
    t(w.outlet ? 'pos.material_negative_warning_at' : 'pos.material_negative_warning', {
      material: w.material,
      outlet: w.outlet,
      before: num(w.stock_before),
      required: num(w.required),
      after: num(w.stock_after).replace('-', '−'),
      unit: w.unit,
    }),
  );
}

/** Tampilkan tiap peringatan sebagai toast warning (lebih lama dari toast biasa supaya sempat terbaca). */
export function toastMaterialWarnings(response, t, toast) {
  for (const message of materialWarningMessages(response, t)) {
    toast.warning(message, { timeout: 9000 });
  }
}
