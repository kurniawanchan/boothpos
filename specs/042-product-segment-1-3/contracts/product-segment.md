# Contract: product segment (create request and import)

## `POST /products` — `product_segment`
- Optional. Type: string.
- Normalised: trimmed, uppercased.
- Valid: 1–3 letters `A–Z`.
- Invalid: 422 with a message on `product_segment` (e.g. "Segmen produk maksimal 3 huruf (A–Z).").
- Blank or absent: derived from `name` (3 letters, `X`-padded when short).
- Response: `code_prefix` = `ARTIST-CAT-SEG`; each variant `sku` = `code_prefix-NNN`.

## Excel import (`products` sheet, column `product_segment`)
- Same rule. Invalid: row error "Segmen produk '{x}' harus 1–3 huruf."; the whole import is rolled back (unchanged behaviour).
- Blank: derived from `product_name`.

## Examples
| Artist | Category | Segment | code_prefix | First SKU |
|---|---|---|---|---|
| SPF | SH | S | SPF-SH-S | SPF-SH-S-001 |
| SPF | SH | SH | SPF-SH-SH | SPF-SH-SH-001 |
| SPF | SH | SPF | SPF-SH-SPF | SPF-SH-SPF-001 |
| SPF | SH | (blank, name "Conan") | SPF-SH-CON | SPF-SH-CON-001 |
| SPF | SH | (blank, name "Ai") | SPF-SH-AIX | SPF-SH-AIX-001 |
