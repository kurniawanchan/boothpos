/**
 * 043 (T033) — teks ringkas item daftar Produk, satu tempat untuk kartu DAN tabel supaya keduanya tak berselisih.
 * `item` = baris GET /products ({ variant_count, variants_summary, modifier_summary, category, station }).
 * `t` = fungsi terjemahan vue-i18n. Teks "Tanpa varian" dibuat di sini (bukan dipercaya dari server) agar ikut bahasa
 * pengguna.
 */

/** Daftar nama varian ("Panas / Es") atau '' bila tidak ada. */
function variantNames(item) {
  return (item.variant_count ?? 0) > 0 ? String(item.variants_summary ?? '').trim() : '';
}

function modifierNames(item) {
  return (item.modifier_summary ?? []).join(', ');
}

/**
 * Satu baris redup pada kartu (desain): "Varian Panas / Es", atau nama modifier ("Gula, Ekstra shot"),
 * atau "Tanpa varian". Varian lebih dulu karena yang mengubah SKU/harga.
 */
export function cardLine(item, t) {
  const variants = (item.variant_count ?? 0) > 1 ? variantNames(item) : '';
  if (variants) return t('products.variants_prefix', { names: variants });
  const mods = modifierNames(item);
  if (mods) return mods;
  return t('products.no_variants');
}

/** Kolom "Varian · modifier" tabel: keduanya bila ada; "Tanpa varian" bila tak satu pun. */
export function variantModifierText(item, t) {
  const parts = [variantNames(item), modifierNames(item)].filter(Boolean);
  return parts.length ? parts.join(' · ') : t('products.no_variants');
}

/** "Kategori · Station" di bawah nama item pada tabel; bagian yang kosong dilewati. */
export function categoryStationText(item) {
  return [item.category?.name, item.station?.name].filter(Boolean).join(' · ');
}
