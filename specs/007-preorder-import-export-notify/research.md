# Phase 0 Research: Pre-order Import/Export, Printing, Email Notification & Search

## R1: How should customer-name search be added to `GET /preorders`?

**Decision**: Add an optional `search` query param to `PreorderController::index()`, applied as `whereHas('customer', fn ($q) => $q->where('name', 'like', "%{$search}%"))`, combinable with the existing `status`/`event_id`/`customer_id`/`fulfillment` filters (all already `when($request->filled(...))` chains — `search` slots into the same chain).

**Rationale**: The list already eager-loads `customer` and is Eloquent-based (not a raw `DB::table()` join like `ReportController::sales()`), so a `whereHas` is a single added line with no N+1 risk and no DEMO/LIVE scoping gap (`Preorder`'s own `HasDataMode` global scope still applies; `whereHas` against `Customer`, an administrative-adjacent but actually non-`HasDataMode` model — confirmed: `Customer` is NOT in the 20-model `HasDataMode` list in CLAUDE.md — needs no extra mode filter either).

**Alternatives considered**: A separate `GET /preorders/search` endpoint — rejected, it would duplicate every other filter already on `index()` for no benefit; a dedicated search index (Scout/Meilisearch) — rejected as disproportionate for a single-store, low-hundreds-of-rows scale (Scale/Scope in plan.md).

## R2: How should the status-appropriate invoice/receipt be generated?

**Decision**: Client-side, reusing the exact pattern already proven twice in this codebase (`ReceiptModal.vue` for sales, `PurchaseOrderDetailModal.vue`'s invoice tab for POs): a new `GET /preorders/{id}/invoice` endpoint returns the same shaped JSON `show()` already returns (items, customer, payments) plus a `document_type` field computed server-side (`invoice` for `ordered`/`dp_paid`/`arrived`, `receipt` for `settled`/`handed_over`, `cancelled` for `cancelled`) so the frontend never re-implements the status→document-type mapping; a new `PreorderInvoiceModal.vue` renders it and dynamically imports `html2canvas`+`jspdf` on click, identical to `ReceiptModal.vue`'s `captureCanvas()`/`downloadAsPdf()` functions.

**Rationale**: Constitution I forbids a second write/derivation path for something that already has one — the status→label mapping must live in exactly one place (the backend, since it's also needed by the email body in R4) rather than being re-derived in the Vue component and risking drift.

**Alternatives considered**: A server-rendered PDF (e.g. `barryvdh/laravel-dompdf`) — rejected: no PDF-generation package exists in this codebase's `composer.json`, and both prior print features (receipt, PO invoice) deliberately chose client-side rendering per their own research docs; introducing a second, server-side PDF path here would violate the "single sanctioned path" spirit even across features.

## R3: How should export/import work without disturbing the existing four-sheet master-data workbook?

**Decision**: A **separate**, single-sheet workbook (not a fifth sheet in `MasterDataSheets::ORDER`). `GET /preorders/export` reuses `GenericArrayExport` (already used by `ReportController::export()` for a flat array-of-rows shape) fed by the same `present()`-shaped rows `index()` produces (respecting whatever filters were active). `POST /preorders/import` gets its own `PreorderImport` class + `PreorderExportImportService`, but explicitly **mirrors** `MasterDataImportService`'s two load-bearing conventions: (a) full validation pass across every row before any write, one DB transaction for the whole file (all-or-nothing) — not the PRD F15.5 partial-success style; (b) `dry_run=1` support through the identical validation path for a preview, matching F15.4.

**Rationale**: Pre-orders are transactional/financial data, not master data — folding them into the master-data workbook would conflate two conceptually different imports (configuring the shop vs. recording historical transactions) and would force every master-data import to also carry pre-order-shaped columns. A separate, purpose-built workbook keeps `MasterDataSheets::ORDER`'s dependency-order guarantee (spec.md's own FR-007 explicitly says "the file structure ... follows that existing pattern rather than a new authoring style" — matched at the *behavioral* level, all-or-nothing + dry-run, not by literally reusing the same file).

**Alternatives considered**: Extending `MasterDataSheets` with a fifth `preorders` sheet — rejected per the reasoning above; a CSV-only import — rejected, `maatwebsite/excel` already handles `.xlsx` uniformly across every existing import/export in this codebase, introducing a second file format would be inconsistent for no gain.

## R4: How should imported pre-orders resolve/create customers and price their items?

**Decision**: Each import row carries a `customer_name` (+ optional `customer_phone`/`customer_email`) column; `PreorderImport` looks up `Customer::where('name', $row['customer_name'])->first()`, creating a new `Customer` row when no match exists — mirroring `StorePreorderRequest`'s existing requirement that a pre-order always has a `customer_id`, just resolved by name instead of ID since a spreadsheet author won't know internal customer IDs. Item rows resolve `variant_id` by SKU (the same resolution style `stock`-sheet rows already use in `MasterDataImportService`), and price/qty are read from the file (this is *recording a historical transaction that already happened elsewhere*, unlike `POST /orders`' live checkout — so, unlike Constitution IV's "server always recomputes" rule for a live sale, the imported total is the row's own recorded total, validated for arithmetic consistency (qty × unit_price = line_total, sum of lines = total) but not re-priced from current master data, since the whole point of import is to backfill orders taken before/outside this system, possibly at different prices than are current today).

**Rationale**: This is a deliberate, documented exception to "server always recomputes" — flagged explicitly here rather than silently deviating, per Constitution IV's own spirit of documented, not silent, exceptions (mirrors how `Setting::get()`'s `FILTER_VALIDATE_BOOLEAN` choice or the master-data import's F15.5 deviation are both written down, not silently done).

**Alternatives considered**: Re-pricing every imported line from current `ProductVariant.sell_price` — rejected, would silently corrupt a genuinely historical record (an order taken 3 months ago at a since-changed price) into today's price, defeating the feature's purpose.

## R5: How should outgoing email be sent and how does the system detect "not configured"?

**Decision**: Use Laravel's built-in `Mail` facade + a single `PreorderStatusMail extends Mailable`, configured entirely through the existing `.env` `MAIL_*` variables (`config/mail.php`, already present, defaulting to `MAIL_MAILER=log` per `.env.example`) — no new in-app Settings UI for SMTP credentials in this feature's scope (spec.md's Assumptions say "store-configurable SMTP-style settings", satisfied by the `.env` file a technician already edits during installation, consistent with this being a locally-installed, self-serve product with no cloud tier or hosted admin panel). "Not configured" is detected as `config('mail.default') === 'log'` (the shipped default) — a store that has genuinely set up SMTP will have changed this env value away from `log`.

**Rationale**: Introducing a new Settings-backed SMTP config UI would be a much larger, security-sensitive surface (storing SMTP credentials in the `settings` table, which today holds no secrets) for a feature whose spec only asks for "send ... to customer email", not "let admins configure email from the UI" — out of scope per the spec's own P3 framing and the "smallest viable slice" spirit of every prior feature's independent-story design.

**Alternatives considered**: A `settings` row holding SMTP host/port/credentials, editable from the Settings screen — rejected as scope creep beyond what spec.md asked for; can be a natural follow-up feature if requested.

## R6: How is a failed/skipped notification made visible to the operator (FR-013/SC-006)?

**Decision**: A new `preorder_notifications` table (NOT `HasDataMode` — like `activity_logs`, `payment_channels`, this is operational/administrative metadata about the store's own actions, not customer-facing business/transactional data subject to the DEMO/LIVE boundary) with `preorder_id`, `trigger` (`status_change`|`manual_resend`), `status` (`sent`|`skipped_no_email`|`skipped_not_configured`|`failed`), `error_message` (nullable), `sent_at`. `PreorderController::show()`'s response includes the pre-order's most recent notification attempt so the existing Pre-order detail screen can show it without a new API round-trip.

**Rationale**: Matches the spec's own Key Entity "Notification attempt" and FR-013's explicit "MUST be visibly reported to the user" — an audit trail table is the same mechanism `ActivityLogger` already establishes for "something happened, make it queryable," just scoped to this one narrow concern rather than overloading the general `activity_logs` table with a different row shape (email `status`/`error_message` fields the generic activity log doesn't have).

**Alternatives considered**: Reusing the generic `activity_logs` table with `action = 'preorder.notification_failed'` — rejected: `activity_logs` rows are free-text `description`s (per its existing usage), not structured enough to reliably drive "does this pre-order have a working notification" UI state without string-matching descriptions, which is fragile.

## R7: When exactly does the status-change email fire?

**Decision**: `PreorderService::transitionStatus()` (or wherever the status write actually commits — confirmed call site during implementation) calls `PreorderNotifier::notifyStatusChange($preorder)` **after** the status-transition DB transaction commits, not inside it. Email delivery is a slow, external, and failure-prone operation (SMTP round-trip) — Constitution IV's "audit log in the same transaction" rule applies to *mutations*, and this notify call deliberately does not hold the business-mutation transaction open waiting on network I/O; the `PreorderNotification` audit row is written by `PreorderNotifier` itself in its own small transaction, immediately after the send attempt (success or failure), so it's still always accurate even though it's a separate transaction from the status change.

**Rationale**: A slow or down mail server must never make the underlying status change (a real business event: the order arrived, the customer paid) fail or roll back — spec.md's own edge case explicitly requires "the failure ... must not block the underlying business action."

**Alternatives considered**: A queued job (`ShouldQueue`) dispatched from within the transaction — rejected for this iteration: this product has no queue worker process running by default (single-machine, `php artisan serve`, no `queue:work` daemon documented anywhere in `docs/RUNBOOK.md`), so a queued mailable would silently never send unless a worker happens to be running; a direct, synchronous send with try/catch keeps behavior predictable on this deployment shape. (A queue-backed send is a reasonable future improvement if this product ever gains a persistent worker process — noted here, not built.)
