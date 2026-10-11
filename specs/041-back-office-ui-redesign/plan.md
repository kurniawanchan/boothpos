**Classification**: INTERNAL

# Implementation Plan: Back Office UI Redesign (full design scope)

**Branch**: `041-back-office-ui-redesign` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/041-back-office-ui-redesign/spec.md`

## Summary

Bring the whole product to the Warkop WJM Back Office design (product-owner decisions: **Q1 = C** build every capability the design shows; **Q2 = B** new visual system on every screen). The work is an umbrella of ten ordered phases, each releasable on its own and each intended to become its own sub-feature (own spec/plan/tasks) when it starts:

1. **Phase 1 — Frame, tokens, page kit, restyle of all screens.** Presentation only; allowed by the current constitution; no data-model change.
2. **Phase 2a — Organisation foundation.** Outlets, business date, action-level permissions with outlet scope, reason codes, one approval path, tax/rounding, payment methods, audit additions, default-outlet migration. **Gated by a constitution amendment.**
3. **Phase 2b — Shared-server hardening.** Production web tier, trusted proxies, token expiry, scheduler, volumes. Required before any real multi-outlet go-live.
4. **Phase 3 — Menu & recipes and stock by location.**
5. **Phase 4 — Purchasing & suppliers.**
6. **Phase 5 — Sales records, returns, cash/shifts, order and table monitor, end-of-day.**
7. **Phase 6 — Dashboard, global search, notifications.**
8. **Phase 7 — Customers & loyalty, report catalogue, users/roles/audit screens.**
9. **Phase 8 — POS operations (devices, sync exceptions, configuration releases, approval limits, receipts, app policy).**
10. **Phase 9 — Settings completion and data-import history** (folded into earlier phases where it unblocks them).

Technical approach (details in [research.md](research.md)): keep the Laravel service layer and single sanctioned write paths; add a second global scope for outlets next to the existing DEMO/LIVE one; derive everything that touches money, stock, dates or permissions on the server; build the frontend frame and a reusable page kit once and migrate the 31 views onto it.

## Technical Context

**Language/Version**: PHP 8.3+ (dev image php 8.4), Laravel (existing app), Vue 3 + Vite, Tailwind v4 CSS-first, Pinia, vue-i18n. No change.

**Primary Dependencies**: existing only (Sanctum, spreadsheet library, `html2canvas`/`jsPDF`, `jszip`, `chart.js`). **New dependencies: none committed by this plan.** A server-side PDF library is **UNVERIFIED** and decided in the Phase 7 task after checking availability; a production web/application server for the shared deployment is chosen and verified in Phase 2b.

**Storage**: MySQL 8 (required; SQLite hard-fails). New tables listed in [data-model.md](data-model.md). Private local disk for generated exports and proofs.

**Testing**: PHPUnit feature tests on real MySQL (`warkop_wjm_test`, run in Docker only with explicit `-e` overrides); Vitest + Testing Library under `qa-tests/`; real-browser verification per phase (see [quickstart.md](quickstart.md)); no new test framework.

**Target Platform**: modern desktop/laptop browsers (≥ 1024 px primary, tablets usable); server on Docker. Single-machine mode stays supported; shared-server mode is new (Phase 2b).

**Project Type**: web application (Laravel API + Vue SPA in one repository).

**Performance Goals**: from the spec — frame and loading placeholder within 1 s; lists of 1,000 rows filter/sort without visible lag (SC-012); cashier flows no slower than today (SC-010); polling screens (~3 s) must hold at the target outlet count (load-tested in Phase 2b).

**Constraints**: server recomputes money, totals, stock, dates and permissions; hidden-not-disabled for unauthorised actions; Indonesian copy with English via i18n; OpenAPI updated in the same commit as any route/response change; DEMO/LIVE isolation preserved; each migration reversible and rehearsed on populated data.

**Scale/Scope**: 13 design modules, 12 built-in roles, ~25 action permissions, 22 reports, ~35 new tables, ~40 new API resource groups, 31 existing views to migrate (21,588 lines). Order of magnitude: multi-quarter.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.* Constitution v1.0.0.

| Principle / section | Phase 1 | Phases 2a–9 | Notes |
|---|---|---|---|
| I. Code quality (services, single write paths, no speculative abstraction) | PASS | PASS with care | `StockService::applyMovement`, `ActivityLogger`, `PaymentRecorder` stay the only write paths; new cross-cutting concerns each get exactly one implementation (`ApprovalService`, `PermissionGate`, `OutletFilter`). Release engine kept isolated to avoid speculative generality. |
| II. Testing (real MySQL, `qa-tests`, real browser, evidence) | PASS | PASS | Per-phase test + browser evidence is a task, not an afterthought. Baseline suite failures (4 files) must be cleared before Phase 2a touches orders/payments. |
| III. UX consistency (tokens only, error convention, hidden-not-disabled, Indonesian copy) | PASS | PASS | Palette becomes new `@theme` tokens; no raw hex; no dependency on the design bundle's `_ds` system. Existing, documented i18n exception (feature 002) stands. |
| IV. Security (server-side truth, 3 authorisation mechanisms, immutable snapshots, audit in transaction) | PASS | PASS with new work | Permissions gate sits behind all three mechanisms; outlet scope is server-derived; approvals, PII masking, device tokens and release snapshots are designed in. Raw-query bypass of global scopes is a known trap (Decision 4). |
| V. Performance (no N+1, lean lists, code-split) | PASS | PASS with risk | KPI/summary endpoints must use grouped queries; heavy modules lazy-loaded; polling load tested in Phase 2b. |
| **Stack & Environment Constraints** ("one local machine per store, no cloud tier, no multi-tenancy; decisions MUST account for this") | PASS (no change) | **VIOLATION — amendment required** | See Complexity Tracking. |
| Documentation & Change Discipline (OpenAPI same commit; dated PRD notes; `BUG YANG DITEMUKAN` comments) | PASS | PASS | PRD/CLAUDE.md dated notes are part of Phase 0 below. |

**Gate result**: Phase 1 passes unconditionally. Phases 2a–9 are **conditionally cleared**: the violation is justified by the product owner's explicit decision (Q1 = C), but implementation of Phase 2a MUST NOT start until the constitution amendment in Complexity Tracking is ratified and the PRD/CLAUDE.md notes are recorded. No other unjustified violations.

**Post-design re-check (after Phase 1 design artefacts)**: no new violations. The design adds two items to watch rather than violations: (a) outlet scope relies on a global scope, mirroring the existing DEMO/LIVE mechanism including its raw-query trap; (b) `current_stock` is kept as a denormalised total, which must only be written by `StockService`.

## Phase 0 — Preconditions (before the phase that needs them)

| # | Precondition | Needed by | Owner decision? |
|---|---|---|---|
| P0-1 | Ratify constitution amendment (Stack section), version level MINOR vs MAJOR | Phase 2a | **Yes** |
| P0-2 | Dated notes in PRD §1/§9.2/§10.2 and `CLAUDE.md` single-store statements; RUNBOOK deployment note | Phase 2a | No |
| P0-3 | Decide hosting for the shared server and the target outlet count (sizing/load test) | Phase 2b | **Yes** |
| P0-4 | Decide licensing entitlement for outlets/devices (default: none enforced) | Phase 2a | **Yes** |
| P0-5 | Clear baseline failures in `InvoiceAvailabilityAndLogoTest`, `ModeScopingFollowUpTest`, `OrderTest`, `PosPartialPaymentTest` | Phase 2a | No |
| P0-6 | Sample design tokens (palette, spacing) from the reference captures into a token table | Phase 1 | No |
| P0-7 | Confirm the 22-report list and columns with report owners | Phase 7 | **Yes** |

## Phased delivery

Each phase lists the spec stories it delivers, what it depends on, and its independent test. Phase = candidate sub-feature (spec/plan/tasks created when it starts).

| Phase | Delivers (spec stories / FRs) | Depends on | Independent verification |
|---|---|---|---|
| **1 Frame & kit** | US1 (without outlet selector), US2, US14; FR-001/002/004/006/007(UI)/009/011/064/065/067 | P0-6 | all roles open every module in the new frame; cashier flows step/time parity; locale parity; contrast/keyboard checks |
| **2a Org foundation** | US3; FR-003/008/010/011–019, 053–056 (backend), 066 | P0-1, 0-2, 0-4, 0-5 | create outlet, cut-off assignment, tax version, payment method, reason code; cross-outlet leakage test matrix; self-approval refused |
| **2b Shared server** | deployment basis for all multi-outlet use | P0-3, 2a (outlet identity) | HTTPS reachability from a second network, trusted-proxy IP in audit/throttle, token expiry, scheduler runs, load test at target polling |
| **3 Menu & stock** | US5, US6; FR-020–032 | 2a | item with variants/modifiers/recipe → cost/margin; 86/quota; count with blind entry and approval; transfer in transit; wastage threshold |
| **4 Purchasing** | US7; FR-033–039 | 3 (locations, units) | PR→approve (other user)→PO→partial receipt→invoice exception→return |
| **5 Sales & cash** | US8, US9; FR-040–044, 057 (EOD), 059 | 2a, 3 | immutable sale, reprint copy marker, return+approval, staff meal limit, variance approval, day close blocked/unblocked |
| **6 Dashboard/search/bell** | US4; FR-004/005/063 | 3, 4, 5 | figures equal list sums; search finds across modules within permissions; bell count matches attention list |
| **7 Customers, reports, users/roles/audit** | US10, US11, US12; FR-045–056 (screens) | 2a, 5; P0-7 | tier change on threshold, points ledger, DSR; schedule+export history; permission matrix edits audited |
| **8 POS operations** | US13; FR-016/057–062 | 2a, 5 | release publish/rollback with per-device version; sync exception resolution; limits applied |
| **9 Settings completion** | remaining settings sections, import history; FR-017/018 | 2a | import validate-then-apply; sections grouped with issue counts |

Phases 3–5 can overlap after 2a if staffed separately; the order above is the minimum-risk sequence.

## Project Structure

### Documentation (this feature)

```text
specs/041-back-office-ui-redesign/
├── plan.md              # This file
├── research.md          # Phase 0 output — decisions 1–20
├── data-model.md        # Phase 1 output — entities by phase
├── quickstart.md        # Phase 1 output — verification per phase
├── contracts/           # Phase 1 output — API and UI contracts
│   ├── README.md
│   ├── api-foundation.md        # outlets, business date, permissions, reasons, approvals, settings, search, attention, dashboard
│   ├── api-catalog-stock.md     # menu, recipes, stock locations, counts, transfers, wastage
│   ├── api-purchasing.md        # suppliers, PR, PO, receipts, invoices, returns
│   ├── api-sales-ops.md         # sales records, returns, shifts, EOD, devices, sync, releases, limits
│   ├── api-people-reports.md    # members/loyalty, reports, users/roles/audit
│   └── ui-contracts.md          # frame, page kit, interaction rules, tokens
├── checklists/requirements.md
└── tasks.md             # NOT created here (/speckit-tasks, per phase)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/        # one controller per resource, thin; new: Outlets, Permissions, ReasonCodes, Approvals,
│                                #   Search, Attention, Dashboard, StockLocations, StockCounts, Transfers, Wastage,
│                                #   PurchaseRequests, GoodsReceipts, SupplierInvoices, SupplierReturns, Members,
│                                #   ReportCatalog, Devices, Sync, ConfigReleases, DayClose, ...
├── Http/Requests/               # FormRequest per write endpoint (authorize() uses PermissionGate)
├── Http/Resources/              # JsonResource per entity (money as "0.00" strings)
├── Models/Concerns/             # existing HasDataMode, DataModeScope + new BelongsToOutlet, OutletScope
├── Policies/                    # existing + new per-entity policies calling PermissionGate
├── Services/                    # business logic; existing StockService, PaymentRecorder, ActivityLogger stay the only
│                                #   write paths; new ApprovalService, OutletFilter, BusinessDateResolver, TaxCalculator,
│                                #   ReleaseService, DayCloseService, ReportRunner/*, LoyaltyService, SyncIngestService
├── Support/                     # PermissionGate, PermissionCatalog, OutletContext, existing MenuKeys (kept, derived)
database/migrations/             # new, date-prefixed after the latest (load-bearing order); reversible
routes/api.php                   # grouped by module; OpenAPI updated in the same commit
resources/
├── css/app.css                  # @theme tokens extended (palette sampled in Phase 1)
└── js/
    ├── components/layout/       # AppShell (rewritten), RailNav, TopBar, Breadcrumb, OutletSelector, GlobalSearch,
    │                            #   NotificationBell, UserMenu
    ├── components/page/         # PageHeader, KpiStrip/KpiCard, TabBar, FilterBar, BulkBar, DetailPanel, SettingsLayout,
    │                            #   StatusChip, ReasonDialog (with codes)
    ├── components/ui/           # existing Base* kept; DataTable gains selection/bulk
    ├── views/                   # existing views migrated onto the kit; new views per module/phase
    ├── api/ stores/ router/     # one api module and store per resource group
    └── locales/{id,en}.json     # parity enforced by existing tests
