# Phase 0 Research: Event Availability & Invoice Redesign

## Decision 1: `available_on` is a plain nullable enum on `events`, resolved to a real date at read time — never stored as a date itself

**Decision**: Add `events.available_on ENUM('day_1','day_2') NULL`. `Event` gains
`availableOnDate(): ?Carbon`, resolving `day_1` → `start_date`, `day_2` →
`end_date`, `null` → `null`. Every consumer (event form, invoices, receipt)
reads the *resolved* date through this one method — never re-implements the
`day_1`/`day_2` → date mapping itself.

**Rationale**: Storing the *choice* (`day_1`/`day_2`) rather than a
snapshotted date means editing the event's start/end date automatically
keeps "available on" pointing at the correct day without a separate
re-derivation step — exactly the same shape of decision this codebase
already made for pre-order `pickup_day` (feature 021 Decision 2), except
`pickup_day` snapshots a real date (because it's a *customer's own* choice
at order time, independent of later event edits) while `available_on` is
an *event-level* fact that should always track the event's current dates.
One method (`availableOnDate()`) as the single place this mapping exists
satisfies Constitution I.

**Alternatives considered**: Storing a real `available_on_date` date column
directly — rejected: it would silently go stale the moment an event's
start/end dates are edited (the exact bug class `EventController::update()`
already guards against for `pickup_day`), and would need its own
recomputation step on every date edit for no benefit over resolving it live
from two columns already on the same row.

## Decision 2: Offered only for multi-day events; cleared automatically on collapse to one day, in the same transaction as the existing pickup-day clearing

**Decision**: `StoreEventRequest`/`UpdateEventRequest` accept `available_on`
as `nullable|in:day_1,day_2`. `EventController::update()`'s existing
`DB::transaction()` (which already clears stale `preorder.pickup_day` rows
when an event's date range shrinks) gains one more line: after the event's
own update, if `start_date === end_date` and `available_on` is set, clear
it to `null`. `EventsView.vue`'s form hides the "Available on" control
entirely when the two date inputs currently hold the same value (client-side
mirror of the same rule, per Constitution III — UI never offers a choice
the backend would reject/no-op).

**Rationale**: Directly implements FR-002/FR-003 and Edge Case 1. Reusing
the exact same transaction the pickup-day-clearing logic already lives in
means this is one additional guarded write, not a second migration-style
cleanup mechanism (Constitution I).

**Alternatives considered**: A separate scheduled/cron cleanup pass for
stale `available_on` values — rejected as unnecessary complexity; the only
moment this can go stale is an explicit event-date edit, which already
runs through one controller action.

## Decision 3: The standout available-on/location block replaces (not duplicates) the existing tiny footer event line

**Decision**: `PreorderInvoiceModal.vue`'s/`ReceiptModal.vue`'s current
small `"Location: X" / "Dates: Y"` footer line is removed and replaced by
one new standout block, positioned near the top of the document (right
after the store-identity header, before the order/customer identity),
showing the resolved available-on date (when set) and the event location
(when set) in larger, higher-contrast styling (a colored block using this
product's existing `@theme` tokens — e.g. `bg-mint-50`/`text-brand-active`,
never a raw hex literal per Constitution III) — not both the old small line
*and* a new prominent one for the same two facts.

**Rationale**: FR-004/FR-005/FR-006 ask for these two facts to be
*prominent*, not merely present a second time. Keeping the old tiny
`Dates: Y` line (the event's full start–end range) *and* adding a new
standout single-day "Available on" fact would show two different,
easily-confused date concepts on the same document (the full event
duration vs. the one day the booth is actually open) — replacing the small
line with the new block, and folding the location into the same standout
block, removes that ambiguity rather than adding to it.

**Alternatives considered**: Keeping the old footer line unchanged and
adding the standout block purely as an addition — rejected: doubles up on
the location fact specifically (it would appear both small and prominent
on the same page) and risks exactly the kind of "which date is this"
confusion the redesign is meant to resolve.

## Decision 4: The pre-order invoice's header/table/footer redesign is scoped to `PreorderInvoiceModal.vue` (and inherited by `PreorderPaymentReceiptModal.vue`, which already shares its shell per feature 022); the sales receipt is untouched beyond the standout block

**Decision**: `ReceiptModal.vue` (the POS sale document) gains only the new
standout available-on/location block from Decision 3. Its existing
stacked-row item list, current width, and lack of an on-document QR are
left exactly as they are.

**Rationale**: FR-007/FR-008/FR-009/FR-010 (header/table/footer
restructure, wider layout, bigger QR, shipping-cost line) describe
concepts that either don't exist for a POS sale (no shipping cost — orders
have no shipping/courier concept at all, confirmed by reading the `orders`
migration) or aren't currently shown on that document at all (no QR is
rendered on the sale receipt itself — the QR only appears in
`ChannelPicker.vue` during live payment collection, a separate screen from
the printed receipt). Applying a table-based restructure to a document
that already reads cleanly as a short stacked list, for no functional
reason tied to this request, would be scope creep beyond what was asked.

**Alternatives considered**: Redesigning `ReceiptModal.vue` to the same
header/table/footer shape "for consistency" — rejected; the user's request
ties every layout/QR/shipping change to "the invoice" specifically and
never asks for the sale receipt's own layout to change, only for it to
also show the two standout facts.

## Decision 5: The invoice's item list becomes a real `<table>`, and the modal widens from `max-w-[480px]` to `max-w-[720px]`

