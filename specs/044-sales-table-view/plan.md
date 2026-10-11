**Classification**: INTERNAL

# Implementation Plan: Sales Page — Tables View

**Branch**: `044-sales-table-view` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/044-sales-table-view/spec.md`

## Summary

Add a two-segment toggle (*Daftar order / Order list* · *Meja / Tables*) to `/sales`. The new **Tables view** is a read-only live board of the floor — four figures, tiles grouped by area, takeaway orders in their own group — and clicking an occupied tile opens a read-only detail of that open order.

**Approach: reuse, don't rebuild.** The floor data, its change-detection token, its permission gate and the open-order detail already exist (`GET /floor`, `GET /tabs/{id}`, both gated by the `floor` menu). The feature adds only: (1) two additive fields on `GET /floor` (`paid_amount` per open order; inactive tables that still hold an open order), (2) one additive field on the tab payload (`opened_by`), (3) a pure front-end function that turns the snapshot into the four figures and tile states, and (4) the Sales-page UI (toggle, board, tiles, detail panel). No migration, no new endpoint, no write path.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 13 (API); Vue 3.5 + Vite (SPA), Tailwind v4 tokens, vue-i18n (id/en), Pinia

**Primary Dependencies**: existing only — `FloorQuery`, `TabResource`, `PaymentSummary`, page kit (`PageHeader`, `ViewToggle`, `KpiStrip`, `DetailPanel`), `usePolling`, `useNow`, `useQueryState`, `useFloorStore`. No new package.

**Storage**: MySQL 8, **no schema change**. Reads `orders` (open), `dining_tables`, `payments`, `kitchen_ticket_items`.

**Testing**: PHPUnit on real MySQL (`tests/Feature/FloorViewTest.php` extended, a tab-payload test, a permission test); Vitest (`qa-tests/unit` for the pure figures/tile function, `qa-tests/component` for the board, tile, detail, toggle and the unchanged list); real-browser check per the constitution.

**Target Platform**: the SPA served by Laravel on the store's machine (`localhost`); desktop/tablet browsers.

**Project Type**: web application (Laravel API + Vue SPA, same repo).

**Performance Goals**: first tiles visible < 2 s for 60 tables (SC-005); a POS change visible on the board within ~5 s (SC-003) — polling at the floor's existing 3 s cadence with the version token making the "no change" path tiny.

**Constraints**: read-only (FR-023); the order list must be byte-for-byte unchanged in behaviour (SC-006); server-side access control (FR-019); no cost/margin in the detail (FR-020); constant query count for `GET /floor` (the existing guarantee).

**Scale/Scope**: tens of tables, tens of open orders per outlet; one screen (a view of an existing page) and one detail panel.

## Constitution Check

*Gate evaluated against `.specify/memory/constitution.md` v1.0.0 (the 042 amendment is still unratified and does not affect this feature).*

| Principle | Check | Result |
|---|---|---|
| I. Single implementation, logic in services | Floor data stays in `FloorQuery`; the four figures and tile states live in ONE pure front-end module used by the board (no per-component re-computation); partial-payment uses the existing `PaymentSummary` SQL helper, not a second formula. | PASS |
| II. Verify, don't assume | Backend tests on MySQL; Vitest for every new unit/component; mandatory browser walk-through of toggle → board → click → detail, console checked (quickstart.md). | PASS (to be evidenced) |
| III. UX consistency | Token classes only; all copy in id + en (constitution says Indonesian-first); the toggle, tiles and detail are **hidden** — not disabled — for roles without floor access; errors follow the 422/409/403 convention; empty/error/loading states provided. | PASS |
| IV. Security | Both data routes already enforce the `floor` menu server-side; the detail payload has no cost fields; the view writes nothing; money is server-supplied (client only sums server strings in integer cents for display). | PASS |
| V. Performance | `GET /floor` keeps a constant number of queries (paid amount is one more column in the existing open-orders query, inactive-with-order tables are one more `OR` in the existing tables query); detail polling runs only while the panel is open; the board is code-split with the Sales route (verified by build output). | PASS |
| Docs discipline | `docs/openapi-pos-mvp.yaml` updated in the same commit for the `/floor` and tab-payload additions. | PASS (task) |
| Stack constraints | MySQL 8, single machine, no new infrastructure. | PASS |

No violations; Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/044-sales-table-view/
├── spec.md
├── plan.md              # this file
├── research.md          # decisions D1–D12
├── data-model.md        # snapshot additions, derived tile states, figures
├── quickstart.md        # verification script (backend + browser)
├── contracts/
│   ├── api-floor-and-tab.md
│   └── ui-contracts.md
├── checklists/requirements.md
└── tasks.md             # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/FloorQuery.php                      # + paid_amount per open order; inactive tables with an open order
└── Http/Resources/TabResource.php               # + opened_by (additive)
(app/Services/TabService.php::loaded()           # eager-load the opener (cashier) — one extra relation)

docs/openapi-pos-mvp.yaml                        # /floor + tab payload additions

resources/js/
├── views/SalesView.vue                          # toggle in header; list wrapped so its state persists; tables view mounted lazily
├── utils/floorBoard.js                          # NEW pure: figures, tile state, elapsed label, grouping
├── components/sales/
│   ├── FloorBoard.vue                           # NEW figures + "Status meja" panel + area groups + takeaway group
│   ├── FloorBoardTile.vue                       # NEW one tile (empty / occupied / partial / over-45 / inactive)
│   └── OpenOrderDetail.vue                      # NEW read-only detail inside DetailPanel (+ live refresh, ended states)
├── stores/floor.js                              # reused unchanged (shared snapshot + polling)
└── locales/{id,en}.json                         # new keys via specs/041-back-office-ui-redesign/tools/merge_locale.py

tests/Feature/FloorViewTest.php                  # extended
tests/Feature/TabDetailTest.php (or existing)    # opened_by, no cost fields, 403 without floor menu
qa-tests/unit/floorBoard.test.js                 # NEW
qa-tests/component/{SalesViewToggle,FloorBoard,FloorBoardTile,OpenOrderDetail}.test.js   # NEW
qa-tests/component/SalesView*.test.js            # existing list tests stay green (SC-006)
```

**Structure Decision**: the existing web-application layout (Laravel `app/` + Vue `resources/js/`); the feature is a view on the existing `SalesView` plus small additive backend changes to the floor read model.

## Post-design Constitution re-check

Design in research.md/data-model.md/contracts keeps every row above PASS: no second implementation of figures or partial-payment logic, no new write path, access still enforced by the existing server gate, and the only backend changes are additive fields that existing consumers ignore.
