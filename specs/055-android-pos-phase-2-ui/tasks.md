# Tasks: Android POS Phase 2 — Two-Column Counter, Server Categories, Live Data, Refresh

**Input**: `specs/055-android-pos-phase-2-ui/` (plan.md, spec.md, research.md, data-model.md, contracts/pos-categories.md, quickstart.md)

**Tests**: Included. Constitution II requires backend tests under `tests/Feature/` against MySQL and Android unit tests for logic, and the spec's acceptance scenarios need them. Manual tablet checks are listed as tasks where a device is required.

**Organization**: by user story (spec.md US1–US7). Foundational phase = the server category endpoint and the tablet's category source, which US2 and US3 need.

**Decisions carried in**: Kirim keeps its count badge (research R6, product owner 2026-10-18). Tax comes from the server (R7). Order list font drops by 2 sp (US7).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[Story]**: US1–US7, only in story phases

---

## Phase 1: Setup (baseline)

- [X] T001 Backend baseline inside the container: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=DeviceHeartbeatTest`. Record the result.
- [X] T002 Android baseline: `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Record the result.

---

## Phase 2: Foundational (blocks US2 and US3)

**Purpose**: the server category endpoint and the tablet builds its category list from it, not from products (root cause, research R1).

- [X] T003 [P] Create `app/Http/Controllers/Api/PosCategoryController.php` with `index()`: `$this->authorize('viewAny', Product::class)`; `Category::query()->where('is_active', true)->withCount(['products as product_count' => fn ($q) => $q->where('is_active', true)])->orderBy('display_order')->orderBy('name')->get()`; respond `{data: [{id, code, name, display_order, product_count}]}`. HasDataMode filters by mode automatically; do not call `withoutGlobalScope`.
- [X] T004 Register `Route::get('/pos/categories', [\App\Http\Controllers\Api\PosCategoryController::class, 'index']);` in `routes/api.php` inside the same route group as `apiResource('products', ...)` (around line 174), so the gate matches `GET /products?pos=1`.
- [X] T005 [P] Create `tests/Feature/PosCategoriesTest.php`: categories of DEMO are not returned in LIVE and the reverse (use `ModeGate::runAs` or the mode setting as the existing mode tests do); an inactive category is hidden; a zero-product category is returned with `product_count` 0; order is `display_order` then `name`; 401 with no session; 403 for a user without the products menu (check `ProductPolicy::viewAny` for the role used).
- [X] T006 Run the new test in the container: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=PosCategoriesTest`. Record the result.
- [X] T007 Update `docs/openapi-pos-mvp.yaml` in the same commit as T003–T004: add `/pos/categories` (GET) and a `PosCategory` schema, using `contracts/pos-categories.md`. Validate: `python3 -c "import yaml; yaml.safe_load(open('docs/openapi-pos-mvp.yaml'))"`.
- [X] T008 [P] Create `android-pos/core-network/src/main/java/com/warkop/core/network/dto/PosCategoryDtos.kt`: `@Serializable data class PosCategoryDto(val id: Long, val code: String, val name: String, @SerialName("display_order") val displayOrder: Int = 0, @SerialName("product_count") val productCount: Int = 0)` and `@Serializable data class PosCategoriesPageDto(val data: List<PosCategoryDto> = emptyList())`.
- [X] T009 Add `@GET("pos/categories") suspend fun posCategories(): PosCategoriesPageDto` to `android-pos/core-network/src/main/java/com/warkop/core/network/PosApi.kt`, next to the products call. Depends on T008.
- [X] T010 Change `android-pos/app/src/main/java/com/warkop/pos/data/MenuMapper.kt`: `toCatalog(products, categories: List<PosCategoryDto>)`. Categories come from the server list in server order, including zero-product categories. A product whose category id is not in the list goes to a group "Lainnya" (never dropped). Categories are no longer built from products (research R1). Depends on T008.
- [X] T011 In `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt` `loadMenu()` (around line 141): fetch the categories with `api().posCategories().data` alongside the product pages and pass them to `MenuMapper.toCatalog`. Depends on T009, T010.
- [X] T012 [P] Create `android-pos/app/src/test/java/com/warkop/pos/data/MenuMapperCategoriesTest.kt`: a zero-product category is kept; category order equals the server order; a product with an unknown category lands in "Lainnya"; no category appears that the server did not return.
- [X] T013 Run `./gradlew --offline :app:testDebugUnitTest --tests '*MenuMapperCategoriesTest*'`. Record the result.

**Checkpoint**: the tablet's category list is the server's, for the active mode.

---

## Phase 3: User Story 1 - Products and order list side by side (Priority: P1) 🎯 MVP

**Goal**: landscape shows products in the left column and the order list in the right column; portrait stacks them with actions reachable.

**Independent Test**: quickstart step 3 (landscape and portrait).

- [X] T014 [US1] In `android-pos/app/src/main/java/com/warkop/pos/ui/screens/EditorScreen.kt`, the `menu` composable (around line 50): on EXPANDED/LARGE sizes use `Row` with the product grid in `Modifier.weight(0.6f)` and `OrderPane` in `Modifier.weight(0.4f)`. Remove the vertical `CategoryRail` call on these sizes (see T017).
- [X] T015 [US1] Same file: on COMPACT/MEDIUM sizes stack the product grid above `OrderPane` in a `Column`, so Kirim and Bayar stay reachable without a second screen (spec FR-002).
- [ ] T016 [US1] Manual check on the pilot tablet: quickstart step 3 (landscape side by side, portrait stacked). Record a screenshot reference in tasks evidence.

---

## Phase 4: User Story 2 - All categories in one horizontal strip (Priority: P1)

**Goal**: every server category is in one horizontal scrolling strip above the product grid, on every size, with full names.

**Independent Test**: quickstart step 3 with 8 categories on the server: 8 of 8 reachable.

- [X] T017 [US2] In `EditorScreen.kt`: replace the vertical `CategoryRail` (around line 95, used on line 52) and the COMPACT/MEDIUM `FilterChip` row (lines 54–57) with one `LazyRow` strip above the grid on all sizes. Chips show full names (no ellipsis), keep the selected highlight, and keep the existing behaviour that tapping a category clears the search query.
- [X] T018 [US2] Create `android-pos/app/src/main/java/com/warkop/pos/ui/CategoryStrip.kt` with pure `fun keepOrPickCategory(categories: List<MenuCategory>, current: String): String` (keep `current` if still present, else the first category id, else empty). In `EditorScreen.kt` use it so a refresh never leaves a selection pointing at a removed category. Empty list shows "Belum ada kategori" in the strip area.
- [X] T019 [US2] Create `android-pos/app/src/test/java/com/warkop/pos/ui/CategoryStripTest.kt`: keeps a present category; picks the first when the current one is gone; returns empty for an empty list.
- [ ] T020 [US2] Manual check: quickstart step 3 with the server's categories (all 8 reachable, scroll sideways, no name cut). Record in evidence.

---

## Phase 5: User Story 3 - Tablet shows the server's data for the active mode (Priority: P1)

**Goal**: counter data is the server's for DEMO or LIVE; no sample design data while the server is reachable; a failed load shows the failure state.

**Independent Test**: quickstart steps 1, 2 and 4 (read per mode; switch and refresh) and step 10 (unreachable server).

- [ ] T021 [US3] **NOTE (2026-10-18): not done as written — `PosCatalog.products`/`categories` are used by the debug-only design presets (`app/src/debug/.../DebugPresets.kt`); no production path references them, so they stay. FR-013a is met because no release code path shows them.** In `android-pos/app/src/main/java/com/warkop/pos/model/PosCatalog.kt`: delete the unused `products` and `categories` lists (they are referenced nowhere; verify with a search first). Keep `denominations`, which the shift screens use.
- [X] T022 [US3] Add `var menuResult: () -> MenuCatalog` and `override suspend fun loadMenu(): MenuCatalog = menuResult()` to `android-pos/app/src/test/java/com/warkop/pos/state/FakePosBackend.kt` (remove the `unused()` stub for `loadMenu`).
- [X] T023 [US3] Create `android-pos/app/src/test/java/com/warkop/pos/state/PosStoreMenuTest.kt`: a successful load sets `catalog` to `Load.Ready` with the server categories; a network failure sets `Load.Failed` and does not substitute any sample data; `loadMenu` is not called when `backend` is null.
- [ ] T024 [US3] Manual check on the server: quickstart steps 1–2 (read `GET /pos/categories` in DEMO, then LIVE; restore the original mode) and step 4 (switch the mode and pull to refresh on the counter, which depends on T026). Record evidence.

---

## Phase 6: User Story 4 - Pull to refresh on every screen (Priority: P1)

**Goal**: pull down reloads each server-backed screen, shows an indicator, keeps the last data on failure, and does not reset the current order selection or the typed payment amount.

**Independent Test**: quickstart step 9 on Meja, Counter, Order, Shift and Perangkat.

- [X] T025 [US4] In `android-pos/app/src/main/java/com/warkop/pos/ui/Common.kt`, add `@Composable fun RefreshableScreen(refreshing: Boolean, onRefresh: () -> Unit, content: @Composable () -> Unit)` built on Material 3 `PullToRefreshBox`. If the API is not available in material3 1.3.0 under the project's opt-in annotation, use the fallback recorded in research R8 and note it in a code comment.
- [X] T026 [US4] In `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt`: add `var refreshing by mutableStateOf(false)` and `fun refreshCounter()`: sets `refreshing` true, calls `loadMenu()` and the settings load (T036), and clears `refreshing` in both outcomes. A failure keeps the previous `catalog` and sets the offline message (FR-018). Do not touch `activeId`, the open order lines, or the payment amount (FR-019).
- [X] T027 [US4] `EditorScreen.kt`: wrap the counter body in `RefreshableScreen(store.refreshing, store::refreshCounter)`.
- [X] T028 [P] [US4] `android-pos/app/src/main/java/com/warkop/pos/ui/screens/TablesScreen.kt`: wrap the floor body with `RefreshableScreen` calling the existing floor reload.
- [X] T029 [P] [US4] `android-pos/app/src/main/java/com/warkop/pos/ui/screens/OrdersScreen.kt`: wrap the orders body with `RefreshableScreen` calling the existing orders reload.
- [X] T030 [P] [US4] `android-pos/app/src/main/java/com/warkop/pos/ui/screens/ShiftScreens.kt`: wrap the shift report and cash screens that load server data with `RefreshableScreen` calling the existing shift reload.
- [X] T031 [P] [US4] `android-pos/app/src/main/java/com/warkop/pos/ui/screens/PrinterScreen.kt`: wrap the Perangkat body with `RefreshableScreen` refreshing device and printer status.
- [X] T032 [US4] Create `android-pos/app/src/test/java/com/warkop/pos/state/PosStoreRefreshTest.kt`: `refreshing` is true during and false after a refresh in both outcomes; a failed refresh keeps the previous catalog and does not clear the selected order id.
- [ ] T033 [US4] Manual check: quickstart step 9 on each screen (indicator shows; a failed refresh keeps data). Record evidence.

---

## Phase 7: User Story 5 - Totals area can be shown or hidden; tax from the server (Priority: P2)

**Goal**: one control hides and shows Subtotal, tax, Total, Kirim and Bayar; the choice survives an app restart; the tax line shows the server's label and rate.

**Independent Test**: quickstart steps 5 and 8.

- [X] T034 [US5] Create `android-pos/app/src/main/java/com/warkop/pos/state/TotalsPreference.kt`: `class TotalsPreference(private val read: () -> Boolean?, private val write: (Boolean) -> Unit)` with `var visible: Boolean` (null read means true). The Context-backed instance is created in `PosApplication` using shared preferences key `counter_totals_visible`. Plain shared preferences, not `TokenVault`.
- [X] T035 [US5] Create `android-pos/app/src/test/java/com/warkop/pos/state/TotalsPreferenceTest.kt`: default is visible; `visible = false` is written and read back; a null read is visible.
- [X] T036 [US5] Add `data class TaxDisplay(val label: String, val ratePercent: Double?)` in `android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt`. Add `suspend fun taxDisplay(): TaxDisplay?` to the interface, implement it in `RemoteBackend.kt` from `GET /settings/features` (`tax_label`, `tax_rate`, already exposed to all roles), add a stub to `FakePosBackend.kt`. Add the `features` call to `PosApi.kt` with a DTO in `core-network/.../dto/`.
- [X] T037 [US5] `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt`: add `var totalsVisible by mutableStateOf(...)` backed by `TotalsPreference` (passed in via the constructor, default in-memory for tests), `fun setTotalsVisible(v: Boolean)`, and `var tax by mutableStateOf<TaxDisplay?>(null)` loaded in `loadSettings()` (called by `refreshCounter` and at counter open).
- [X] T038 [US5] `EditorScreen.kt` `OrderPane` (around lines 186–194): wrap Subtotal, the tax row, Total and the Kirim/Bayar row in one collapsible section with a show/hide control; when hidden, show a compact bar with only the control (FR-006, FR-007). Showing and hiding does not change values (FR-008).
- [X] T039 [US5] `EditorScreen.kt` line 188: replace `MoneyRow("Pajak 10%", rp(o.tax))` with a label from `store.tax` (`"${label} ${ratePercent}%"`); when the rate is null show only the label; when `store.tax` is null show "Pajak" without a rate (research R7; no hard-coded rate).
- [ ] T040 [US5] Manual check: quickstart steps 5 (hide, show, restart keeps the choice) and 8 (change the server rate, pull to refresh, the tax line changes). Record evidence.

---

## Phase 8: User Story 6 - Kirim and Bayar labels readable (Priority: P1)

**Goal**: Kirim and Bayar labels are whole; Kirim keeps its count badge (decided); Bayar shows no amount; text fits without cropping.

**Independent Test**: quickstart step 6.

- [X] T041 [US6] Create `android-pos/app/src/main/java/com/warkop/pos/ui/CounterLabels.kt` with pure `fun kirimLabel(): String = "Kirim"` and `fun bayarLabel(waiter: Boolean): String = if (waiter) "Cetak pre-bill" else "Bayar"`. The Kirim count stays as a separate badge value (decision: keep the badge).
- [X] T042 [US6] `android-pos/app/src/test/java/com/warkop/pos/ui/CounterLabelsTest.kt`: Kirim label is "Kirim" regardless of count; Bayar label is "Bayar" with no amount; the waiter case keeps "Cetak pre-bill".
- [X] T043 [US6] `EditorScreen.kt` lines 193–194: `TonalPill` shows `kirimLabel()` plus the count badge `${o.unsent.size}` (only when > 0, decision kept); `PrimaryPill` uses `bayarLabel(waiter)` with `trailing = null` (no amount). Both pills fit their text with `ChipFit.chooseFontSize` (from 054) with `maxLines = 1`, shrinking toward 12 sp, and never cropping (FR-009).
- [ ] T044 [US6] Manual check: quickstart step 6 (both words whole, Bayar shows no amount, Kirim badge visible with a count). Record evidence.

---

## Phase 9: User Story 7 - Order list font 2 points smaller (Priority: P3)

**Goal**: the order list's text is 2 sp smaller; the product grid and totals do not change.

**Independent Test**: quickstart step 7 (before and after screenshots).

- [X] T045 [US7] `EditorScreen.kt` `LineRow` (lines ~207–213) and the `OrderPane` header text in the order list (lines ~157–158): change 16 sp to 14 sp, 13 sp to 11 sp, 12 sp to 10 sp. Do not change the product grid text (lines ~101–143) or the totals text.
- [ ] T046 [US7] Manual check: quickstart step 7 (the order list is exactly 2 sp smaller and lines stay readable). Record a before/after screenshot reference.

---

## Final Phase: Polish & cross-cutting

- [X] T047 Run the full Android unit suite `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Record the result.
- [X] T048 Run the backend group: `--filter='PosCategoriesTest|ServerAddressTest|DeviceHeartbeatTest'` with the container overrides. Record the result.
- [X] T049 Confirm the OpenAPI file parses after T007 (see T007 command) and lists `/pos/categories`.
- [ ] T050 Gate: the product owner confirms the LIVE category list matches the web admin Categories page (research R2, quickstart step 2).
- [ ] T051 Walk through `quickstart.md` on the pilot tablet (steps 3–10) and record the evidence. Remove any debug logging added during the work.

