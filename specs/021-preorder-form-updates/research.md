# Phase 0 Research: Pre-order Form & Workflow Updates

No `NEEDS CLARIFICATION` markers remain in the Technical Context — both
spec-level ambiguities were resolved before planning (pickup day derived
from event dates; discount is a fixed Rupiah amount). This document records
the concrete technical decisions made while translating the spec into this
codebase's existing architecture.

## Decision 1: Courier default lives on `Preorder`, not on `Shipment`

**Decision**: Add `preorders.courier_name` (nullable string, default-filled
to `'JNE'` client-side when "Mail Order" is chosen) rather than creating a
`Shipment` row at preorder-creation time.

**Rationale**: `shipments.recipient_name`, `recipient_phone`, `address_line`,
and `city` are all `NOT NULL` today (`2026_10_05_000001_create_preorders_tables.php`),
and `ShipmentController::store()` requires them (`'required'` in its
validation) — a `Shipment` is deliberately a *separate, later* step in this
product's existing workflow (confirmed by that controller's own comment:
cashier is allowed to hit this endpoint, and a preorder that isn't
`fulfillment=courier` yet gets a `409`, not a validation error — this is a
distinct lifecycle stage, not part of creation). Loosening those `NOT NULL`
columns just to let a courier be chosen earlier would weaken a real,
already-tested invariant (a shipment always has somewhere to actually ship
to) for no benefit — the spec only asks for the courier *choice* to happen
early, not for the full shipment to exist early.

**Alternatives considered**:
- Make `shipments`' address fields nullable and create a partial `Shipment`
  row at preorder-creation time — rejected: touches a working, tested
  invariant (`test_shipment_can_only_be_created_for_courier_fulfillment` and
  siblings) for a field that's genuinely just a preference/default, not a
  real shipment yet.
- Store the courier default in a new, separate table — rejected as
  over-engineering for one nullable string column with no relationships of
  its own (Constitution I).

**Consequence**: `ShipmentController::store()`'s `courier_name` field
becomes a dropdown *on the frontend only* (same shared list, see Decision
5), pre-filled from `preorder.courier_name` when the shipment form opens,
but remains its own required, independently-editable string column — a
staff member can still pick a different courier at actual shipping time
than what was chosen at order time (e.g. the customer's preferred courier
turned out to be unavailable).

## Decision 2: Pickup day is stored as a real date, derived from the linked event

**Decision**: Add `preorders.pickup_day` (nullable `date` column, not an
integer "1"/"2"). `PreorderService::create()` computes the set of valid
dates as every calendar day from the linked `Event`'s `start_date` to
`end_date` inclusive; the chosen `pickup_day` must be one of those dates or
the request is rejected. When there is no linked event, `pickup_day` is
not accepted at all (FR-008a) — there's no range to validate against.

**Rationale**: The resolved Question 1 (Option B) explicitly asked for the
value to reflect the event's real dates, not a generic label — a `date`
column lets `present()`/the invoice literally show "12 Okt 2026" instead of
reconstructing a date from a bare integer plus the event's start date every
time it's displayed (duplicate logic in every reader, vs. computed once at
write time). Storing the *chosen date itself* is also what makes FR-009a
(clearing an out-of-range day if the event's dates change) a simple
range-membership check, not a re-derivation.

**Alternatives considered**: Store a small integer "day index" (1, 2, 3…)
plus recompute the actual date from the event whenever displayed —
rejected: every future reader (invoice, reports, exports) would need to
re-fetch the event and redo the date-arithmetic, and a *later* change to
the event's `start_date` would silently shift the meaning of a
previously-saved "Day 1" without anyone editing that preorder — a real
correctness risk (exactly the kind of "past transaction's numbers silently
rewritten by a later master-data change" Constitution IV warns against for
money; the same principle applies here to a saved commitment date).

## Decision 3: Discount validated and applied exactly like `shipping_cost` — added to items, not multiplied

**Decision**: `PreorderService::create()`/`PreorderExportImportService::import()`
compute `total_amount = subtotal + shipping_cost - discount`, rejecting a
`discount` that would make this negative (`ValidationException`, matching
the existing `customer_id`-not-found pattern in the same method — a 422,
not a 409, since this is a request-shape problem, not a business-state
conflict).

**Rationale**: Resolved Question 2 (Option A) — a flat amount, computed
once at creation time and stored, never re-derived later (same "immutable
snapshot" principle Constitution IV already applies to `sell_price`/
`cost_price` on each line item — a later price change must never rewrite
a past preorder's total, and neither should a later *discount policy*
change).

**Alternatives considered**: Percentage-of-subtotal — explicitly rejected
by the resolved clarification.

## Decision 4: "Courier" → "Mail Order" is `id.json`/`en.json` copy only; `fulfillment` enum value stays `courier`

**Decision**: No database migration touches the `fulfillment` enum or any
stored `'courier'` value. Every `t('preorders.fulfillment_courier')`-style
translation key's *string* changes to "Mail Order" (and its Indonesian
equivalent); no key is renamed.

