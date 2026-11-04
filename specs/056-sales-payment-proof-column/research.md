# Research: Sales Payment Proof Visibility & List Column Refinements

**Classification**: INTERNAL

No NEEDS CLARIFICATION remained. Findings come from reading the code (verified), not assumptions, except where marked ASSUMPTION.

## D1 — How the list knows a sale has a viewable proof
- **Decision**: add one row field `payment_proof_count` in `SalesTransactionsService::orderRow()` = number of this sale's non-cash payments whose current proof the requesting user may view (`$p->currentProof()` + `$p->proofViewableBy($user, $current)`). `build()` already receives `$user`; add `payments.proofs` to the existing `with([...])`.
- **Rationale**: `Payment::currentProof()` reads the loaded relation (no N+1) and `proofViewableBy()` is the single permission definition (spec FR-008). Visibility is decided per request user, so a cashier never gets a count that hints at proofs they cannot open (SC-005).
- **Alternatives**: boolean `has_payment_proof` (loses "how many" for the dialog title, no cheaper) — rejected; reuse `has_proof` from `OrderResource` (only exists on the detail payload) — not available on rows; client-side derivation — impossible without proof data.

## D2 — How the list opens the proof(s)
- **Decision**: new `PaymentProofsModal.vue`: on open calls the existing `getOrder(id)` (`GET /orders/{id}` already eager-loads `payments.proofs` and returns `proof_id` / `has_proof` / `can_view_proof` per payment), renders one `PaymentProofThumbnail` per viewable proof labelled with method + amount, click → `ImageLightbox` gallery (055).
- **Rationale**: zero new endpoint and zero new authorization path; images load only when the dialog opens (FR-007). Same failure pattern as other detail loads (error + Retry).
- **Alternatives**: a dedicated `GET /orders/{id}/payment-proofs` — duplicates permission logic, rejected; open the full detail modal instead — heavier, and the spec says the link does not open the full detail.

## D3 — Detail thumbnail
- **Decision**: `PaymentProofThumbnail.vue` (fetch `getPaymentProofBlobUrl(proofId)`, revoke on unmount/proof change, neutral placeholder on failure). `PaymentHistoryList` renders it only when a new opt-in prop (`proofThumbnails`, default false) is set and `showProof && canViewProof(p)`; click emits the existing `view-proof`. `TransactionItemsModal` passes the prop.
- **Rationale**: `PaymentHistoryList` is shared with Pre-orders; the spec scopes this to Sales, so Pre-orders stay unchanged by default. Existing "View proof" text link and `proofLightboxSrc` flow are kept (thumbnail is an additional, same-action target).
- **Alternatives**: always-on thumbnails for pre-orders too — out of scope (spec: Pre-orders out of scope).

## D4 — Fetch limiting
- **Decision**: no concurrency limiter. A sale has a handful of payments; the 055 `ReferenceThumbnails` limiter (≤ 3 in flight) exists for up to 10 images. Keyed by `proof_id` so a replaced proof refetches.
- **Alternative**: extract a shared queue — rejected as speculative (constitution I).

## D5 — "Verified" status
- **Decision**: client-side only. Show a `mint` `StatusPill` "Verified" in the Payment cell when `row.payment_state === 'verified'` AND `row.payment_methods` contains a non-cash method. `payment_state` is already "worst of": rejected > pending > verified (server `paymentState()`), so a mixed sale stays Not verified, and cash-only is excluded by the method check (cash is born verified, so state alone would label cash sales).
- **Rationale**: no API change. The existing `pstate=verified` filter option already exists (`paystate_verified`), so the pill and filter agree.
- **Alternative**: server-side `payment_state_noncash` — rejected: changes a payload for something derivable.

## D6 — Column swap and rename
- **Decision**: reorder the `columns` array (Total before the optional Margin; cell slots are keyed, so nothing else moves; sorting keys unchanged). Change `sales_reference.link` value to "Sale Reference" (en) / the Indonesian equivalent (id) — grep confirms at implement time that the key is used only by the list link.
- **Alternative**: new key — unnecessary churn.

## D7 — Sale Reference filter
- **Decision**: `filters.reference` in `useSalesFilters` with values `''|'has'|'none'`, matching on `tx.has_reference` (missing treated as none, like other optional row fields); counted in `activeCount`, cleared by `reset()`, URL key `ref` (validated with `oneOf`, unknown ignored), key added to `OUR_QUERY_KEYS` in `SalesView`. Control: a `BaseSelect` next to the other single selects. Page resets via the existing sync watcher. Summary, KPIs, select-all, export keys all already use `filtered`, so they follow automatically.
- **Rationale**: `has_reference` is already on every row (055); matches the existing pattern for `paymentState` / `settlement`.
- **ASSUMPTION** (carried from spec): "filter sale reference" = has / has none, not note text search.