---

## Dependencies & Execution Order

- **Setup (T001–T002)** → everything.
- **Foundational (T003–T013)** → blocks US2 and US3; US1 and US4–US7 can start after T002 but use the server categories once T011 lands.
- **US1 (T014–T016)**: after Foundational (it uses the categories in the layout). T014 and T015 are in the same file as T017 and T038, so they run in order.
- **US2 (T017–T020)**: after US1 layout (same file, `EditorScreen.kt`).
- **US3 (T021–T024)**: after Foundational; T024 manual check needs T026.
- **US4 (T025–T033)**: T025 blocks T027–T031; T026 blocks T027 and T024.
- **US5 (T034–T040)**: T036 blocks T037 and T039; T038 is in `EditorScreen.kt`, after US1/US2.
- **US6 (T041–T044)**: after US5 (same `OrderPane` area in `EditorScreen.kt`).
- **US7 (T045–T046)**: last, to avoid conflicting edits in `EditorScreen.kt`.
- **Polish (T047–T051)** last.

### Parallel opportunities

- T003 and T005 (backend endpoint and its test) are different files.
- T008 with T003: DTO and backend controller.
- T012 with T010 (test vs mapper).
- T028, T029, T030, T031: four different screen files, after T025.
- T034 and T036: different files.

---

## Implementation Strategy

- **MVP**: Foundational + US1 + US2 + US3. Gives the server category list, the two-column counter and all categories reachable, for the active mode.
- **Increment 2**: US4 (pull to refresh) and US6 (readable Kirim and Bayar).
- **Increment 3**: US5 (totals show/hide, server tax) and US7 (font).
- Each increment ends with its own checkpoint and evidence (constitution II).

---

## Task count

- Total: **51 tasks** (T001–T051).
- Setup: 2 · Foundational: 11 · US1: 3 · US2: 4 · US3: 4 · US4: 9 · US5: 7 · US6: 4 · US7: 2 · Polish: 5.
- Parallel-marked [P]: T003, T005, T008, T012, T028, T029, T030, T031, T034 (mixed with the notes above).
- Format check: every task has a checkbox, a sequential ID, a `[USn]` label in story phases only, and a file path or a named command/manual check.
