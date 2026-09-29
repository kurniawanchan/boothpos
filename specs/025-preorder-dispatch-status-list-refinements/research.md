# Phase 0 Research: Pre-order Invoice/Shipping Progress, Print Menu & List Refinements

## Decision 1: A new `dispatch_status` column — not a new `status` value, not `shipments.status`

**Decision**: Add `preorders.dispatch_status` (`pending` / `invoice_sent` / `shipping`, default `pending`) beside the existing `status`.

**Rationale**: `status` is the stock/payment state machine (`ordered → dp_paid → arrived → settled → handed_over`, with `handed_over` blocked until fully paid); a "we emailed the invoice" marker has no place in it and would have inherited transition guards that make no sense for it. `shipments.status` exists only after a courier record is created and never for Self Pickup, whereas staff need to mark "invoice sent" long before that. A separate column also keeps both filters simple.

**Alternatives considered**: extra values on `status` (rejected — couples a courtesy marker to stock/payment rules); reusing `shipments.status` (rejected — absent for most rows when the marker matters); two independent booleans (rejected — the two values are sequential, and one enum filters and displays more simply).

## Decision 2: Default in both the database and the model

**Decision**: `DEFAULT 'pending'` in the migration **and** `protected $attributes = ['dispatch_status' => 'pending']` on the model.

**Rationale**: Found by test — Eloquent's `create()` does not read back database defaults, so `POST /preorders` returned `dispatch_status: null` until the row was reloaded. The model default makes the create response correct without an extra query.

## Decision 3: "Shipping" is Mail Order only, enforced twice, plus the edit path

**Decision**: The API returns 409 for `shipping` on a non-courier pre-order; the detail UI simply doesn't offer it; and `PreorderService::update()` downgrades `shipping → invoice_sent` (clearing `shipping_at`) when an edit moves the order off courier.

**Rationale**: A rule enforced only at one write path leaves gaps — editing fulfillment is a second way to reach the impossible state. The UI check is a convenience mirror; the server is the guard (CLAUDE.md: frontend checks are cosmetic). `invoice_sent` is the downgrade target because the invoice was, in fact, already sent.

**Alternatives considered**: reset to `pending` (rejected — would discard true information); forbid changing fulfillment while shipping (rejected — blocks a legitimate correction).

## Decision 4: Dates derive from the *target state* and are server-only

**Decision**: `invoice_sent_at` / `shipping_at` are set in `updateDispatchStatus` from the requested target: `pending` clears both; `invoice_sent` keeps an existing invoice date or uses now, clears the shipping date; `shipping` keeps an existing shipping date or uses now, keeps the invoice date, and does **not** invent one. The request body's own date values are never read.

**Rationale**: Deriving from the target (rather than from click history) makes forward, backward and repeated clicks converge on one consistent picture; re-marking the active value must not move its date. Not inventing an invoice date for a direct jump matches "we don't know when it was sent". Separate columns are needed because `updated_at` changes on every unrelated edit.

## Decision 5: Row actions — inline up to three, otherwise "Detail" + a teleported "More" menu

**Decision**: A small `PreorderRowActions.vue`: when `actions.length > 3`, the primary action (`detail`) stays inline and the rest go into a menu rendered via `Teleport to="body"` with `position: fixed`, closed on outside click / Escape / scroll / resize.

**Rationale**: The table sits in a rounded, overflow-clipped card; an absolutely-positioned menu would be cut off. The Teleport + fixed technique is already used by `BaseMultiSelect.vue`. Closing on scroll avoids recomputing position for a fixed menu. The component only emits `select(key)` so the view keeps its existing handlers.

## Decision 6: The list "Payment invoice" bug was a signature mismatch

**Decision**: `openPaymentReceipt(paymentId, preorderId = detail.value?.id)`; the list passes `(null, row.id)`. The receipt modal already falls back to the latest payment when no `paymentId` is given.

**Rationale**: The row called `openPaymentReceipt(row, null)` — the row landed in the `paymentId` slot and the function returned early because no detail was open, so the link silently did nothing. A default parameter leaves every existing detail-panel caller untouched. The item is disabled when the row has no payment.

## Decision 7: Export/import

**Decision**:
- `dispatch_status`, `invoice_sent_at`, `shipping_at` join the shared column list used by template, export and import; `created_at`, `updated_at` are `EXPORT_ONLY` (after the shared columns, never read on import, absent from the template).
- Timestamps are ISO 8601 with offset; on import they are parsed and **normalised to the application timezone**.
- Import applies the same rules as the endpoint; a violation is a row error and the whole file is rejected (409), as the importer already does.
- The export accepts `status`/`fulfillment`/`dispatch_status` as scalar or array.

**Rationale**: The invariant that an exported file imports back unchanged (022 SC-005) forces the importer to tolerate the export-only columns; ignoring them (rather than honouring them) keeps creation time honest for imported orders. ISO 8601 with an offset is unambiguous while the app timezone (UTC) differs from what the screen shows. Normalising matters because Eloquent writes a Carbon in *its own* timezone without converting, so `10:00+07:00` would otherwise be stored as `10:00 UTC` — found by test. The array-filter support fixes a latent defect: the "Export .xlsx" button forwards the list's array filters, which the old scalar `where()` could not take (verified: the export threw).

**Alternatives considered**: exporting local Jakarta wall-clock time (deferred — needs a store-timezone setting the app doesn't have); honouring `created_at` on import (rejected — audit trail).

## Decision 8: Locale keys must be unique per section

**Decision**: The detail's dates use new keys `detail_created_label` / `detail_updated_label`.

**Rationale**: A first attempt reused the name `created_at_label`, which already existed in the same section ("Created on"); JSON parsers keep the last duplicate, so one screen's text changed silently. A duplicate-key scan of both locale files is now part of the checks.