tests/Feature/                   # PHPUnit per phase incl. OutletIsolationMatrixTest, SelfApprovalTest
qa-tests/{component,unit}/       # Vitest per kit component and view
docker/
├── php/ store/                  # unchanged single-store images
└── shared/                      # NEW in Phase 2b: production web tier + scheduler (stack chosen and verified there)
docs/openapi-pos-mvp.yaml        # updated with every route change
```

**Structure Decision**: single repository, existing Laravel + Vue layout; no new top-level project. New backend code follows the established controller → service → model layering; new frontend code is organised as a reusable kit plus per-module views. A separate `docker/shared/` tree keeps the single-store images unmodified.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Stack constraint "single local machine per store, no multi-tenancy" | Q1 = C: the design is a three-outlet organisation sharing stock, purchasing, customers and reports | One install per outlet with replication would need conflict resolution for stock, numbering and cash across independent databases, far more risk than one shared server; ignoring the constraint would leave the constitution contradicting the product |
| Second global scope (`OutletScope`) beside `DataModeScope` | Outlet isolation must hold for every query, including those written later | Hand-written `where outlet_id` per query is exactly the defect class the DEMO/LIVE work already hit (raw queries); a scope plus an enumerating test is the cheaper guard |
| Denormalised `current_stock` kept next to `stock_balances` | Hundreds of existing call sites, imports and reports read `current_stock` | Removing it would force a rewrite of working code in the same release as the new feature; the single write path keeps it consistent |
| Release snapshots (Phase 8) | Design shows publish/schedule/rollback with per-device applied version | Live edits with no versioning contradict the design; the engine is isolated so it can shrink to "price/menu only" |

**Proposed amendment text (for P0-1)**: replace the first paragraph of "Stack & Environment Constraints" with: *"BoothPOS runs for one organisation, which may have one or more outlets. It runs either entirely on one local machine (the original deployment, unchanged) or on one shared server reachable by all outlets over HTTPS. Multi-tenancy across unrelated customers remains out of scope. Architectural decisions MUST work in the single-machine case and MUST NOT assume more than one server."* Add the MySQL, git and documentation bullets unchanged. Version 1.1.0 (MINOR) unless the owner treats the old wording as a principle (then 2.0.0).

## Open decisions (need the product owner)

1. Ratify the constitution amendment and its version level (P0-1).
2. Hosting for the shared server and the target number of outlets/devices (P0-3).
3. Licensing: does the licence limit outlets or devices? Default assumed: no limit enforced (P0-4).
4. Report list and columns beyond the visible sample (P0-7).
5. Whether the existing seller/event features (pre-orders, events, sellers) stay in the restaurant product or are hidden for restaurant installs (default: kept, restyled, permission-gated).

## Risks

- **Scale**: multi-quarter; mitigated by independent phases and a first phase with no data-model risk.
- **Migration risk**: adding outlet scope and business date to live tables (orders, payments, sessions) on populated data; mitigated by reversible, re-runnable migrations rehearsed on a copy, and by clearing the four failing test files first.
- **Raw-query leakage**: any `DB::table()` report that forgets the outlet filter leaks across outlets; mitigated by `OutletFilter` and an enumerating test.
- **Shared-server exposure**: moving from LAN-only to internet-reachable changes the attack surface (token lifetime, CORS, headers, DB user); Phase 2b is mandatory before go-live.
- **Polling load**: ~3 s polling per kitchen/floor screen multiplied by outlets; measured only to ~5 tabs; load test in Phase 2b.
- **Privacy**: member/user personal data, exports and deletion requests carry UU PDP and ISO 27701 obligations; designed in Phase 2a/7 (Decision 16).
- **Design fidelity**: captures are cropped and the bundle contains an unrelated design system (`_ds`); tokens are sampled from the captures, and the 12 page definitions are the source for field-level detail.
- **Unverified items**: server-side PDF library, production web stack, sampled token values — each resolved inside the task that needs it, not assumed.
