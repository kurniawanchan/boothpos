/**
 * 093 — bahan resep bisa berupa BAHAN BAKU (`material_id`) atau VARIAN PRODUK (`variant_id`). Satu pemilih (BaseSelect)
 * memuat keduanya, jadi nilai opsi memakai kunci berawalan: `m12` = bahan baku 12, `v34` = varian produk 34.
 * Aturan sebenarnya (boleh/tidak, bulat/tidak) ada di server (RecipeService::validate); fungsi di sini hanya
 * pratinjau supaya pengguna tak perlu menunggu 422 untuk kesalahan yang jelas.
 */

/** Kunci pemilih sebuah baris, atau '' bila belum memilih. */
export function ingredientKey(line) {
  if (line?.variant_id) return `v${line.variant_id}`;
  if (line?.material_id) return `m${line.material_id}`;

  return '';
}

/** @returns {{ type: 'material'|'product', id: number } | null} */
export function parseIngredientKey(key) {
  const m = /^([mv])(\d+)$/.exec(String(key ?? ''));
  if (!m) return null;

  return { type: m[1] === 'm' ? 'material' : 'product', id: Number(m[2]) };
}

/** Teks opsi produk: nama · SKU · satuan · stok tersedia (angka tersedia hanya bila satu outlet berlaku). */
export function productOptionLabel(p, t) {
  const name = p.variant_name && p.variant_name.toLowerCase() !== 'standard' ? `${p.product_name} — ${p.variant_name}` : p.product_name;
  const stock = p.available === null || p.available === undefined ? '' : ` · ${t('products.rc_product_available', { qty: p.available, unit: p.unit })}`;

  return `${name} · ${p.sku}${stock}`;
}

/**
 * Kebutuhan per porsi harus bilangan bulat ≥ 1 (stok varian bilangan bulat) — cermin RecipeService::productLineErrors.
 * `quantity` dan `portions` boleh string dari isian.
 */
export function isWholePerPortion(quantity, portions) {
  const q = Number(quantity);
  const y = Number(portions);
  if (!Number.isFinite(q) || !Number.isFinite(y) || y <= 0) return false;
  const per = q / y;

  return per >= 1 - 1e-6 && Math.abs(per - Math.round(per)) <= 1e-6;
}

/** Baris resep dari server → baris isian. Baris produk tidak punya susut. */
export function lineFromServer(l, nextKey) {
  const product = l.variant_id !== null && l.variant_id !== undefined;

  return {
    key: nextKey(),
    material_id: product ? null : l.material_id,
    variant_id: product ? l.variant_id : null,
    name: l.name ?? '',
    unit: l.unit ?? '',
    sku: l.sku ?? '',
    // false = penanda "Gunakan sebagai bahan" sudah dimatikan sesudah resep dibuat (resep tetap berlaku).
    flagged: product ? l.ingredient_flagged !== false : true,
    quantity: String(Number(l.quantity)),
    shrinkage: product ? '0' : String(Number(l.shrinkage_pct ?? 0)),
  };
}

/** Muatan satu baris untuk API: tepat satu dari material_id / variant_id. */
export function linePayload(l) {
  const quantity = Number(l.quantity);
  if (l.variant_id) return { variant_id: l.variant_id, quantity, shrinkage_pct: 0 };

  return { material_id: l.material_id, quantity, shrinkage_pct: l.shrinkage === '' ? 0 : Number(l.shrinkage) };
}
