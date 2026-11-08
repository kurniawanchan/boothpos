# Research: Bulk Approve Transactions

**Classification**: INTERNAL

Grounded in the code as read on 2026-11-08: `app/Services/OrderApprovalService.php`, `OrderApprovalController.php`,
`SalesView.vue` (bulk verify), `ApproveSellerModal.vue`, `config/sales.php`, `CloneRegistry`, feature 059 spec/CLAUDE.md notes.
Items not verified are marked ASSUMPTION or "verify at implementation".

## D1 — Freshness: preview + plan hashes (not hashes in the list)

- **Decision**: add a read-only `POST /orders/approvals/bulk/preview`. It returns what would be approved and a `plan`
  (`order_id` → `artist_id` → `items_hash`). The apply call sends the same `plan`; for every seller the server recomputes the
  current hash and skips the order as `changed` when it differs, or when an eligible seller is missing from the plan.
- **Rationale**: single approval binds "approved" to the content hash the user reviewed (059 `approval_stale`). The Sales
  list payload deliberately carries no hash (059), so bulk has nothing to echo. A preview also gives the confirmation step
  its REAL numbers (FR-004: transactions, seller approvals, sellers, amount, "nothing eligible") computed by the one
  server formula instead of re-derived in the browser (059 rule: SPA only displays server fields). Same idea as the
  plan-hash approval in `app:sync-stock-from-transactions` (051).
- **Alternatives rejected**: (a) put `items_hash` into every list row — contradicts 059's lean-list rule, bloats the payload,
  hash would be stale after any list refresh anyway; (b) no freshness check — would approve content the user never saw,
  breaking 059 FR-012; (c) one all-in-one call with a "dry_run" flag (the master-data import style) — doable, but
  multipart with a file for a dry run is wasteful; two JSON/multipart calls are clearer.

## D2 — Transaction boundary: one DB transaction per ORDER

- **Decision**: `approveBatch()` loops orders; each order runs in its own `DB::transaction`: lock the order row →
  voided check → `reconcileLocked()` → recompute `OrderApprovalSummary` → verify freshness → for each eligible seller
  `recordSeller()` (row + `order_approved` log). Any exception rolls back THAT order only; the batch continues.
- **Rationale**: FR-010 — a problem on one sale must not roll back others and must not leave a sale half-processed by this
  batch (an owner approving 3 sellers of one sale gets all 3 or none). A single giant transaction would hold 200 row locks
  and lose everything on one failure.
- **Edge**: if another user approves one of the planned sellers meanwhile, that order is skipped (`already_approved` or
  `changed`); retrying the same batch is safe and approves the remainder (FR-016). ASSUMPTION: dropping just that seller and
  approving the rest was considered and rejected as extra complexity for a rare race.
- **Concurrency safety net kept**: the `uq_order_approvals_active` UNIQUE index (059) still guarantees one active row per
  (order, seller); a `UniqueConstraintViolationException` maps to `already_approved` exactly as single approval does.

## D3 — Files: validate and store ONCE, share the path

- **Decision**: the image is validated (type sniffed from content, ≤ 5 MB, required for draw/upload, forbidden for stamp)
  and stored once through the existing `storeImage()` logic; every approval row of the batch gets the same `image_path` /
  `image_mime`. If no approval was created (all skipped) the file is deleted at the end.
- **Rationale**: 200 approvals × a 5 MB upload would write ~1 GB for one click. Approvals are append-only with no delete
  route, so a shared path cannot be orphaned by an approval deletion. `CloneRegistry` already copies per row and its
  wipe rule deletes an old file only if no row in either mode still references it, so sharing is already safe there.
- **Alternatives rejected**: copy per approval (disk blow-up, no benefit); a separate `approval_images` table (new entity
  and clone entry for no user-visible gain).
- **Known limitation (same as 059)**: approval images are not in backups.
- **Authorization of viewing is unchanged**: `mayViewImage()` is per approval row (owner/admin, approver, linked seller),
  so one shared file does not widen who can see it — the endpoint is keyed by `{order}/{approval}`.

## D4 — Traceability: `bulk_ref` column, not a batch table

- **Decision**: nullable `order_approvals.bulk_ref` (`CHAR(36)` UUID, indexed), generated once per batch by the server.
  Owner/admin approval history marks entries `via_bulk: true`. The `order_approved` log of each approval carries `bulk_ref`;
  one aggregate `order_bulk_approved` row records who, method, selected/approved/skipped counts and the ref.
- **Rationale**: the spec's "Bulk Approval Action" entity needs identity + counts, both satisfied by the ref + the audit
  log, which already is the project's audit trail. A new table would need `HasDataMode`, a `CloneRegistry` entry (and the
  drift test allowlists) and a FK dance for no extra capability. A nullable column is additive, needs no data migration
  (NULL = single approval) and `CloneRegistry` copies it verbatim (not unique, so no re-keying needed — ASSUMPTION:
  verify the clone copies unknown nullable columns generically; if it whitelists columns, add `bulk_ref` there).
- **Alternatives rejected**: `order_approval_batches` table (heavier, see above) — revisit only if batch-level queries
  ("list all bulk actions") become a requirement; the spec doesn't ask for one.

## D5 — Eligibility (one definition, reusing 059)

