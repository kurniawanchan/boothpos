# Tasks: Android POS Phase 4 — Payment, Order and Shift Fixes; Discount Stored on the Order

**Input**: `specs/057-android-pos-phase-4-fixes/` (plan.md, spec.md, research.md, data-model.md, contracts/order-discount.md, contracts/reprint-copy.md, quickstart.md)

**Tests**: Included. Constitution II requires backend feature tests (MySQL) and Android unit tests for the logic; the device checks are manual tasks.

**Organization**: by user story (spec.md US1–US8). The discount columns (US8) are the only backend change; the tablet stories are independent of it except where noted.

**Open items carried in**: R4 (closing timestamp field) and R5 (admin token after offline) are verified by the first tasks in Phase 2 and the device checks in US7.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (baselines)

- [X] T001 Backend baseline inside the container: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=DeviceHeartbeatTest`. Record the result.
- [X] T002 Android baseline: `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Record the result.

---

## Phase 2: Foundational (blocks US3, US5, US8)

**Purpose**: the server fields the tablet needs (closing time, discount fields, reprint count) and the verification of the two open items.

- [X] T003 Verify R4: read `app/Http/Resources/OrderResource.php` and the closed-order list path the tablet uses (`GET /orders`, `OrderController::index`). Record which timestamp field a completed order returns (candidates: `completed_at`, `closed_at`, `updated_at`). Note the result in `specs/057-android-pos-phase-4-fixes/research.md` R4 (replace TO VERIFY).
- [X] T004 Verify R6: read `app/Support/...` / `app/Http/Middleware` and the retry helper `retryNetwork` in `android-pos/core-network` (search for `retryNetwork`). Confirm whether a 401 is retried. Record in research.md R6.
- [X] T005 Create migration `database/migrations/2026_12_25_000001_add_discount_percent_and_reason.php` adding nullable `discount_percent decimal(5,2)` and `discount_reason_code varchar(40)` to `orders` and to `order_items`; `down()` drops them. The filename must sort after `2026_12_24_000003_add_refund_business_date_to_orders.php`.
- [X] T006 Run `php artisan migrate` against the dev database and `php artisan migrate --env=testing` (or the container equivalent with `DB_DATABASE=warkop_wjm_test`). Confirm the columns exist.
- [X] T007 Add the new attributes to `app/Models/Order.php` and `app/Models/OrderItem.php` `casts` (`discount_percent` => decimal:2). Do NOT add them to `$fillable` (only `DiscountService` writes them — constitution IV).
- [X] T008 Add `discount_percent`, `discount_reason_code`, `discount_reason_label` to the payload in `app/Http/Resources/OrderResource.php` and `app/Http/Resources/TabResource.php` (the reason label from the existing reason-code lookup used by the discount reasons). Items get `discount_percent` and `discount_reason_code`. Use `whenLoaded` / null-safe access; no new queries per row (constitution V).
- [X] T009 Add the reprint count to the order payload (`receipt_copies` exposed as `reprint_count`) in `app/Http/Resources/OrderResource.php` and `app/Http/Resources/TabResource.php`. Verify `ReprintService` reads the same column.
- [X] T010 Add the closing timestamp (the field verified in T003) to the closed-order list payload in `OrderResource.php`, named `closed_at`, nullable.

**Checkpoint**: the server returns the discount percentage and reason, the reprint count, and the closing time.

---

## Phase 3: User Story 1 - Left column of the payment screen readable (Priority: P1) 🎯 MVP

**Goal**: the pending QRIS tile in the left column of the payment screen shows "Verifikasi", the status, and the amount on readable lines.

**Independent Test**: quickstart step 4.

- [X] T011 [US1] In `android-pos/app/src/main/java/com/warkop/pos/ui/screens/TenderScreen.kt` (around line 51), give the QRIS tile a minimum width (for example 160 dp) so its label is not squeezed; keep the status and amount on their own lines.
- [X] T012 [US1] In the same file, the "Verifikasi" label uses `maxLines = 1` with the fitted-size helper (`ui/ChipFit.kt` from 054) — never one letter per line; wrap the amount to its own line if needed.
- [ ] T013 [P] [US1] **NOTE (2026-10-18): not written — the fit rule is layout only (the button is widened by `widthIn(min = 150.dp)` and the label uses `FitPillText`); verified on the device (T014).** Create `android-pos/app/src/test/java/com/warkop/pos/ui/TenderTileFitTest.kt`: the fit decision returns a size for "Verifikasi" in a 160 dp tile and never returns a width that puts one letter per line (test the pure width rule used by T012).
- [ ] T014 [US1] Manual check: quickstart step 4. Record evidence.

