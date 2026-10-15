# Research: PO Row Actions and Out-of-date Database Errors

## Evidence (verified)

- **Dev database is behind**: `docker compose exec app php artisan migrate:status` → 4 **Pending**: `2026_11_01_000001` (payment-proof supersede, feature 031), `2026_11_02_000001/2/3` (feature 034). `boothpos-app-1` had been up 38 h, i.e. started before PR #29; `docker/php/entrypoint.sh` runs `php artisan migrate --force` only **on container start**.
- **Screenshot 28** (PO detail): `PurchaseOrderController::show()` loads `items` with `withCount('bomLines')` → `product_variant_bom_lines.purchase_order_item_id` missing. **Screenshot 29** (BOM selector): `PurchaseOrderItem::eligibleForSeller()` filters `purchase_orders.artist_id` → missing. The PO **list** still works because it never reads those columns (`artist_id` is just `null`), which is why the list shows "No seller assigned".
- A fresh test database with every migration applied does not reproduce either error (the 034 test suite and browser run opened both screens successfully).
- **Today's row actions** (`PurchaseOrdersView.vue`): next-status buttons for the status, plus Edit/Delete only when `status === 'draft'`; Detail only by clicking the PO number. Form: `rowsLocked` already shows lines read-only after draft and the payload then sends only vendor/seller/notes; `PurchaseOrderService::update()` already allows `vendor_id`, `notes`, `artist_id` at any status and blocks `items` unless draft; `delete()` already refuses non-drafts (409).
- **Error handling today**: API exceptions render JSON (`shouldRenderJsonWhen`); with `APP_DEBUG=true` a `QueryException` message (full SQL) goes to the client and `api/client.js` only auto-toasts 409/403, so the SPA surfaces raw text through each screen's own `toast.error(err.message)`. The detail modal on failure toasts and leaves an empty dialog; `AddBomItemModal` on failure toasts and then shows the **"no eligible lines"** empty state (misleading).
- `GET /settings/features` is called once per SPA load by `AppShell` (`settings.load()`), open to every role, and already carries cosmetic server flags.
- `PreorderRowActions.vue` is a generic `{ key, label, danger?, disabled?, title? }[]` menu (`maxInline`, primary action, teleported "More").

## Decision 1 — Reuse `PreorderRowActions` for PO rows

**Decision**: build the action list per row: `detail` (primary), `edit`, `delete` (danger; `disabled` + `title` for non-drafts), then the existing status actions (`Mark Ordered/Received/Paid`, `Cancel`). With more than 3 actions the component already collapses to Detail inline + "More". No new menu component.

**Rationale**: Constitution I and UX consistency with Pre-orders (Split is likewise visible-but-disabled with a tooltip). The spec requires unavailable actions to stay visible with a reason.

**Alternatives**: a PO-specific menu (duplicate); hiding unavailable actions (rejected by the spec).

## Decision 2 — Edit is simply no longer draft-only

