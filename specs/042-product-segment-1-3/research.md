# Research: Product Segment Length (1–3) and SKU Format

## Decision 1 — One definition of the segment rule
- **Decision**: `App\Support\ProductSegment` holds `PATTERN`, `CODE_PATTERN` and `normalise()`; the store request, import, generator and demo clone reference it.
- **Rationale**: the rule already drifts today — `size:3` (request), `strlen === 10` (generator), `[A-Za-z]{3}` (demo clone), `harus tepat 3 huruf` (import), `padEnd(3,'X')` (SPA). Changing each by hand is how a demo clone would silently break again.
- **Alternatives**: edit each site in place (rejected: five copies to keep in sync); share the rule with the SPA (rejected: no shared code between PHP and the SPA; the SPA stays a labelled mirror).

## Decision 2 — No migration
- **Decision**: keep `products.code_prefix CHAR(10)` and `product_variants.sku CHAR(14)`.
- **Rationale**: the longest new value is 10 (code) and 12 (SKU), within the widths set by the 2026-10-25 migration. `CHAR` pads on storage and trims trailing spaces on read, so `SPF-SH-S` reads back exactly. Risk: the uniqueness index compares `CHAR` values with padding ignored — harmless because no value ends in a space.
- **Verification**: a test saves `SPF-SH-S` and reads it back unchanged (Principle II).

## Decision 3 — Typed segment is never padded
- **Decision**: typed input is normalised (trim + uppercase); padding to 3 only applies to a derived segment.
- **Rationale**: the spec's SKU example (`SPF-SH-S-001`). Padding a typed value would change the owner's code.
- **Alternatives**: pad everything (rejected, contradicts the example); reject short segments (rejected, contradicts the request).

## Decision 4 — Letters only
- **Decision**: `A–Z` only, as today. Assumption recorded in the spec.
- **Rationale**: no request for digits; codes stay readable and the existing rule is letters.
- **Alternatives**: alphanumeric (wider scope, needs product-owner confirmation).

## Decision 5 — DEMO clone is in scope
- **Decision**: update `CodeAllocator::productPrefix()` regex.
- **Rationale**: otherwise a LIVE product with a short segment makes `app:clone-live-to-demo` fail for the whole store (CloneException). Discovered while planning; it is the highest-impact call site.

## Open items
- Product owner: confirm letters-only (spec Assumptions).
- Decide the preview meta label text ("product · 1–3" vs the effective length) during tasks.
