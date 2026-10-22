---

description: "Task list for 064-terminal-name-only"
---

# Tasks: Terminal Identified by Name Only

**Input**: `/specs/064-terminal-name-only/` — plan.md, spec.md, research.md (D1–D11), data-model.md, contracts/terminal-api.md, quickstart.md

**Tests**: INCLUDED — the constitution (Principle II) requires backend tests under `tests/Feature/`, Vitest under `qa-tests/`, and a real-browser check of any touched screen. Backend tests run on real MySQL; inside the container ONLY with `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=<Test>`. Never run the suite against `warkop_wjm`.

**Organization**: by user story (US1 identity P1, US2 order link P1, US3 other modules P2).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different files, no dependency on an unfinished task)
- Code comments, UI copy and commit messages are in Indonesian (CLAUDE.md convention); comments explain *why*.

## Phase 1: Setup

- [X] T001 Record the baseline: run `php artisan outlets:reconcile --before` (in the container, dev DB) and run the existing suites that this feature touches (`DevicePairingTest`, `ReceiptNumberingTest`, `TabOpenPosFieldsTest`, `TabPrebillTest`, `SalesTransactionsTest`, `SalesTransactionsExportTest`, `CashierSessionTest`, `FloorViewTest`) with the safe `-e` flags, so later failures are attributable. Save notes in `specs/064-terminal-name-only/` only if something already fails.

---

## Phase 2: Foundational (blocks every story)

**⚠️ CRITICAL**: the schema must exist before any story code or test.

- [X] T002 Create migration `database/migrations/2026_12_26_000001_terminal_name_identity.php` (data-model.md): (a) dedupe active device names per `(outlet_id, data_mode)` case-insensitively — oldest keeps its name, later ones get " 2", " 3"…, blank → "Terminal" + suffix; (b) make `devices.code` NULLABLE (keep the old unique index); (c) create `outlet_receipt_counters(outlet_id PK FK outlets, receipt_seq unsigned int default 0)`; (d) on `orders` drop UNIQUE `receipt_no` and add UNIQUE `(outlet_id, receipt_no)`. `down()` refills NULL codes with `T01…` per outlet+mode, drops the counter table, restores the old unique (refuse with a clear message if duplicate receipt numbers across outlets exist). Header comment cites research.md D1–D4 and the `terminal_counters` "kept, inert" decision.
- [X] T003 [P] Write `tests/Feature/TerminalNameMigrationTest.php`: dedupe suffixing (same outlet+mode, differing case, blank), other outlet same name untouched, `code` accepts NULL, `(outlet_id, receipt_no)` allows the same number in two outlets but rejects it twice in one, legacy `T01-000124` values untouched.
- [X] T004 [P] Add translations `name_required`, `name_taken` (with `:name`), `device_revoked_no_rename` to `lang/id/devices.php` and `lang/en/devices.php`; leave the old `code_taken` key alone only if still referenced, otherwise remove it.

**Checkpoint**: `php artisan migrate` succeeds on the test DB; `TerminalNameMigrationTest` green.

---

## Phase 3: User Story 1 — A terminal is known by its name only (Priority: P1) 🎯 MVP

**Goal**: pairing/rename use only a unique name; no `Tn` code is created, returned or shown anywhere in the Android app.

**Independent Test**: pair "Kasir Depan" via API and on the Android setup screen; PIN screen, shift screens, chips and a tab opened on another terminal show names, never `T0n`.

### Tests for US1

- [X] T005 [P] [US1] Rewrite `tests/Feature/DevicePairingTest.php`: pairing with only `name` → 201 and response has no `code`; a sent `code` is ignored (not stored); blank/whitespace name → 422 `name`; duplicate name (also `" kasir depan "`) in same outlet+mode → 422 `name`; same name in another outlet → 201; revoked terminal frees its name; outlet-scope tests keep their intent (replace `code` fixtures with names; `Device::forceCreate` calls use `code => null`).
- [X] T006 [P] [US1] Add rename tests to `tests/Feature/DevicePairingTest.php`: `PATCH /api/v1/devices/{id}` → 200 + audit row `device_renamed`; duplicate → 422; own current name (different case) → 200; other outlet's device → 404; revoked → 409; non-owner/admin → 403.
- [X] T007 [P] [US1] Update `tests/Feature/DeviceHeartbeatTest.php`: heartbeat response now includes `name`.
- [X] T008 [P] [US1] Update `tests/Feature/TabOpenPosFieldsTest.php` (`device.code` → `device.name`) and `tests/Feature/TabPrebillTest.php` (`device_code` → `device_name`) so the tab/floor payloads are asserted by name.

### Implementation for US1 — backend