---

## Phase 4: User Story 2 - Verify button on its own row (Priority: P1)

**Goal**: in the "Verifikasi QRIS" sheet, Verifikasi is a full-width button on its own row with its full label.

**Independent Test**: quickstart step 5.

- [X] T015 [US2] In `android-pos/app/src/main/java/com/warkop/pos/ui/Sheets.kt` `QrisVerifyDialog` (around line 600–612): keep Tutup and Tidak ditemukan on one row; move the Verifikasi button to a row below it, `Modifier.fillMaxWidth()`, with `FitPillText("Verifikasi")` (from `ui/Common.kt`, 055).
- [X] T016 [US2] Keep `enabled = ref.length >= 4` unchanged (FR-003).
- [ ] T017 [US2] Manual check: quickstart step 5 (full-width Verifikasi, label whole; disabled under 4 characters). Record evidence.

---

## Phase 5: User Story 3 - Reprint works (Priority: P1)

**Goal**: "Cetak ulang" sends the copy number the server expects (`receipt_copies + 2`), so it prints without "The copy no field is required".

**Independent Test**: quickstart step 6.

- [X] T018 [US3] Backend test first: create `tests/Feature/ReprintCopyFromOrderTest.php`: a completed order with no reprints; `POST /orders/{id}/reprints` with `copy_no` 2 succeeds; with no `copy_no` gives 422 `copy_no` required; a stale `copy_no` gives 409 `reprint_copy_changed` with `context.copy_no`. Run it (container command) and confirm it fails before T019.
- [X] T019 [US3] Android DTO: in `android-pos/core-network/src/main/java/com/warkop/core/network/dto/S4Dtos.kt` (line ~61) add `@SerialName("copy_no") val copyNo: Int` to `ReprintRequestDto` (keep `override_token`).
- [X] T020 [US3] In `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt` `reprintOrder` (line ~374): accept the copy number and send it in `ReprintRequestDto(copyNo = …, overrideToken = …)`. Update `PosBackend.kt` (line ~ reprintOrder) and the fake in `app/src/test/.../state/FakePosBackend.kt` to match.
- [X] T021 [US3] In `android-pos/app/src/main/java/com/warkop/pos/data/OrderMapper.kt`: map the order payload's `reprint_count` onto `Order` (new field `reprints` already exists in the UI model — set it from the server, not from local state).
- [X] T022 [US3] In `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` `reprint(orderId, overrideToken)` (line ~1057): compute `copyNo = active order reprints + 2` from the loaded order (not `reprintCopies[orderId] + 1`); on 409 `reprint_copy_changed` re-read the order (`loadReceipt`/`tab` call) and retry once.
- [X] T023 [US3] Create `android-pos/app/src/test/java/com/warkop/pos/state/ReprintCopyTest.kt`: the copy number is server count + 2 (0 reprints → 2, 1 → 3); a 409 triggers exactly one retry with the new number.
- [X] T024 [US3] Run `./gradlew --offline :app:testDebugUnitTest --tests '*ReprintCopyTest*'` and the backend test from T018 (container). Record results.
- [ ] T025 [US3] Manual check: quickstart step 6. Record evidence.

---

## Phase 6: User Story 4 - Refund button shows its label (Priority: P1)

**Goal**: the third button on the order detail (Refund) shows its full label.

**Independent Test**: quickstart step 7.

- [X] T026 [US4] In `android-pos/app/src/main/java/com/warkop/pos/ui/screens/OrdersScreen.kt` (around lines 84–86): the detail button row uses `FitPillText`-style fitted labels for "Lihat struk", "Cetak ulang" and "Refund", and the row wraps to a second line when there is no room (`FlowRow` or two rows), so no button is squeezed to an empty outline.
- [ ] T027 [US4] Manual check: quickstart step 7 (Refund label whole in landscape and portrait). Record evidence.

---

## Phase 7: User Story 5 - Closed order shows a real closing time (Priority: P1)

**Goal**: the closed-order list and detail show HH:MM from the server's closing time, and nothing when the server gives none.

**Independent Test**: quickstart step 8.

