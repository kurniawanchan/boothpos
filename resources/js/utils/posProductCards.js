// Groups the POS browse grid by product instead of flattening every
// variant into its own card. Split out from PosView so the grouping rule
// and the single-variant "skip the picker" shortcut are unit-testable
// without mounting the whole screen (which needs a session/category/API
// mock chain to render at all).
import { parseMoney } from './money';

/**
 * Builds one browse card per product from `GET /products?with_variants=1`
 * results. Inactive variants are excluded from price/stock aggregation and
 * from the pickable list — the endpoint deliberately includes them (so
 * product management screens keep seeing the full set), but nothing
 * inactive should ever be sellable at the register.
 */
export function buildProductCards(products, categoryCodeById = {}) {
  return (products || []).map((p) => {
    const variants = (p.variants || []).filter((v) => v.is_active);
    const prices = variants.map((v) => parseMoney(v.sell_price));
    const totalStock = variants.reduce((sum, v) => sum + (v.current_stock ?? 0), 0);
    return {
      product_id: p.id,
      name: p.name,
      artist_name: p.artist_name,
      category_code: categoryCodeById[p.category_id] ?? null,
      variant_count: variants.length,
      min_price: variants.length ? Math.min(...prices) : 0,
      max_price: variants.length ? Math.max(...prices) : 0,
      total_stock: totalStock,
      // No sellable (active, in-stock) variant at all — the card should
      // read and behave as out-of-stock rather than opening an empty or
      // all-disabled variant picker.
      out_of_stock: variants.length === 0 || totalStock <= 0,
      variants,
      // 004-sidebar-menu-reorg (FR-006) — passed through unchanged from
      // ProductResource.image_url; null when the product has no image.
      image_url: p.image_url ?? null,
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
    artist_name: card.artist_name,
    sell_price: variant.sell_price,
    current_stock: variant.current_stock,
  };
}
