// Groups the POS browse grid by product instead of flattening every
// variant into its own card. Split out from PosView so the grouping rule
// and the single-variant "skip the picker" shortcut are unit-testable
// without mounting the whole screen (which needs a session/category/API
// mock chain to render at all).
import { parseMoney } from './money';
import { materialPortions } from './modifierRules';

/**
 * Builds one browse card per product from `GET /products?with_variants=1`
 * results. Inactive variants are excluded from price/stock aggregation and
 * from the pickable list — the endpoint deliberately includes them (so
 * product management screens keep seeing the full set), but nothing
 * inactive should ever be sellable at the register.
 */
// 045 Stage 3 — harga yang DITAMPILKAN di kasir: harga khusus outlet bila ada (`outlet_price`), selain itu harga dasar.
// Hanya tampilan: server selalu menghitung ulang dengan harga outlet shift (PriceService::applyOutletPrice).
/** 045 Stage 5 — saldo di outlet shift bila server mengirimnya (`pos=1`), selain itu total tersimpan. */
export const onHand = (v) => v.outlet_stock ?? v.current_stock;

/**
 * BUG YANG DITEMUKAN & DIPERBAIKI (087, laporan pemilik 2026-10-31): POS ritel dulu menjumlah stok SEMUA varian dan
 * membandingkan `current_stock <= 0` tanpa pernah membaca `track_stock`. Akibatnya item bersumber stok modifier
 * (Indomie Kuah) — dan sejak 028 juga menu masak — tampil "Stok 0 / Habis" dan tidak bisa dijual, padahal server
 * mengirim `track_stock: false` (efektif) di payload POS. Aturan 028 itu dulu hanya dipasang di TabView.
 *
 * SATU aturan untuk semua kontrol POS: `stockOf` = saldo bila varian punya stok sendiri, `null` bila tidak;
 * `isOutOfStock` hanya benar untuk angka ≤ 0, tidak pernah untuk `null`.
 */
export const stockOf = (v) => (v?.track_stock === false ? null : onHand(v));

export const isOutOfStock = (stock) => stock !== null && stock !== undefined && stock <= 0;

export const shownPrice = (v) => v.outlet_price ?? v.sell_price;

export function buildProductCards(products, categoryCodeById = {}, categoryNameById = {}) {
  return (products || []).map((p) => {
    const variants = (p.variants || []).filter((v) => v.is_active);
    const prices = variants.map((v) => parseMoney(shownPrice(v)));
    // Angka stok kartu hanya bila SEMUA varian aktif punya stok sendiri; satu varian tanpa stok = kartu tanpa angka.
    const stocks = variants.map(stockOf);
    const totalStock = stocks.some((s) => s === null) ? null : stocks.reduce((sum, s) => sum + (s ?? 0), 0);
    return {
      product_id: p.id,
      name: p.name,
      home_outlet_name: p.home_outlet_name,
      category_code: categoryCodeById[p.category_id] ?? null,
      category_name: categoryNameById[p.category_id] ?? null,
      variant_count: variants.length,
      min_price: variants.length ? Math.min(...prices) : 0,
      max_price: variants.length ? Math.max(...prices) : 0,
      total_stock: totalStock,
      // 2026-10-31 (permintaan pemilik, membalik FR-031 087): produk bersumber bahan modifier menampilkan porsi yang
      // masih bisa dibuat dari bahan di outlet shift. HANYA angka: tidak pernah membuat kartu "habis", karena
      // penjualan tidak ditolak karena bahan (FR-014).
      material_portions: p.stock_source === 'modifier' ? materialPortions(p.modifier_groups) : null,
      // No sellable (active, in-stock) variant at all — the card should
      // read and behave as out-of-stock rather than opening an empty or
      // all-disabled variant picker.
      out_of_stock: variants.length === 0 || isOutOfStock(totalStock),
      variants,
      // 004-sidebar-menu-reorg (FR-006) — passed through unchanged from
      // ProductResource.image_url; null when the product has no image.
      image_url: p.image_url ?? null,
      // 084 — grup modifier efektif produk (GET /products?with_modifiers=1); kosong = tanpa pemilih.
      modifier_groups: p.modifier_groups ?? [],
    };
  });
}

/**
 * Builds a posCart-shaped item from a browse card + the variant the
 * cashier picked (or the card's only variant, for the single-variant
 * shortcut). A single-variant product keeps the bare product name; a
 * multi-variant pick disambiguates with " — <variant name>", the same
 * convention the old flattened grid used.
 */
export function toCartItem(card, variant) {
  return {
    variant_id: variant.id,
    sku: variant.sku,
    name: card.variant_count > 1 ? `${card.name} — ${variant.variant_name}` : card.name,
    home_outlet_name: card.home_outlet_name,
    sell_price: shownPrice(variant),
    current_stock: stockOf(variant), // null = tanpa stok sendiri (tanpa batas qty di keranjang)
    // Falls back to the product's own image when this variant has none of
    // its own — mirrors the same fallback used by GET /variants/lookup.
    image_url: variant.image_url ?? card.image_url ?? null,
    modifier_groups: card.modifier_groups ?? [],
  };
}

/**
 * BUG YANG DITEMUKAN & DIPERBAIKI (030-fix-pos-search-product-image) —
 * kartu hasil pencarian POS dulu dibuat inline di PosView.vue dengan
 * `map` yang menyalin hanya sebagian field, sehingga `image_url` yang SUDAH
 * dikirim GET /variants/lookup ikut terbuang: kartu menampilkan ikon
 * placeholder dan baris keranjang (kartu yang sama didorong ke posCart apa
 * adanya) juga tanpa foto. Bentuk kartu browse dan item keranjang sudah
 * tinggal di file ini; bentuk kartu pencarian ikut di sini supaya
 * satu field baru di endpoint tidak lagi hilang diam-diam di sebuah `map`
 * milik view. JANGAN kembali membuat kartu pencarian secara inline.
 *
 * `image_url` sudah di-resolve backend (foto varian sendiri, else foto
 * produk induk — ProductVariant::image_url), jadi hasil pencarian dan grid
 * browse menampilkan foto yang sama untuk item yang sama. Nama kategori juga
 * ikut (permintaan lanjutan, FR-009): endpoint sudah mengirim `category_name`
 * dan kartu browse menampilkannya, jadi kartu pencarian pun menampilkan label
 * yang sama untuk membedakan produk bernama sama. `category_code` tetap null:
 * itu hanya dipakai sebagai pengganti foto di kartu browse.
 */
export function buildSearchCards(results) {
  return (results || []).map((v) => ({
    variant_id: v.variant_id,
    sku: v.sku,
    name: v.label,
    home_outlet_name: v.home_outlet_name,
    sell_price: shownPrice(v),
    current_stock: stockOf(v), // GET /variants/lookup mengirim track_stock efektif (087)
    category_code: null,
    category_name: v.category_name ?? null,
    image_url: v.image_url ?? null,
    // 084 — dari GET /variants/lookup?with_modifiers=1 (bentuk sama dengan daftar produk).
    modifier_groups: v.modifier_groups ?? [],
  }));
}
