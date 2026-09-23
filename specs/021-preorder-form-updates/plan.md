# Implementation Plan: Pre-order Form & Workflow Updates

**Branch**: `021-preorder-form-updates` | **Date**: 2026-09-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/021-preorder-form-updates/spec.md`

## Summary

Six additive changes to the existing Pre-order create form and its downstream
surfaces (invoice, import/export): (1) collapse the customer picker from a
button-opens-a-second-modal flow into one inline searchable dropdown; (2) a
new order-level `discount` field (fixed Rupiah amount, mirroring Invoice's
existing pattern); (3) direct numeric entry for item quantity alongside the
existing +/- stepper; (4) rename the "Courier" fulfillment label to "Mail
Order" everywhere (label-only, `fulfillment` enum value `courier` unchanged);
(5) a pickup-day field for "Self Pickup" that is *derived from the linked
event's real date range* (not a fixed "Day 1"/"Day 2"), shown on the invoice
as a real date; (6) a courier-name dropdown (default "JNE") captured at
creation time for "Mail Order", stored on the preorder itself as a *default/
preference* value — the existing, separately-created `Shipment` record (with
its own required recipient/address fields) is untouched in shape, just
pre-filled from this default and upgraded from free text to the same
dropdown. Import/export gets three new columns (discount, pickup_day,
courier_name) with a cross-field validation rule (a fulfillment-mismatched
value is a row error).

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 12), Vue 3 (Composition API) — no new language/runtime.

**Primary Dependencies**: No new dependency. Reuses `maatwebsite/excel` (import/export), Laravel FormRequest validation, Eloquent, existing Vue `api/` client layer, `vue-i18n`, and this codebase's existing dropdown mechanics (`BaseMultiSelect.vue`'s Teleport/positioning/click-outside pattern, adapted for a single-select remote-search case).

**Storage**: MySQL 8. Three new nullable columns on `preorders` (`discount`, `pickup_day`, `courier_name`) — no change to `shipments` or `preorder_items` schema (quantity direct-entry is a frontend-only change; qty already exists and is already validated `min:1`).

**Testing**: `php artisan test` (Feature, real MySQL — **run via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`, not the bare command**, per the environment bug documented in `phpunit.xml`/`docs/RUNBOOK.md` during feature 020) and `qa-tests/` (Vitest) for the frontend, plus a real-browser check per Constitution II for the form/invoice changes.

**Target Platform**: Same single-machine BoothPOS deployment (native or Docker) — no new deployment concern.

**Project Type**: Existing web application (Laravel API + Vue SPA in one repo).

**Performance Goals**: Customer dropdown search must feel as responsive as today's picker-modal search (same debounced `listCustomers()` call, same 300ms debounce) — collapsing two steps into one must not make the search itself slower.

**Constraints**: The existing `Shipment` entity's required recipient/address fields and its own creation endpoint/validation are NOT to be loosened or restructured — the new courier default is a separate, narrower field on `Preorder` itself (see research.md Decision 3), so this feature adds no new way to create an incomplete `Shipment` row.

**Scale/Scope**: Same single-store pre-order volume as today — this is a form/workflow change, not a data-volume change.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. All new money/derivation logic (discount validation, pickup-day-from-event-range, cross-field import validation) lives in `PreorderService`/`PreorderExportImportService`, the two already-sanctioned write paths for this entity — no second path introduced. The customer dropdown reuses `BaseMultiSelect.vue`'s proven positioning/Teleport/click-outside mechanics rather than inventing new dropdown plumbing, and `CustomerPickerModal.vue`'s existing search/walk-in/add-new-customer logic is *relocated*, not duplicated, into the new inline component (the old modal-opened-by-button pattern is removed, not kept alongside the new one).
- **II. Testing Standards** — PASS (planned). New Feature tests under `tests/Feature/` for: discount validation (rejects negative total), pickup-day derivation for 1-day/2-day/3+-day/no-event cases, courier default propagation, and the three import/export cross-field rules. Run against real MySQL via the corrected env-override command above. The form, invoice, and dropdown changes get a real-browser check (Docker dev instance) before being called done.
- **III. User Experience Consistency** — PASS. New UI reuses existing token classes, `BaseButton`/`BaseModal`/toast/i18n conventions, and (per Constitution III) the pickup-day/courier fields are only ever shown when their fulfillment option is selected — never present-but-disabled. All new/changed UI copy (including the "Courier" → "Mail Order" rename) ships in both `id.json` and `en.json`, and the receipt/invoice stays Indonesian-only per this codebase's existing login/receipt exception (though this feature's screens outside the invoice do respect the language toggle like the rest of the app).
- **IV. Security** — PASS. Money fields (`discount`) are validated and the total recomputed server-side in `PreorderService`/`PreorderExportImportService` — a client-supplied `total_amount` is never trusted, matching the existing "server always recomputes" rule already enforced for `subtotal`/`shipping_cost`. `discount` cannot make the total negative (FR-004), enforced server-side, not just disabled in the UI.
- **V. Performance & Optimization** — PASS. The customer dropdown's remote search is the exact same single, debounced, paginated `GET /customers?search=...&per_page=10` call the current modal already makes — no new N+1, no additional round trip introduced by collapsing the two-step flow into one.

