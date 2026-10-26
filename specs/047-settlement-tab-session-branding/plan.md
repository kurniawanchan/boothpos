**Classification: INTERNAL**

# Implementation Plan: New-Tab Session, Seller Settlement Module, Store Branding

**Branch**: `076-settlement-tab-session-branding` | **Date**: 2026-10-26 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/047-settlement-tab-session-branding/spec.md` (the spec directory number and the branch number differ; `.specify/feature.json` points at the directory).

## Summary

Three independent slices, shippable in this order of risk:

1. **New-tab session (P1, small, frontend only).** Root cause is by design: the token lives in per-tab `sessionStorage`. Fix by handing the token from an already-signed-in tab to the new tab over a same-origin `BroadcastChannel` (memory-to-memory, still `sessionStorage` only, so closing the browser still ends the session), propagate logout across tabs, and add return-to-requested-page after login (it does not exist today).
2. **Seller settlement (P1, large, full stack).** A new owner/admin module: one reconciled statement per `(seller, event)` built by the server from the *same* revenue rules the reports use (extracted, not copied), frozen at finalize into per-transaction source rows, with explicit deductions/adjustments (no commission, no automatic cost deduction), a seller acknowledgement (drawn signature / typed / recorded-outside), a dedicated payment ledger with idempotent, over-payment-proof payouts, and two browser-generated printable documents. The balance is enforced three ways: cent-exact per-row rounding, an independently computed reconciliation that must be `0.00` to finalize, and DB `CHECK` constraints.
3. **Store branding (P3, small, mostly frontend).** One composable sets the tab title (`<store name> · <app name>`) and favicon (store logo, square-fitted); the sidebar brand block shows logo + store name; `GET /settings/features` gains `store_logo_url`; Settings write-through updates it live.

One spec amendment came out of planning: settlement scope is **one event per settlement** (not "event or period") because overlapping periods would allow double settlement. See research.md C1.

## Technical Context

**Language/Version**: PHP ≥ 8.3 (composer floor; container pins 8.3) / Laravel; Vue 3 SPA (Vite, Pinia, vue-router, vue-i18n); Tailwind v4 CSS-first tokens.

**Primary Dependencies**: Existing only — Laravel Sanctum bearer tokens, `maatwebsite/excel` (not used here), `html2canvas` + `jsPDF` (already dynamically imported through `utils/pdfCapture.js`), Phosphor icons. **No new package** (signature pad is ~60 lines on a `<canvas>` with pointer events; `BroadcastChannel` is a browser built-in). Any package/version beyond these: unverified, none proposed.

**Storage**: MySQL 8 (required; raw `CHECK` constraints). Five new tables (data-model.md). Private `local` disk for signature images and payout proofs (`settlement-signatures/`, `settlement-proofs/`), served only through authorizing endpoints.

**Testing**: PHPUnit `tests/Feature/` against real MySQL `boothpos_test` (inside the `app` container only with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`); Vitest `qa-tests/` (APIs mocked); real-browser verification per quickstart.md (constitution II).

**Target Platform**: Laptop at an event venue, Laravel + SPA on `localhost` (native or Docker store image); modern evergreen browsers. `BroadcastChannel` absent → graceful fallback to today's behaviour.

**Project Type**: Web application (Laravel API + Vue SPA, single repo, same origin).

**Performance Goals**: Statement build = one grouped query for POS + one for pre-orders per (event, seller) — no per-row queries (N+1 forbidden, constitution V); expected ≤ a few hundred source rows per statement. Detail view adds one extra aggregate for the `source_changed` cue. Signature/document code is loaded on demand (dynamic import), not in the initial bundle; verified from the production build output.

**Constraints**: Server computes every amount; client never sends revenue. Single-machine, no queue worker. Existing reports must not change a digit. `docs/openapi-pos-mvp.yaml` updated in the same commit as the routes. UI copy, comments and commit messages in Indonesian; locale keys in both `en.json` and `id.json` (guarded by `localeKeys.test.js`); `LoginView` stays Indonesian-only with no `t()`.

**Scale/Scope**: 1 menu key, ~16 API endpoints, 5 tables, 2 services + 2 support classes, ~6 screens/dialogs + 2 documents on the SPA; small edits to auth store, router, login view, sidebar, settings store/view, `App.vue`, `ReportController`, `SettingsController`, `CloneRegistry`, two delete guards.

## Constitution Check

*GATE: passed before research; re-checked after design — still passes. One justified entry in Complexity Tracking.*

