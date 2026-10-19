# Tasks: Android POS Phase 3 — Static QRIS Sheet Layout

**Input**: `specs/056-android-qris-sheet-layout/` (plan.md, spec.md, research.md, data-model.md, contracts/payment-channels-tablet.md, quickstart.md)

**Tests**: Included for the pure rules (title, mapping, size decisions) under constitution II. Layout and the QR image need the tablet and are manual tasks.

**Organization**: by user story (spec.md US1–US5). The channel data (Foundational) feeds the title, the QR, and the footer, so it comes first. No backend change.

**Open decision carried in**: a failed QR download shows "QR belum diatur" (plan R5, spec edge case). Confirm before T015.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (baseline)

- [X] T001 Backend baseline inside the container: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=DeviceHeartbeatTest`. Record the result (no backend change in this feature).
- [X] T002 Android baseline: `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Record the result.

---

## Phase 2: Foundational (blocks US1–US5)

**Purpose**: the tablet keeps the channel's account name and QR URL (root cause, plan R1).

- [X] T003 Add `@SerialName("qr_image_url") val qrImageUrl: String? = null` to `PaymentChannelDto` in `android-pos/core-network/src/main/java/com/warkop/core/network/dto/ActionDtos.kt` (line ~52). `account_name` is already there.
- [X] T004 Change `data class QrisChannel` in `android-pos/app/src/main/java/com/warkop/pos/model/PosModels.kt` (line ~123) to `QrisChannel(val id: Long, val provider: String, val accountName: String?, val qrImageUrl: String?)`. Fix the one call site (T006).
- [X] T005 [P] Create `android-pos/app/src/main/java/com/warkop/pos/data/QrisMapper.kt`: `object QrisMapper` with `fun sheetTitle(provider: String, accountName: String?): String` (provider + " " + account when both non-blank; provider alone when account blank; "QRIS" when provider blank; both trimmed) and `fun toChannel(dto: PaymentChannelDto): QrisChannel` (provider blank → "QRIS"; accountName blank → null; qrImageUrl blank → null).
- [X] T006 In `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt` `qrisChannel()` (around line 301): replace the mapping with `QrisMapper.toChannel(dto)` for the first `type == "qr_ewallet"` channel. Depends on T003–T005.
- [X] T007 [P] Create `android-pos/app/src/test/java/com/warkop/pos/data/QrisMapperTest.kt`: title with both names; title with blank account name is the provider only; blank provider gives "QRIS"; names are trimmed; blank QR URL becomes null; blank account name becomes null.
- [X] T008 In `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` (around lines 900–906): keep the loaded channel as `var qrisChannel: QrisChannel?` next to `qrisLabel` (keep `qrisLabel` = provider for the other screens); add `val qrisTitle: String get() = QrisMapper.sheetTitle(...)` from the channel. Depends on T004–T006.
- [X] T009 Run `./gradlew --offline :app:testDebugUnitTest --tests '*QrisMapperTest*'`. Record the result.

**Checkpoint**: the tablet holds the account name and QR URL for the static QRIS channel.

---

## Phase 3: User Story 1 - Title shows provider and account holder (Priority: P1) 🎯 MVP

**Goal**: the sheet title reads "{provider} {account holder}", fitted in width, never cut.

**Independent Test**: quickstart steps 3–4.

- [X] T010 [US1] Create `android-pos/app/src/main/java/com/warkop/pos/ui/QrisSheetLayout.kt` with pure `fun titleSizeFor(textWidthAt: (Float) -> Int, available: Int): Float?` using sizes 30, 28, 26, 24, 22 (via `chooseFontSize` from 054). Null means too long even at 22 sp (then the title wraps; see T011).
- [X] T011 [US1] In `android-pos/app/src/main/java/com/warkop/pos/ui/Sheets.kt` `QrisRecordDialog` (line ~488): replace `Text("${store.qrisLabel} (statis)", ...)` with the title from `store.qrisTitle`, sized by `titleSizeFor` (fallback 22 sp with `softWrap` allowed so it wraps, never truncated).
- [X] T012 [P] [US1] Create `android-pos/app/src/test/java/com/warkop/pos/ui/QrisSheetLayoutTest.kt`: title size for a short name is 30; a long name gets a smaller size; a name too long even at 22 sp returns null; available width zero returns null.
- [ ] T013 [US1] Manual check: quickstart steps 3–4 (title "QRIS Warkop WJM 3"; cleared account name gives "QRIS"). Record evidence.

---

## Phase 4: User Story 2 - Warning smaller than title (Priority: P1)

**Goal**: the warning "Jangan terima bukti dari layar ponsel pelanggan." is fully visible at 14 sp, smaller than the 30 sp title.

**Independent Test**: quickstart step 5.

