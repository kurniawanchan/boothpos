# Phase 0 Research

## R1: Runtime theme color — how to override `@theme` tokens without a second styling system

**Decision**: Tailwind v4's `@theme` block in `resources/css/app.css` compiles
`--color-brand`/`--color-brand-hover`/`--color-brand-active` (and every
other token) into real CSS custom properties on `:root`, and every
generated utility (`bg-brand`, `text-brand-active`, `border-brand`, …)
references them via `var(--color-brand)` rather than inlining the hex at
build time. A saved custom color is therefore applied at runtime by setting
`document.documentElement.style.setProperty('--color-brand', hex)` (plus
programmatically-derived `--color-brand-hover`/`--color-brand-active`
shades) once on app boot, after `GET /settings/features` resolves — no
component, no Tailwind rebuild, and no second theming mechanism needed.

**Rationale**: This is the only way to satisfy Constitution III's "no raw
hex literals outside the token sheet" rule while still letting a store
customize one token at runtime: the override happens exactly at the token
layer the constitution already designates as the sole place hex values are
allowed to live, just relocated from build-time CSS to a runtime
`style.setProperty()` call driven by a stored setting.

**Alternatives considered**:
- **CSS-in-JS / a second design-token system**: rejected outright — directly
  contradicts the "single token sheet" rule and would require every
  component to be touched.
- **Server-side Tailwind rebuild per store**: rejected — this product ships
  as a static built SPA per install; rebuilding CSS per customer defeats
  the whole "install once" model and isn't feasible from a browser action.

## R2: Split payment — already ~80% built on the backend

**Decision**: No backend schema change for split payment on the order path.
`app/Http/Requests/StoreOrderRequest.php` already validates
`payments => ['required','array','min:1']` and
`OrderService::create()` (lines ~102-133) already sums all entries,
rejects insufficient total, and rejects non-cash overpay. The entire gap is
`PosPaymentModal.vue`/`PaymentPanel.vue`/`PosView.vue`, which construct and
submit exactly one payment object (`payments: [payment]`, `PosView.vue`
line ~181) — `PaymentPanel.vue` has no concept of "add another entry" or a
running remaining-balance display. Work is: rework `PaymentPanel.vue` to
hold an array of entries with a live remaining-balance computed value,
gate the submit button on remaining === 0, and pass the full array through
`PosView.vue`/`createOrder()` unchanged on the wire format.

For **pre-orders**, `PreorderController::storePayment()` accepts one
payment per call (not an array) — but a preorder already supports multiple
payments over its lifecycle (down payment, then settlement, as separate
calls), so split payment there is implemented as the frontend issuing the
existing single-payment endpoint once per entry in sequence, not a new
array-accepting endpoint. This avoids touching an already-working,
already-tested endpoint contract.

**Rationale**: Reuse over rebuild (Constitution I) — the sum/validation
logic already exists and is already covered by `OrderService` tests;
duplicating it in a new endpoint would be the exact kind of second write
path the constitution prohibits.

**Alternatives considered**:
- **New dedicated split-payment endpoint**: rejected — `POST /orders`
  already does this; a parallel endpoint would fork validation logic.
- **Array-accepting preorder payment endpoint**: rejected for this feature
  — a larger, riskier change to an existing tested contract than looping
  the existing one-at-a-time call from the frontend, for the same
  end-user-visible result.

## R3: Payment notes — real column, half-wired

**Decision**: `payments.notes` already exists (`Payment.php` fillable list
includes `notes`; `PaymentRecorder::record()`'s docblock already types its
input as accepting `notes:?string`). `PreorderController::storePayment()`
already validates and forwards `notes`. The only backend gap is
`StoreOrderRequest`'s `payments.*` rules, which is missing a
`'payments.*.notes' => ['nullable', 'string', 'max:1000']` line, and
`OrderService::create()`'s per-entry loop (needs confirming it forwards
`notes` into `PaymentRecorder::record()` alongside method/amount/etc — to
verify and fix during implementation if the loop currently drops it). The
frontend gap is universal: `PaymentPanel.vue` collects no note field at
all, for either flow.

