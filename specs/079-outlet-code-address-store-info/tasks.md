# Tasks: Outlet Identity and Web Terminal Management

**Classification**: INTERNAL

**Input**: `specs/079-outlet-code-address-store-info/` — plan.md, spec.md, research.md (D1–D6, R-A…R-D), data-model.md, contracts/api-changes.md, quickstart.md

**Tests**: included. Constitution II requires backend tests under `tests/Feature/`, frontend tests under `qa-tests/`, and a browser check of every changed screen.

**Test command (container, mandatory overrides)**: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=<Name>`. Never run it without the overrides, and never against `boothpos-*`.

**Conventions**: code comments, UI copy and commit messages in Indonesian; no raw hex; OpenAPI (`docs/openapi-pos-mvp.yaml`) moves in the same commit as each route/response change.

Format: `- [ ] T### [P?] [US?] description with path`. `[P]` = different files, no dependency on an unfinished task.

---

## Phase 1: Setup

- [X] T001 Confirm the base contains 077 and 078 (`git merge-base --is-ancestor` for `0e2737c1` and `2acf16c0`/`97ea4046`); if not, stop and report. Record the result in `specs/079-outlet-code-address-store-info/evidence.md`
- [X] T002 Record a baseline in `specs/079-outlet-code-address-store-info/baseline.md`: full backend suite result (expected pre-existing failure: `ShiftReportParityTest`), `npm test` count, Android `./gradlew test` count
- [X] T003 [P] List every column that holds a SKU or code prefix: query `information_schema.COLUMNS` on `warkop_wjm_test` for names like `%sku%` / `code_prefix` and their widths; append the table to `research.md` under R1 (closes risk R-B)
- [X] T004 [P] Inspect the unique index on `outlets.code` (`SHOW INDEX FROM outlets`) and how soft delete interacts with it; record the finding and the chosen fix under R2/R-A in `research.md`

---

## Phase 2: Foundational (blocks US1, US3, US4)

- [X] T005 Migration `database/migrations/2026_12_28_000001_widen_outlet_code_and_add_outlet_address.php` (pick the next free date prefix after the latest existing one; never rename old ones): `outlets.code` → `VARCHAR(5)` (keep case-sensitivity/collation as it is today), add `outlets.address TEXT NULL`, widen `products.code_prefix` → CHAR(12), `product_variants.sku` / `order_items.sku_snapshot` / `preorder_items.sku_snapshot` → CHAR(16) and any extra column found in T003, via raw `ALTER TABLE` like `2026_10_25_000001`; include a working `down()`; apply T004's fix for reusing a deleted outlet's code
- [X] T006 [P] Add `app/Rules/OutletCodeRule.php` (or `app/Support/` if the project has no `Rules`): 3–5 chars, `^[A-Za-z0-9]+$`, unique among non-deleted outlets ignoring case, constructor takes the outlet id to ignore; messages from `lang/{id,en}/master_data.php` (new keys `outlet_code_rule`, `outlet_code_taken`)
- [X] T007 [P] Add `address` to `Outlet::$fillable` in `app/Models/Outlet.php` and to `app/Http/Resources/OutletResource.php`
- [X] T008 Add `app/Support/StoreIdentity.php` with `forReceipt(Outlet): array` and `forScreen(?Outlet): array` exactly per research D4 / data-model (keys `name, address, phone, email, notes`; DEMO uses `store_name_demo`; shared legacy `store_contact` maps to phone); reuse `StoreBranding::storeName()` for the shared name rather than re-reading the setting
- [X] T009 [P] Unit-level test `tests/Feature/StoreIdentityTest.php`: receipt block with full details, partial details (empty omitted, no shared leak), all-empty (whole shared block incl. name), screen block (single outlet, empty field falls back per field, `null` outlet = shared), DEMO name key
- [X] T010 Run T009 plus `OutletScopeRegistryTest` in the container; both must pass before moving on

**Checkpoint**: columns exist, rule and identity classes tested.

---

## Phase 3: User Story 1 — Outlet address and complete outlet records (P1)

**Goal**: owner/admin can store and see an address with the other four fields.
**Independent test**: create an outlet with address, reopen, edit, values persist; cashier refused.