**Rationale**: A stored enum value is not user-facing — renaming it would
require a data migration touching every existing preorder row for zero
functional gain, and would risk breaking any hardcoded `'courier'`
comparison across the codebase (`ShipmentController`, `PreorderService`,
tests) for a change the spec itself frames as label-only (FR-007: "no
functional change to what the option itself does").

**Alternatives considered**: Rename the enum value to `mail_order` with a
data migration — rejected as unnecessary scope/risk for a label change;
would also require touching `ShipmentController::store()`'s
`$preorder->fulfillment !== 'courier'` check and its own passing test.

## Decision 5: One shared courier list (`app/Support/Couriers.php`), not duplicated per screen

**Decision**: A small, single `App\Support\Couriers` class (mirrors
`MasterDataSheets.php`'s "one definition, multiple callers" role) holding
the fixed list (JNE default, plus J&T, SiCepat, Pos Indonesia, Other — per
spec's Assumptions) — read by both the new-preorder form's courier
dropdown and the existing shipment-creation form's (now-a-dropdown)
`courier_name` field, and by import validation to check a courier value is
one of the known options.

**Rationale**: Constitution I — a value list needed by more than one
caller gets exactly one definition. Without this, the two dropdowns (and
import validation) could silently drift out of sync over time (e.g. a
courier added to one form but not the other).

**Alternatives considered**: Hardcode the list separately in each Vue
component — rejected, exactly the duplication Constitution I forbids;
also makes import validation's "is this a known courier" check impossible
to keep in sync without a third, independent copy.

## Decision 6: Import/export cross-field validation lives in `PreorderExportImportService`'s existing per-row validation pass

**Decision**: Extend the existing per-row validation loop (the same one
that already checks `sku`/`qty`/`event_id`) with: `discount` optional
numeric ≥ 0 and not exceeding subtotal+shipping (same rule as the form);
`pickup_day` only accepted (and required) when `fulfillment=pickup` *and*
the row's `event_id` resolves to an event whose date range includes that
date; `courier_name` only accepted when `fulfillment=courier`, and must be
one of `Couriers`' known values. A `pickup_day` on a `courier`-fulfillment
row (or vice versa) is a row-level error (FR-014), added to the same
`row_errors` array the all-or-nothing transaction already reports.

**Rationale**: This is the identical "validate everything, then one
transaction" shape `PreorderExportImportService::import()` already uses
(research.md decisions from feature 007) — the new fields are just more
columns validated the same way, not a new validation mechanism.

**Alternatives considered**: Silently ignore a mismatched value (e.g. drop
a courier value on a pickup row) — rejected per spec's own FR-014, which
explicitly asks for this to be a reported error, not silent data loss.

## Decision 7: Quantity direct-entry is frontend-only

**Decision**: `PreordersView.vue`'s item row gains a bound numeric input
(min `1`) alongside the existing +/- buttons, both writing to the same
`item.qty` value already sent to `POST /preorders`. No backend change —
`StorePreorderRequest`'s `'items.*.qty' => ['required', 'integer', 'min:1']`
already accepts any qty ≥ 1 by whatever means the frontend collected it.

**Rationale**: The backend has never restricted *how* a quantity was
chosen, only what value is acceptable — this is purely a frontend
convenience matching FR-006, requiring zero server-side change.

## Decision 8: Customer dropdown reuses `BaseMultiSelect.vue`'s mechanics, not a generic new combobox primitive

**Decision**: New `resources/js/components/preorder/CustomerSearchDropdown.vue`
(single-select, remote debounced search) borrows `BaseMultiSelect.vue`'s
proven `Teleport`/fixed-position-panel/click-outside/scroll-close pattern,
but replaces its local `filteredOptions` computed with the same debounced
`listCustomers({ search, per_page: 10 })` call `CustomerPickerModal.vue`
already makes — and keeps that same component's "continue as walk-in" and
"add new customer" inline actions, relocated into the dropdown panel.
`PreordersView.vue`'s button-that-opens-`CustomerPickerModal` is removed
from the *pre-order* create form once the new component replaces it there.
**Correction found while planning**: `CustomerPickerModal.vue` is also used
by `PosView.vue` (the POS checkout screen) — entirely out of this
feature's scope (the spec only asks about the Pre-order form). So that
component is NOT deleted or modified; it stays exactly as-is for POS,
and `CustomerSearchDropdown.vue` is a new, additional component used only
by the pre-order create form — this is two components serving two
different screens' otherwise-similar-looking needs, not duplication of the
same concern (POS's flow isn't being changed by this feature at all).

**Rationale**: `BaseMultiSelect.vue`'s positioning/accessibility mechanics
are already correct and tested in production for this exact family of
problem (a dropdown panel anchored to a trigger, closing on outside click/
scroll/resize) — copying that shape avoids re-solving the same UI
plumbing bugs a second time, while the *content* of the panel (remote
search results instead of a static option list) is different enough that
extending `BaseMultiSelect` itself with a "remote" mode would bolt an
unrelated concern onto a component eight-plus other screens already
depend on (mirrors why `BaseMultiSelect` itself was built new instead of
extending `BaseSelect.vue` — research.md precedent from feature 005).

**Alternatives considered**: Keep the two-modal flow but make the *inner*
modal's list update live as you type (already true today) and just call
that "good enough" — rejected: the spec explicitly asks for one
interaction, not two, and this was written from a real observed pain
point (the screenshot shows the exact two-modal stacking being replaced).

## Decision 9: The New Preorder form needs an event selector added — it has none today

**Discovery**: `PreordersView.vue`'s create form never sets `event_id` at
all today — there is no event picker anywhere in it (`submitCreate()`'s
payload has no `event_id` key), even though `StorePreorderRequest`/
`Preorder` already support an optional `event_id`, and the *list* screen's
filter bar has an event filter (a different, pre-existing control, not
part of the create form). This was not visible from the spec or the
screenshot alone — found by reading the actual component while planning
Decision 2, which depends on a linked event existing.

**Decision**: Add a plain `BaseSelect` event dropdown to the create form
(reusing `listEvents()` from `resources/js/api/events.js`, already used
elsewhere in this codebase, e.g. the Products/POS event-scoped screens),
optional like `event_id` already is server-side. The pickup-day picker
(Decision 2) only appears once both "Self Pickup" is chosen **and** an
event has been selected — otherwise FR-008a's "no event, no pickup-day
field" rule has literally nothing to attach to.

**Rationale**: Without this, Decision 2's entire premise (deriving pickup
days from a linked event) would be unreachable through the UI — a staff
member could never actually use the feature this spec asks for. This is
new scope the original request didn't explicitly name, but it's a
necessary, minimal enabling piece, not a speculative addition (Constitution
I) — the alternative (silently doing nothing until someone notices pickup
day never works) is worse.

**Alternatives considered**: Infer an "active event" automatically (e.g.
whichever event has the soonest/current date) — rejected: no such
"current active event" concept exists anywhere else in this codebase
(checked `stores/`, `PosView.vue`, `CashierSession`) to piggyback on, and
inventing one here would be a much larger, unrelated change than this
feature's scope justifies. An explicit, optional dropdown is the smallest
correct fix.