**Rationale**: Same reuse-first reasoning as R2 — this is a validation-rule
and UI gap on top of an existing column, not new schema.

## R4: Materials have no stock concept — genuinely new capability

**Decision**: `StockService::applyMovement()` is typed to `ProductVariant`
only, and `Material` (`app/Models/Material.php`) has no `current_stock`
column — it exists purely for BOM cost-reference pricing
(`referencePrice()`, `VendorMaterialPrice`). Spec FR-003 ("Received MUST
add stock to the material... through the same single sanctioned
stock-mutation path already used elsewhere") cannot literally reuse
`StockService`, because a `Material` is not a `ProductVariant`. This
feature therefore adds: a `materials.current_stock` column, and a new
`material_stock_movements` table + `MaterialStockService::applyMovement()`
method, structurally mirroring `stock_movements`/`StockService` exactly
(append-only history, row-locked balance update in one transaction) but
scoped to `Material` — a parallel sanctioned path for a parallel entity,
not a fork of the existing one. Movement `type` for this new table starts
with just `purchase` (a PO being Received) — `adjustment` is left for a
future manual-correction feature, out of this spec's explicit scope.

**Rationale**: Constitution I requires exactly one sanctioned write path
**per concern** — `ProductVariant` stock and `Material` stock are different
concerns (finished-goods inventory vs. raw-material inventory) that happen
to share a design pattern, not the same concern. Mirroring the proven
append-only + row-lock pattern (rather than inventing a new one) satisfies
Constitution IV's audit-trail expectation for anything that "adjusts
stock", generalized here to raw materials.

**Alternatives considered**:
- **No material stock tracking at all — PO Received only records a
  purchase history row**: considered and rejected as under-delivering on
  spec FR-003/SC-002, which explicitly ties Received to "materials' stock
  increasing" and measures it ("zero discrepancy against stock-movement
  history"). If a future clarification decides material stock tracking is
  out of scope after all, this is the fallback to fall back to.
- **Extend `stock_movements`/`StockService` to be polymorphic
  (variant-or-material)**: rejected — `stock_movements.variant_id` is a
  non-nullable FK today; making it polymorphic touches every existing
  caller and every existing report/query that already joins on it, for a
  new entity type that has different fields (materials have no SKU,
  price, or category). Higher blast radius than a new parallel table.

## R5: Purchase Order status workflow — mirror `PreorderService`'s transition-guard pattern

**Decision**: `PurchaseOrderService::transitionStatus()` mirrors
`PreorderService`'s existing shape exactly: a `match`/lookup of allowed
`(from, to)` pairs, throwing `ValidationException` (→ controller catches
and returns `409`) on an invalid transition, matching
`PreorderController::updateStatus()`'s existing try/catch shape line for
line. Allowed transitions: `draft→ordered`, `ordered→received`,
`received→paid`, and `{draft,ordered}→cancelled`. `received`/`paid`/
`cancelled` are terminal (no further transition, matching how `preorders`
already treats `handed_over`/`cancelled` as terminal).

**Rationale**: This exact pattern already exists, is already tested, and
is already the established idiom in this codebase for "a record with a
constrained status lifecycle, gated by 409 on violation" — reusing it
minimizes new patterns a future maintainer has to learn.

## R6: Purchase order invoice printing — reuse the existing client-side PDF pattern

**Decision**: `ReceiptModal.vue`'s pattern (dynamically `import('html2canvas')`
→ render a DOM node to a PNG → dynamically `import('jspdf')` → wrap the PNG
into a single-page PDF sized to the content) is reused as-is for a new
`PurchaseOrderInvoice.vue` component/view. No backend PDF generation is
introduced.