- [X] T028 [US5] In `android-pos/core-network/src/main/java/com/warkop/core/network/dto/TabDtos.kt` `ClosedOrderDto` (line ~134): add `@SerialName("closed_at") val closedAt: String? = null`.
- [X] T029 [US5] In `android-pos/app/src/main/java/com/warkop/pos/data/OrderMapper.kt` `closed(c)` (line ~150): `closedAt = c.closedAt?.let(::hhmm)` — null when absent (no "null" text). Keep `openedAt` from `created_at`.
- [X] T030 [US5] In `android-pos/app/src/main/java/com/warkop/pos/ui/screens/OrdersScreen.kt` (line ~75): show "ditutup HH:MM" only when `closedAt` is non-null; otherwise omit that part.
- [X] T031 [US5] Create `android-pos/app/src/test/java/com/warkop/pos/data/OrderMapperClosedTimeTest.kt`: a closing timestamp maps to HH:MM; a null closing timestamp maps to null and the text never contains "null".
- [ ] T032 [US5] Manual check: quickstart step 8. Record evidence.

---

## Phase 8: User Story 6 - Tandai habis shows its reasons (Priority: P1)

**Goal**: the sold-out dialog shows the reasons or a failure with Coba lagi; never "Memuat alasan..." forever.

**Independent Test**: quickstart step 9.

- [X] T033 [US6] In `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` `loadSoldOutReasons` (line ~1006): the load ends in `Load.Ready` or `Load.Failed` (a 401 included); remove any path where it stays `Load.Loading` after the request finished. Depends on the decision in T004 (retry of 401).
- [X] T034 [US6] In `android-pos/app/src/main/java/com/warkop/pos/ui/Sheets.kt` (the sold-out sheet, `Sheet.SoldOut`): when `Load.Failed`, show the failure text and a "Coba lagi" button that calls `loadSoldOutReasons()`; when `Load.Ready` with an empty list, show "Tidak ada alasan" (spec edge case).
- [X] T035 [US6] Create `android-pos/app/src/test/java/com/warkop/pos/state/SoldOutReasonsTest.kt`: after a successful load the state is Ready; after a failed load it is Failed (never Loading); an empty list is Ready with zero items.
- [ ] T036 [US6] Manual check: quickstart step 9. Record evidence.

---

## Phase 9: User Story 7 - Recover after offline and back online (Priority: P1)

**Goal**: after an expired session and a reconnect, the sign-in screen recovers: the expired session is dropped, the staff list loads with the admin token, or a sign-in message appears; "Coba lagi" always gives a visible result.

**Independent Test**: quickstart step 10.

- [X] T037 [US7] Test first: create `android-pos/app/src/test/java/com/warkop/pos/state/SessionRecoveryTest.kt`: with a session token present, a 401 from the staff-list call clears the session token and retries once with the admin token; a second 401 leaves the store in `Load.Failed` with the sign-in message; no infinite retry. Confirm it fails before T038.
- [X] T038 [US7] In `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt`: add `fun dropSession()` that clears `sessionToken` and `SESSION_EXPIRES` (vault), and make `roster()` (and the sold-out reasons call) retry once with the admin token after dropping the session on 401. Never loop.
- [X] T039 [US7] In `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` `loadRoster`: when the second attempt also returns 401, set the roster to `Load.Failed` with the sign-in message; "Coba lagi" (`loadRoster()`) then re-runs the same bounded path.
- [ ] T040 [US7] Manual check (device): quickstart step 10 — offline for a minute, back online, Coba lagi; the staff list loads or the sign-in message appears. Record evidence and the result of the R5 check (admin token still valid).

---

## Phase 10: User Story 8 - Discount percentage and reason stored on the order (Priority: P2)

**Goal**: applying a discount stores its percentage, scope and reason on the order (or the line); the detail and the receipt show them; older discounts show the amount only.

**Independent Test**: quickstart steps 2–3 and 11.

