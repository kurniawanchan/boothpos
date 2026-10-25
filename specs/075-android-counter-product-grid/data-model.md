# Data Model — 075 (Android in-memory model; no database or API change)

**Classification**: INTERNAL

## Variant (existing `Product`, extended)
| Field | Source | Note |
|---|---|---|
| id | `variants[].id` | order-line unit, unchanged |
| name | product + variant | unchanged ("Produk — Varian" when >1 active) — used by search, sheets |
| variantName | `variant_name` | NEW; picker row title |
| code | `sku` | NEW; picker row |
| price | `outlet_price ?? sell_price` | display only |
| stock | `outlet_stock ?? current_stock`; null when `track_stock=false` | NEW; display only |
| imageUrl | `variants[].image_url ?: product.image_url`, URL-resolved | already mapped; now resolved + drawn |
| groups | modifier group ids | unchanged |

## ProductEntry (NEW) — one grid card
| Field | Rule |
|---|---|
| id | `products[].id` |
| name, categoryId, categoryName | product; category falls back to "Lainnya" as today |
| imageUrl | `product.image_url`, URL-resolved (card photo: product's own, else first variant's) |
| variants | active variants, server order |
| derived `priceLabel` | min==max → one price; else "min–max" |
| derived `stock` | sum of non-null variant stocks; null if none tracked |
| derived `isSingle` | `variants.size == 1` |
| derived `soldOut(set)` | all variants ∈ sold-out set (or no variants → unavailable) |

Invariants: a product with 0 active variants is **not shown** (spec edge case); entries partition by category; every variant belongs to exactly one entry; entry order = server order.

## State
`Sheet.VariantPicker(entryId)` — holds the id, not a copy, so a silent menu refresh updates sold-out marks while open.
