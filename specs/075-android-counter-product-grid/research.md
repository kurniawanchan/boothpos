# Research — 075 Android Counter product grid

**Classification**: INTERNAL. Facts below were read from the repo on 2026-10-26; anything else is marked ASSUMPTION.

## R1 — How to group without breaking the variant-level order flow
- **Fact**: `Product` (model) is a *variant*: `id` = variant id, used by `addLine`, `soldOut`, `Sheet.Modifier`, `Sheet.SoldOut`, `qtyById`. `MenuMapper` emits one `Product` per active variant, naming multi-variant ones "Produk — Varian".
- **Decision**: keep `Product` as the variant unit; add `ProductEntry(id, name, categoryId, categoryName, imageUrl, variants: List<Product>)` and `MenuCatalog.entries`. Grid renders `entries`; search and every downstream flow keep using the flat variant list.
- **Why**: no change to cart, sold-out, option groups, printing; FR-010 holds by construction.
- **Rejected**: replacing `Product` with a nested model (touches ~every screen and test).

## R2 — Photos: library or in-house
- **Fact**: no image library (Coil/Glide) in `gradle/libs.versions.toml`/`app/build.gradle.kts`. `QrImageLoader` already fetches public-disk images with OkHttp but keeps an unbounded map and decodes full size.
- **Fact**: `MenuMapper` already sets `imageUrl = v.imageUrl ?: p.imageUrl`, but (a) `MenuCard` never draws it, and (b) the URL is not passed through `ServerUrl.resolve`. The server builds it with `Storage::disk('public')->url()`, i.e. from the server's `APP_URL` — may be `localhost`, unreachable from a tablet (the exact case `ServerUrl` documents for QR/logo).
- **Decision**: new `MenuImageLoader` modelled on `QrImageLoader` but with a byte-bounded LRU and `inSampleSize` downsampling to card size; resolve URLs in `MenuMapper` with `ServerUrl.resolve(serverBase, url)`.
- **Why**: a new dependency's version/availability is unverified here and the offline build constraint is unknown (ASSUMPTION); the in-house loader is ~60 lines and follows an existing, tested pattern. 100+ full-size bitmaps in an unbounded map would risk OOM on a tablet.
- **Rejected**: Coil/Glide (unverified version, new dependency for one screen); reusing `QrImageLoader` as is (unbounded memory).

## R3 — Variant code, stock, tracking
- **Fact**: `ProductVariantResource` returns `sku`, `current_stock`, `outlet_stock` (only for `pos=1`; per-outlet balance), `track_stock` (false = made-to-order). Web uses `onHand = outlet_stock ?? current_stock`.
- **Decision**: add those four fields to `VariantDto`; display stock = `outlet_stock ?? current_stock`; card stock = sum over orderable variants; no stock line when `track_stock` is false (ASSUMPTION: matches how web treats made-to-order — verify against `PosView.vue` during implementation, T-check in quickstart).
- No endpoint change; payload already sent today.

## R4 — What "sold out" means
- **Fact**: Android's sold-out set comes from `GET /menu/availability` (variant ids), refreshed silently; `tapProduct` refuses ids in it. CLAUDE.md: `MenuAvailability` is the only "can this be ordered" rule.
- **Decision**: variant is sold out ⇔ id ∈ `soldOut`; entry is sold out ⇔ all its variants are. Stock numbers are display only and never gate ordering client-side (differs from the web's `current_stock <= 0` disable; ASSUMPTION that the server availability already folds stock in — to confirm in quickstart step 6).

## R5 — Tap, hold, search
- Single variant → existing `tapProduct`. Multi → new `Sheet.VariantPicker(entry)`; a row tap calls `tapProduct(variant)` (keeps sold-out message and the option-group step) and closes the picker.
- Hold: single-variant card → `markSoldOut` as today; multi-variant card → no action (picker rows support hold → `markSoldOut(variant)`), matching the spec assumption.
- Search stays on the flat variant list, rendered with the same photo card (name "Produk — Varian"), as the web does.
- Variant badge replaces the existing "Pilihan" pill on multi-variant cards (same slot); the picker row shows "Pilihan" when that variant has option groups. ASSUMPTION — easy to change.

## R6 — Totals block
- **Fact**: `OrderPane` already shows Subtotal, discount (only when > 0, default colour, no divider style), tax with rate from `taxLine`, big Total, and the hide/show control (055).
- **Decision**: show the Discount row always ("Rp 0" when none), red when > 0, add a dashed divider above Total; keep the editable-discount decision: **no** rupiah field (spec FR-014). No calculation touched.

## R7 — Photo freshness (FR-018)
- Cache key = resolved URL; a replaced photo normally has a new stored filename (ASSUMPTION, uploads not inspected) so a refreshed menu yields a new key. Pull-to-refresh (`refreshCounter`) also clears the image cache so an overwritten same-name file cannot stay stale.
