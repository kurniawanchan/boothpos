# Implementation Plan: Seller-Scoped User Access

INTERNAL

**Branch**: `094-seller-scoped-user-access` | **Date**: 2026-11-05 | **Spec**: [spec.md](spec.md)

## Summary

A nullable `users.artist_id` links a user to ONE seller. When set, every read and write is narrowed to that seller by ONE gate (`App\Support\SellerGate`) used in three places: (1) an Eloquent global scope on seller-attributable models, (2) an explicit helper on the ~12 hand-rolled `DB::table` report queries (the same places that already add `data_mode` by hand), (3) a route middleware that refuses owner/admin-only surfaces. Order and pre-order HEADERS are visible when at least one line is the seller's (product-owner decision Q1:B), and then show the whole order; statistics and seller reports still count only the seller's own lines. No user keeps a hidden bypass except having no seller. Scope only narrows: the role/menu/action rules of feature 057 still run first.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (existing), Vue 3 SPA (existing)
**Primary Dependencies**: none new
**Storage**: MySQL 8; one migration (`users.artist_id` nullable FK, `customers.artist_id` nullable origin seller)
**Testing**: PHPUnit `tests/Feature` on `boothpos_test` (inside the container only with the `-e APP_ENV=testing -e DB_DATABASE=boothpos_test` flags); Vitest `qa-tests/`
**Target Platform**: local single-machine install (no multi-tenancy)
**Project Type**: web application (Laravel API + Vue SPA)
**Performance Goals**: no N+1; seller predicate is one `EXISTS`/`IN` per query
**Constraints**: backward compatible (NULL seller = today); must work in CLI/queue/seeder where no user is authenticated (gate resolves to "no scope")
**Scale/Scope**: ~25 controllers/services touched, ~12 raw queries, 7 screens with seller dropdowns

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I. Single implementation per concern | PASS | One `SellerGate`; reports call its helper, never re-implement the predicate |
| II. Testing (real MySQL, browser check) | PASS, with obligation | Isolation test per surface; route-coverage style test; browser check of Sales/POS/Reports as a scoped user |
| III. UX consistency | PASS | Controls a scoped user cannot use are hidden; seller dropdowns list only their seller; UI copy Indonesian |
| IV. Security | PASS, high-risk | Server-side enforcement on every path; 404 not 403 for foreign records; audit log in the same transaction for assignment changes |
| V. Performance | PASS | Denormalised `order_items.artist_id` already exists; no per-row lookups |
| Docs discipline | OBLIGATION | OpenAPI in the same commit; dated CLAUDE.md note; PRD §10.2 untouched (Q3:A, no portal) |

No unjustified violations. Re-checked after design: unchanged.

## Project Structure

```text
specs/058-seller-scoped-user-access/
├── plan.md  research.md  data-model.md  quickstart.md
└── contracts/seller-scope-api.md
```

Source touched (existing tree):

```text
app/Support/SellerGate.php                      # NEW: current seller, applyToItems(), visibleOrderIds
app/Models/Concerns/SellerScoped*.php           # NEW: global scopes (see data-model)
app/Http/Middleware/EnsureNotSellerScoped.php   # NEW: refuses admin-only surfaces
app/Models/User.php, Artist.php                 # artist relation, guards
app/Http/Controllers/Api/{User,Artist,Auth,Product,Stock,Order,Preorder,Report,Customer,SellerSettlement,PurchaseOrder,...}Controller.php
app/Services/{SalesTransactionsService,SalesByProductReport,SalesByPaymentMethodReport,SellerSettlementBuilder,SettlementService,...}.php
database/migrations/2026_11_24_000001_add_artist_id_to_users_and_customers.php
resources/js/{stores/auth.js,views/UsersView.vue,...}
tests/Feature/SellerScope*Test.php, qa-tests/...
docs/openapi-pos-mvp.yaml, CLAUDE.md
```

**Structure Decision**: existing Laravel + Vue layout, no new top-level directories.

## Key design decisions (detail in research.md)

1. **Gate, not per-controller checks**: `SellerGate::current()` reads the authenticated user; null in CLI/queue means no scope.
2. **Headers vs lines**: scope Order/Preorder headers by "has a line of my seller"; do NOT scope order/pre-order item relations (Q1:B shows whole orders); reports and statistics filter `order_items.artist_id` explicitly.
3. **Foreign record = 404** (route-model binding through scoped models).
4. **Write side**: `artist_id` forced to the user's seller on create; a foreign variant/product id fails as not found.
5. **Refused surfaces** for scoped users: users, roles, settings, backups, activity log, master-data import/export, vendors/materials, payment-method report, demo clone.
6. **Seller deactivated / deleted**: login still works, empty view and a notice; delete blocked while users are linked (409).