No violations — **Complexity Tracking is empty.**

## Project Structure

### Documentation (this feature)

```text
specs/021-preorder-form-updates/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── preorder-updates.md
└── tasks.md              # Phase 2 output (/speckit-tasks — not created by /speckit-plan)
```

### Source Code (repository root)

```text
database/migrations/
└── <new>_add_discount_pickup_day_courier_name_to_preorders_table.php   # NEW

app/Models/
└── Preorder.php                              # MODIFIED — $fillable + casts for discount/pickup_day/courier_name

app/Http/Requests/
└── StorePreorderRequest.php                  # MODIFIED — discount rule, fulfillment enum label unchanged (value stays 'courier'), conditional pickup_day/courier_name rules

app/Services/
├── PreorderService.php                       # MODIFIED — create() computes total_amount with discount, derives/validates pickup_day against the linked event, defaults courier_name to 'JNE'
└── PreorderExportImportService.php           # MODIFIED — HEADINGS + groupRows() + validation gain discount/pickup_day/courier_name, including the fulfillment-mismatch row error (FR-014)

app/Http/Controllers/Api/
├── PreorderController.php                    # MODIFIED — present()/invoice() expose discount/pickup_day/courier_name
└── ShipmentController.php                    # MODIFIED — courier_name pre-fillable from the preorder's default, still its own required field, still a plain string column (dropdown is frontend-only)

app/Support/
└── Couriers.php                               # NEW — the fixed, shared courier list (JNE default), single source for both the create-form dropdown and the shipment-form dropdown (Constitution I — one definition, not duplicated per screen)

resources/js/
├── components/preorder/
│   └── CustomerSearchDropdown.vue             # NEW — inline single-select searchable dropdown, replacing CustomerPickerModal's button-opens-modal entry point (absorbs its search/walk-in/add-new-customer logic)
├── views/PreordersView.vue                    # MODIFIED — new-preorder form: swap in CustomerSearchDropdown, add an event dropdown (research.md Decision 9 — none exists today), discount input, qty direct-entry, conditional pickup-day (derived from the selected event) / courier dropdown, "Courier" → "Mail Order" label
├── components/preorder/PreorderInvoiceModal.vue  # MODIFIED — show discount line and (when applicable) pickup day / courier
├── api/customers.js                           # UNCHANGED (listCustomers already supports search+per_page)
├── api/preorders.js                           # UNCHANGED (create/export/import already pass through arbitrary payload/columns)
└── locales/{id,en}.json                       # MODIFIED — "Mail Order" label, discount/pickup-day/courier copy

docs/openapi-pos-mvp.yaml                      # MODIFIED — POST /preorders, GET /preorders/{id}, GET /preorders/{id}/invoice, export/import contracts

tests/Feature/
└── PreorderFormUpdatesTest.php                 # NEW
```

**Structure Decision**: Existing single web application — no new project boundary. `app/Support/Couriers.php` is the one new small shared-definition file (mirrors `MasterDataSheets.php`'s "single source shared by multiple callers" pattern already used elsewhere in this codebase); everything else is a targeted change to an already-existing file.

## Complexity Tracking

*No violations — table intentionally omitted.*