- [X] T011 [P] [US1] Feature test `tests/Feature/OutletAddressTest.php`: create with address → 201 and `address` in the response; update address; address >500 chars → 422 naming the limit; null address allowed; cashier create/update → 403; `GET /outlets` and `/outlets/{id}` include `address`
- [X] T012 [US1] Add `'address' => ['nullable','string','max:500']` to `app/Http/Requests/StoreOutletRequest.php` and `app/Http/Requests/UpdateOutletRequest.php`; make sure `OutletController` passes it through (no extra logic)
- [X] T013 [P] [US1] Append `address` at the END of the outlets sheet column list in `app/Support/MasterDataSheets.php` (export, template, import; blank cell = leave unchanged); extend `tests/Feature/OutletExcelSheetsTest.php` for: export has the column, old file without it still imports, value round-trips
- [X] T014 [P] [US1] Frontend: add the Address field (textarea, hint) to the create/edit form in `resources/js/views/OutletsView.vue`, include `address` in the form state, payload and edit prefill, show a shortened address under the name or in a column of the list, add copy keys to `resources/js/locales/{id,en}.json`
- [X] T015 [P] [US1] Vitest `qa-tests/` for the outlet form: address sent on create/edit, shown in list, 422 on `address` shown under the field (follow the existing `OutletsView` test file naming)
- [X] T016 [US1] Update `docs/openapi-pos-mvp.yaml`: `Outlet` schema + `POST/PUT /outlets` request body (`address`), same commit as T012

**Checkpoint**: US1 done and testable alone.

---

## Phase 4: User Story 2 — Receipts show the outlet's own store information (P1) 🎯 MVP

**Goal**: receipts (web and tablet) show the outlet of the sale.
**Independent test**: two outlets, two sales, two receipts, each only its own details; reprint after editing shows the new address.

- [X] T017 [P] [US2] Feature test `tests/Feature/ReceiptStoreIdentityTest.php`: sale in outlet A with B selected via `X-Outlet-Id` → A's details; two outlets never mix; empty phone → `null`; outlet with no details → shared block as before (SC-007); reprint after editing outlet address → new address; DEMO mode name; customer phone/email still present only as customer fields and never as store contact; `store_notes` present; footer/logo/`app_name`/`store_contact_person` still shared
- [X] T018 [US2] In `app/Http/Controllers/Api/OrderController.php::receipt`: widen the eager load to `outlet:id,code,name,address,contact_phone,contact_email,notes`, build the store block from `StoreIdentity::forReceipt($order->outlet)` and map keys per `contracts/api-changes.md` (`store_name`, `store_address`, `store_contact_phone`, `store_contact`, `store_contact_email`, new `store_notes`); remove the direct `Setting::get('store_*')` reads this replaces; keep the existing comments that still apply and fix the ones that no longer do
- [X] T019 [US2] Check every other place that builds a store block for a customer-facing document (grep `store_address|store_contact` in `app/` and `resources/js/`) and route any found through `StoreIdentity`; list the findings in `evidence.md` (expected: none beyond receipt, since pre-order documents were removed in 046)
- [X] T020 [P] [US2] Frontend `resources/js/components/receipt/ReceiptModal.vue`: render address, phone, email and notes from the receipt payload, omit empty lines, wrap long notes, keep the shared logo/footer; Vitest in `qa-tests/` for omit-empty and notes rendering
- [X] T021 [P] [US2] Android DTO/mapper: add `store_notes` to `android-pos/core-network/src/main/java/com/warkop/core/network/dto/ReceiptDtos.kt`, map it in `android-pos/app/src/main/java/com/warkop/pos/data/ReceiptMapper.kt` and the receipt model in `model/PosModels.kt`; tests in `ReceiptMapperStoreTest.kt` (notes present, empty, absent in old payload)
- [X] T022 [US2] Android rendering: print the notes line (wrapped, omitted when empty) and keep address/phone/email in `android-pos/app/src/main/java/com/warkop/pos/print/Documents.kt` (and `PrinterService.kt` if it formats separately); extend `DocumentsTest.kt` / `StrukIdentityTest.kt`
- [X] T023 [US2] Update `docs/openapi-pos-mvp.yaml` receipt response (new `store_notes`, source of the others) in the same commit as T018
- [X] T024 [US2] Run T017, `OutletIsolation*` tests and the existing receipt tests in the container; fix regressions without loosening assertions

**Checkpoint**: MVP (US1 + US2) complete.

---

## Phase 5: User Story 3 — Outlet code 3–5 letters/digits, editable (P2)

**Goal**: new code rule, edit allowed, old product codes untouched.
**Independent test**: create `KD001`, edit `WJM`→`WJM2X`, products old/new codes, rule violations refused.

