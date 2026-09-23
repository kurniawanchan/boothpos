# Implementation Plan: Pre-order Invoice & CRUD Overhaul

**Branch**: `022-preorder-invoice-crud-overhaul` | **Date**: 2026-09-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/022-preorder-invoice-crud-overhaul/spec.md`

## Summary

Seven additive/corrective changes to the Pre-order screen and its documents:
(1) edit/delete a pre-order, keeping stock consistent (edit allowed until
"Handed over"/"Cancelled" with recalculated stock past "Goods arrived";
delete only while still "Ordered"); (2) the shipment form auto-fills
recipient name/phone/address from the linked customer and drops the
separate city/postal-code fields in favor of one address field, mirroring
`Customer.address`; (3) "Receipt" → "Invoice" everywhere, with the
document gaining the store's identity, payment channels, and footer text
— all three already exist as Settings data and are already assembled this
exact way for the POS sale receipt (`OrderController::receipt()` /
`ReceiptModal.vue`), so this reuses that established shape rather than
inventing a new one; (4) "Payment receipt" → "Payment invoice," restyled
to the same document shell; (5) bulk download/email of invoices from the
list; (6) the customer picker shows a scrollable default list, not only
search results; (7) the pre-order import/export workbook is redesigned to
one-row-per-order (event name, "pickup"/"mail order", pickup day as "Day
N", comma-separated products/quantities, shipping cost, courier, ETA),
replacing the existing row-per-item format entirely.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 12), Vue 3 (Composition API) — no new language/runtime.

**Primary Dependencies**: No new dependency for most of this feature — reuses `maatwebsite/excel`, `html2canvas`+`jsPDF` (client-side document rendering, unchanged mechanism), Laravel Mail. **One new dependency is needed for bulk download**: a client-side zip library (e.g. `jszip`, already a common, small, dependency-free-of-native-bindings package) to bundle multiple client-generated PDFs into one downloaded file — see research.md Decision 5 for why this stays client-side rather than introducing server-side PDF generation.

**Storage**: MySQL 8. Schema changes: drop `shipments.city`/`shipments.postal_code` (values folded into `address_line`); no new tables. `preorders`/`preorder_items` gain no new columns for CRUD (edit/delete reuse existing columns); the import/export rewrite is a service-layer change, not a schema change (it already reads/writes the same `preorders`/`preorder_items` columns, just via a different file layout).

**Testing**: `php artisan test` (Feature, real MySQL — **`docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`**, per the environment bug documented in `phpunit.xml`/`docs/RUNBOOK.md` since feature 020) and `qa-tests/` (Vitest), plus a real-browser check per Constitution II for every screen/document change in this feature.

**Target Platform**: Same single-machine BoothPOS deployment — no deployment change.

**Project Type**: Existing web application (Laravel API + Vue SPA).

**Performance Goals**: Bulk invoice generation for up to ~20 selected pre-orders (a realistic single-session batch for this business) must complete client-side without freezing the tab — sequential (not parallel) canvas rendering, same one-at-a-time approach the existing single-invoice flow already uses.

**Constraints**: No server-side PDF/image rendering is introduced (Constitution/existing precedent, research.md Decision 5) — every document (including bulk ones) is still rendered from the same Vue template client-side. Editing/deleting a pre-order MUST go through `StockService::applyMovement()` for any stock change, never a direct `current_stock` write (Constitution I).

**Scale/Scope**: Same single-store pre-order volume as today.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. Stock reversal/recalculation on edit/delete goes through the one sanctioned `StockService::applyMovement()` path, never a second write path. The invoice's store-identity/footer block is *reused*, not reimplemented — `PreorderController::invoice()` gains the exact same `Setting::get(...)` assembly `OrderController::receipt()` already has (see research.md Decision 1), and the QR-enlarge/click-to-popup change lands in the one shared `ChannelPicker.vue` component already used by both POS checkout and pre-order settlement, not duplicated per screen.
- **II. Testing Standards** — PASS (planned). New Feature tests for: editing items recalculates stock correctly at each allowed status and is refused at "Handed over"/"Cancelled"; deleting is refused at every status except "Ordered" and when a payment exists; the redesigned import/export round-trips; shipment auto-fill and the dropped city/postal fields. Every UI change gets a real-browser check.
- **III. User Experience Consistency** — PASS. All new UI reuses existing token classes and component conventions; the "Invoice"/"Payment invoice" relabeling is applied everywhere the old labels appeared (Constitution III's "no partial rename" spirit), not just the primary screen.
- **IV. Security** — PASS. Deleting a pre-order is blocked whenever a payment exists (FR-004) — this is itself a security/audit-trail control, not just a UX rule. The invoice's payment-channel list always shows the *unmasked* account number (research.md Decision 2) since this is a customer-facing document meant to be actually paid against — unlike the masked-for-internal-staff view `PaymentChannelController::index()` already applies elsewhere, this is a deliberate, narrower exception, not a general loosening of that masking rule.
- **V. Performance & Optimization** — PASS. Bulk document generation stays sequential and client-side (research.md Decision 5) rather than spinning up a new server-side rendering pipeline that would need its own scaling story. The customer picker's "show list by default" (US6) reuses the exact same paginated `GET /customers` call already made for search, just triggered on open instead of on keystroke — no new endpoint, no N+1.

No violations — **Complexity Tracking is empty.**

## Project Structure

### Documentation (this feature)

```text
specs/022-preorder-invoice-crud-overhaul/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── preorder-crud-invoice.md
└── tasks.md   # /speckit-tasks — not created by /speckit-plan
```

### Source Code (repository root)

```text
database/migrations/
└── <new>_drop_city_and_postal_code_from_shipments_table.php   # NEW