**Decision**: `PreorderInvoiceModal.vue`'s items section changes from a
`flex` stack of rows to an HTML `<table>` with four columns (Product, Qty,
Unit price, Line total), inside a header/`<table>`/footer document
structure. The modal's `max-width-class` grows from `max-w-[480px]` to
`max-w-[720px]` (a plain, unstyled-library table using the product's
existing token classes for borders/spacing, matching every other data
table already in this codebase, e.g. `DataTable.vue`'s header row
styling) — still a single-column receipt-style document at heart, just
wide enough for a real table to read comfortably instead of wrapping.

**Rationale**: Directly implements FR-007/FR-008. `720px` was chosen as
roughly 1.5× the current width — wide enough to fit four table columns
without cramming, narrow enough to still render sensibly inside
`BaseModal`'s existing `max-h-[90vh]` viewport constraint and to still
rasterize cleanly via `html2canvas` at `scale: 2` (the existing PDF-export
mechanism, unchanged) without an excessively tall/thin output.

**Alternatives considered**: A fixed A4-proportioned width — rejected;
this product has never targeted a specific paper size for any document
(the existing PDF export already sizes the output page to the rasterized
content, not a standard paper size), so there's no established convention
to match, and a receipt-shaped document (tall and narrow-ish, not
page-shaped) remains the more honest representation of what this document
actually is.

## Decision 6: Shipping cost is a rendering-only change — the data already exists and is already returned

**Decision**: No backend change is needed for FR-009. `Preorder.shipping_cost`
is already a real column, already serialized by
`PreorderController::present()` (and therefore already present in every
invoice/payment-invoice payload via `invoicePayload()`, added in feature
022). `PreorderInvoiceModal.vue` simply adds one more conditional line
to its existing totals section — `v-if="parseMoney(invoice.shipping_cost) > 0"`
— mirroring the exact pattern the discount line already uses one line
above it.

**Rationale**: Confirmed by reading `PreorderController::present()`
directly — the field is there and already correctly computed server-side
(never client-supplied, per Constitution IV); this was simply never wired
into the invoice's visual totals block when feature 022 built it. This is
the cheapest, lowest-risk way to satisfy FR-009 exactly because it touches
zero backend code.

**Alternatives considered**: None — once confirmed the data was already
present, there was no design decision left to make besides where the line
goes.

## Decision 7: The payment QR image grows from `h-14 w-14` to `h-24 w-24` inside the redesigned payment-terms block

**Decision**: The per-channel QR thumbnail inside `PreorderInvoiceModal.vue`'s
payment-terms list grows from `h-14 w-14` (56px) to `h-24 w-24` (96px) —
still a thumbnail that opens the existing full-size `ImageLightbox.vue`
popup on click (feature 022, unchanged), not a further-enlarged
always-full-size image inline.

**Rationale**: Directly implements FR-010. `96px` roughly matches the
proportional increase already applied to `ChannelPicker.vue`'s own QR in
feature 022 (`h-40`→`h-56`, a ~40% increase) relative to this document's
smaller thumbnail-in-a-list context, staying legible without the
thumbnail overwhelming the rest of the payment-terms row it sits in.

**Alternatives considered**: Removing the thumbnail-then-popup pattern
entirely in favor of always showing the QR at full inline size — rejected;
would push every other payment channel further down the document for
stores with several channels configured, and the click-to-enlarge
interaction already exists and works (feature 022), so there's no reason
to discard it.

## Decision 8: The store-logo bug's fix — the backend returns a resolved URL; the frontend stops constructing one

**Decision**: `SettingsController::index()`'s response gains a sibling
`store_logo_url` field (alongside the existing `data` array),
computed the same way every other image URL in this product already is —
`$this->imageUploadService->url(Setting::get('store_logo_path'))`.
`SettingsController::uploadStoreLogo()`'s response gains the same field so
the just-uploaded logo can render immediately without a second round-trip.
`SettingsView.vue`'s hand-rolled `storeLogoUrl = computed(() =>
'/storage/${storeLogoPath.value}')` is deleted; `storeLogoUrl` becomes a
plain `ref` populated directly from these two responses.

**Rationale**: This is the concrete root cause identified during
specification (spec.md Assumptions) — `SettingsView.vue` is the *only*
place in this codebase that builds a public-disk image URL by hand instead
of going through `ImageUploadService::url()` (confirmed by checking every
other consumer: `OrderController::receipt()`, `BuildsInvoiceDocument`,
`PaymentChannelController`, `CategoryController`, `ProductResource` — all
go through the service). In this dev environment the two constructions
happen to produce an equivalent result (`Storage::disk('public')->url()`
here resolves to `APP_URL` + `/storage/...`, and the hand-built path is a
same-origin-relative `/storage/...`), which is why the bug couldn't be
reproduced live — but the hand-built version has no guarantee of staying
correct under a different disk/URL configuration (e.g. a different
`APP_URL`, a subpath deployment, or a future switch away from the local
public disk), and duplicating a URL-building convention that already
exists once is itself a Constitution I violation regardless of whether it
happens to produce the same output today. Fixing it at the source removes
the only place this could diverge, rather than trying to reproduce and
patch a possibly-environment-specific symptom.

**Alternatives considered**: Leaving `SettingsView.vue`'s construction in
place and instead auditing/hardening the backend `Storage` disk config —
rejected; even if a config issue exists somewhere, having two independent
URL-construction implementations for the same value is the underlying
defect Constitution I flags, and fixing only the config would leave that
duplication (and the next time it silently diverges) in place.