- [X] T025 [P] [US3] Feature test `tests/Feature/OutletCodeTest.php`: accepts 3, 4, 5 chars alnum and stores uppercase; refuses 2, 6, symbol, space, empty; duplicate ignoring case (`kd001` vs `KD001`) → 422; edit to valid free code → 200; edit to own current code → 200; edit to another outlet's code → 422; reuse of a soft-deleted outlet's code → accepted (T004 fix); code change is in the activity log inside the same transaction; cashier → 403
- [X] T026 [P] [US3] Feature test `tests/Feature/OutletCodeProductSkuTest.php`: product created before an edit keeps `code_prefix` and `sku`; product created after uses the new code; with a 5-char code the generated prefix is 12 chars and SKU 16 and persists in `products`, `product_variants`, and `order_items.sku_snapshot` after a sale; existing 3-letter outlets still generate 10/14
- [X] T027 [US3] Replace `size:3`/`alpha` with `OutletCodeRule` in `app/Http/Requests/StoreOutletRequest.php`; in `app/Http/Requests/UpdateOutletRequest.php` remove `'code' => ['prohibited']` and its message, use `OutletCodeRule($outlet->id)` with `sometimes`
- [X] T028 [US3] `app/Http/Controllers/Api/OutletController.php` (update path): when `code` changes, write the activity log with old → new inside the same transaction; remove the `master_data.outlet_code_permanent` usage and delete that key from `lang/{id,en}/master_data.php`
- [X] T029 [US3] `app/Services/ProductCodeGenerator.php`: update the class docblock and `formatPrefix`/related comments for 3–5-char outlet codes and the new widths; keep the rule "never regenerate"; add a short comment pointing at the widening migration; fix any hard-coded length (grep `10`/`14`/`substr` in this file and in `app/Support/MasterDataSheets.php`)
- [X] T030 [P] [US3] Import/export code behaviour test (extend `tests/Feature/OutletExcelSheetsTest.php` or `StockImportOutletTest.php`): after a code edit, export shows the new code; an import row carrying the old code is rejected with a message naming that code and nothing is written (all-or-nothing)
- [X] T031 [P] [US3] Update the existing tests that asserted the old behaviour: `tests/Feature/OutletTest.php`, `tests/Feature/OutletRenameCharacterizationTest.php` (and any other found by grepping `outlet_code_permanent`, `'code' => 'ABCD'`, `size:3`); keep their intent, change expectations to the new rule
- [X] T032 [P] [US3] Seeders/factories: confirm `database/seeders/*` and `database/factories/OutletFactory.php` generate codes valid under the rule (3–5 alnum, unique); fix if not
- [X] T033 [P] [US3] Frontend `resources/js/views/OutletsView.vue`: make the code input editable in edit mode, `maxlength=5`, uppercase as typed, label/hint describe "3–5 huruf atau angka" and no longer say permanent; update `master_data.code_3_letters` / `code_permanent_hint` copy keys in `resources/js/locales/{id,en}.json`; Vitest: editable on edit, rule messages shown from 422, uppercase
- [X] T034 [P] [US3] Frontend places that assume 3-char codes (grep `code` formatting/width in `resources/js` e.g. fixed-width badges, `padEnd`, `slice(0, 3)`); fix and note in `evidence.md`
- [X] T035 [US3] `docs/openapi-pos-mvp.yaml` (`code` pattern 3–5, now accepted on `PUT`), `docs/PRD-POS-Event-Multivendor.md` dated note on F19.4 (outlet code now editable; product code/SKU still permanent; widths 12/16), `docs/RUNBOOK.md` note on editing a code and spreadsheet imports
- [X] T036 [US3] Run T025, T026, T030, T031 plus `OutletMoveGuardTest`, `OutletScopeRegistryTest`, product creation tests in the container

**Checkpoint**: US3 done.

---

## Phase 6: User Story 4 — Screens show the selected outlet's identity (P2)

**Goal**: web header/sidebar and tablet follow the selected outlet.
**Independent test**: picker A → B → All changes the shown name; tablet shows its outlet name.