app/Services/
├── PreorderService.php                    # MODIFIED — update()/delete(), status-guarded, stock-consistent (research.md Decision 3)
└── PreorderExportImportService.php        # REWRITTEN — new one-row-per-order layout (Q3=A, full replace)

app/Http/Controllers/Api/
├── PreorderController.php                 # MODIFIED — update()/destroy() actions; invoice()/present() gain store-identity + payment-channels block (mirrors OrderController::receipt()); new bulkInvoices()/bulkEmailInvoices() actions
├── ShipmentController.php                 # MODIFIED — drop city/postal_code from validation
└── CustomerController.php                 # UNCHANGED — index() already supports an empty `search` (returns unfiltered page)

app/Http/Requests/
├── UpdatePreorderRequest.php               # NEW
└── StorePreorderRequest.php                 # UNCHANGED (create-time rules already correct)

routes/api.php                              # MODIFIED — PATCH/DELETE /preorders/{preorder}, POST /preorders/bulk-invoices, POST /preorders/bulk-email

app/Mail/
└── PreorderInvoiceMail.php                 # NEW — bulk-email body (rich HTML, no PDF attachment — research.md Decision 5)

resources/js/
├── components/preorder/
│   ├── PreorderInvoiceModal.vue            # MODIFIED — relabel to Invoice, add store-identity header/payment-channels/footer blocks
│   └── PreorderPaymentReceiptModal.vue      # MODIFIED — relabel to Payment Invoice, restyle to match PreorderInvoiceModal's shell
├── components/payment/ChannelPicker.vue     # MODIFIED — bigger QR + click-to-popup (shared by POS + pre-order settlement)
├── components/preorder/CustomerSearchDropdown.vue  # MODIFIED — load a default page of customers on open, not only on search
├── views/PreordersView.vue                  # MODIFIED — edit action wired to existing create-form UI in edit mode; delete action (status-guarded); bulk-select checkboxes + bulk download/email toolbar actions
├── api/preorders.js                         # MODIFIED — updatePreorder(), deletePreorder(), bulkDownloadInvoices(), bulkEmailInvoices()
├── api/shipments.js                         # UNCHANGED (payload shape change only, same endpoint)
└── locales/{id,en}.json                     # MODIFIED — Invoice/Payment Invoice copy, new import/export column labels

docs/openapi-pos-mvp.yaml                    # MODIFIED — PATCH/DELETE /preorders/{id}, invoice schema additions, bulk endpoints, rewritten import/export contract

tests/Feature/
└── PreorderCrudInvoiceTest.php              # NEW
```

**Structure Decision**: Existing single web application. `app/Mail/PreorderInvoiceMail.php` is the one genuinely new backend class; everything else is a targeted change to an already-existing file, and the invoice's store-identity data is *copied from an existing, working pattern* (`OrderController::receipt()`) rather than designed from scratch.

## Complexity Tracking

*No violations — table intentionally omitted.*
