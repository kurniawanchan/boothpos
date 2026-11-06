# Implementation Plan: Owner Reopens a Closed Shift

**Classification**: INTERNAL

**Branch**: `085-reopen-closed-shift` (spec dir number ≠ branch number) | **Date**: 2026-11-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/063-reopen-closed-shift/spec.md`

## Summary

A closed cashier shift is final today (`CashierSessionController::close()` stores `closed_at`, `closing_cash`,
`expected_cash`, `cash_difference`, and nothing can undo it). This feature lets the **Owner role only** reopen a closed
shift, with a mandatory reason, so existing tools (cash payment records and corrections, a fresh closing count) can
correct it, and then close it again with recomputed figures.

Approach (details in research.md):

- **One new endpoint** `POST /sessions/{session}/reopen` and **one writer**, `CashierSessionReopenService`.
- **One new append-only table** `cashier_session_reopens` holds who/when/why plus the closing figures as they were
  before that reopen. The shift row itself goes back to the plain *open* shape (status `open`, `closed_at` and the three
  closing figures NULL), so every existing open-shift behaviour (expected-cash formula, cash attribution, summary,
  close) works unchanged and `close()` needs no change.
- **Owner-only is a fixed rule**, not a configurable action: the check is `$user->role?->isOwner()` inline in the
  controller, and the route is listed in `MenuActionsRouteCoverageTest::EXEMPT` with that reason. No `MenuActions` entry,
  so no role can be granted it through the role editor.
- Reopening touches no orders, payments, stock, reports, recaps or settlements (FR-008).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API), Vue 3 + Vite (SPA), Tailwind v4 tokens

**Primary Dependencies**: existing only (`ActivityLogger`, `CashierSessionQuery`, `BaseDrawer`/`ConfirmDialog`-style
modal, `vue-i18n`). No new package.

**Storage**: MySQL 8 — one new table, no change to existing columns (all four closing columns are already nullable).

**Testing**: PHPUnit feature tests on MySQL (`boothpos_test`), Vitest in `qa-tests/`, plus a real-browser check
(Constitution II).

**Target Platform**: local single-machine install (native or Docker), unchanged.

**Project Type**: web application (Laravel API + Vue SPA in one repo).

**Performance Goals**: none beyond not adding N+1: the list adds one `withCount`, the detail one eager load.

**Constraints**: server-side authorization and audit inside the same transaction; DEMO/LIVE clone must keep working.

**Scale/Scope**: 1 endpoint, 1 service, 1 model, 1 migration, 1 view extended, ~6 locale keys per language.

No NEEDS CLARIFICATION remain.

## Constitution Check

*GATE: passed before research; re-checked after design — still passed.*

| Principle | Status | How |
|-----------|--------|-----|
| I. Clean code / single write path | Pass | Logic in `CashierSessionReopenService`, controller only validates and delegates; the reopen table is written nowhere else. No speculative configurability (Owner-only is hard-wired, not a permission matrix). |
| II. Testing | Pass (open item) | Feature tests on MySQL listed in research D9; Vitest for the view; a real-browser run is a task (cannot be claimed done from tests alone). |
| III. UX consistency | Pass | Button rendered from the server-computed `can_reopen` (hidden, never disabled-for-role); 409/403/422 convention; tokens only; copy in Indonesian + English locale files. |
| IV. Security | Pass | Owner check server-side; reason required; audit row and `activity_logs` row in the same transaction as the state change; the pre-reopen figures are snapshotted, never recomputed; the cashier's row is locked to stop two shifts opening at once. |
| V. Performance | Pass | `withCount('reopens')` on the list, one eager load on detail; no new frontend dependency. |
| Stack constraints | Pass | MySQL only; migration is additive. |
| Docs discipline | Pass (task) | OpenAPI in the same commit; dated note in the PRD (shift reconciliation was documented as immutable once closed) and in CLAUDE.md. |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/063-reopen-closed-shift/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── reopen-session.md
└── tasks.md            # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/CashierSessionController.php   # + reopen()
├── Models/CashierSession.php                           # + reopens() relation
├── Models/CashierSessionReopen.php                     # NEW
├── Services/CashierSessionReopenService.php            # NEW (only writer)
├── Services/CashierSessionQuery.php                    # detail(): reopens + can_reopen; list(): reopen_count
└── Services/DemoClone/CloneRegistry.php                # + cashier_session_reopens (via cashier_sessions)
database/migrations/2026_11_06_000001_create_cashier_session_reopens_table.php   # NEW
routes/api.php                                          # + POST /sessions/{session}/reopen
lang/{id,en}/events_sessions.php                        # + messages
docs/openapi-pos-mvp.yaml                               # + endpoint, + fields
docs/PRD-POS-Event-Multivendor.md                       # dated note
tests/Feature/CashierSessionReopenTest.php              # NEW
tests/Feature/MenuActionsRouteCoverageTest.php          # + EXEMPT entry
resources/js/api/sessions.js                            # + reopenSession()
resources/js/views/SessionsHistoryView.vue              # button, reason dialog, reopen history, list badge
resources/js/locales/{id,en}.json                       # + keys
qa-tests/unit/SessionsHistoryReopen.test.js             # NEW
```

**Structure Decision**: the existing Laravel API + Vue SPA layout; every addition sits next to the session code it
extends (controller, query service, history view). No new top-level directory.
