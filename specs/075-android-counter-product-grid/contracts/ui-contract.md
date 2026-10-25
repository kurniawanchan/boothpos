# UI & Interface Contract — 075

**Classification**: INTERNAL

## Server API
No route or response change. Android additionally reads existing fields of `GET /api/v1/products?pos=1&with_variants=1&with_modifiers=1`: `variants[].sku`, `current_stock`, `outlet_stock`, `track_stock`. `docs/openapi-pos-mvp.yaml` unchanged (all four fields already present in `ProductVariantResource`; verify the schema lists them, add only if missing).

## Product card
Photo (or initials tile) · qty badge (sum over variants, top-end) · "Habis" pill when entry sold out · "N varian" pill when N>1 (else "Pilihan" when option groups) · name (2 lines) · category · price or "Rp a–Rp b" · "Stok N" (omitted if untracked).
Tap: N=1 → add (or option sheet); N>1 → picker; sold out → message "<nama> sedang habis." Hold: N=1 → sold-out sheet.

## Variant picker sheet
Header: product name, category, "pilih varian untuk ditambahkan ke order", close control. Rows: photo/initials, variant name, code, price, "Stok N", "Habis" mark. Row tap → add + close; sold-out row not addable; row hold → sold-out sheet; scrolls for long lists; close by control or scrim adds nothing.

## Photo
Source = resolved `image_url`. Missing/failed → initials tile, no dialog. Variant → product fallback.

## Totals
Subtotal · Diskon (red, "Rp 0" when none) · `<label> <rate>%` · dashed divider · Total (large bold). Empty order → all "Rp 0".
