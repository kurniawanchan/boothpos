# Data Model: Product Segment (rewritten 2026-10-16)

No schema change.

## Product
| Field | Type | Rule |
|---|---|---|
| product_segment | string | 1–3 letters `A–Z`, uppercase, stored as typed; set at creation; blank → derived 3 letters from name (padded `X`) |
| code_prefix | CHAR(10) | `artist.code(3)-category.code(2)-product_segment`, 8–10 characters; unique across DEMO and LIVE; permanent |

## Product variant
| Field | Type | Rule |
|---|---|---|
| sku | CHAR(14) | `code_prefix` + `-` + 3-digit sequence starting `001`; e.g. `SPF-SH-S-001` (12 chars) |

## Validation (single source: `App\Support\ProductSegment`)
- `PATTERN`: `^[A-Z]{1,3}$` after trim + uppercase.
- `CODE_PATTERN`: `^[A-Z]{3}-[A-Z]{2}-[A-Z]{1,3}$`.
- Blank input is not a segment; it triggers derivation (name → 3 letters, `X`-padded).

## Invariants
- Stored segment equals the segment used in `code_prefix` and every SKU of the product.
- Existing products keep their codes (F19.4).
- No two products share a `code_prefix`, across modes.