| Principle | Assessment | Evidence |
|---|---|---|
| **I. Code quality / single write paths** | PASS (1 justified deviation) | Logic in `app/Services/` (`SellerSettlementBuilder` read-only compute, `SellerSettlementService` the **only** writer of the five tables). Revenue formula **extracted** into `App\Support\PreorderRecognizedRevenue` and reused by `ReportController` — no second formula. Audit via `ActivityLogger` only. Payout writes are not routed through `PaymentRecorder` (see Complexity Tracking). No speculative config: no commission, no period, no e-signature provider. |
| **II. Testing** | PASS (planned) | Backend feature tests for every guarantee in contracts/settlements-api.md (balance, idempotency, over-payment, concurrency, voided/cancelled exclusion, parity with `artist-profit`, role denial, clone registry); Vitest for `tabSession`, `safeRedirect`, `useBranding`, signature pad, sidebar links, locale keys; real-browser walk-through in quickstart.md with console check. |
| **III. UX consistency** | PASS | Tokens from `resources/css/app.css` only (no hex). Errors use the 422/409/403 convention and the central handler; paginated list via `usePaginatedList`. Role-gated controls are **hidden** (sidebar item, no menu key); *state*-gated actions (e.g. Reopen with payments) stay visible but disabled with a reason, matching feature 035 — server `can.*` flags drive this. UI copy Indonesian + English locale files. |
| **IV. Security** | PASS | All amounts server-side; auth enforced server-side (FormRequest + inline menu check, all three mechanisms considered — no Policy needed, none exists for report-style modules); signature/proof files on the private disk behind an authorizing endpoint; settlement figures are immutable snapshots (rows), not re-derived; every mutation writes the audit row in the same transaction; token handoff same-origin only, never persisted outside `sessionStorage`; open-redirect guard on `redirect`; customer PII excluded from documents. |
| **V. Performance** | PASS | Grouped queries, eager loads, lean list payload (detail rows only on `GET /settlements/{id}`); signature pad and document components dynamically imported; the report's live recompute choice is deliberate and documented (`source_changed`). |
| **Stack constraints** | PASS | MySQL-only `CHECK`s, `DB_CONNECTION` untouched; no remote push; migration filenames date-prefixed after the latest (`2026_11_19_000001…`) so FK order holds. |
| **Docs discipline** | PASS (planned) | OpenAPI updated in the same commit; PRD gets a dated note (settlement module added post-MVP at the product owner's request; commission explicitly not taken; scope = one event); CLAUDE.md gains a "Seller settlement" section and the active-plan pointer. |

### Cross-cutting risks found while reading the code

- **Clone LIVE→DEMO silently skips unregistered tables** → register all five in `CloneRegistry` and extend `CloneRegistryDriftTest` (not its allow-lists).
- **`relationLoaded` trap** (CLAUDE.md): the detail payload must eager-load `sources`, `adjustments`, `acknowledgements`, `payments` and users; the presenter is a `JsonResource` family, and a test asserts no field silently vanishes.
- **Hand-rolled `DB::table()` queries bypass the data-mode scope** → the builder filters `data_mode` explicitly (`ModeGate::current()`), and the number sequence uses `withoutGlobalScope`.
- **Rounding drift vs reports** → handled by per-row rounding + stated tolerance (research C3), not hidden.
- **Recap stays POS-only (040)**: nothing here touches `SettlementService`/`artist_settlements`.

## Project Structure

### Documentation (this feature)

```text
specs/047-settlement-tab-session-branding/
├── plan.md                       # this file
├── research.md                   # Phase 0 — decisions A1–A2, B1–B3, C1–C9
├── data-model.md                 # Phase 1 — 5 tables, derived values, reconciliation, transitions
├── quickstart.md                 # Phase 1 — end-to-end verification incl. safe test commands
├── contracts/
│   ├── settlements-api.md        # REST contract (mirror into docs/openapi-pos-mvp.yaml)
│   └── session-and-branding.md   # BroadcastChannel protocol + features.store_logo_url + title/favicon rules
├── checklists/requirements.md
└── tasks.md                      # Phase 2 — created by /speckit-tasks (NOT by this command)
```

### Source Code (repository root) — Laravel API + Vue SPA, existing layout

```text
app/
├── Models/
│   ├── SellerSettlement.php                  # HasDataMode; derived accessors (direction, balance, payment_status…)
│   ├── SellerSettlementSource.php
│   ├── SellerSettlementAdjustment.php
│   ├── SellerSettlementAcknowledgement.php
│   └── SellerSettlementPayment.php
├── Services/
│   ├── SellerSettlementBuilder.php           # read-only: live sources + totals + memo (one grouped query per kind)
│   ├── SellerSettlementService.php           # ONLY writer: create/refresh/delete/adjust/finalize/reopen/ack/payments (+ ActivityLogger)
│   └── SettlementReconciliation.php          # independent cent-sum proof (value object)
├── Support/
│   └── PreorderRecognizedRevenue.php         # extracted query+fraction expr (ReportController delegates)
├── Http/
│   ├── Controllers/Api/SellerSettlementController.php   # thin: validate → delegate → shape; file streams; document payloads
│   ├── Requests/Settlement/*.php             # shared base (authorize = canAccessMenu('settlements')) + Store/Adjustment/Acknowledge/Payment
│   └── Resources/SellerSettlement*Resource.php
├── Http/Controllers/Api/{ReportController,SettingsController,EventController,ArtistController}.php   # small edits (see data-model "Touches")
└── Services/DemoClone/CloneRegistry.php      # +5 tables

database/migrations/
├── 2026_11_20_000001_create_seller_settlements_tables.php            # 5 tables + raw CHECKs
└── 2026_11_20_000002_add_settlements_menu_key_to_default_roles.php   # Owner, Admin

routes/api.php                                # /settlements… inside the existing authenticated group
app/Support/MenuKeys.php                      # + 'settlements'
docs/openapi-pos-mvp.yaml  docs/PRD-POS-Event-Multivendor.md   # contract + dated scope note

resources/js/
├── utils/{tabSession.js,safeRedirect.js,logoFavicon.js}
├── composables/useBranding.js
├── stores/{auth.js,settings.js}              # restore() handoff + logout broadcast; storeLogoUrl, setters
├── router/index.js                           # redirect query; routes: settlements, settlement-detail (menuKey 'settlements')
├── api/settlements.js
├── views/{SettlementsView.vue,SettlementDetailView.vue,LoginView.vue,SettingsView.vue}
├── components/settlement/                    # SignaturePad, AcknowledgeModal, AdjustmentModal, AddSettlementPaymentModal,
│                                             #   ReconciliationCard, SettlementSourcesTable, SettlementProofDocument, SettlementPaymentProofDocument
├── components/layout/AppSidebar.vue          # brand block + 'settlements' nav item
├── api/client.js                             # 401 redirect carries ?redirect=
├── locales/{en,id}.json                      # nav.settlements, settlements.*
└── App.vue                                   # mounts useBranding

tests/Feature/SellerSettlement/*Test.php  tests/Feature/CloneRegistryDriftTest.php (extended)
qa-tests/unit/{tabSession,safeRedirect,useBranding,signaturePad,sidebarLinks}.test.js  qa-tests/component/…
```

**Structure Decision**: Keep the existing single-repo web layout. Settlement gets its own services/models/controller instead of extending `SettlementService`/`ArtistSettlement` (a different concept with a frozen POS-only contract from feature 040). Session and branding changes live in small utilities/composables beside the code they modify.

## Phase 2 hand-off (for `/speckit-tasks`)

Suggested slicing — each slice independently mergeable and testable:

1. **Branding** (US4): `features.store_logo_url`, store actions, `useBranding`, sidebar block, Settings write-through. No migration.
2. **Session** (US1): `tabSession`, `auth.restore/logout`, `safeRedirect`, router + interceptor + login redirect, sidebar-link test.
3. **Settlement foundation**: migrations, models, `PreorderRecognizedRevenue` extraction + report-parity tests, builder, reconciliation, menu key + role migration, clone registry, delete guards.
4. **Settlement lifecycle API**: service + controller + requests + resources + OpenAPI; payments; acknowledgement; file streams; documents payloads; concurrency/idempotency tests.
5. **Settlement UI**: list, create dialog, detail, modals, signature pad, two documents, locale keys, real-browser pass.
6. **Docs**: CLAUDE.md section, PRD dated note, RUNBOOK migration reminder.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Payouts to sellers are written by `SellerSettlementService` into `seller_settlement_payments`, **not** through `PaymentRecorder` (constitution I names it the only creator of payment rows) | `payments` is the customer-receipts ledger: each row must tie to an order / pre-order / PO by `CHECK`, feeds shift cash, verification and reports. A payout is money leaving (or returning) in the opposite direction against a different aggregate. | Reusing `payments` needs a fourth nullable FK + CHECK rewrite and would leak payouts into shift cash, sales and verification screens — a correctness risk to already-tested code. The new table still has exactly **one** writer, so the single-write-path intent is kept. |
| Refactor `ReportController::preorderRecognizedRevenueBase()` into `App\Support\PreorderRecognizedRevenue` | The settlement must use the identical pre-order recognition rule; CLAUDE.md forbids a second formula (feature 033). | Copying the SQL would let reports and statements drift apart silently. Mitigation: behaviour-preserving delegate + existing report tests + parity test. |
