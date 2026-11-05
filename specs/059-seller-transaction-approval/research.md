# Research: Transaction Approval & Seller Audit

**Classification**: INTERNAL

All Technical Context items were resolvable from the code; no open NEEDS CLARIFICATION.

## Facts found in the code (grounding)

- `order_items.artist_id` is a per-line seller snapshot (`OrderItem::$fillable`); a repo search found **no code path that updates or deletes `order_items` after checkout** other than void (which changes `orders.status` to `voided`, `OrderService.php:245`). So "items changed after approval" is a safeguard today (spec US4), not a live flow.
- `SellerSettlementService::acknowledge()` is the closest precedent: append-only rows, `superseded_at`, private-disk upload with `storeUpload()`, file cleanup when the transaction throws, one `ActivityLogger` row inside the transaction. `seller_settlements.active_slot` (nullable tinyint default 1, NULL when superseded) + a UNIQUE index is the DB-level "one active row" mechanism.
- `OrderReferenceService` is the precedent for a per-sale writer with row lock, content-sniffed MIME, UUID file names, authorizing image route with `scopeBindings()`, `Cache-Control: private, no-store`, and a single presenter used by both endpoint and resource.
- `components/settlement/SignaturePad.vue` already draws on a pointer-events canvas (mouse, touch, stylus), reads the stroke colour from a token class, exposes `toBlob()`/`clear()`/`hasInk`, `touch-none` for Android. Reusable as is.
- Sales list: `SalesTransactionsService::orderRow()` builds each row; the page filters/sorts/paginates **client-side** in `useSalesFilters` (URL keys in `OUR_QUERY_KEYS`, e.g. `ref`, `pstate`). A new filter = one more filter field + URL key + a row field.
- Permissions: `MenuActions` registry (`sales` named actions), `ability:menu,action` route middleware, `MenuActionsRouteCoverageTest` fails for an ungated non-GET route; 058 adds `SellerGate`, `seller.forbidden` and `ForbiddenSurfacesTest::test_every_api_route_is_classified_for_seller_scope`. `User::isOwnerOrAdmin()` = holds the `settings` menu; `users.artist_id` links a user to one seller; Owner role is never seller-scoped.
- `CloneRegistry` copies LIVE→DEMO with new ids; snapshot columns are copied verbatim; files are copied. `CloneRegistryDriftTest` fails when a table is unregistered.

## Decisions

### D1 — Approval is its own append-only table, not columns on `orders`
Decision: `order_approvals` (one row per seller per cycle). Rationale: spec FR-002; multi-seller; audit. Alternatives: JSON on `orders` (no uniqueness/FK/query), columns on `orders` (one approval only) — rejected.

### D2 — Overall status and requirements are DERIVED
Decision: `OrderApprovalSummary::for($order)` computes the unique sellers from current `order_items.artist_id`, the valid approval per seller, then pending/partial/full + X/N. Nothing stored on the order. Rationale: FR-004 "never manually overridden", FR-006 legacy sales work with zero migration, and the requirement set can never drift from the items (same stance as `PaymentSummary`). Alternative: stored requirement rows created at checkout — rejected: needs backfill and a hook in every line-changing path.

### D3 — "Approved" is bound to a content hash; invalidation is derived
Decision: on approve, store `items_hash` = SHA-256 of the seller's lines canonicalised as sorted `(sku_snapshot, name_snapshot, qty, sell_price, discount_amount, line_total)` plus `items_snapshot` (the readable list). A row counts as valid only while `items_hash` equals the seller's current hash. Mismatch → seller reads as Pending ("re-approval required") without any write. `reconcile($order)` (called inside `approve()` and exposed for any future edit path) sets `invalidated_at`, `invalidation_reason = items_changed`, frees `active_slot` and logs it.
Rationale: satisfies FR-020/021 for any future change path automatically; only the affected seller's hash changes (spec 9), moving a line between sellers changes both hashes.
**Critical detail**: the hash MUST NOT contain `order_items.id`, `variant_id` or `artist_id` — the LIVE→DEMO clone remaps ids, which would silently invalidate every cloned approval. Seller identity is already the row's `artist_id`. Money is hashed as fixed 2-decimal strings.
Alternatives: version counter on the order (invalidates every seller on any change — violates spec 9); event hooks only (a missed path = silent stale approval) — rejected.

### D4 — One active approval per (sale, seller): the database enforces it
Decision: `active_slot` tinyint nullable default 1, UNIQUE(`order_id`,`artist_id`,`active_slot`); set NULL when invalidated. `approve()` also locks the order row (`lockForUpdate`) first. Concurrent double-submit → the loser gets 409 `already_approved` (unique violation is caught and mapped, not a 500). Rationale: FR-011/SC-004 even if a code path forgot the check. No client idempotency key needed: replay returns the same 409 and leaves one row.

### D5 — Freshness check against a stale screen
Decision: the GET payload carries each seller's current `items_hash`; POST must echo the `items_hash` the user reviewed; a mismatch → 409 `approval_stale`. The value is server-produced and only able to refuse, never to grant, so it is not "trusting the client". Rationale: FR-012.