- [X] T009 [US1] `app/Http/Requests/PairDeviceRequest.php`: only `name` (required, string, max 80; trimmed in `prepareForValidation`); remove the `code` rule. Create `app/Http/Requests/RenameDeviceRequest.php` (same name rule, `authorize()` = owner/admin like the controller).
- [X] T010 [US1] `app/Services/DeviceService.php`: `pair()` no longer calls `nextCode()` (delete it) and no longer inserts into `terminal_counters`; add the active-name uniqueness check (case-insensitive, same outlet + data mode, not revoked) inside the transaction after `lockForUpdate` on the outlet row, throwing `ValidationException` on `name`; add `rename(Device, string, User)` (revoked → 409, same uniqueness excluding itself, `ActivityLogger` row `device_renamed` inside the transaction); heartbeat unchanged except it returns `name` too.
- [X] T011 [US1] `app/Http/Controllers/Api/DeviceController.php`: `present()` returns `{id,name,outlet_id,app_version,last_seen_at,revoked}` (no `code`), `index()` orders by `name`, add `rename()` action (`abort_unless(isOwnerOrAdmin)`, RenameDeviceRequest, service call); add `Route::patch('/devices/{device}', …)` next to the other device routes in `routes/api.php`.
- [X] T012 [P] [US1] `app/Http/Resources/TabResource.php`: `device` => `{id, name}`; `app/Services/FloorQuery.php`: `device_code` → `device_name` (`$order->device?->name`; the floor `version` hash must stay clock-independent). Keep `device` eager-loaded where these are built (relationLoaded trap).
- [X] T013 [P] [US1] `app/Models/Device.php`: remove `code` from `$fillable` if present / cast notes; add a comment that `code` is legacy (research D1).

### Implementation for US1 — Android

- [X] T014 [P] [US1] `android-pos/core-network/src/main/java/com/warkop/core/network/dto/AuthDtos.kt`: `PairDeviceRequest(name)` without `code`; `DeviceDto` loses `code` (name stays) and the heartbeat response DTO gains `name`; `dto/TabDtos.kt`: `DeviceRefDto` → `{id, name}`, floor DTO `deviceCode` → `deviceName` (`@SerialName("device_name")`).
- [X] T015 [US1] `data/TokenVault.kt`: add `DEVICE_NAME` key (keep `DEVICE_CODE` constant only to clear it on logout/reset). `data/PosBackend.kt`: `TerminalConfig.deviceCode` → `deviceName`. `data/RemoteBackend.kt`: `pairTerminal` stores `device.name`; `savedConfig()` reads `DEVICE_NAME` (empty → shown as "Terminal"); `myDeviceCode` → `myDeviceName`; the heartbeat handler writes the returned `name` to the vault and updates the in-memory config so tablets paired before this release learn their name without re-pairing; the reset/forget-terminal path clears both keys.
- [X] T016 [US1] `state/PosStore.kt` (config refresh after heartbeat) and `data/OrderMapper.kt` (`otherDevice = t.device?.name?.takeIf { it != myDeviceName }`, floor `t.deviceName`): compare names for the "other terminal" rule — note in a comment that names are unique per outlet so name equality is safe within the config's outlet.
- [X] T017 [P] [US1] `ui/screens/PinScreen.kt` (lines ~141, ~150: `"Terminal ${…deviceName}"`, chips), `ui/screens/ShiftScreens.kt` (~168 "Outlet / terminal"), `ui/screens/TablesScreen.kt` (~102 `Perangkat <name>`): show the name; when the name is blank show "Terminal". `setup/TerminalSetupScreen.kt`: keep the single "Nama terminal" field; reject a blank name client-side with the 422 message from the server shown inline for duplicates.
- [X] T018 [P] [US1] Update Android fixtures/tests: `state/FakePosBackend.kt`, `debug/DebugPresets.kt` (`otherDevice = "Kasir Belakang"`), `state/PosStoreConnectionTest.kt`; add `data/OrderMapperTerminalTest.kt` covering own tab (no other label), other terminal's tab (name label), blank name fallback, and vault name refresh from heartbeat.

**Checkpoint**: `DevicePairingTest`, `DeviceHeartbeatTest`, `TabOpenPosFieldsTest`, `TabPrebillTest` green; `./gradlew test` for android-pos green; US1 independent test passes.

---

## Phase 4: User Story 2 — Every order shows which terminal took it (Priority: P1)

**Goal**: new receipts use a plain per-outlet number; the terminal's name appears on the receipt, Android order surfaces, web Sales page (+ filter, export) and shift summary.

**Independent Test**: take orders on two named terminals; each shows its terminal name on receipt, Android detail, Sales page and export; receipt numbers are `000001`, `000002`; an order at another outlet also starts at `000001`.

### Tests for US2