- [X] T037 [P] [US4] Feature test `tests/Feature/FeaturesStoreIdentityTest.php`: `GET /settings/features` with `X-Outlet-Id` A/B → `store.name` and `store_name` follow; no header with several outlets → shared; user with a single permitted outlet → that outlet; empty outlet field → shared value; two requests with different outlets never return each other's data (no cross-request caching); asking for a non-permitted outlet keeps the existing 403
- [X] T038 [US4] `app/Http/Controllers/Api/SettingsController.php::features`: add `store` from `StoreIdentity::forScreen(<narrowed single outlet or null>)` using `OutletContext` (do not read the header yourself); keep `store_name` equal to `store.name`
- [X] T039 [P] [US4] Feature test `tests/Feature/PosSetupOutletBrandingTest.php`: `GET /pos-setup/outlets/{outlet}/branding` returns outlet name + shared logo + mode; inactive/unknown outlet → 404; only those three keys; no token needed; throttle present; existing `GET /pos-setup/branding` unchanged
- [X] T040 [US4] Add the route in `routes/api.php` next to the other `pos-setup` routes and `branding`-style action in `app/Http/Controllers/Api/PosSetupController.php` via `StoreIdentity`/`StoreBranding` (no new read of settings); record it as a continuation of the 077 Constitution IV exception in `plan.md` Constitution Check (already noted as R6, keep wording consistent)
- [X] T041 [P] [US4] Frontend `resources/js/stores/settings.js` + `resources/js/composables/useBranding.js`: read `store.name` (fallback `store_name`), refetch features when the selected outlet changes (watch the outlet store used by `OutletSelector`/`AppShell`), update document title/favicon logic accordingly; touch `TopBar.vue`/`RailNav.vue`/`AppShell.vue` only where they read the name; Vitest: switching outlet changes the label, All outlets shows shared name
- [X] T042 [P] [US4] Android: `android-pos/core-network/.../PosApi.kt` + `dto/BrandingDtos.kt` add the path-keyed branding call; `data/BrandingMapper.kt` and `state/PosStore.kt` use it for the stored outlet on the PIN screen, fall back to the unkeyed call when no outlet is stored, and use `features` after login; tests in `PosStoreModeTest.kt` / a new `PosStoreOutletBrandingTest.kt`; keep the per-mode terminal key behaviour from 077 intact
- [X] T043 [US4] `docs/openapi-pos-mvp.yaml`: `features.store`, new `pos-setup` branding route; run T037, T039 and the existing branding tests (`PosSetup*`, 077 tests)

**Checkpoint**: US4 done.

---

## Phase 7: User Story 5 — Terminal management on the web (P2)

**Goal**: Terminals page with filters; create, rename, move, deactivate, reactivate, delete-if-unused.
**Independent test**: see quickstart step 4.

### Backend

- [X] T044 [P] [US5] Feature test `tests/Feature/DeviceManagementTest.php` (extend patterns of `DevicePairingTest`): list filters `outlet_id[]`, `status`, `q` combine; pagination envelope vs legacy shape without `page`; outlet outside permission → 403 `outlet_forbidden`; create with `outlet_id` (permitted/forbidden/inactive); duplicate name same outlet (case/space-insensitive) 422, same name other outlet ok; rename; move ok/`open_shift` 409/name taken in target 422/inactive target/revoked terminal; old orders/shifts keep their `outlet_id` after a move; deactivate then the device's heartbeat and `pin-sessions` get 422 `device_id`; reactivate ok / name taken / outlet inactive; permanent delete ok when unused, 409 `in_use` with an order, a shift or a cash movement; cashier/inventory → 403 on every route; admin assigned to outlet X cannot see or touch outlet Y's terminals (404); activity log entries for each action; DEMO vs LIVE separation
- [X] T045 [US5] `app/Services/OutletMoveGuard.php`: add `deviceBlocker(Device): ?string` returning `open_shift` (open `cashier_sessions` row with this `device_id`, read without global scopes like `tableBlocker`) and its message in `message()`; extend `tests/Feature/OutletMoveGuardTest.php`
- [X] T046 [US5] `app/Services/DeviceService.php`: add `pair()` support for an explicit permitted outlet id (keep header-derived default), `move()` (guard + name recheck in target under `lockOutlet`, active target, log `device_moved` old → new), `reactivate()` (name free, outlet active, log `device_reactivated`), `deleteIfUnused()` (check `orders`, `cashier_sessions`, `cash_movements` without global scopes; log `device_deleted`; throw 409 `in_use`); all writes and logs in one transaction; add `lang/{id,en}/devices.php` messages (`open_shift`, `in_use`, `outlet_inactive`, `not_revoked`)
- [X] T047 [US5] Requests: `app/Http/Requests/PairDeviceRequest.php` add optional `outlet_id` (exists + permitted resolved via `OutletContext`, never trusted raw); replace `RenameDeviceRequest` by `UpdateDeviceRequest` (`name` and/or `outlet_id`, at least one; owner/admin only) and update the route; add `DeviceIndexRequest` (or inline validation consistent with 070) for `outlet_id[]`, `status`, `q`, `page`, `per_page`
- [X] T048 [US5] `app/Http/Controllers/Api/DeviceController.php` + `routes/api.php`: filtered/paginated `index` (eager-load `outlet:id,code,name`, `OutletContext::narrowedOrNull`, no N+1), extended `pair`, `update` (PATCH), new `reactivate` and `destroyPermanent`; keep `destroy` = deactivate unchanged; extend `present()` with `outlet`, `paired_at`, `revoked_at`; register any new raw query/table in `OutletScopeRegistry` if `OutletScopeRegistryTest` demands it
- [X] T049 [US5] `docs/openapi-pos-mvp.yaml` for every `/devices` change in `contracts/api-changes.md`; run T044, `DevicePairingTest`, `DeviceHeartbeatTest`, `ServerAddressTest`, `OutletMoveGuardTest`, `OutletScopeRegistryTest` (existing deactivate tests must pass unchanged)

