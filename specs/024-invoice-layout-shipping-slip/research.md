# Phase 0 Research: Pre-order Invoice Layout Refinements & Shipping Slip

## Decision 1: `created_at` is added to the one shared `present()` method, not a new field on `invoicePayload()` alone

**Decision**: `PreorderController::present()` gains `'created_at' => $preorder->created_at?->toIso8601String()`. Since `invoicePayload()` already spreads `...$this->present($preorder)`, this one addition reaches the invoice, the payment invoice, and every other endpoint that already calls `present()` (`show()`, `store()`, `updateStatus()`, `storePayment()`) for free — consistent with how `PreorderController::index()` already separately includes `created_at` in its own row-mapping, closing a real (if minor) inconsistency between the list view and the detail/invoice views.

**Rationale**: Confirmed by reading `present()` directly — `created_at` was the one genuinely missing field the spec's FR-004 needs; every other field FR-001–FR-011 requires (`customer.*`, `store_identity.*`, `fulfillment`, `items`, `payment_channels`) is already present in the existing invoice payload. Adding it to the single shared method (Constitution I) means every present/future consumer gets it automatically, rather than special-casing it into `invoicePayload()` only and creating exactly the kind of "two shapes for the same entity" drift Constitution I warns against.

**Alternatives considered**: Adding `created_at` only inside `invoicePayload()` (not `present()`) — rejected; would leave `GET /preorders/{id}` (the plain detail view) without a field every other timestamp-bearing entity in this API already exposes, and would be the kind of narrow, endpoint-specific patch this codebase's own `present()`/`invoicePayload()` split was designed to avoid.

## Decision 2: The two-column header is a CSS grid, not two independently-positioned blocks

**Decision**: The existing store-identity block, the standout available-on/location block, and the order-identity block are restructured into a two-column `grid grid-cols-2 gap-4` layout: column one holds event name (new) + the existing standout location/available-on block (now with its rows centered per FR-002, not left/right-justified); column two holds the pre-order number + status pill (already existing) + a new "To:" block. The store-identity (logo/store name/store contact) block stays full-width above both columns — it's not part of either column per the spec's own two-column content list (which only names event-info and order/recipient-info, not the store's own identity).

**Rationale**: Directly implements FR-001. A CSS grid (rather than two separately-styled `flex` columns) keeps both columns' natural heights independent and avoids the fragile manual-width-splitting that would be needed with plain `flex`, matching how this codebase already uses `grid-cols-2`/`grid-cols-4` for other two/four-column form layouts (e.g. `EventsView.vue`'s date-pair row).

**Alternatives considered**: Keeping the standout block as one full-width row and only splitting the *new* content (event name, "To:") into columns — rejected; the spec explicitly describes the whole left column as "event name, location, available on" together, meaning the existing standout block's content moves into that column rather than staying separate from it.

## Decision 3: The "To:" block reuses `CustomerResource`'s fields already present on `invoice.customer` — no backend change

**Decision**: The right column's "To:" block reads `invoice.customer.name` / `.email` / `.phone` / `.social_handle` / `.address` — all five fields `CustomerResource` (used by `present()`'s `customer` field) already returns. Each line is individually `v-if`-guarded, omitted when absent (FR-003).

