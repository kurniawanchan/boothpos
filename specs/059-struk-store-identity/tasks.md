# Tasks: Store Identity on the Printed Struk

**Input**: `specs/059-struk-store-identity/` (plan.md, spec.md, research.md, data-model.md, quickstart.md, contracts/payment-receipt-fields.md)

**Tests**: Included for the mapper, the URL rule, the raster conversion, and the struk layout (constitution II). Printer checks are manual.

**Organization**: by user story (US1 contact, US2 logo, US3 name/address and reprint). The shared pieces (URL rule, raster line) come first because US2 depends on them.

**R4 (settled 2026-10-18)**: the struk is built at print time (`PrinterService.printOne`), so the logo is fetched and converted there, not stored in the queue.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (baselines)

- [X] T001 Backend baseline inside the container: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=DeviceHeartbeatTest`. Record the result (no backend change).
- [X] T002 Android baseline: `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Record the result.

---

## Phase 2: Foundational (blocks US2; shared rules)

- [X] T003 [P] Create `android-pos/app/src/main/java/com/warkop/pos/data/ServerUrl.kt` with `object ServerUrl { fun resolve(serverBase: String?, url: String?): String? }` — the rule moved from `QrisMapper.resolveQrUrl` (keep path and query, attach to the server base; no base → the URL unchanged; blank → null).
- [X] T004 Edit `android-pos/app/src/main/java/com/warkop/pos/data/QrisMapper.kt`: `resolveQrUrl` delegates to `ServerUrl.resolve` (no duplicate rule). Keep `QrisMapperTest` green.
- [X] T005 [P] Create `android-pos/app/src/test/java/com/warkop/pos/data/ServerUrlTest.kt`: localhost URL re-based to the LAN server; online base replaces the host and keeps the query; no base returns the URL; blank returns null.
- [X] T006 Add a raster variant to `PrintLine` in `android-pos/app/src/main/java/com/warkop/pos/print/EscPos.kt`: optional `raster: String?` (hex of 1-bit rows), `rasterWidthDots: Int`, `rasterHeightDots: Int` (all null/0 for text lines).
- [X] T007 In `EscPos.kt` `renderLine`: when `raster` is set, write the raster block with `GS v 0` (mode 0, width in bytes = width/8 rounded up, height in dots, then the decoded bytes); keep text rendering unchanged for other lines.
- [X] T008 [P] Create `android-pos/app/src/main/java/com/warkop/pos/print/Logo.kt`: `object Logo` with `fun toRaster(bitmap: Bitmap, maxWidthDots: Int = 384): PrintLine` (scale to at most maxWidthDots keeping aspect ratio, grayscale, threshold 128 → 1 bit, pack into bytes, hex-encode) and a `fun isRasterFit(width, height): Boolean` guard (height at most 96 dots).
- [X] T009 [P] Create `android-pos/app/src/test/java/com/warkop/pos/print/LogoRasterTest.kt`: width capped at 384; aspect kept; a white image gives all-zero rows; a black image gives all-one rows; the hex length equals rows × bytes-per-row; height above 96 is refused.
- [X] T010 Create `android-pos/app/src/test/java/com/warkop/pos/print/EscPosRasterTest.kt`: a raster line renders the `GS v 0` header with the correct byte counts; a text line renders as before (no regression).

**Checkpoint**: shared URL rule and raster line exist and are tested.

---

## Phase 3: User Story 1 - Struk shows the store contact (Priority: P1) 🎯 MVP

**Goal**: the contact person, phone, and email print under the address; blank ones are skipped.

**Independent Test**: quickstart step 1 and 4.

- [X] T011 [US1] Edit `android-pos/core-network/src/main/java/com/warkop/core/network/dto/ReceiptDtos.kt` `ReceiptDto`: add `store_contact_person`, `store_contact_phone`, `store_contact_email` (nullable) and `store_logo_url` (nullable).
- [X] T012 [US1] Edit `android-pos/app/src/main/java/com/warkop/pos/model/PosModels.kt` `ReceiptView`: add `storeContactPerson`, `storeContactPhone`, `storeContactEmail`, `storeLogoUrl` (all nullable, defaults null so existing constructors still compile).
- [X] T013 [US1] Edit `android-pos/app/src/main/java/com/warkop/pos/data/ReceiptMapper.kt`: map the four fields; blank strings become null; `storeLogoUrl` is rebased with `ServerUrl.resolve(serverBase, …)` (the base passed in from `RemoteBackend`, same as `qrisChannel`).
- [X] T014 [US1] Create `android-pos/app/src/test/java/com/warkop/pos/data/ReceiptMapperStoreTest.kt`: contact fields map; blank contact becomes null; a null logo stays null; a localhost logo URL is re-based to the server.
- [X] T015 [US1] Edit `android-pos/app/src/main/java/com/warkop/pos/print/Documents.kt` `struk(r)`: after the address line, print each non-null contact line (person, phone, email) centred, in the address style; print nothing for null.
- [X] T016 [US1] Create `android-pos/app/src/test/java/com/warkop/pos/print/StrukIdentityTest.kt` (contact part): with all contacts set, the contact lines follow the address; with only a phone, only one contact line; with none, no contact line and no "null" text.
- [X] T017 [US1] Run `./gradlew --offline :app:testDebugUnitTest --tests '*ReceiptMapperStoreTest*' --tests '*StrukIdentityTest*'`. Record the result.
- [ ] T018 [US1] Manual check: quickstart steps 1 and 4 (contact lines on the printed struk; blank contact prints nothing). Record evidence.