## D8 — Contract and docs
- **Decision**: only additive row field `payment_proof_count` (integer ≥ 0) on `GET /reports/sales` `transactions[]`; OpenAPI updated in the same commit. The Excel export maps columns explicitly (verified in `ReportController::exportSalesTransactions`), so it is unaffected.

## Open items to verify at implement time
- Screenshots #1/#2 were not viewable at spec time: confirm the "Payment Proof" link/thumbnail look against them (spec Assumption).
- Query-count test needs one warm-up call first (project note) when asserting no N+1.

## D9 — Seller-scoped statistics (US9)

Decision: keep one definition of "a seller's part" — per-row `seller_items[name] {units,gross,net}` (server) summed by `part()` (client) and by the same arithmetic in the export. First task reproduces the observed whole-transaction figures before changing anything.
Facts (code read 2026-11-05): `kpis`/`summary` already use `part(tx)` when `filters.sellers` is non-empty; rows are keyed by seller NAME; the filter predicate matches `artist_names`. Candidate causes to check, not assumed: (a) two sellers sharing one name, or a numeric-looking name (PHP turns a numeric-string key into an int key, so JSON may become a list and `si[name]` misses), (b) rows without `seller_items` falling back to whole-sale figures (`part()` returns null), (c) the "Total"/cash columns and the summary `cash`/`noncash` are whole-sale by design and may be what is being read, (d) a stale build of the SPA.
**RESOLVED (T032, dev DB 2026-11-05):** server data is correct (104 sales, 16 mixed, `seller_items` always matches `artist_names`, no duplicate seller names), and the client maths already scopes correctly when `filters.sellers` is set. ROOT CAUSE: clicking a seller name in the table (`searchByArtist`) only filled the SEARCH box, never the seller filter, so `part()` stayed off and mixed sales counted whole. Fix: `filterBySeller()` in `useSalesFilters` (sets the filter, clears the search) used by that click. Suspects (a) duplicate/numeric names and (b) rows lacking `seller_items` were ruled out on dev data. Remaining by-design whole-sale figures: Total, cash, non-cash, outstanding.
Alternatives: key by `artist_id` (more robust; needs the filter to carry ids — larger change, choose only if (a) is confirmed).

## D10 — Toggle data path (US7)

Decision: no image data in the list payload. With the toggle on, only rows on the current page that have `has_reference` or `payment_proof_count > 0` fetch: reference via `GET /orders/{id}/reference` (note + image ids), proofs via `GET /orders/{id}` (`proof_id`/`can_view_proof`), thumbnails via the existing blob endpoints, ≤ 3 images in flight (as `ReferenceThumbnails.vue`). Rejected: embedding images/ids in `GET /reports/sales` (would break "zero up-front images" SC-003 and leak ids to viewers without permission); a new batch endpoint (second permission rule).
Toggle state in `localStorage` (try/catch, per viewer/device); default off.

## D11 — Export shape (US8)

Decision: extend `POST /reports/sales/transactions/export` (still key-based; ignores keys outside the filters). One shared row builder feeds list and export so columns cannot drift; the export adds `verification`, `reference_note`, image paths for reference and the exporter-viewable current proofs (`Payment::proofViewableBy()`). Images are downscaled (`CatalogImageThumbnail`, GD) and embedded as drawings in the sale's row (row height fixed, images side by side with offsets, ≤ 10 reference + proofs per sale), temp files cleaned by `cleanup()`. PDF/unreadable ⇒ cell text "could not embed". `sellers[]` (names) lets the server apply the same seller-scoped units/gross/net.
Limit: a config cap on total embedded images, set from the measured run (task T042; catalog precedent ≈ 85 MB peak at 500 pictures); over the cap ⇒ 422 stating the limit, never a silent partial file.
**Measured (T042, 2026-11-05, dev container, 4000×3000 JPEG, one decode per image):** 100 images 13.4 s / 135 MB peak; 200 → 25.8 s / 139 MB; 400 → 50.2 s / 140 MB (≈0.13 s per image, memory flat because each image is decoded and released). TIME is the constraint, not memory: 400 would exceed PHP's 30 s HTTP default. Decision: default cap `sales.export_max_images` = 200 (≈ 26 s), `set_time_limit(120)` for this request, env override `SALES_EXPORT_MAX_IMAGES`. Dev data: 104 sales / 126 images, i.e. a whole event fits under the cap.
Alternatives: images as links only (rejected — user asked for images); one sheet per sale (rejected — one row per sale is the requirement); server PDF (out of scope).

## D12 — Spec/doc consequences

FR-013 and the "exports unchanged" edge case were revised in spec.md. OpenAPI: request `sellers[]`, 422 limit response, columns note. `CLAUDE.md` 056 section gets the export/toggle/seller notes at T048.