- [X] T019 [P] [US2] Rewrite `tests/Feature/ReceiptNumberingTest.php`: `receiptFor($outletId)` → `000001`, `000002` per outlet regardless of terminal; second outlet starts at `000001`; counter row is created lazily and locked (concurrent allocation test pattern already in the file); rollback of the caller's transaction returns the number; queue-number tests untouched.
- [X] T020 [P] [US2] Update `tests/Feature/TabOpenPosFieldsTest.php` (`receipt_no` → `000001`, receipt payload `terminal_name`), add to `tests/Feature/CashierSessionTest.php` that the session/summary payload carries `terminal_name`, and a legacy order (`receipt_no = 'T01-000124'`, `device_id` null) still renders with `terminal_name: null`.
- [X] T021 [P] [US2] Add to `tests/Feature/SalesTransactionsTest.php` and `SalesTransactionsExportTest.php`: rows carry `terminal_id`/`terminal_name`; export has a "Terminal" column equal to the screen; a query-count assertion proves `device` is eager-loaded (no N+1).
- [X] T022 [P] [US2] Vitest `qa-tests/unit/useSalesFilters.terminal.test.js` and a case in `qa-tests/component/SalesViewAdvanced.test.js`: Terminal column renders; terminal filter hidden with <2 distinct terminals, shown and effective with ≥2; URL param `terminal` round-trips and an unknown value is ignored; rows without a terminal show "—".
- [X] T023 [P] [US2] Android tests: update `data/ReceiptMapperTest.kt`, `ReceiptMapperStoreTest.kt`, `print/DocumentsTest.kt`, `print/PrintQueueTest.kt`, `print/StrukIdentityTest.kt` (`T01-000124` samples → `000124`); add to `DocumentsTest.kt` that the struk prints a "Terminal" line with the name and never overflows 58 mm for a 40-character name.

### Implementation for US2 — backend

- [X] T024 [US2] `app/Services/ReceiptNumbering.php`: `receiptFor(int $outletId): string` using `outlet_receipt_counters` (insertOrIgnore row, `lockForUpdate`, increment, `sprintf('%06d')`); docblock updated (no terminal part; unique per outlet; call inside the settling transaction). `app/Services/TabSettlementService.php::receiptFor()` (~296): keep "no device → null", allocate by `$order->outlet_id` (not the device); update the two call sites' comments. `terminal_counters` is no longer touched.
- [X] T025 [P] [US2] `app/Http/Controllers/Api/OrderController.php::receipt()` (~266): add `'terminal_name' => $order->device?->name` and make sure `device` is loaded in that method; fix the stale `T01-000124` comment. `app/Http/Resources/OrderResource.php`: add `terminal_id`/`terminal_name` only `whenLoaded('device')`; ensure every endpoint returning orders loads `device:id,name` (grep `OrderResource` callers: `OrderController`, `TabController`, dashboard orders).
- [X] T026 [P] [US2] `app/Services/SalesTransactionsService.php` (~42 `with([...])`): add `device:id,name`; rows get `terminal_id`, `terminal_name` (null when none); the export builder (same service) gets a "Terminal" column — one source for list and file.
- [X] T027 [P] [US2] `app/Http/Controllers/Api/CashierSessionController.php` (session payload and `summary()` / ShiftReportService output): add `terminal_name` from `session.device` (eager-load).

### Implementation for US2 — web

- [X] T028 [US2] `resources/js/composables/useSalesFilters.js`: add `terminal` filter (AND across filters, OR within; URL param `terminal`, invalid ignored, same pattern as `cashier`) and expose `terminalOptions` (distinct `terminal_id`/`terminal_name`). `resources/js/views/SalesView.vue`: Terminal column (after cashier), filter control rendered only when `terminalOptions.length >= 2`; copy in `resources/js/locales/id.json` and `en.json` (key checked by `localeKeys.test.js`).

### Implementation for US2 — Android

- [X] T029 [P] [US2] `android-pos/core-network/.../dto/` receipt DTO: add `terminalName` (`terminal_name`, nullable); order/tab DTOs already carry `device {id,name}` (T014). `data/ReceiptMapper.kt` and `model/PosModels.kt` (`Receipt.terminalName`, `Order.terminalName`); `data/OrderMapper.kt` maps `terminalName` from `t.device?.name` (unlike `otherDevice`, always set).
- [X] T030 [US2] `ui/screens/OrdersScreen.kt` (~57, ~61): replace the hard-coded `?: "T01"` fallback with `o.terminalName ?: store.config?.deviceName`; show the name pill; `ui/screens/TenderScreen.kt` (~151): replace the `"tanpa nomor terminal"` fallback text with "tanpa nomor"; `ui/Sheets.kt` (~692) receipt sheet shows a "Terminal" row; `print/Documents.kt` (~74): struk prints `PrintLine(text = "Terminal", right = r.terminalName)` when present (long names rely on the existing wrapping; no new truncation logic); reprints use the same document builder.

