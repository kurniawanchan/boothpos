# Phase 0 Research: Pre-order Invoice & CRUD Overhaul

All three spec-level ambiguities were resolved before planning (edit
allowed until Handed over/Cancelled; delete only while Ordered;
import/export layout replaces the old one). This document records the
technical decisions made translating those into this codebase's existing
patterns — several of which turned out to already exist almost verbatim
for the POS sale receipt, which materially de-risks this feature.

## Decision 1: The invoice's store-identity block is copied from `OrderController::receipt()`, not designed fresh

**Decision**: `PreorderController::invoice()`'s response gains the exact
same fields `OrderController::receipt()` already assembles for the POS
sale receipt: `store_name` (mode-aware demo/live key), `store_contact`,
`store_address`, `store_logo_url` (respecting the existing
`receipt_show_logo` toggle), `store_contact_person`,
`store_contact_phone`, `store_contact_email`, `receipt_footer_text`.

**Rationale**: This is not new scope to design — feature 001 (User Story
3) and feature 006 (US7) already built and shipped this exact assembly
for `ReceiptModal.vue`, including the "omit entirely if unset, don't
render an empty block" degrade-gracefully behavior the spec's Edge Cases
ask for verbatim. Copying it means the pre-order invoice inherits already
-correct behavior (logo toggle, demo/live store name, graceful omission)
instead of re-deriving each of those rules from scratch and risking a
subtly different bug. `PreorderInvoiceModal.vue`'s header markup is
likewise adapted from `ReceiptModal.vue`'s existing logo/name/address
header block, not invented from the external reference images alone — the
reference images informed *tone* (a real invoice, not a bare list), the
actual field set and null-handling come from this codebase's own working
precedent.

**Alternatives considered**: Designing the store-identity block fresh
from the attached mood-board images — rejected: those images are useful
for overall visual tone (a proper invoice header, a payment-terms
section, a closing line) but this codebase already has a working,
tested version of exactly this sub-problem; re-deriving it independently
risks reintroducing bugs (e.g. the logo-toggle/demo-mode-name edge cases)
that were already found and fixed once.

## Decision 2: Payment channels on the invoice are always shown unmasked

**Decision**: The invoice's payment-channel list (the "payment terms"
data, per spec Assumptions) is assembled directly in
`PreorderController::invoice()` via
`PaymentChannel::where('is_active', true)->orderBy('display_order')->get()`
with the **full, unmasked** `account_number` — not routed through
`PaymentChannelController::index()`'s existing role-based masking.

**Rationale**: `PaymentChannelController::index()`'s masking exists to
protect account numbers from being casually visible to lower-trusted
staff *browsing the settings screen* — a completely different concern
from a document meant to be handed to (or emailed to) the customer so
they can actually pay. Masking the account number on the customer's own
invoice would make the invoice useless for its stated purpose (FR-009).
This is a narrow, deliberate exception scoped to this one customer-facing
document, not a general loosening of the existing masking rule anywhere
else in the product.

**Alternatives considered**: Reusing `GET /payment-channels` as-is
(masked for non-privileged roles) — rejected outright, defeats the
feature's purpose; the customer reading the invoice was never the
audience the masking rule was protecting against.

## Decision 3: Pre-order edit/delete — exact stock and guard logic

**Decision**: New `PreorderService::update(Preorder $preorder, array $data): Preorder`
and `PreorderService::delete(Preorder $preorder): void`.

- `update()`: refuses (`ValidationException`) if
  `$preorder->status` is `handed_over` or `cancelled` (FR-001a). Otherwise:
  recomputes `subtotal`/`total_amount` from the new item list exactly like
  `create()` already does; replaces `preorder_items` (delete removed
  lines, update changed quantities, insert new lines — same
  `sku_snapshot`/`cost_price`/`sell_price` snapshot rule as `create()`,
  re-read from the variant at edit time since this is a *new* line being
  added/resized, not a rewrite of history for lines that didn't change).
  If `status` is `arrived` or later (stock was already increased for the
  *old* item list), the stock effect is corrected by computing the
  **delta per variant** between the old and new quantities and applying
  one `StockService::applyMovement()` call per changed variant (type
  `purchase`, `qtyChange` = new − old, which may be negative) — never a
  blanket "reverse everything then reapply everything," which would
  create spurious zero-sum movement pairs in `stock_movements`' append
  -only history for variants that didn't actually change.
- `delete()`: refuses (`ValidationException`, `409`-mapped by the
  controller) unless `$preorder->status === 'ordered'` **and**
  `$preorder->payments()->doesntExist()`. Since "Ordered" is checked
  first and no stock movement or payment can exist at that status in this
  product's own state machine (`arrived`/`handed_over` are the only
  statuses that ever write stock movements, and `dp_paid` is the earliest
  a payment can exist), the payment check is a defensive belt-and-braces
  guard, not a scenario this code path expects to actually hit — but it's
  cheap and matches FR-004's own wording exactly. Deletion itself removes
  the `preorder_items` rows and the `preorders` row in one transaction;
  no stock reversal call is made (none was ever applied at "Ordered").

