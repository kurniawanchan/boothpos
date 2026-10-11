import { formatIDR } from './formatters';

/**
 * 043 (US2) — pembantu murni halaman item (GET /products/{id}). Dipisah dari komponen supaya aturan teksnya
 * (rentang harga, aturan modifier) punya SATU definisi yang bisa diuji tanpa memasang halaman.
 *
 * Bentuk varian dari server memakai nama kolom lama (`variant_name`, `sell_price`); kontrak menulisnya `name` /
 * `base_price`. Keduanya diterima di sini supaya perubahan penamaan di server tidak mengosongkan halaman diam-diam.
 */
export const variantName = (v) => v.variant_name ?? v.name ?? '';
export const variantPrice = (v) => v.sell_price ?? v.base_price ?? null;

/** Rentang harga dasar varian AKTIF ("Rp 8.000" atau "Rp 8.000 – Rp 10.000"); '—' bila tak ada harga. */
export function basePriceText(variants) {
  const prices = (variants ?? [])
    .filter((v) => v.is_active !== false)
    .map(variantPrice)
    .filter((p) => p !== null && p !== undefined)
    .map(Number)
    .filter(Number.isFinite);
  if (!prices.length) return '—';
  const min = Math.min(...prices);
  const max = Math.max(...prices);
  return min === max ? formatIDR(min) : `${formatIDR(min)} – ${formatIDR(max)}`;
}

/**
 * Aturan satu tautan modifier: "Opsional · 0–1" / "Wajib · 1–2". Batas atas kosong (null) = tanpa batas -> "1+".
 * Nilai yang dipakai adalah aturan EFEKTIF yang sudah diselesaikan server (tautan menimpa grup).
 */
export function modifierRuleText(link, t) {
  const kind = link.is_required ? t('products.rule_required') : t('products.rule_optional');
  const min = link.min_select ?? 0;
  const range = link.max_select === null || link.max_select === undefined ? `${min}+` : `${min}–${link.max_select}`;
  return `${kind} · ${range}`;
}

/** Ringkasan BOM lama: jumlah varian ber-BOM dan jumlah biaya BOM-nya (hanya bila server mengirim has_bom/bom_cost). */
export function bomSummary(variants) {
  const withBom = (variants ?? []).filter((v) => v.has_bom);
  if (!withBom.length) return null;
  const costs = withBom.map((v) => Number(v.bom_cost)).filter(Number.isFinite);
  return { count: withBom.length, cost: costs.length ? Math.min(...costs) : null, costMax: costs.length ? Math.max(...costs) : null };
}