### Frontend

- [X] T050 [P] [US5] `resources/js/api/devices.js` (list with params, create, update, deactivate, reactivate, deletePermanent); list requested with `skipOutletHeader` and `outlet_id[]` per the 070/071 pattern
- [X] T051 [US5] `resources/js/views/TerminalsView.vue`: table (name, outlet, status badge, last seen), filters (outlet multi-select via the existing `useOutletPageFilter`, status, name search, filters in the URL query), `usePaginatedList`, empty state with "clear filters", add/edit modal (outlet select on create and move, name), row actions (rename/move, deactivate/reactivate, delete with confirm), 409/422 shown through the central error handler, move confirm copy explains the tablet must be set up again (R-D); tokens only, Indonesian copy
- [X] T052 [P] [US5] Router + navigation: add `terminals` route in `resources/js/router/index.js` beside `outlets` (reuse the Outlets menu key per research R6), entry in the nav component, hidden entirely for roles that are not owner/admin; copy keys in `resources/js/locales/{id,en}.json`
- [X] T053 [P] [US5] Vitest in `qa-tests/` for `TerminalsView` and `api/devices.js`: filters build the right query, pagination, create/rename/move/deactivate/reactivate/delete call the right routes, 409 `open_shift` and `in_use` messages shown, nav entry hidden for a cashier role fixture
- [X] T054 [US5] `docs/RUNBOOK.md` terminal section: web management, move behaviour (tablet re-setup), reactivate, permanent delete rules; `docs/PRD-POS-Event-Multivendor.md` dated note for web terminal management (no new F-number, nothing un-cut)

**Checkpoint**: US5 done.

---

## Phase 7b: User Story 5 addendum — Terminal bulk actions (P2)

**Requirements**: FR-025–FR-031, SC-010, SC-011, spec acceptance scenarios 12–16. Reuses the single-terminal rules; partial success, per-item results.

- [X] T062 [US5] Plan note in `plan.md`/`research.md`: choose the bulk endpoint shape (one `POST /devices/bulk` with `action` = activate|deactivate|delete and `ids[]`, or per-action routes), batch cap (= max page size), and the per-item result shape `{id, name, status: changed|skipped|refused, reason?}`; confirm the route is declared before `/devices/{id}` so it is not captured as an id
- [X] T063 [US5] Backend tests first in `tests/Feature/`: bulk deactivate/activate (skips already-in-state, tablets refused/restored), bulk delete (deletes unused, refuses with-history per item with reason, leaves others untouched), mixed batch is NOT all-or-nothing, outlet-assigned admin cannot touch another outlet's ids sent directly, cashier/inventory get 403, one activity-log entry per changed terminal, empty or oversized `ids` gives 422
- [X] T064 [US5] `DeviceService` bulk methods that loop the existing single-terminal paths (`deactivate`, `reactivate`, `deleteIfUnused`) inside one transaction per terminal; no second copy of the delete/in-use rule; request class validates `ids` and `action`, scope comes from `OutletContext` and never from the body; route + controller action in `routes/api.php` / `DeviceController`; strings in `lang/{id,en}/devices.php`
- [X] T065 [US5] `docs/openapi-pos-mvp.yaml` in the same change: bulk route, request, per-item response, 403/422
- [X] T066 [US5] Frontend: `api/devices.js` bulk call; `TerminalsView.vue` row checkboxes, select-all for the page, selected-count bar with Activate/Deactivate/Delete, confirm dialog with count for Deactivate and Delete, result summary listing refused terminals with reasons, failed rows stay selected, selection cleared on filter/search/page change; tokens only, Indonesian copy with `{id,en}.json` parity
- [X] T067 [P] [US5] Vitest in `qa-tests/` for the API call and the view: bar hidden with no selection, select-all covers the page only, selection cleared by filter change, confirm text shows the count, mixed result shows refused names and keeps them selected
- [X] T068 [US5] `docs/RUNBOOK.md` terminal section (bulk actions and their refusal reasons) and a dated note in `docs/PRD-POS-Event-Multivendor.md`; add the bulk step to `quickstart.md` and results to `evidence.md` (browser check not claimed until done)

