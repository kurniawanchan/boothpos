# Implementation Plan: Terminal Identified by Name Only

**Branch**: `064-terminal-name-only` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/064-terminal-name-only/spec.md`

## Summary

A registered terminal (`devices` row) is known only by its **name**. The auto-generated `T01`/`T02` code stops being created, accepted, returned or displayed; the terminal name is unique among the *active* terminals of an outlet and data mode. Receipt numbers lose their terminal prefix and become a plain per-outlet running number (Q1 = A). Orders already carry `device_id`; this feature exposes the terminal **name** on every order surface (receipt screen and print, tab/floor payloads, Android order list/detail, web Sales rows + filter + export, shift summary) and replaces every `deviceCode` in the Android app with the name. Existing terminals and receipts are untouched (legacy codes become inert data). One backend migration, one new rename endpoint, no new web screen. Details: [research.md](research.md), [data-model.md](data-model.md), [contracts/terminal-api.md](contracts/terminal-api.md).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API); Kotlin + Jetpack Compose (`android-pos/`); Vue 3 + Vite (web Sales page)

**Primary Dependencies**: none new (no package, no Gradle dependency)

**Storage**: MySQL 8 — `devices` (code nullable, name rules), new `outlet_receipt_counters`, `orders` unique index on `receipt_no` becomes `(outlet_id, receipt_no)`; `terminal_counters` left in place, unused

**Testing**: `tests/Feature/` (real MySQL, container run with the explicit `-e` flags from CLAUDE.md), Android JVM unit tests (`android-pos`), Vitest `qa-tests/`; real-browser check of the Sales page

**Target Platform**: Laravel server (single machine or shared server), Android tablet (Warkop WJM 3-tablet pilot), web SPA

**Project Type**: Mobile + API + web (existing monorepo)

**Performance Goals**: no new per-request queries on list endpoints: `device` is eager-loaded (`select id,name`) in the order/Sales/floor queries (no N+1)

**Constraints**: receipt-number allocation stays inside the settling transaction under a row lock (no duplicates, no gaps on rollback); issued receipt numbers never change; tablets already paired must keep working without re-pairing; Android and server ship together (the `device.code` response key is removed)

**Scale/Scope**: ~3 terminals per outlet; touches ~8 backend files, ~12 Android files (+ tests), 2 web files, 1 migration

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes, no violations.*

| Principle | Check |
|-----------|-------|
| I. Single write path | `ReceiptNumbering` stays the ONLY receipt/queue allocator (its method just takes the outlet instead of the device); `DeviceService` stays the only device writer (pair + new `rename`); audit rows via `ActivityLogger`. No second path added. |
| II. Testing | Backend feature tests for pairing/rename/uniqueness, numbering, migration dedupe, order/receipt/Sales payloads; Android unit tests; Vitest for the Sales column/filter; browser check of Sales. Container test command carries the mandatory `-e` flags. |
| III. UX consistency | 422 for duplicate/blank name, 409 not used; Android copy in Indonesian; no raw hex; terminal filter hidden when the outlet has fewer than two terminals. |
| IV. Security | Outlet still derived server-side (pairing/rename use `OutletContext` + `OutletScope`; a foreign device → 404). Rename and pairing write the audit row inside the transaction. Order's terminal link is a server-stamped FK, never from a request body. Terminal name is not sensitive; no PII. |
| V. Performance | Eager-load `device:id,name`; counter row locked briefly; no cached figures introduced. |
| Docs discipline | `docs/openapi-pos-mvp.yaml` updated in the same change; dated note added to CLAUDE.md. |

## Project Structure

### Documentation (this feature)

```text
specs/064-terminal-name-only/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/terminal-api.md
├── checklists/requirements.md
└── tasks.md              # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/DeviceService.php            # pair(): no code; rename(); name rules; log by name
├── Services/ReceiptNumbering.php         # receiptFor(outletId): plain %06d per outlet
├── Services/TabSettlementService.php     # receiptFor(): allocate by order outlet
├── Services/SalesTransactionsService.php # rows + terminal_id/terminal_name, eager-load device
├── Services/FloorQuery.php               # device_code → device_name
├── Http/Controllers/Api/DeviceController.php   # present() without code; PATCH rename
├── Http/Requests/PairDeviceRequest.php   # name only; trimmed; no `code`
├── Http/Requests/RenameDeviceRequest.php # new
├── Http/Resources/TabResource.php        # device {id, name}
├── Http/Resources/OrderResource.php      # terminal_name (device must be loaded)
├── Http/Controllers/Api/OrderController.php    # receipt(): terminal_name
├── Http/Controllers/Api/CashierSessionController.php # summary/session payload: terminal_name
└── Models/Device.php
database/migrations/2026_12_2x_…_terminal_name_identity.php
lang/{id,en}/devices.php                  # name_taken, name_required
routes/api.php                            # PATCH /devices/{device}
docs/openapi-pos-mvp.yaml
resources/js/views/SalesView.vue, composables/useSalesFilters.js  # terminal column + filter
android-pos/app/src/main/java/com/warkop/pos/
├── data/{PosBackend,RemoteBackend,TokenVault,OrderMapper,ReceiptMapper,…Dto}.kt  # deviceCode → deviceName
├── model/PosModels.kt                    # terminalName on Order/Receipt
├── print/Documents.kt                    # "Terminal: <name>" on the struk
└── ui/screens/{PinScreen,ShiftScreens,OrdersScreen,TablesScreen,TenderScreen}.kt # name, no "T01"
tests/Feature/…  qa-tests/…  android-pos/app/src/test/…
```

**Structure Decision**: existing mobile + API + web layout; changes are in place, no new module. The web app gets no terminal-management or dashboard device screen (none exists today — the spec was narrowed accordingly).

## Complexity Tracking

No constitution violations to justify.
