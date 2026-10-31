/**
 * 087 US6 — sumber stok produk: `variant` (stok di varian, perilaku lama) atau `modifier` (varian tidak punya stok;
 * yang berkurang adalah bahan dari pilihan modifier). Server satu-satunya penentu; di sini hanya TAMPILAN.
 *
 * `stock_tracked` (payload pengelolaan) = pelacakan efektif. Payload POS sudah mengirim `track_stock` efektif, jadi
 * kartu POS/Tab tidak membutuhkan helper ini.
 */
export const STOCK_SOURCES = ['variant', 'modifier'];

/** Varian ini punya angka stok sendiri? Payload lama tanpa `stock_tracked` jatuh ke `track_stock`. */
export function variantHasOwnStock(variant) {
  if (variant?.stock_tracked !== undefined) return variant.stock_tracked !== false;
  return variant?.track_stock !== false;
}

/** Teks stok untuk tabel/detail: angka, atau "—" bila stok tidak dilacak di varian. */
export function stockText(variant) {
  return variantHasOwnStock(variant) ? String(Number(variant?.current_stock ?? 0)) : '—';
}

/** Kode peringatan dari respons simpan produk → kunci terjemahan. */
export function stockSourceWarningKeys(response) {
  return (response?.warnings ?? []).map((w) => `products.stock_source_warning_${w.code}`);
}