**Checkpoint**: `ReceiptNumberingTest`, `TabOpenPosFieldsTest`, `CashierSessionTest`, `SalesTransactions*Test`, Vitest sales tests, Android tests green; US2 independent test passes (includes a real-browser check of `/sales`, console clean — see T035).

---

## Phase 5: User Story 3 — Terminal name used across other modules (Priority: P2)

**Goal**: remaining references (activity log, server terminal list, any leftover payload) use the name.

**Independent Test**: register, rename, revoke a terminal; the activity log and `GET /devices` show names only.

- [X] T031 [P] [US3] Test in `tests/Feature/DevicePairingTest.php` (or new `DeviceActivityLogTest.php`): audit rows `device_paired`, `device_renamed` (old → new name), `device_revoked` contain the terminal name and no `T\d\d` code text; `GET /devices` rows are ordered by name and contain no `code`.
- [X] T032 [US3] `app/Services/DeviceService.php`: log messages use the name (`"Terminal {$device->name} didaftarkan/diganti namanya dari X menjadi Y/dicabut"`); sweep with `grep -rn "device->code\|->code" app` for any remaining terminal-code reads (FloorQuery, OrderController, ReprintService, overrides) and replace them.
- [X] T033 [P] [US3] Sweep for leftovers: `grep -rnE "T0[0-9]|deviceCode|device_code" app resources/js android-pos/app/src android-pos/core-network lang database/seeders database/factories` — remove or rewrite every hit that refers to a terminal code (seeders/factories included); the only allowed survivors are the migration (T002), the legacy `devices.code` column and tests that assert legacy values are untouched.

**Checkpoint**: sweep grep clean; US3 test green.

---

## Phase 6: Polish & Cross-Cutting

- [X] T034 [P] Update `docs/openapi-pos-mvp.yaml` in the same change (constitution): pairing body/response without `code`, `PATCH /devices/{id}`, device list rows, heartbeat `name`, tab `device {id,name}`, floor `device_name`, receipt `terminal_name`/`receipt_no` example, sales row `terminal_id/terminal_name`, session `terminal_name`.
- [ ] T035 Real-browser verification of `/sales` against the real dev API (Terminal column, filter appears only with ≥2 terminals, export matches screen, console free of errors) and Android emulator/tablet pass of quickstart step 5 (PIN, shift, chips, other-terminal tab, order list/detail, struk preview/print). Record evidence in `specs/064-terminal-name-only/` (e.g. `evidence.md`). If no device/emulator is available, say so explicitly instead of claiming it.
- [ ] T036 Upgrade rehearsal on a copy of dev data (quickstart step 1): `outlets:reconcile --before`, `migrate`, `--after --compare`; confirm an already-paired tablet logs in without re-pairing, old receipts unchanged, duplicate names suffixed. Never run migrations or tests against another checkout's (`boothpos-*`) containers/databases.
- [X] T037 Run the full backend suite (safe `-e` flags), `npm test`, `npm run build`, and `./gradlew test` in `android-pos`; fix regressions; run `qa-tests/unit/localeKeys.test.js` for the new i18n keys.
- [X] T038 Add the dated note to `CLAUDE.md` ("Terminal name only, feature 064, 2026-10-22": code legacy/nullable, name unique among active per outlet+mode, receipt `%06d` per outlet with `(outlet_id, receipt_no)` unique, `terminal_counters` inert, heartbeat returns `name`, Android+server ship together) and a short release note `specs/064-terminal-name-only/release-notes.md` including the owner-confirmation point from research.md D4.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002 blocks everything; T003/T004 parallel after T002) → US1 and US2 → US3 → Polish.
- **US1 before US2** where they share files: T014 (DTOs) and T015–T016 (vault/mapper) are prerequisites of T029–T030; T012 (TabResource/FloorQuery) precedes T025. Otherwise the stories are independently testable: US2's backend part (T024–T027) does not depend on US1's rename logic.
- US3 depends on T010 (service) and is otherwise independent.
- Within a story: tests first (they should fail), then backend, then web/Android.

## Parallel Examples

- After T002: T003, T004 together.
- US1 tests: T005, T006, T007, T008 together; then T012, T013, T014, T017, T018 together once T009–T011 are in.
- US2: T019–T023 together; T025, T026, T027, T029 together after T024.

## Implementation Strategy

- **MVP = Phase 2 + US1** (name-only identity, server and Android). Stop and demo: pair by name, no `Tn` anywhere.
- Then US2 (order link, receipt numbers, Sales column/filter) — deliver with US1 in one release because the server and the APK must ship together (contracts/terminal-api.md).
- US3 and Polish last. Do NOT push or open a PR without the developer's explicit authorization.