- [X] T014 [US2] In `android-pos/app/src/main/java/com/warkop/pos/ui/Sheets.kt` `QrisRecordDialog` (warning Surface, line ~494): set the warning `Text` to `fontSize = 14.sp`, keep the wording, keep the Row with the icon, and let it wrap fully (no `maxLines`).
- [ ] T015 [US2] Manual check: quickstart step 5 (warning readable and smaller than the title; no overlap with the QR). Record evidence.

---

## Phase 5: User Story 3 - QR image centred; standee text removed (Priority: P1)

**Goal**: the channel's QR image is shown centred; "Standee QRIS statis di meja kasir" is removed; with no image (or a failed download) the sheet shows "QR belum diatur".

**Independent Test**: quickstart steps 6–7.

- [X] T016 [P] [US3] Create `android-pos/app/src/main/java/com/warkop/pos/data/QrImageLoader.kt`: `object QrImageLoader` with a `StateFlow`-free API: `suspend fun load(url: String): ImageBitmap?` using the OkHttp client from `core-network` (reuse the app's `OkHttpClient`, do not create a second one), `BitmapFactory.decodeStream`, `asImageBitmap()`, and an in-memory `ConcurrentHashMap<String, ImageBitmap>` cache keyed by URL. Returns null on any failure. Depends on the OkHttp client accessor in `core-network/src/main/java/com/warkop/core/network/ApiClient.kt` (add a shared accessor if none exists).
- [X] T017 [US3] In `Sheets.kt` `QrisRecordDialog`: remove the side-by-side `Row` with the 210 dp card; put the card in a centred `Column` (horizontal alignment centred) with the header from `store.qrisChannel?.provider`, the image (`produceState` with `QrImageLoader.load`) at about 240 dp, and the footer = account name (remove the literal "WARKOP WJM 3 · NMID ID10••••"). The placeholder icon is removed. Depends on T008, T016.
- [X] T018 [US3] **NOTE: implemented as "QR belum diatur" for both no-image and failed download (plan R5); product owner confirmation still open (T030).** In `Sheets.kt`: when `qrisChannel?.qrImageUrl` is null or the load returned null, show "QR belum diatur" in the same centred place (FR-007). Confirm the open decision (plan R5) before merging this task.
- [X] T019 [US3] Remove the text "Standee QRIS statis di meja kasir" from `Sheets.kt` (line ~504). Verify with a search that the string no longer appears in the app source.
- [ ] T020 [US3] Manual check: quickstart steps 6–7 (QR centred; no standee text; "QR belum diatur" when the image is removed). Record evidence.

---

## Phase 6: User Story 4 - Total on one line; instruction fully visible (Priority: P1)

**Goal**: the total is one line, sized to fit; the instruction "Catat sebagai menunggu verifikasi, lalu cek transaksi di aplikasi {provider}." wraps fully below it.

**Independent Test**: quickstart steps 8–9.

- [X] T021 [US4] In `android-pos/app/src/main/java/com/warkop/pos/ui/QrisSheetLayout.kt`: add pure `fun totalSizeFor(textWidthAt: (Float) -> Int, available: Int): Float` with sizes 40, 36, 32, 28 (via `chooseFontSize`); fall back to 28 when nothing fits.
- [X] T022 [P] [US4] Add cases to `android-pos/app/src/test/java/com/warkop/pos/ui/QrisSheetLayoutTest.kt`: a normal total gets 40; a large total gets a smaller size; the fallback is 28 when even 28 does not fit.
- [X] T023 [US4] In `Sheets.kt` `QrisRecordDialog`: under the QR card, put the total in a full-width block as one `Text` with `maxLines = 1`, `softWrap = false`, and the size from `totalSizeFor` (measured with the same `rememberTextMeasurer` pattern as `FitPillText`); then the instruction `Text` at 16 sp, full width, center-aligned, wrapping with no `maxLines`.
- [ ] T024 [US4] Manual check: quickstart steps 8–9 (large amount on one line; instruction fully visible). Record evidence.

---

## Phase 7: User Story 5 - Record button text tidy (Priority: P2)

**Goal**: "Catat · menunggu verifikasi" on one line with its arrow, or fitted inside the button; never cropped.

**Independent Test**: quickstart step 10.

- [X] T025 [US5] In `Sheets.kt` `QrisRecordDialog` (line ~511): replace the button's label `Text("Catat · menunggu verifikasi", ...)` with `FitPillText("Catat · menunggu verifikasi")` (from `ui/Common.kt`, added in 055), keep the arrow icon and the `Spacer` next to it, and keep `Modifier.height(52.dp)`.
- [ ] T026 [US5] Manual check: quickstart step 10 (label on one line with the arrow; not cropped). Record evidence.

---

## Final Phase: Polish & cross-cutting

- [X] T027 Run the full Android unit suite `./gradlew --offline :app:testDebugUnitTest`. Record the result.
- [X] T028 Confirm FR-011 (recording unchanged): the `recordQris()` path and the server call are not edited (`git diff` limited to `Sheets.kt`, `PosStore.kt` title/channel fields, and the new files).
- [ ] T029 Walk through `quickstart.md` steps 3–11 on the pilot tablet and record evidence; test one static QRIS payment end to end (step 11). Remove debug logging.
- [ ] T030 Gate: confirm the failed-download message (plan R5, "QR belum diatur") with the product owner before T018 is merged.

---

## Dependencies & Execution Order

- **Setup (T001–T002)** → Foundational.
- **Foundational (T003–T009)** blocks all stories: the title, the QR, and the footer read the channel.
- **US1 (T010–T013)** then **US2 (T014–T015)**: both edit `QrisRecordDialog`, so they run in order.
- **US3 (T016–T020)**: T016 [P] (new loader file) can run in parallel with US1/US2; T017 onward edits `Sheets.kt`.
- **US4 (T021–T024)**: after US3 (same dialog area; the total sits under the QR card).
- **US5 (T025–T026)**: last among the layout edits (same button block).
- **Polish (T027–T030)** last.

### Parallel opportunities

- T005 and T007 (mapper and its test), T012 and T022 (layout test cases), T016 (loader) alongside US1–US2 work.

## Implementation Strategy

- **MVP**: Foundational + US1 + US3 (the QR and the right account on the sheet).
- **Increment 2**: US2 (warning) and US4 (total and instruction).
- **Increment 3**: US5 (button) and polish.

## Task count

- Total: **30 tasks** (T001–T030).
- Setup: 2 · Foundational: 8 · US1: 4 · US2: 2 · US3: 5 · US4: 4 · US5: 2 · Polish: 4 (T027–T030 + checkpoint evidence in T029).
- Parallel-marked [P]: T005, T007, T012, T016, T022.
- Format check: every task has a checkbox, a sequential ID, a `[USn]` label in story phases only, and a file path or named command/manual check.

---

## Phase 8: Amendment 2026-10-18 — two-column sheet and saving the QR image

- [X] T031 Server: `PaymentChannelController::index` returns `can_manage_qr` (settings menu) per channel; `tests/Feature/PaymentChannelTest.php` covers owner true, cashier false. OpenAPI `PaymentChannel` updated in the same change.
- [X] T032 Android DTO and model: `PaymentChannelDto.canManageQr` and `QrisChannel.canManageQr`; `QrisMapper.toChannel` maps it.
- [X] T033 Android API: `PosApi.uploadQrImage` (multipart `qr_image` to `POST payment-channels/{id}`); `PosBackend.uploadQrImage` + `RemoteBackend` (errors via `ApiErrorMapper`); fake stub.
- [X] T034 `QrisMapper.isAllowedQrMime` (JPEG/PNG only) + tests in `QrisMapperTest`.
- [X] T035 `PosStore.saveQrImage`: uploads to the loaded channel, evicts the old image from `QrImageLoader`, reloads the channel; tests in `PosStoreQrisUploadTest`.
- [X] T036 `Sheets.kt` `QrisRecordDialog`: title and warning full width; two columns (column 1 full QR image + "Ganti gambar QR" when allowed; column 2 total, instruction, buttons). `QrCodeBox` uses `ContentScale.Fit`.
- [X] T037 Compile and full Android unit suite: 118 tests pass.
- [ ] T038 Manual (tablet): two-column layout; full QR visible; replace with a JPG and a PNG as an owner PIN; a cashier PIN does not see the control; a non-image file is refused. Record evidence.
- [ ] T039 Product owner: confirm that only owner/admin (settings menu) may replace the QR (spec amendment assumption).

## Phase 9: Amendment 2026-10-18 (round 2) — modal width and QR image URL

- [X] T040 Modal QRIS 50 % wider: `ui/Sheets.kt` `SheetHost` centred branch uses `widthIn(max = 720.dp)` for `Sheet.QrisRecord` (was 480 dp).
- [X] T041 QR image URL: the server stores `APP_URL` (localhost) in `qr_image_url`. `QrisMapper.resolveQrUrl` keeps the path and query and attaches them to the server address the tablet uses (`RemoteBackend.qrisChannel` passes `serverUrl`). Tests in `QrisMapperTest` (local, online, no base).
- [X] T042 Compile and full Android unit suite: 121 tests pass.
- [ ] T043 Manual (tablet): the modal is 50 % wider; the QR image shows from the saved channel on the local server and after switching to the online address (feature 054).