**Decision**: show Edit for every status; the form/API rules are unchanged (lines read-only after draft with the existing hint; seller change blocked when a BOM uses the order's lines — already enforced, 409). Edit from the list keeps loading the full PO first (feature 034 fix).

**Rationale**: the rule set was already implemented and tested; only the button gate hid it. Zero new write paths.

## Decision 3 — Edit audit (`purchase_order_updated`)

**Decision**: `PurchaseOrderService::update()` writes one `purchase_order_updated` log (old/new: vendor_id, total_amount, line count) inside the same transaction whenever the vendor changes or the lines are rewritten; seller changes keep their existing `purchase_order_seller_*` entries; a notes-only edit writes nothing (no financial meaning). FR-008.

## Decision 4 — Delete: server stays draft-only; guards are explicit; message names the alternative

**Decision**: keep draft-only (`409`); update the message to "Only draft purchase orders can be deleted. Cancel it instead."; add explicit guards (any payment row, any BOM row referencing a line) with their own messages, even though a draft cannot normally have either — spec FR-011 says "in any case", and the guard makes the rule independent of status-transition rules. UI: Delete disabled with the same reason for non-drafts; the existing confirm dialog (names the PO) and audit entry are kept.

**Alternatives**: deleting ordered/cancelled orders with safeguards — rejected by the requester.

## Decision 5 — Reactive: one exception renderer for "schema is behind"

**Decision**: in `bootstrap/app.php` `withExceptions`, render `Illuminate\Database\QueryException` whose SQLSTATE is `42S22` (unknown column) or `42S02` (table not found) — for API requests only — as **503** `{ message: <friendly>, code: 'schema_outdated' }`. Reporting is untouched (the full error still lands in the log). Everything else keeps today's behaviour. Message (en/id) is generic: "The database needs an update. Ask an administrator to apply the pending updates." — no table/column names.

**Rationale**: a missing column/table is never the user's fault and always means a stale schema; 503 distinguishes it from a code bug (500) and lets the SPA react. Matches Constitution IV (no internals leaked).

**Alternatives**: catching per controller (duplicate, misses every other screen); mapping all QueryExceptions (would hide real bugs behind "update required"); showing the real text only when `APP_DEBUG` (still raw text for the very users who hit it).

## Decision 6 — Proactive: `SchemaStatus` + `schema_update_required`

**Decision**: `App\Support\SchemaStatus::pendingMigrations(): array` = migration files (via the framework `Migrator`) minus ran names (`MigrationRepository::getRan()`); returns `[]` if the repository table does not exist yet. `features()` adds `schema_update_required` = `count > 0` **only when the user can access the `settings` menu** (owner/admin), `false` otherwise (no information to cashiers). Computed per call — no cache (once per SPA load; avoids stale "still pending" after an update and any cache-driver dependency). The SPA stores it and `AppShell` shows `SchemaUpdateBanner` ("Database update required — restart the app (Docker applies updates on start) or run `php artisan migrate`").

**Rationale**: owners/admins learn about the stale database before a screen breaks; recovery steps are on screen.

**Alternatives**: auto-running migrations from the web request (dangerous: schema changes under live traffic, permission/locking issues, hidden state change); blocking the whole app like the licence gate 423 (too aggressive — most screens still work).

## Decision 7 — Failure states instead of blank/misleading dialogs

**Decision**: `PurchaseOrderDetailModal`, `AddBomItemModal` and `VariantBomModal` track a `loadError`; on failure they render the message (the server's friendly one for `schema_outdated`) and a **Retry** button that reloads without a page refresh; the selector no longer falls through to "no eligible lines" when the request failed. `api/client.js` toasts a `schema_outdated` 503 once (like 409) and `ApiError` gains `isSchemaOutdated`.

## Decision 8 — Operational recovery is documented, not automated by the feature

**Decision**: the existing dev database is repaired by restarting the app container or `docker compose exec app php artisan migrate` (outside this feature's code). RUNBOOK gets a short "after pulling changes" note; the Docker store image already migrates on start (feature 016).

## Alternatives considered (summary)

| Alternative | Why rejected |
|---|---|
| Treat the errors as code bugs and add defensive fallbacks for missing columns | Would hide a real deployment problem; the fix is applying the updates |
| Run migrations automatically on first failed request | Hidden schema change at runtime |
| Allow deleting non-draft POs | Rejected by the requester (stock/payments/BOM) |
| Per-screen error text for schema problems | Duplicated and incomplete; one renderer covers every endpoint |

## Risks / notes

- `QueryException` SQLSTATE detection: `$e->getCode()` returns the SQLSTATE string for PDO errors; verified by a test that throws a real-shaped `QueryException`.
- Feature 031's pending migration means payment-confirmation screens on the same stale DB will also hit the renderer — covered by the same mechanism.
- The banner is cosmetic guidance; nothing about it is a security boundary.