For each requested id the server resolves the order through the SCOPED `Order` model (DEMO/LIVE mode + seller scope):

| Condition (checked in this order) | Result |
|---|---|
| id not visible to the user / wrong mode / deleted | skip `not_found` (indistinguishable from "does not exist" — nothing revealed) |
| order voided | skip `voided` |
| the user has pending-and-approvable sellers | eligible for exactly those sellers |
| sellers approvable by the user exist but all already approved | skip `already_approved` |
| none of the order's sellers is approvable by this user | skip `no_approvable_seller` |
| apply only: plan hash mismatch / seller newly pending and not in plan | skip `changed` |
| apply only: unexpected exception for this order | skip `failed` (generic message; detail in the log, never SQL/paths) |

"Approvable by the user" = `OrderApprovalService::mayApproveFor()` (owner/admin: any seller; otherwise own seller). A
stale prior approval already reads as pending in `OrderApprovalSummary`, so it is eligible and `reconcileLocked()` frees
its slot inside the transaction — the same path single approval uses.

The route needs `ability:sales,approve` (057); the per-seller check then runs additively (never widens), identical to
single approval. `approval_not_allowed` 403 happens at the route/permission level BEFORE any order state is read.

## D6 — Logging

- Each approval: the existing `order_approved` log, inside the order's transaction (Constitution IV). Adds `bulk_ref` to
  `newValues`.
- One aggregate `order_bulk_approved` row after the loop. It summarises transactions that are already committed; if writing
  it fails, the approvals and their own logs stand and the failure is reported to the log. This is the one place a log is
  not in the same transaction as what it describes — acceptable because it is a summary, not the record of any single
  change. Whether `ActivityLogger::log()` accepts a null `entity_id` is **unverified** — verify at implementation; fallback:
  `entity_type='Order'`, `entity_id` = first approved order id, with the ref in `newValues`.

## D7 — Limits and time

- Cap: `config('approvals.bulk_max')` (default 200, env `APPROVALS_BULK_MAX`), the same number the other Sales bulk
  actions use (`verify-payments`, `bulk-receipts`). Over the cap → 422 with the limit in the message, nothing written.
  The FormRequest enforces `max` from config, not a literal.
- Time: each order costs ~two short transactions. ASSUMPTION ≈ tens of ms each → a few seconds for 200; **to be measured
  at implementation** (the Sales export had its 30 s PHP limit hit once, hence `set_time_limit` for that request). The
  apply action raises the limit for itself only. SC-001 (50 in < 30 s) is the acceptance bar.
- `max_input_vars` (PHP default 1000) would break nested multipart arrays for 200 orders × sellers, so `order_ids` and
  `plan` are single JSON-string fields decoded and validated server-side.

## D8 — Who sees the button

Constitution III: hide, don't disable. The button shows when `auth.canDo('sales','approve')` AND the server says the user
can approve any seller. That second fact is a new server-computed boolean on `/auth/me` — `can_approve_sellers`
(`OrderApprovalService::mayApproveAny($user)` = owner/admin, or a user linked to a seller) — so the SPA never derives it
from the role name (057/059 convention). A user with permission but whose preview finds nothing eligible still gets the
clear "nothing to approve" state (FR-004).

## D9 — Frontend reuse

- `ApproveSellerModal` mixes three things: method tabs, the lazy `SignaturePad`, upload validation, stamp preview, and the
  confirm step. The method-selection part (tabs + pad + upload + stamp, with `draw` canvas kept mounted via `v-show`
  across steps — the 059 trap where unmounting made `toBlob()` return nothing) is extracted into
  `ApprovalMethodPicker.vue` and used by BOTH modals, so there is one implementation of file rules and the
  `v-show` fix. The existing `ApproveSellerModal` tests are the regression guard.
- The result summary mimics `bulkVerifySummary()` (grouped reasons) but lists skipped orders (≤ 200) in a scrollable
  list with reason chips; counts must add up to the selection (FR-013), asserted by a test.
- Selection reuses `chosenOrderIds` (the existing `order:<id>` keys, "Select all" over ALL filtered rows). `preorder:*`
  keys are not on the Sales list anymore, so no extra filtering beyond what bulk verify does.
- After the result, `load()` refreshes the list; selection is reduced to rows still pending (as bulk verify does).
- Dialog and picker are lazy (`defineAsyncComponent`) so `SalesView`'s bundle does not grow (Constitution V; verify in the
  production build output).

## D10 — Non-goals (explicitly out)

Per-seller narrowing inside the dialog; a different signature per sale; undo/withdraw; pre-order bulk approval; a
"list all bulk actions" screen; background/queued processing; bulk approval from the customer-history modal; adding
approval columns to the Sales Excel export (059 non-goal kept).

## Open items to verify at implementation (none block planning)

1. `ActivityLogger::log()` nullable `entity_id` (D6).
2. `CloneRegistry` copies unlisted nullable columns generically, or needs `bulk_ref` added (D4); run `CloneRegistryDriftTest`
   and `OrderApprovalCloneTest`.
3. Measured time for 200 orders (D7).
4. Exact `/auth/me` presenter location for `can_approve_sellers`.
5. Real-browser drawing check on desktop + Android (SC-008) — Playwright/DevTools MCP were unavailable in earlier
   sessions; this stays an open task, not an assumption.