---

## Phase 4: User Story 2 - Struk shows the store logo (Priority: P2)

**Goal**: when the server gives a logo URL, the logo prints above the store name; without it or on failure the struk prints text-only.

**Independent Test**: quickstart steps 2, 3 and 5.

- [x] T019 [US2] Verify R4 first (DONE: built at print time, see research R4): read `PosStore.kt` `printer?.enqueueStruk(…)` and `PrinterService` to see whether the struk lines are built at enqueue time or at print time. Record the answer in `research.md` R4. If lines are built at print time, the logo raster is fetched and stored when the receipt is first loaded for printing, and the plan's R4 is updated before T020.
- [X] T020 [US2] Edit `android-pos/app/src/main/java/com/warkop/pos/print/PrinterService.kt` `printOne`: after `fetchReceipt`, when `storeLogoUrl` is non-null, load the bitmap with `QrImageLoader.load(url)` and convert it with `Logo.toRaster`; on null or failure, build the struk with no logo (never block the struk).
- [X] T021 [US2] Edit `android-pos/app/src/main/java/com/warkop/pos/print/Documents.kt` `struk(r)` (and the logo raster parameter it needs): when a logo raster is supplied, emit it centred as the first line, above the store name; with none, the layout is unchanged.
- [X] T022 [US2] Add the logo cases to `StrukIdentityTest.kt`: with a logo raster the logo line is first; without a logo the first line is the store name; a failed logo produces the text-only struk.
- [X] T023 [US2] Add a raster round-trip check to `android-pos/app/src/test/java/com/warkop/pos/print/PrintQueueTest.kt` (existing): a job with a raster line survives a serialise/deserialise round trip (the queue must persist raster lines; struk jobs themselves store no logo, R4).
- [X] T024 [US2] Run `./gradlew --offline :app:testDebugUnitTest --tests '*StrukIdentityTest*' --tests '*PrintQueueTest*' --tests '*EscPosRasterTest*'`. Record the result.
- [ ] T025 [US2] Manual check on the TM-58V: quickstart step 2 (logo above the name, readable at 58 mm; start with threshold 128; adjust if too pale or too dark, and record the threshold used). Record evidence and print time.
- [ ] T026 [US2] Manual check: quickstart step 3 (receipt-logo setting off → no logo) and step 5 (printer off → struk waits, then prints with identity). Record evidence.

---

## Phase 5: User Story 3 - Name and address match admin settings; reprints match (Priority: P1)

**Goal**: the name and address come from Store identity for the active mode; a reprint shows the same identity.

**Independent Test**: quickstart step 6.

- [X] T027 [US3] Test first: extend `ReceiptMapperStoreTest.kt`: the store name and address map from the receipt payload unchanged (no tablet-side override); a receipt for a reprint uses the same payload fields.
- [X] T028 [US3] Confirm in `PosStore.kt` reprint path (`reprint(orderId, …)`) that the struk is built from the server receipt (not from local order data), so the identity matches the original (FR-007). Change only if a local source is found; record the check in research.md R5.
- [ ] T029 [US3] Manual check: quickstart step 6 (change the store name in admin, reprint an old order: the reprint shows the same identity as the original). Record evidence.

---

## Final Phase: Polish & cross-cutting

- [X] T030 Full Android unit suite: `./gradlew --offline :app:testDebugUnitTest`. Record the result.
- [X] T031 Confirm no server or API change: no file under `app/` (Laravel), `routes/`, or `docs/openapi-pos-mvp.yaml` is edited for this feature.
- [ ] T032 Walk through `quickstart.md` on the pilot tablet and printer; record evidence. Remove debug logging.

---

## Dependencies & Execution Order

- **Setup (T001–T002)** first.
- **Foundational (T003–T010)**: T003 → T004 → T005 (test). T006 → T007 → T010. T008 → T009. US2 needs T006–T010; US1 needs T003 (receipt URL rebase) only for the logo URL.
- **US1 (T011–T018)**: T011 → T012 → T013 → T014 → T015 → T016 → T017 → T018. Text contact does not need the raster code.
- **US2 (T019–T026)**: T019 (R4 check) before T020; T020 → T021 → T022; T023 after T006.
- **US3 (T027–T029)**: after US1 (same receipt mapper); T028 is a read-only check.
- **Polish (T030–T032)** last.

### Parallel opportunities

- T003 and T008 (URL rule and logo conversion, different files).
- T005, T009, T010 (tests for different units).
- US1 text work and US2 raster work after Foundational.

## Implementation Strategy

- **MVP**: Foundational + US1 — contact lines on every struk.
- **Increment 2**: US2 logo, after the printer check decides the threshold.
- **Increment 3**: US3 reprint check and polish.

## Task count

- Total: **32 tasks** (T001–T032).
- Setup: 2 · Foundational: 8 · US1: 8 · US2: 8 · US3: 3 · Polish: 3.
- Parallel-marked: T003, T005, T008, T009, T010.
- Format check: every task has a checkbox, a sequential ID, a `[USn]` label in story phases only, and a file path or named command/manual check.