**Rationale**: `jspdf`/`html2canvas` are already dependencies (confirmed in
the production build's chunk list); this keeps the bundle unchanged and
reuses a pattern already proven correct in this codebase, rather than
adding a server-side PDF library (Constitution V: no new dependency without
justification, and this one is entirely avoidable).

## R7: Activity Log — backend already exists, only the frontend screen is missing

**Decision**: `ActivityLogController::index()` already exists, is already
gated by `canAccessMenu('reports')`, and its own code comment states
"belum punya layar/menu tersendiri di frontend" (no dedicated frontend
screen yet). This feature adds only `ActivityLogView.vue` + an
`AppSidebar.vue` nav entry (gated by the same `reports` menu key so no new
authorization concept is introduced) + `resources/js/api/activityLog.js`.
The endpoint's existing filter/pagination parameters are read from its
current implementation during Phase 1 design rather than guessed.

**Rationale**: Directly avoids rebuilding a working, tested backend surface
— this user story is frontend-only work.

## R8: POS drafts — new table, no stock/session coupling

**Decision**: A new `pos_drafts` table stores a JSON snapshot of cart state
(items with variant_id/qty/discount, customer_id, discount_amount) plus
`user_id`/`data_mode`/`created_at`, uses `HasDataMode` like every other
transactional table in this system. `PosDraftService` is thin CRUD with an
ownership check (a cashier only sees their own drafts, matching how a
cashier session is already scoped to `user_id`). No relation to
`cashier_sessions` — a draft's saved cart is resumed independent of which
session happens to be open when it's resumed, per the confirmed answer
that a draft carries no session-specific state.

**Rationale**: A JSON snapshot (rather than normalized draft-line rows) is
the appropriate shape here because a draft is explicitly *not* a
transactional record with its own referential integrity guarantees (per
spec: it must gracefully degrade if a referenced variant/customer later
disappears, "flag the affected line" rather than enforce a live FK) — a
loosely-validated snapshot is the correct data shape for that requirement,
not a mistake to "fix" into normalized rows later.

**Alternatives considered**:
- **Normalized `pos_draft_items` table with FKs**: rejected — would force
  exactly the FK-integrity behavior the spec explicitly says NOT to have
  (a deleted variant should surface as a flagged line, not cascade-delete
  or hard-fail the draft).

## R9: Per-artist opening cash — additive table, existing column untouched

**Decision**: New `session_opening_cash_entries` table
(`session_id`, `artist_id` nullable [nullable = the existing
non-attributed amount], `amount`). `cashier_sessions.opening_cash` is
**left as-is** and becomes a generated/derived display value (sum of its
entries) rather than being removed — per the confirmed answer that old
sessions without per-artist detail must keep working unchanged.
`CashierSessionController::store()` gains an optional
`opening_cash_entries: [{artist_id, amount}]` array alongside the existing
required `opening_cash` scalar; when entries are provided, the server
recomputes and reconciles rather than trusting the client to have summed
correctly (Constitution IV — server is the source of truth for money).

**Rationale**: Additive-only change preserves every existing caller/test of
`CashierSessionController::store()` and `opening_cash`, exactly matching
the confirmed "additive, not a replacement" direction.

## R10: Reports — match the existing `ReportController` pagination/filter/export convention

**Decision**: `purchases()` and `stockByArtist()` are added as new methods
on `ReportController` (not a new controller), following the exact
convention already established by `sales()`: `DB::table()` base query with
explicit `data_mode` filter (Constitution's flagged mode-scoping gotcha,
same as every existing report), `date_from`/`date_to`/entity filters via
`$request->filled()`, and an `export()` path consistent with the existing
`GET /reports/{report}/export` mechanism.

**Rationale**: One reporting controller with one established pattern beats
a second one — same reasoning as 005-ux-enhancements-dashboard's decision
to extend `ReportController` rather than build a parallel
`DashboardController`.