**Rationale**: Directly implements the resolved Question 1/Question 2
answers. The per-variant-delta approach for stock (rather than
reverse-then-reapply) avoids doubling the audit trail's row count for
every edit and avoids a moment where stock briefly reads zero for a
variant mid-edit under concurrent access.

**Alternatives considered**: Reverse-then-reapply (delete all of the old
item's stock movements' effects, then create fresh ones for the new item
list) — rejected: `stock_movements` is documented append-only history;
this approach would record two movements (one negative, one positive) for
every variant even when only one *other* line item on the same order
changed, needlessly noisy for anyone auditing stock history later.

## Decision 4: Shipment address consolidation — drop columns, don't just hide them

**Decision**: A migration drops `shipments.city` and
`shipments.postal_code` outright (not just stops asking for them in the
form). `ShipmentController::store()`/`update()`'s validation rules for
`city`/`postal_code` are removed; `address_line` remains the one address
field (already `string, max:255`, already required).

**Rationale**: The spec is explicit ("no longer exist as separate
fields") — leaving unused nullable columns around when the feature
explicitly says this data now lives in one field would leave a second,
dead path for address data to (incorrectly) end up split across two
places again later. This mirrors how this codebase already prefers a
real schema change over a cosmetic one when a field is genuinely retired
(e.g. Customer's own single `address` field, added in feature 020,
established the "one address field, no city/postal breakdown" pattern
this shipment change now matches).

**Alternatives considered**: Keep the columns, nullable, just stop
collecting them in the form — rejected: leaves dead schema and a latent
inconsistency (what if some future code path still writes to them?) for
zero benefit, when a clean drop is equally simple.

## Decision 5: Bulk invoices stay client-side — no server-side PDF generation

**Decision**: "Bulk download" runs client-side: for each selected
pre-order (sequentially, not in parallel, to avoid freezing the tab), the
existing `PreorderInvoiceModal.vue` rendering path (fetch → render into an
offscreen DOM node → `html2canvas` → `jsPDF`) runs once, and each
resulting PDF is added to a `JSZip` archive, downloaded as one `.zip` at
the end. "Bulk email" does **not** attach a generated PDF at all — it
sends a new `App\Mail\PreorderInvoiceMail` whose HTML body contains the
same information the invoice document shows (items, total, payment
channels, footer), reusing `PreorderNotifier`'s existing "record every
attempt, skip missing email, skip when mail isn't configured" pattern
(`PreorderNotification` rows), triggered once per selected pre-order.

**Rationale**: This codebase has a firm, repeatedly-documented rule
(feature 007, 008, and every receipt/invoice component's own docblock):
every document (receipt, PO invoice, pre-order invoice, payment receipt)
is rendered **client-side only** — `html2canvas` + `jsPDF` — specifically
so there is exactly one template per document (the Vue component),
instead of a second, PHP-side template that could visually drift from the
first. Introducing server-side PDF rendering just for the bulk-email case
would create precisely that drift risk this codebase has consistently
avoided. A rich HTML email body (not a PDF attachment) is a fully
legitimate way to "send an invoice by email" that doesn't require
duplicating the invoice's visual template on the server.

**Alternatives considered**:
- Server-side PDF generation (e.g. a headless-browser or PHP PDF library)
  for the email attachment only — rejected: introduces the exact
  second-template-drift risk this codebase's existing documents all
  avoid, for one feature's email case only.
- Bulk email that asks the browser to generate each PDF and upload it to
  the backend to attach to the outgoing email — technically avoids a
  second template, but adds a much larger, stateful upload/attachment
  pipeline for a business need ("the customer can see their invoice
  details in an email") that a well-formatted HTML email body already
  satisfies without it.

## Decision 6: Customer picker's default (no-search) list reuses the existing paginated endpoint, triggered on open

**Decision**: `CustomerSearchDropdown.vue` calls the same
`listCustomers({ search: '', per_page: 10 })` on `open()` (before any
typing), populating the same scrollable results list search already
fills — the dropdown panel's `max-h-[360px] overflow-y-auto` (already
present, built in feature 021) already provides the scrollbar the spec's
Edge Case/User Story 6 explicitly asks for; no new scroll container is
needed, only triggering the fetch earlier.

**Rationale**: `GET /customers` already returns an unfiltered, paginated
page when `search` is empty/omitted (`CustomerController::index()`'s
`when($request->filled('search'), ...)` guard simply skips the filter) —
this is a "call the same thing sooner," not a new capability.

**Alternatives considered**: A separate "recent/frequent customers"
endpoint shown by default, falling back to search results once typing
starts — rejected as unnecessary scope; the spec only asks to see *a*
list to scroll, not a curated one, and the plain alphabetical/default-
order list `GET /customers` already returns satisfies that.

## Decision 7: Import/export column layout — full mapping

**Decision**: `PreorderExportImportService`'s `HEADINGS` becomes:
`event_name, fulfillment, pickup_day, products, quantities, unit_prices,
shipping_cost, courier_name, expected_date, discount, notes` (one row per
order — no more multi-row grouping). Column semantics:

- `event_name`: matched against `Event::where('name', $value)` (scoped by
  the active data mode, same as every other Eloquent lookup in this
  service) — ambiguous/duplicate event names are a row error, not a
  silent first-match guess.
- `fulfillment`: accepts `"pickup"` or `"mail order"` (case-insensitive),
  mapped to the stored `pickup`/`courier` values (FR-007's label-only
  rename from feature 021 — the enum value is still `courier` internally).
- `pickup_day`: accepts `"Day 1"`, `"Day 2"`, etc. (case-insensitive,
  tolerant of extra whitespace), resolved against the row's matched
  event's date range to the same real date `pickup_day` already stores
  (feature 021's Decision 2) — `"Day N"` where `N` exceeds the event's
  span is a row error.
- `products` / `quantities`: comma-separated, split and matched by
  position (`products[i]` ↔ `quantities[i]`); a count mismatch (FR-018) or
  an unresolvable product (by `sku`, matching the existing single-item
  lookup's own field) is a row error naming the row and the offending
  entry.
- `unit_prices`: comma-separated, same positional matching as `products`/
  `quantities` — kept from the existing format's per-item `unit_price`
  column (historical, as-entered pricing, research.md R4 from feature
  007), just repositioned into the same one-row-per-order shape.
- `shipping_cost`, `courier_name`, `expected_date`, `discount`, `notes`:
  order-level values, same validation rules already built for these
  fields in feature 021's `POST /preorders` and this service's own prior
  cross-field checks (courier only valid for `mail order`, pickup day
  only for `pickup`, etc.) — reapplied here against the new single-row
  shape instead of the old grouped-rows shape.

**Rationale**: Directly implements FR-017/FR-018 and the resolved
Question 3 (full replace). Keeping `unit_price` (not in the user's literal
list, but load-bearing for the existing historical-pricing behavior
feature 007 already established) avoids silently regressing that
already-documented, already-tested behavior while still satisfying every
explicitly requested column.

**Alternatives considered**: Keep the old row-per-item grouping *and*
accept comma-separated lists as an alternative — rejected by the
resolved Question 3 (full replace, not two coexisting shapes).

## Decision 8: QR enlarge + click-to-popup lands in the one shared `ChannelPicker.vue`

**Decision**: `ChannelPicker.vue`'s QR `<img>` (currently `h-40 w-40`)
grows to a larger fixed size (e.g. `h-56 w-56`) and gains a click handler
opening a simple full-size image lightbox (a small new
`ImageLightbox.vue` or reusing `BaseModal` with just the image at natural
size) — implemented once, in the one component both POS checkout and
pre-order settlement already share.

**Rationale**: Constitution I — a value/behavior needed in more than one
place gets exactly one definition. `ChannelPicker.vue` is already that
one place for QR display; the change belongs there, not duplicated into
a pre-order-specific copy.

**Alternatives considered**: A pre-order-only enlarged QR view — rejected,
would duplicate `ChannelPicker.vue`'s QR-rendering logic for no reason
tied to this feature (the spec never asks for POS's QR to stay small
while pre-order's grows — enlarging the one shared component benefits
both, consistently).