- [X] T069 [US5] Outlets follow the system mode (FR-032–FR-034, SC-012): `tests/Feature/OutletModeIsolationTest.php` locks `/me/scope`, `/outlets` and `/devices` per mode and the refusal of an other-mode `X-Outlet-Id`; `SystemModeSection.vue` clears the selection and reloads the outlet store and store identity after a switch (`qa-tests/component/SystemModeSwitchRefresh.test.js`)
- [ ] T070 [US5] FR-034 (mode changed elsewhere): decide and implement the recovery (re-read `/settings/features` on window focus, or treat 403 `outlet_forbidden` on a remembered outlet as "drop selection and reload scope once"); Vitest for it; not claimed done until the browser check passes

---

## Phase 8: Polish and verification

- [ ] T055 Full backend suite in the container with the safe overrides; compare against `baseline.md`; only the pre-existing `ShiftReportParityTest` may remain failing
- [X] T056 `npm test` and `npm run build`; confirm `TerminalsView` is in its own chunk or no worse than baseline (Constitution V: inspect the build output)
- [X] T057 Android `./gradlew test` and debug build; compare to baseline count
- [ ] T058 Browser walk-through per `quickstart.md` steps 1–5 against `:8010`/`:5180`, console checked for errors; write results, screenshots paths and anything not verified into `evidence.md`
- [X] T059 Security pass against the plan's Constitution IV row: outlet never read from a body unchecked (create/move validate against permitted ids), no PII or secrets in logs/receipts, customer phone/email still excluded from receipts, token-less branding returns only the three keys
- [ ] T060 Device-only checks (tablet PIN screen name, 58 mm receipt wrap of address/notes, moved terminal returns to setup): mark as **not verified** in `evidence.md` unless a device is available; do not claim them done
- [X] T061 Update `CLAUDE.md` Domain rules with a dated note (identity via `StoreIdentity`, editable outlet code and widths, terminal move/permanent-delete rules); commit only when the user asks, one commit per story is suggested

---

## Dependencies and order

- Phase 1 → Phase 2 → stories. T005 (migration) blocks T011–T016, T017+, T025+; T008 (`StoreIdentity`) blocks US2 and US4; T006 blocks US3.
- **US1** (address) before **US2** (receipts need the column and form to be meaningful end to end), though the code paths are separate.
- **US3** is independent of US2/US4 after Phase 2 and the riskiest; do it after the MVP.
- **US4** depends on T008 and benefits from US1 (address in `store`); independent of US3.
- **US5** is independent of US1–US4 (no shared files except `routes/api.php`, `openapi`, locale files and `OutletMoveGuard`); can run in parallel with US3/US4 by a second contributor.
- Shared-file hotspots (serialize edits): `routes/api.php`, `docs/openapi-pos-mvp.yaml`, `resources/js/locales/{id,en}.json`, `resources/js/views/OutletsView.vue` (T014 then T033), `lang/{id,en}/*.php`.
- Within a story: test (written first, seen failing) → service/support → request/controller → frontend → docs.

## Parallel examples

- After T010: T011, T013, T017, T025, T026, T037, T044 (all new test files) can be written in parallel.
- US2: T020 (web), T021 (Android DTO) are independent of T018.
- US5: T050, T052, T053 can proceed while T046–T048 are in review.

## Implementation strategy

1. **MVP = Phases 1–4** (foundation, address, receipts): the headline request, shippable alone.
2. Then US3 (code rule, riskiest, touches widths and docs), then US4, then US5; or US5 in parallel.
3. Commit per story after its checkpoint tests pass; do not push without the developer's explicit say-so.
4. Anything that needs a device stays open in `evidence.md` rather than being reported as done.

## Summary

61 tasks: Setup 4, Foundational 6, US1 6, US2 8, US3 12, US4 7, US5 11, Polish 7.
