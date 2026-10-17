# Contract Changes: Product Segment Length

Applies to `docs/openapi-pos-mvp.yaml` (must move in the same commit as the code, PRD §9.5).

## Product create / update

- `product_segment`: pattern `^(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$` (was `^[A-Z]{1,3}$`): letters with single hyphens, at most 6 characters. Empty → derived from the name, as before.
- Refusal, HTTP 422, on `product_segment`:
  - Not letters or single hyphens, or more than 6 characters: "Segmen produk harus 1 sampai 6 karakter (huruf A–Z, boleh tanda hubung tunggal di antara huruf)."
  - Code or a regenerated SKU longer than the limit (segment change, `PUT /products/{id}`): "Segmen ini membuat SKU lebih dari 20 karakter. Gunakan segmen yang lebih pendek."
- Code already used by another product: unchanged (422 on `product_segment`).

## Product response

- `code_prefix`: `maxLength: 13` (was 10).
- `product_segment`: `maxLength: 6` (was 3); the pattern above.

## Variant SKU (create, update, sequence batch)

- `sku`: `minLength: 1`, `maxLength: 20` (was 14). Example unchanged in shape: `RYU-KY-SAK-1`.
- Refusal, HTTP 422, on the sequence field: "SKU lengkap melebihi 20 karakter. Gunakan nomor urut yang lebih pendek." (was "… 14 karakter").

## Master-data import

- `product_segment` in the products sheet: same rule as above. Refusal message on the row: "Segmen produk '…' harus 1 sampai 6 karakter (huruf A–Z, boleh tanda hubung tunggal di antara huruf)."

## Unchanged

- Artist code (3 letters), category code (2 letters), SKU number rules, DEMO/LIVE behaviour, sales and stock snapshots (text kept as recorded).
- Products export `code` / `item_label`: `code` is the SKU after `ART-CA-` (e.g. `SH-K-12`); legacy products keep the 7-character tail (research Decision 10).