### D6 — Eligibility = permission AND relationship
Decision: both must hold: `ability:sales,approve` (new named action, 057 registry; legacy roles with NULL actions keep it) AND (`user.isOwnerOrAdmin()` OR `user.artist_id === seller`). Owner/admin may approve for any seller (`on_behalf = true` stored when the approver's own seller ≠ the seller). Pure cashiers without a seller link cannot. Rationale: spec FR-013 & assumptions; additive to 057/058 (never widens). A seller-scoped user sees the whole sale (058 Q1:B) but the payload sets `can_approve` only on their seller.
Alternatives: any `sales` user may approve (defeats the audit) — rejected.

### D7 — Methods and files
Decision: `draw` (PNG from SignaturePad), `upload` (PNG/JPEG, 5 MB max, same limit as 055), `stamp` (no file). Type read from file content (`getMimeType`), UUID name, private `local` disk dir `order-approvals/`, written inside the transaction, deleted if it rolls back (copy of `acknowledge()`). A blank drawing is refused client-side and server-side (a required file for `draw`/`upload`; none allowed for `stamp`). Stamp artwork is rendered by the SPA from tokens (`ApprovalStamp.vue`) with seller name + approver + date; nothing is stored for it. Rationale: no generated assets, no new dependency. Honest wording: not a certified e-signature (same disclaimer as 076).

### D8 — Who can SEE an image
Decision: `GET /orders/{order}/approvals/{approval}/image` (scopeBindings; param name `approval`) allowed for owner/admin, the approver, and users whose `artist_id` equals the approval's seller; others get 403 and the payload's `can_view_image=false` (server-computed). Status, method, approver, time are visible to anyone who can see the sale. Invalidated rows' images follow the same rule (kept for audit). `Cache-Control: private, no-store`; the SPA fetches as a blob (needs the token), as 055 does.

### D9 — List payload and filter
Decision: each Sales row gets `approval: { status: pending|partial|full|voided, total, approved, pending, sellers: [{artist_id, name, status, approver_name, method, approved_at, on_behalf}] }` — no hashes, no URLs, no images. Built from `orders.approvals` eager-loaded once per page plus `items.artist` already loaded (the query-count test guards it). Filter `filters.approval` (`''|pending|partial|full`, URL key `appr`) in `useSalesFilters` over `row.approval.status`; voided rows never match a non-empty filter. Rationale: the whole page is client-side, so a second endpoint would duplicate rules. Statistics, "select all", and export keep covering all filtered rows (existing behaviour); approval columns in the Excel export are **not** added in this feature (out of spec).

### D10 — Voided sales
Decision: `approve()` on a voided sale → 409 `order_voided`; existing rows untouched; list shows `voided`. Void itself does not invalidate approvals (spec FR-022).

### D11 — Seller-scoped viewer and Owner
Decision: approvals are visible with the sale (058 Q1:B). For a scoped user the overall status stays whole-sale. The 058 `seller.forbidden` classification: the approvals routes are **scope-aware** (the controller enforces own-seller) — classify in the route test accordingly. Owner is never scoped (`SellerGate`) and may approve on behalf.

### D12 — Audit trail
Decision: `ActivityLogger` rows in the same transaction: `order_approved` (seller, method, on_behalf, hash prefix), `order_approval_invalidated` (reason), entity `Order`. History view (owner/admin only) reads `order_approvals` including invalidated rows plus nothing else — the table itself is the audit store; the activity-log rows are the cross-cutting trail (appears on the existing Activity Log screen). No edit/delete route exists; the model has no update path and the test asserts it.

### D13 — Clone and backups
Decision: register `order_approvals` in `CloneRegistry` (`fks: order_id→orders, artist_id→artists`, `files: image_path`, `via: orders.order_id`, no `data_mode` column — same shape as `order_reference_images`); `approved_by` points at shared users. Backups: images are **not** included (same known gap since 054/055) — recorded in CLAUDE.md, not solved here.

### D14 — Frontend
Decision: Approval section in `TransactionItemsModal` (also used by customer history); method tabs in `ApproveSellerModal` (dynamic import, so Sales stays lean), a confirmation step ("Approve items of {seller} — {n} items, {total}") before submit, success toast + section refresh. Icon (`ApprovalStatusIcon`) uses existing Phosphor duotone icons: clock / progress (half-circle) / check-circle; popover = click, Esc closes, focus returns, also exposes `aria-label` text; uses `BaseTooltip` only for the hover text. The filter is a `BaseSelect` next to the existing payment-state filter. UI copy via `vue-i18n` keys in both locales (project convention), tokens only.

### D15 — Not built (explicit)
Pre-order approval; withdrawing/undoing an approval; per-store custom stamp; certified e-signature; approval columns in exports; notifications/reminders for pending sellers; blocking settlement/finalize on approval status (approval is an audit overlay and does not touch money, stock, reports or `SellerSettlement`).

## Risks

- **Hash definition drift**: changing the canonical fields later invalidates all stored approvals. Mitigation: one function, a versioned prefix inside the stored hash (`v1:`), a test that pins a known input→hash.
- **Float/decimal formatting** in the hash: use strings from `decimal:2` casts, `qty` as integer string.
- **No browser tooling** in prior sessions: drawing on Android is a manual check task, not assumed.
