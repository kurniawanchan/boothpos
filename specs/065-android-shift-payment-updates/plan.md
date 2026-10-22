# Implementation Plan: Android Shift, Payment and Setup Updates

**Branch**: `065-android-shift-payment-updates` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/065-android-shift-payment-updates/spec.md`

## Summary

Six changes. **Server/web**: (1) a shift list for owner/admin (open + closed, both sources), a shift detail with cash in/out, a cash-movements list, and a dashboard "open shifts" panel — all inside the existing *Cashier Session* page and dashboard; (2) app and web shifts become **separate** (source = `cashier_sessions.device_id` set or not), so one user can hold one of each; (3) `close` accepts a typed total (`counted_total`) inside the same controlled protocol as denominations (pending-QRIS check + variance approval kept), drops EDC, and `summary`/`x-report` now expose `expected_cash` (Q2 = B); (4) a public `GET /server-address` returns the admin's chosen address. **Android**: tidy open-shift layout + typed amount + no skip link; close screen with sales info, typed amount, no EDC; QRIS sheet text/title/QR from the payment channel (with a real failure state); "Siapkan terminal" loses the address input — the app asks the build-time default addresses (Q1 = A) for the admin's choice, stores it on the device, and never re-asks until data is cleared. No new tables; one small migration (index). Details: [research.md](research.md), [data-model.md](data-model.md), [contracts/shift-and-address-api.md](contracts/shift-and-address-api.md).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API); Kotlin + Jetpack Compose (`android-pos/`); Vue 3 + Vite (web)

**Primary Dependencies**: none new

**Storage**: MySQL 8 — `cashier_sessions` (existing columns `device_id`, `counted_total`, `variance`, `z_report`, `edc_batch_total` nullable), `cash_movements` (existing); one new index on `cashier_sessions (user_id, status, device_id)`; Android `TokenVault` (server URL)

**Testing**: `tests/Feature/` (real MySQL, container with `-e` flags **before** the service name), Android JVM unit tests (`./gradlew testDebugUnitTest`), Vitest `qa-tests/`, real-browser check of Cashier Session + dashboard

**Target Platform**: Laravel server, Android tablet, web SPA

**Project Type**: mobile + API + web (existing monorepo)

**Performance Goals**: shift list and dashboard panel use batched grouped queries (constant query count regardless of rows); open-shifts panel capped (50 rows)

**Constraints**: outlet and DEMO/LIVE scope on every query; non-owner/admin sees only own shifts (server-enforced); close stays one transaction with idempotency; already-set-up tablets keep working without re-pairing after upgrade; server and APK released together

**Scale/Scope**: ~3 tablets per outlet; ~15 backend files, ~12 web files, ~14 Android files (+ tests), 1 migration

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — passes; one owner-approved control relaxation recorded below.*

| Principle | Check |
|-----------|-------|
| I. Single write path | Shift writes stay in `CashierSessionController`/`CashMovementService`/`ShiftReportService` (formulas in ONE place — the new list reuses `ShiftReportService` building blocks, no second cash formula). Source resolution lives in ONE helper (`ShiftSource`), used by `current`, `store`, `PaymentService`, `RefundService`. |
| II. Testing | Feature tests for list/scope/source separation/close typed total/EDC removal/address endpoint/dashboard panel; Android unit tests; Vitest; browser check. |
| III. UX consistency | 422/409/403 conventions kept; Indonesian copy on Android; web copy via i18n (id + en); tokens only; controls hidden (not disabled) for roles that cannot use them (all-shifts tabs hidden for non-owner/admin). |
| IV. Security | Server decides outlet/source (source from `X-Device-Id` validated device, never body); non-owner/admin cannot list or fetch others' shifts or cash movements; public `GET /server-address` returns ONLY the selected address (the other never leaves the server, as 054 FR-011), throttled; `variance` approval still bound to the exact submitted count (now also to `counted_total`). **Relaxation (owner decision Q2 = B):** `expected_cash` is shown before counting, ending the blind count — recorded in CLAUDE.md as superseding 048. |
| V. Performance | Batched aggregates; eager loads (`user:id,name`, `device:id,name`, `outlet:id,name`); dashboard panel cache not used (live, small). |
| Docs discipline | `docs/openapi-pos-mvp.yaml` updated in the same change; dated note in CLAUDE.md. |

## Project Structure

### Documentation (this feature)

```text
specs/065-android-shift-payment-updates/
├── plan.md  research.md  data-model.md  quickstart.md
├── contracts/shift-and-address-api.md
├── checklists/requirements.md
└── tasks.md            # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Support/ShiftSource.php                        # NEW: source of the request (app = valid X-Device-Id, else web); openFor(user)
├── Http/Controllers/Api/CashierSessionController.php  # current/store scoped by source; index(); close: counted_total, no EDC; summary/x-report expose expected_cash
├── Http/Controllers/Api/CashMovementController.php    # + list across shifts (filters)
├── Http/Controllers/Api/ServerAddressController.php   # + public show for tablets (or new PublicServerAddressController)
├── Http/Controllers/Api/DashboardController.php       # + shifts()
├── Services/ShiftListService.php                  # NEW: batched rows for list/dashboard (reuses ShiftReportService pieces)
├── Services/ShiftReportService.php                # + batch helpers; z_report without EDC
├── Services/PaymentService.php (≈427), RefundService.php (≈54)  # open shift = ShiftSource::openFor(user)
├── Http/Requests/ (close request validation: counted_total)
database/migrations/2026_12_27_000001_cashier_sessions_source_index.php
routes/api.php                                     # GET /sessions, GET /cash-movements, GET /dashboard/shifts, GET /server-address (public)
lang/{id,en}/events_sessions.php
docs/openapi-pos-mvp.yaml
resources/js/
├── views/SessionView.vue                          # + owner/admin "All shifts" (tabs: Open · Closed · Cash in/out), detail drawer
├── components/session/ShiftTable.vue, ShiftDetail.vue, CashMovementsTable.vue   # NEW
├── components/dashboard/OpenShiftsPanel.vue       # NEW, used by DashboardView
├── api/sessions.js, api/dashboard.js, locales/{id,en}.json
android-pos/app/src/main/java/com/warkop/pos/
├── ui/screens/OpenShiftScreen.kt   # layout, typed amount, no skip link
├── ui/screens/ShiftScreens.kt      # CloseShiftScreen: sales info, typed amount, no EDC; CloseResult w/o EDC
├── ui/Sheets.kt                    # QRIS sheet(s): title beside icon, no warning, new text, QR failure + retry
├── setup/TerminalSetupScreen.kt    # no address input; default-address discovery
├── data/{PosBackend,RemoteBackend,TokenVault,ServerDiscovery(NEW)}.kt  # discovery, store once, drop 054 adoption
├── state/PosStore.kt               # openShift(typed), submitCount(typed, no EDC), remove adoptSelectedServer
└── app/build.gradle.kts            # BuildConfig defaults: DEFAULT_LOCAL_URL / DEFAULT_ONLINE_URL
core-network/…/PosApi.kt, dto/*.kt  # CloseRequest counted_total, no edc; server-address (public)
tests/Feature/…  qa-tests/…  android-pos/app/src/test/…
```

**Structure Decision**: existing mobile + API + web layout; web additions live inside the existing Cashier Session route and dashboard, as requested ("include in Cashier Session page").

## Complexity Tracking

No constitution violations. The Q2 = B relaxation is an owner decision, documented in research D6.