- [X] T041 [US8] Backend test first: create `tests/Feature/DiscountStoresPercentReasonTest.php`: an order discount of 11 % with reason `promotion` stores `discount_percent` 11.00 and `discount_reason_code` `promotion`; a line discount stores the line's fields; removing the order discount sets both to null and the amount to 0; a discount applied before the migration (null fields) shows the amount only. Confirm it fails before T042.
- [X] T042 [US8] In `app/Services/DiscountService.php` `apply()` (lines ~31–90): for line scope set `$line->discount_percent` and `$line->discount_reason_code` alongside the amount (in the same `forceFill` call); for order scope pass the percent and reason to `recalculate` or set them on `$order` in the same transaction. In `clearOrderDiscount()` set both order fields to null. Activity log unchanged.
- [X] T043 [US8] Verify no request field can set `discount_percent` or `discount_reason_code` on orders or items directly (they are not in `$fillable`); add an assertion to the test in T041.
- [X] T044 [US8] Android DTOs: in `android-pos/core-network/src/main/java/com/warkop/core/network/dto/TabDtos.kt` add `discount_percent`, `discount_reason_code`, `discount_reason_label` to the order DTO and the same two fields to the item DTO (nullable).
- [X] T045 [US8] In `android-pos/app/src/main/java/com/warkop/pos/data/OrderMapper.kt`: map them into the UI model (`Order` / `OrderLine`); a null percent shows the amount only (FR-013).
- [X] T046 [US8] Show the stored percentage and reason in the order detail and the receipt (`ui/Sheets.kt`, the discount line in `OrderPane` / `TransactionItemsModal` where "Potongan" is shown): "Potongan 11 % · Promosi" when present, amount only when absent.
- [X] T047 [US8] Create `android-pos/app/src/test/java/com/warkop/pos/data/OrderMapperDiscountTest.kt`: a present percent and reason map through; null maps to null; the label text never prints "null".
- [ ] T048 [US8] Manual check: quickstart step 11. Record evidence.

---

## Phase 11: Contracts and documentation

- [X] T049 Update `docs/openapi-pos-mvp.yaml` in the same change as T008–T010 and T019: order and item discount fields (`discount_percent`, `discount_reason_code`, `discount_reason_label`), the order `reprint_count`, the closed-order `closed_at`, and the reprint request `copy_no` (contracts/order-discount.md and contracts/reprint-copy.md). Validate with `python3 -c "import yaml; yaml.safe_load(open('docs/openapi-pos-mvp.yaml'))"`.

---

## Final Phase: Polish & cross-cutting

- [X] T050 Backend group: `--filter='DiscountStoresPercentReasonTest|ReprintCopyFromOrderTest|DeviceHeartbeatTest'` with the container overrides. Record the result.
- [X] T051 Android: full unit suite `./gradlew --offline :app:testDebugUnitTest`. Record the result.
- [ ] T052 Walk through `quickstart.md` steps 4–11 on the pilot tablet; record evidence. Remove debug logging added during the work.
- [ ] T053 Gate: product owner confirms the discount reason label text (`Promosi` / `promotion`) and that the reprint count is shown to cashiers as "cetak ulang N×" as today.

---

## Dependencies & Execution Order

- **Setup (T001–T002)** → Foundational.
- **Foundational (T003–T010)**: T003/T004 verification first; T005–T010 blocks US3, US5 and US8 (server fields).
- **US1 (T011–T014)**, **US2 (T015–T017)**, **US4 (T026–T027)**: independent of the backend; can run in parallel with Foundational.
- **US3 (T018–T025)**: T018 test first; depends on T008–T009 (reprint count).
- **US5 (T028–T032)**: depends on T010 (closing time field).
- **US6 (T033–T036)**: depends on T004 (401 retry decision) and shares `PosStore.kt` with US3/US7 — run after them.
- **US7 (T037–T040)**: T037 test first; touches `RemoteBackend.kt`/`PosStore.kt`.
- **US8 (T041–T048)**: depends on T005–T008 and the T042 service change.
- **Contracts (T049)** alongside T008–T010 and T019.
- **Polish (T050–T053)** last.

### Parallel opportunities

- T013 (fit test) with T011/T012.
- T018 (backend test) and T037 (session test) and T041 (discount test) — separate files, written before their implementations.
- US1, US2, US4 Android layout work (different screens) in parallel.

## Implementation Strategy

- **MVP**: Setup + Foundational + US3 (reprint) + US7 (reconnect) + US1 — the blocking fixes at the counter.
- **Increment 2**: US2, US4, US5, US6 (layout, closing time, sold-out).
- **Increment 3**: US8 (discount stored on the order) with the migration in production order.
- Each increment ends with its checkpoint and device evidence (constitution II).

## Task count

- Total: **53 tasks** (T001–T053).
- Setup: 2 · Foundational: 8 · US1: 4 · US2: 3 · US3: 8 · US4: 2 · US5: 5 · US6: 4 · US7: 4 · US8: 8 · Contracts: 1 · Polish: 4.
- Parallel-marked [P]: T013.
- Format check: every task has a checkbox, a sequential ID, a `[USn]` label in story phases only, and a file path or a named command/manual check.
