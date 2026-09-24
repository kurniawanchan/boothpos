# Implementation Plan: Event Availability & Invoice Redesign

**Branch**: `023-event-availability-invoice-redesign` | **Date**: 2026-09-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/023-event-availability-invoice-redesign/spec.md`

## Summary

Six changes: (1) a new `available_on` field on Event (`day_1`/`day_2`, resolved
to the event's own start/end date, offered only for multi-day events and
auto-cleared if the event collapses to a single day — mirrors the existing
pickup-day-clearing pattern in `EventController::update()`); (2)/(3) the
resolved available-on date and the event location both rendered as a
visually standout block (not small footer text) on the pre-order invoice,
the payment invoice (shares that layout), and the POS sale receipt; (4) the
pre-order invoice restructured into header/itemized-table/footer sections,
rendered wider; (5) a bigger, still-clickable payment QR on that invoice,
plus a shipping-cost line in its totals (the data — `shipping_cost` — is
already returned by the existing invoice payload, just never rendered); (6)
a fix for the reported store-logo display bug — `SettingsView.vue` currently
hand-constructs the logo's display URL instead of reusing the one existing,
working convention (`ImageUploadService::url()`) every other image in this
product goes through; the backend now returns the resolved URL directly and
the frontend stops guessing it.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 12), Vue 3 (Composition API) — no new language/runtime.

**Primary Dependencies**: No new dependency. Reuses `html2canvas`+`jsPDF` (client-side document rendering, unchanged mechanism — no server-side PDF generation is introduced, consistent with every prior document feature in this codebase) and the existing `ImageUploadService`.

**Storage**: MySQL 8. Schema change: `events` gains a nullable `available_on` enum column (`day_1`/`day_2`). No other schema changes — `shipping_cost` already exists on `preorders` and is already serialized by the existing invoice payload; this feature only renders it.

**Testing**: `php artisan test` (Feature, real MySQL — `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`, per the environment bug documented in `phpunit.xml`/`docs/RUNBOOK.md`) and `qa-tests/` (Vitest), plus a real-browser check per Constitution II for every screen/document change, including a live repro of the store-logo bug before and after the fix.

**Target Platform**: Same single-machine BoothPOS deployment — no deployment change.

**Project Type**: Existing web application (Laravel API + Vue SPA).

**Performance Goals**: No new performance-sensitive path — document rendering stays client-side and single-document (no batch concern here, unlike feature 022's bulk download).

**Constraints**: No server-side PDF/image rendering is introduced (Constitution I/existing precedent). Event date edits that collapse a multi-day event to one day MUST clear `available_on` in the same transaction as the date update, exactly like the existing pickup-day-clearing logic it sits beside.

**Scale/Scope**: Same single-store event/pre-order volume as today.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. The store-logo fix specifically *removes* a duplicated, divergent URL-construction path in favor of the one sanctioned one (`ImageUploadService::url()`), directly the kind of fix Principle I calls for. The available-on-clearing logic is added to the exact same `EventController::update()` transaction that already clears stale pickup days, not a second parallel mechanism. The invoice redesign reuses the same client-side-only rendering pipeline (`html2canvas`+`jsPDF`) every other document in this codebase already uses — no new document-rendering path is introduced.
- **II. Testing Standards** — PASS (planned). New Feature tests for: `available_on` accepted/rejected per event shape, cleared on date-collapse; invoice/receipt payloads include the resolved available-on date and shipping cost; the store-logo endpoint returns a resolved URL. Every UI change (event form, both invoice documents, sale receipt, Settings General) gets a real-browser check — including reproducing the logo bug's leading root cause before the fix and confirming it's gone after.
- **III. User Experience Consistency** — PASS. The standout available-on/location treatment uses the same design-token classes (no raw hex) already used throughout the product; the wider invoice layout and bigger QR are applied consistently to both documents that share that shell (invoice + payment invoice), not one and not the other.
- **IV. Security** — PASS. No new authorization surface — event editing stays behind the existing `EventPolicy`; the store-logo URL fix does not change who can upload (`Setting::class` policy, unchanged) or what's exposed (the resolved URL is exactly as public as the raw path already was, since both point at the same public disk).
- **V. Performance & Optimization** — PASS. No N+1 introduced — `available_on`'s resolved date is computed from columns already loaded on the `Event` model already being eager-loaded by the invoice/receipt endpoints; no new query.

No violations — **Complexity Tracking is empty.**

## Project Structure

### Documentation (this feature)

```text
specs/023-event-availability-invoice-redesign/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── event-availability-invoice.md
└── tasks.md   # /speckit-tasks — not created by /speckit-plan
```

### Source Code (repository root)

```text
database/migrations/
└── <new>_add_available_on_to_events_table.php   # NEW

app/Models/
└── Event.php                               # MODIFIED — available_on fillable/cast, availableOnDate() helper

app/Http/Requests/
├── StoreEventRequest.php                    # MODIFIED — available_on validation
└── UpdateEventRequest.php                   # MODIFIED — available_on validation

app/Http/Controllers/Api/
├── EventController.php                      # MODIFIED — clear available_on when event collapses to one day (same transaction as existing pickup-day clearing)
├── OrderController.php                      # MODIFIED — receipt() gains event_available_on_date
├── PreorderController.php                   # MODIFIED — invoicePayload() gains event_available_on_date
└── SettingsController.php                   # MODIFIED — index()/uploadStoreLogo() return a resolved store_logo_url

resources/js/
├── views/
│   ├── EventsView.vue                       # MODIFIED — Available on select (day_1/day_2), shown only for multi-day events
│   └── SettingsView.vue                     # MODIFIED — storeLogoUrl now comes from the backend, not hand-built
├── components/
│   ├── preorder/PreorderInvoiceModal.vue     # MODIFIED — header/table/footer redesign, wider, bigger QR, shipping-cost line, standout available-on/location block
│   ├── preorder/PreorderPaymentReceiptModal.vue  # MODIFIED — same standout block + shares the redesigned shell
│   └── receipt/ReceiptModal.vue              # MODIFIED — standout available-on/location block only (no layout/QR/shipping changes — sales have no shipping concept)
└── locales/{id,en}.json                      # MODIFIED — new labels (Available on, day 1/day 2 option text, Shipping cost)

docs/openapi-pos-mvp.yaml                     # MODIFIED — Event schema, invoice/receipt response additions, settings response addition

tests/Feature/
├── EventAvailabilityTest.php                 # NEW
└── InvoiceAvailabilityAndLogoTest.php         # NEW
```

**Structure Decision**: Existing single web application. No new backend classes beyond one migration — everything else is a targeted change to an already-existing file, consistent with how small this feature actually is once the store-logo root cause is confirmed.

## Complexity Tracking

*No violations — table intentionally omitted.*
