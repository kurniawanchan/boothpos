# Implementation Plan: UI/UX Refinements Batch

**Branch**: `009-ui-ux-refinements` | **Date**: 2026-09-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/009-ui-ux-refinements/spec.md`

## Summary

Eight independently-shippable UI/UX changes to the existing BoothPOS Laravel + Vue SPA:
(1) trim marketing/deployment copy off the login screen, (2) fix a menu-color
inconsistency in the sidebar's default (non-active) icon/text state for the
Purchase/Inventory/Settings groups, (3) redesign the Sales report page —
transaction list first, drop the grouped summary table, replace the
transaction-number → `ReceiptModal` action with a new products-sold table
popup that opens the existing `ProductDetailModal` on product-name click,
(4) rename "Artist"/"Artists" → "Penjual"/"Sellers" across `lang/id/*.php`
and `resources/js/locales/{id,en}.json` (label-only, no identifier renames),
(5) add guarded hard-delete for Event and Customer (mirroring the existing
Artist/Category delete-guard pattern) plus the missing `Customer`
`orders()`/`preorders()` relations, (6) add a per-customer transaction
history endpoint/view spanning Order+Preorder, (7) add per-customer stats
(table + chart) to the Dashboard via a new `group_by=customer` option on
`GET /reports/sales`, (8) add a variant-level drilldown to the existing
`stockByArtist()` report, (9) delete the Settings "Data Backup" block, and
(10) move username + logout from the sidebar footer into the top-right of
`AppTopbar.vue`, adding the still-missing i18n key for the logout label and
surfacing store name + active event name in the same navbar.

No new business-logic services, no schema changes beyond the additive
`Customer::orders()`/`preorders()` Eloquent relations (no migration — FK
columns already exist) and Event's missing `preorders()` relation. All new
backend work follows this codebase's existing delete-guard, `present()`, and
report-controller conventions rather than introducing new patterns.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), Node/Vue 3 (Vite build) — matches existing repo, no version change.

**Primary Dependencies**: Backend: existing `App\Services\*`, `App\Policies\*`, `App\Support\ActivityLogger`, `App\Support\ModeGate`. Frontend: Vue 3 + Pinia + vue-i18n + Chart.js (already a Dashboard dependency, reused as-is) + existing `BaseModal`/`DataTable`/`ProductDetailModal` components.

**Storage**: MySQL 8 (existing `orders`, `preorders`, `customers`, `events`, `products`, `product_variants`, `activity_logs` tables) — no new tables; no migration for `Customer`/`Event` relations since `customer_id`/`event_id` FK columns already exist on `orders`/`preorders`.

**Testing**: `php artisan test` (MySQL, `tests/Feature/`) for new/changed endpoints; Vitest under `qa-tests/` for new components; manual browser verification per Constitution Principle II for every touched screen (Login, Sidebar, Sales, Events, Customers, Dashboard, Reports, Settings, Navbar).

**Target Platform**: Same as existing app — single-machine local install, served over `localhost`.

**Project Type**: Web application (Laravel API + Vue SPA in one repo) — existing "Option 2"-shaped structure, no restructuring.

**Performance Goals**: No new goals beyond existing Constitution Principle V (no N+1s on the new customer-history/aggregation queries; `stockByArtist()` drilldown must not turn into a per-artist N+1 when expanded).

**Constraints**: Label-only rename (FR-008) — must not touch route segments, model names, DB columns, or API field names. Delete guards MUST block on any existing order/preorder reference, including non-final preorder statuses (spec Assumptions). DEMO/LIVE mode boundary and existing role-based menu gating (`canAccessMenu`) MUST be respected on every new screen/endpoint.

**Scale/Scope**: 9 screens/views touched (Login, Sidebar, Topbar, Sales, Events, Customers, Dashboard, Reports, Settings); 2 new Eloquent relations; ~4 new/extended controller actions; 2 locale files + N `lang/id/*.php` files touched for the rename; no new tables.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. Event/Customer delete guards reuse `ArtistController::destroy()`/`CategoryController::destroy()`'s existing pattern (policy authorize → guarded existence check → `DB::transaction` + `ActivityLogger`) rather than inventing a new one. Sales page reuses the existing `ProductDetailModal` unchanged; only a new "products sold" table popup is net-new. No speculative abstraction planned.
- **II. Testing Standards** — PASS, enforced by plan: every new/changed backend action gets a `tests/Feature/` test against MySQL; every touched screen gets a real-browser check before being marked done, per the plan's task breakdown.
- **III. User Experience Consistency** — PASS. All new UI uses existing `@theme` tokens and existing components (`BaseModal`, `DataTable`, `BaseSelect`); no raw hex. Delete controls are hidden entirely (not disabled) for roles without `canManageMasterData()`/ownership, matching the "hidden means unavailable" rule. UI copy is Indonesian at the source (`lang/id/*`, `locales/id.json`) with English as a translation, matching convention — this plan also **fixes** a pre-existing gap (hardcoded, unkeyed "Keluar" logout string) rather than adding a new one.
- **IV. Security** — PASS. Delete guards are server-authoritative (Policy `delete` abilities + controller-side existence checks), not cosmetic UI hiding alone. No client-supplied money/stock values introduced. Customer PII (phone/email/social_handle — see `Customer.php` L10-14 comment) stays server-side except where the existing Sales popover already exposes it (unchanged); the new customer-history view must reuse that same existing exposure boundary, not widen it. Delete + rename operations that mutate data go through `ActivityLogger` inside the same transaction.
- **V. Performance & Optimization** — PASS with an explicit note: the new `stockByArtist()` drilldown and `group_by=customer` sales aggregation must be built as a single grouped query (or one additional query keyed by the clicked artist/customer), not a loop-per-row N+1 — called out explicitly in Phase 1 design and re-verified in code review.

No violations requiring Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/009-ui-ux-refinements/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/             # Phase 1 output (API contract deltas)
└── tasks.md               # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/
│   ├── EventController.php          # + destroy()
│   ├── CustomerController.php       # + destroy(), + transactions()
│   └── ReportController.php         # + group_by=customer on sales(); stockByArtist() drilldown param
├── Models/
│   ├── Event.php                    # + preorders() relation
│   └── Customer.php                 # + orders(), + preorders() relations
├── Policies/
│   ├── EventPolicy.php              # + delete()
│   └── CustomerPolicy.php           # + delete()
└── Http/Requests/                   # no new FormRequests expected (delete has no body)

resources/js/
├── views/
│   ├── LoginView.vue                # trim right-panel copy
│   ├── SalesView.vue                # reorder, drop summary table, new popup flow
│   ├── EventsView.vue                # + delete action
│   ├── CustomersView.vue             # + delete action, + "view transactions" action
│   ├── DashboardView.vue             # + per-customer table/chart
│   ├── ReportsView.vue               # + stock-by-artist drilldown
│   └── SettingsView.vue              # remove Data Backup block
├── components/
│   ├── layout/AppSidebar.vue         # fix default-state color class; remove logout block
│   ├── layout/AppTopbar.vue          # + store name, event name, user name + logout (top-right)
│   └── sales/TransactionItemsModal.vue   # NEW — products-sold table popup
├── api/
│   ├── events.js                    # + remove()
│   ├── customers.js                 # + remove(), + transactions()
│   └── reports.js                   # group_by 'customer' passthrough (no new function)
├── locales/{id,en}.json              # Artist→Penjual/Sellers rename; + nav.logout / common.logout key
lang/id/*.php, lang/en/*.php          # Artist→Penjual/Sellers rename in matching keys

tests/Feature/
├── EventDeleteTest.php               # NEW
├── CustomerDeleteTest.php            # NEW
├── CustomerTransactionsTest.php      # NEW
└── ReportControllerTest.php          # extended: group_by=customer, stock-by-artist drilldown

qa-tests/
├── sales-transaction-popup.spec.js   # NEW
└── (existing suites extended for renamed labels where they assert on "Artist" text)
```

**Structure Decision**: Existing single Laravel+Vue repo (no `backend/`/`frontend/` split — matches how this codebase is already organized: `app/`, `resources/js/`, `tests/Feature/`, `qa-tests/`). This feature adds no new top-level directories.

## Complexity Tracking

*No Constitution Check violations — table not needed.*