**Rationale**: Confirmed by reading `CustomerResource::toArray()` directly — all five fields the spec names ("name, email, phone, social media handler, address") already exist on this exact object, already loaded on the invoice payload (`present()`'s `customer` field, loaded via `$preorder->load(['... 'customer'])` in `invoice()`). This is a pure frontend rendering change.

**Alternatives considered**: None — there was no decision to make once the existing resource was confirmed to already carry every field requested.

## Decision 4: Payment channels split into two columns by their existing `type` field — no new categorization

**Decision**: `invoice.payment_channels` is filtered client-side into two computed arrays — `qrChannels` (`type === 'qr_ewallet'`) and `bankChannels` (`type === 'bank_transfer'`) — rendered in a `grid grid-cols-2` (or stacked on narrow viewports) layout, each column's heading/content omitted individually when that array is empty (FR-006). The QR image inside `qrChannels`' column grows again, from the 96px feature-023 size to 128px (`h-32 w-32`), since this feature's own request ("make it bigger") is on top of that already-larger size.

**Rationale**: `PaymentChannel.type` is already exactly the two-value enum (`bank_transfer`/`qr_ewallet`) the spec's "first column is for qr code... second column is for bank payment" maps onto one-to-one — confirmed by reading the `PaymentChannel` model/migration and `PaymentChannelController`. No new field, no new backend logic; this is a client-side `.filter()` on data already returned.

**Alternatives considered**: Introducing a new explicit "payment_group" concept — rejected as unnecessary; `type` already **is** that grouping, just not previously used to split the layout.

## Decision 5: The shipping slip is a template-only section, gated on `invoice.fulfillment === 'courier'`, sourced entirely from data already on the payload

**Decision**: A new section renders only when `invoice.fulfillment === 'courier'` (the internal value for "Mail Order", per feature 021's own established label-only rename), showing: event name (`invoice.event_name`), pre-order number (`invoice.preorder_number`), "From" (`invoice.store_identity`, the same block already at the top of the document), "To" (`invoice.customer`, the same fields as Decision 3's header block), and "Item type" — a computed, de-duplicated list of `invoice.items[].name_snapshot` values. No `Shipment` record is read or required.

**Rationale**: Directly implements FR-008/FR-009/FR-010 and the spec's own resolved Assumption — a shipping slip that could only be produced *after* a `Shipment` record exists would defeat its own purpose (a document staff should be able to prepare while packing, before formally logging a shipment). Gating on `fulfillment` (already returned, already reliable — it's the pre-order's own committed choice, not the separate, optionally-created `Shipment.courier_name`) rather than on `invoice.shipment` existing satisfies FR-010 directly.

**Alternatives considered**: Sourcing "To" from `invoice.shipment.recipient_name/phone/address_line` when a shipment exists, falling back to the customer record otherwise — rejected as unnecessary complexity for this pass; the spec's own Edge Cases explicitly ask for the customer's own details to be usable *even when no shipment record exists yet*, and using the customer record consistently (shipment or not) is simpler and matches what's already shown in the header's "To:" block one section above it, avoiding two different "recipient" values appearing on the same document.

## Decision 6: The document title becomes "Pre-order Invoice — {number}" via `BaseModal`'s `title` prop, using a new dedicated locale key

**Decision**: `PreorderInvoiceModal.vue`'s `BaseModal` `:title` binding changes from `invoice?.preorder_number` alone to `` `${t('preorders.document_title_invoice')} — ${invoice?.preorder_number}` `` (falling back gracefully while loading), where `preorders.document_title_invoice` is a **new** locale key = "Pre-order Invoice" (id: "Invoice Pre-order"). The existing `document_invoice_title` key (value "Invoice", confirmed by reading both locale files) stays exactly as-is and keeps driving the in-body heading badge — unrelated to this change, not reused for it.

**Rationale**: Directly implements FR-005 ("adjust preorder invoice as title" — spec.md Assumptions resolved this to mean the document's own title/heading text). A **new**, dedicated key is used rather than reusing `document_invoice_title` because that key's existing value is a bare "Invoice" — correct for the small in-body badge sitting next to the already-present "PRE-ORDER" marking, but too terse for a window title read on its own; concatenating it wouldn't produce "Pre-order Invoice" without still needing a second key or a hardcoded string fragment either way, so a purpose-built key is the more direct fix (Constitution I: one concept, one clearly-named source, not a workaround chain).

**Alternatives considered**: Reusing `document_invoice_title` for both places — rejected per above (see corrected rationale — the original draft of this decision incorrectly assumed that key's value already varied appropriately by context; it doesn't, it's the same fixed "Invoice" string used by the badge today).
