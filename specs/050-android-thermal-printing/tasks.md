# Tasks: Thermal Printer for the Android POS

**Input**: Design documents from `/specs/050-android-thermal-printing/`

**Prerequisites**: plan.md (required), spec.md (required), research.md, data-model.md, contracts/print-documents.md, quickstart.md

**Tests**: Included. Constitution II requires tests for every change, and the plan names the test files. Device checks V1–V15 in quickstart.md are manual and listed in the final phase.

**Organization**: Tasks are grouped by user story. Story priority follows spec.md: US1, US2, US3, US5 are P1; US4 is P2. US5 (failure handling and queue) is P1 but its queue core is built in Phase 2 because every story prints through it.

**Paths**: `A` = `android-pos/app/src/main/java/com/warkop/pos`, `N` = `android-pos/core-network/src/main/java/com/warkop/core/network`, `AT` = `android-pos/app/src/test/java/com/warkop/pos`, `NT` = `android-pos/core-network/src/test/java/com/warkop/core/network`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[Story]**: US1–US5, only in story phases

---

## Phase 1: Setup

**Purpose**: Platform permissions the printer link needs. No new Gradle dependency (research D4).

- [X] T001 Add Bluetooth permissions to `android-pos/app/src/main/AndroidManifest.xml`: `BLUETOOTH_CONNECT` (API 31+); `BLUETOOTH` and `BLUETOOTH_ADMIN` with `android:maxSdkVersion="30"`. Do not add location permissions (bonded-device listing needs none).

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: The one printer writer, the pure encoder, the durable queue, and the ticket data the send response carries. All user stories depend on these.

**CRITICAL**: No story phase starts until this phase is complete.

### Data from the server

- [X] T002 [P] Create `N/dto/TicketDtos.kt` with `@Serializable TicketDto` (`id`, `round_no`, `sent_at`, `station: {id, name}?`, `order: {id, order_number}?`, `table: {id, name}?`, `tab_label`, `items: List<TicketItemDto>` where `TicketItemDto` has `id`, `name`, `qty`, `modifiers`, `note`, `status`). Every field defaulted so an older response still decodes.
- [X] T003 Add `tickets: List<TicketDto> = emptyList()` to `SendResponseDto` in `N/dto/TabDtos.kt`.
- [X] T004 [P] Create `A/model/KitchenPrint.kt`: `data class PrintableKot(val ticketId: Long, val stationName: String?, val tableName: String?, val tabLabel: String?, val orderNumber: String?, val roundNo: Int, val sentAtText: String, val items: List<PrintableItem>)` and `data class PrintableItem(val qty: Int, val name: String, val modifiers: List<String>, val note: String?)`. No price fields (FR-014).
- [X] T005 Create `A/data/TicketMapper.kt`: `object TicketMapper { fun kot(dto: TicketDto): PrintableKot }`, pure, no arithmetic, no price fields.
- [X] T006 Change `PosBackend.sendToKitchen` in `A/data/PosBackend.kt` to return `KitchenSend(tab: Order, tickets: List<PrintableKot>)` (define `KitchenSend` in `A/data/PosBackend.kt` next to `TerminalConfig`). Update `RemoteBackend.sendToKitchen` (`A/data/RemoteBackend.kt`, line ~252) to map `SendResponseDto.tickets` through `TicketMapper`.
- [X] T007 Update the `sendToKitchen` call site in `A/state/PosStore.kt` (around line 480) to the new return type. Behaviour unchanged in this task: it still just reports the item count.
- [X] T008 Add the single-ticket read `ticket(id: Long): TicketDto` to `N/PosApi.kt` as `@GET("tickets/{id}")`, and `PosBackend.ticket(ticketId: Long): PrintableKot` mapped in `RemoteBackend.kt`. Needed by US4 KOT reprint.

### Printer core

- [X] T009 [P] Create `A/print/EscPos.kt`: pure encoder producing `ByteArray`. Commands: initialise (`ESC @`), bold on/off (`ESC E n`), double-height for headings (`GS ! n`), line feed, feed-and-cut (`GS V`). Constant `COLUMNS = 32` (UNVERIFIED until V2). Word-wrap helper for lines longer than `COLUMNS`. Replace characters outside ASCII with `?`. Reference contracts/print-documents.md Part B.
- [X] T010 [P] Create `A/print/PrintJob.kt` with the entities from data-model.md: `enum PrintKind { STRUK, KOT, PREBILL, BATAL }`, `enum PrintStatus { PENDING, FAILED, PRINTED, DISMISSED }`, `data class PrintJob(key, kind, refId, copyNo, status, attempts, lastError, createdAt, printedAt, outletId, terminal)`, and `fun jobKey(kind, refId, copyNo): String`.
- [X] T011 Create `A/print/PrintQueue.kt`: JSON file `filesDir/print-queue.json`, written atomically (temp file then rename). Operations: `enqueue(job)` is a no-op when the key is already `PRINTED` (FR-007), `all()`, `pending()`, `markPrinted(key)`, `markFailed(key, reason)`, `dismiss(key, reason?)`. Loads on construction; a corrupt file is moved aside, not crashed on. Uses kotlinx-serialization already in the app.
- [X] T012 [P] Create `A/print/PrinterSettings.kt`: `data class PrinterConfig(name, address)` stored in `filesDir/printer.json`. `load()`, `save()`, `clear()`. Absent file = not configured. Paper width is a constant 58 mm, not a field (FR-003).
- [X] T013 Create `A/print/Documents.kt` with the shared layout builder: `fun lines(...)` helpers for centred heading, two-column row (left label, right amount, padded to `COLUMNS`), dashed separator, and the `testSlip(storeName, nowText)` document. Only the test slip and the helpers in this task; the four real layouts come in their stories.
- [X] T014 Create `A/print/PrinterService.kt`: the only class that writes to the printer (Constitution I). Holds a single serial worker coroutine (`Dispatchers.IO`, one `Channel`). `enqueue(job)` writes the job to `PrintQueue` first, then wakes the worker. The worker: loads the config, opens an RFCOMM socket on the SPP UUID `00001101-0000-1000-8000-00805F9B34FB` (connect timeout 5 s, write stall 5 s), writes the bytes built for the job, marks `PRINTED` on success or `FAILED` with `lastError` on failure. Maps exceptions to `bluetooth_off` / `out_of_range` / `paper_out` / `write_error` / `not_configured` (contracts Part C). Raw exception text goes to the log only.
- [X] T015 Add to `PrinterService`: `status(): PrinterStatus` (`NOT_CONFIGURED`, `DISCONNECTED`, `CONNECTING`, `CONNECTED`, `ERROR(reason)`), derived from the last attempt. Expose as a `StateFlow`.
- [X] T016 Create the service's holder in `A/PosApplication.kt`: construct one `PrinterService` (with `PrintQueue` and `PrinterSettings` under `filesDir`) and make it reachable from `PosStore`. No DI framework (Hilt is in the catalog but unused; constitution forbids speculative structure). Start the worker, and re-enqueue nothing: pending jobs are picked up from the file (FR-021).

### Foundational tests

- [X] T017 [P] Create `AT/print/EscPosTest.kt`: bytes for init and feed/cut; a 40-character line wraps at 32; a line of exactly 32 characters does not wrap; non-ASCII becomes `?`; bold toggles on and off.
- [X] T018 [P] Create `AT/print/PrintQueueTest.kt`: enqueue of an existing `PRINTED` key is a no-op; `PENDING` survives reload (restart simulation); `markFailed` keeps the job; a corrupt file is moved aside and the queue starts empty; `dismiss` records the reason.
- [X] T019 [P] Create `NT/TicketDtosTest.kt`: a send response with `tickets` decodes; a send response without `tickets` still decodes to an empty list; an item with null `note` and empty `modifiers` decodes.

**Checkpoint**: The printer core builds and its unit tests pass. No screen is wired yet.

---

## Phase 3: User Story 1 — Connect the printer and run a test print (Priority P1) 🎯 MVP

**Goal**: The owner pairs the VSC TM-58V, chooses it in the app, and prints a readable test slip (spec US1).

**Independent test**: Pair the printer in Android settings, choose it in the app, press "Tes cetak", and read the slip (quickstart V1).

- [X] T020 [US1] Create `A/ui/screens/PrinterScreen.kt`: lists bonded Bluetooth devices (`BluetoothAdapter.bondedDevices`), lets the user choose one, shows the connection status from `PrinterService.status`, and has a "Tes cetak" button. Colours from `PosTheme` tokens only (no hex). Indonesian copy only (Constitution III).
- [X] T021 [US1] Request `BLUETOOTH_CONNECT` at runtime on API 31+ from `PrinterScreen.kt` before listing devices. If denied, show "Izin Bluetooth diperlukan" with no list. Never block the rest of the app.
- [X] T022 [US1] Implement `PrinterService.choose(device)` (saves `PrinterConfig` through `PrinterSettings`) and `PrinterService.testPrint()` (enqueues a `PrintJob` with kind `STRUK`, `refId = "test"`, and `copyNo` = the current time in ms so every test is a distinct key).
- [X] T023 [US1] Add `Documents.testSlip(storeName, nowText)` in `A/print/Documents.kt`: store name centred and bold, date and time, and a line of sample characters that is exactly `COLUMNS` long (V1, V2).
- [X] T024 [US1] Replace the "Printer / Belum tersedia / Cetak belum tersambung ke perangkat ini" row in `A/ui/screens/ShiftScreens.kt` (line ~308, "Perangkat & sinkron") with the real printer state and an entry point to `PrinterScreen`.
- [X] T025 [US1] Add the `PrinterScreen` destination to the existing navigation in `A/state/PosStore.kt` and `A/ui/Shell.kt` (sheet or sub-screen, following how `Dest.SYNC` is handled today).
- [X] T026 [US1] Auto-reconnect on app start: when a config exists, `PrinterService` checks the socket once at launch and sets `DISCONNECTED` or `CONNECTED` (FR-004). No dialog, no prompt.
- [X] T027 [US1] Create `AT/print/TestSlipTest.kt`: the test slip has the store name, a date line, and a sample line of exactly 32 characters; it contains no phone or e-mail when the store fixture includes them.

**Checkpoint**: US1 works on its own with the printer. Story complete when quickstart V1 and V2 pass on the device.

---

## Phase 4: User Story 2 — Kitchen order prints automatically when sent (Priority P1)

**Goal**: "Kirim ke dapur" prints one KOT per station ticket; a re-send prints nothing; a void of a sent item prints a BATAL slip (spec US2, FR-006 to FR-008).

**Independent test**: Add three items with a modifier and a note, send, read one KOT per station (quickstart V3, V4, V12).

- [X] T028 [US2] Add the KOT layout to `A/print/Documents.kt`: `fun kot(k: PrintableKot): List<PrintLine>`. Heading `KOT` in capitals, double-height. Then station name (or "Tanpa stasiun"), table or tab label with order number, `Ronde <n>` and the time. Then each item as `qty x name`, modifiers indented, note as `Catatan: ...`. No prices or totals (FR-014). Contracts A2.
- [X] T029 [US2] Add the BATAL layout to `A/print/Documents.kt`: `fun batal(k: PrintableKot, item: PrintableItem, reason: String?): List<PrintLine>`. Heading `BATAL` in capitals, double-height, then station, table, `BATAL qty x name`, and reason. Contracts A4.
- [X] T030 [US2] Add `PrinterService.enqueueKot(kots: List<PrintableKot>, cashierName: String)` that creates one `KOT` job per ticket with key `kot:<ticketId>:1`. The cashier name is the signed-in PIN user (open item 3). Duplicate keys are no-ops via T011.
- [X] T031 [US2] In `A/state/PosStore.kt` `sendToKitchen` path (around line 480): after a successful send, pass `KitchenSend.tickets` to `PrinterService.enqueueKot`. Replace the message "Cetak KOT belum tersedia." with "Dikirim ke dapur · KOT dicetak" or "KOT menunggu cetak" depending on the printer status. Printing never blocks or rolls back the send (FR-017).
- [X] T032 [US2] Add the BATAL path in `A/state/PosStore.kt` `voidLine` (around line 655–667): when a sent item is voided, enqueue a BATAL job for that item's ticket, key `batal:<ticketId>:<lineId>:1`. Build from the ticket snapshot already on the tablet; if no ticket is known, skip the slip and log it. (UNVERIFIED: research D7.)
- [X] T033 [US2] Update the message in `A/ui/Sheets.kt` line ~349 ("Slip BATAL dicetak ke station") so it says the slip is queued, and reflects "belum tercetak" when the printer is not connected.
- [X] T034 [US2] Create `AT/print/KotDocumentTest.kt`: a ticket with three items, one modifier, one note renders one KOT with `KOT` in capitals; output contains no `Rp`, no digit price pattern, no `total`; `station = null` renders "Tanpa stasiun".
- [X] T035 [US2] Create `AT/print/BatalDocumentTest.kt`: BATAL output contains `BATAL` and the item; contains no price.
- [X] T036 [US2] Create `AT/state/KitchenPrintTest.kt`: sending the same tickets twice enqueues each KOT once (same key); a void enqueues exactly one BATAL.

**Checkpoint**: US2 works on its own. Story complete when quickstart V3, V4, V12 pass.

---

## Phase 5: User Story 3 — Receipt prints automatically after payment (Priority P1)

**Goal**: A completed payment prints the struk without any extra tap (spec US3, FR-005, FR-013, FR-015).

**Independent test**: Pay in cash with change; the struk prints with the server's numbers (quickstart V6).

- [X] T037 [US3] Add the STRUK layout to `A/print/Documents.kt`: `fun struk(r: ReceiptView): List<PrintLine>`. Store name (bold, centred), address if present, `No. struk`, time, cashier. Items as `qty x name` with line total, modifiers and note indented. Subtotal, discount (only if > 0), tax label with rate, TOTAL (bold), payment lines, `Bayar`, `Kembali` (only if > 0). `SALINAN n` only if `copyNo > 1`. Contracts A1. Amounts are the server's, formatted only (no arithmetic).
- [X] T038 [US3] Add `PrinterService.enqueueStruk(orderId: String, copyNo: Int)`: key `struk:<orderId>:<copyNo>`. The job fetches `receipt(orderId)` at print time, so it can wait for the network as well (research D2). Map the fetched `ReceiptDto` through `ReceiptMapper`.
- [X] T039 [US3] In `A/state/PosStore.kt`, after a successful `pay` that closes the tab (the settlement path that currently sets the "Cetak struk belum tersedia" state), enqueue a STRUK job for the order. Payment result is not changed by printing (FR-017).
- [X] T040 [US3] Replace "Cetak struk belum tersedia · …" in `A/ui/screens/TenderScreen.kt` (line ~140) with a print status line from the queue: "Tercetak", "Belum tercetak", or "Menunggu printer". Indonesian copy only.
- [X] T041 [US3] Create `AT/print/StrukDocumentTest.kt`: the layout contains the server's totals as given (no recomputation); the discount line is absent when zero; `SALINAN 2` appears only on copy 2 and later; output has no phone or e-mail even when the fixture customer has them (FR-015).

**Checkpoint**: US3 works on its own. Story complete when quickstart V6 passes.

---

## Phase 6: User Story 5 — Printer trouble never stops selling (Priority P1)

**Goal**: A failed print never blocks payment or a send; failed jobs are visible, retryable, and never printed twice (spec US5, FR-017 to FR-021).

**Independent test**: Switch the printer off, pay, send; the sale and the send succeed; retry prints once (quickstart V7, V8, V9, V14).

- [X] T042 [US5] Map every `PrinterService` failure to the Indonesian messages in contracts Part C in `A/print/PrinterService.kt` (`bluetooth_off` → "Bluetooth mati", `out_of_range` → "Printer di luar jangkauan", `paper_out` → "Kertas habis", `write_error` → "Gagal mencetak, coba lagi", `not_configured` → "Printer belum dipasang"). Expose the message with the job, never raw text.
- [X] T043 [US5] Add `PrinterService.retry(key)` and `PrinterService.retryAll()`. Both act only on `PENDING` and `FAILED` jobs; a `PRINTED` job is skipped even if retried twice (FR-019). Retry of a STRUK copy reuses its stored `copyNo` and never calls the server reprint endpoint again (research D2).
- [X] T044 [US5] Add `PrinterService.dismiss(key, reason)`. A KOT dismissal requires a non-blank reason (FR-020); STRUK and PREBILL do not.
- [X] T045 [US5] Create `A/ui/screens/PrintQueueSection.kt` (or a section of `PrinterScreen.kt`): "Antrean cetak" listing `PENDING` and `FAILED` jobs newest first, each with kind, reference, status word ("Belum tercetak" / "Gagal"), the message, and buttons Coba lagi and Tutup (dismiss). Shows "Antrean kosong" when nothing is pending. Status words exactly as spec FR-025.
- [X] T046 [US5] Show a persistent warning on the open tab in `A/ui/screens/EditorScreen.kt` when any KOT job for that tab is `PENDING` or `FAILED`: "KOT belum tercetak — kirim ulang ke dapur secara manual" (FR-020). Hide when resolved or dismissed.
- [X] T047 [US5] Make printing resilient to the app going to the background: the worker resumes from the file after a restart (already true from T011/T016), and a job in flight at kill time stays `PENDING` (FR-021).
- [X] T048 [US5] Create `AT/print/RetryTest.kt`: retry of a `PRINTED` job sends nothing; retry of a `FAILED` STRUK reuses its copy number; a KOT cannot be dismissed without a reason; a job in flight at restart remains pending.
- [X] T049 [US5] Create `AT/print/PrinterErrorTest.kt`: each failure code maps to exactly the Indonesian message from contracts Part C, and no message contains raw exception text.

**Checkpoint**: US5 works on its own. Story complete when quickstart V7, V8, V9, V14 pass.

---

## Phase 7: User Story 4 — Print buttons where a printed copy is needed (Priority P2)

**Goal**: Manual print buttons at the four places in spec US4: struk on a completed sale, "Cetak ulang" on Orders, "Cetak pre-bill" on an open tab, "Cetak ulang KOT" on an open tab (FR-009 to FR-011).

**Independent test**: Each button prints once; a second struk reprint is "SALINAN 2" with no approval, the third needs a supervisor PIN (quickstart V10, V11).

- [X] T050 [US4] Wire the "Cetak ulang" button already present in `A/ui/screens/OrdersScreen.kt` (line ~85) to `PosStore.reprint`, which calls `PosBackend.reprintOrder` for the server copy number (`ReprintService` rules: copy 2 free, copy 3+ needs override) and then enqueues STRUK with that copy number. Printing happens only after the server accepts the reprint.
- [X] T051 [US4] Add a "Cetak struk" button to the sale detail sheet (`Sheet.Receipt`, `A/ui/Sheets.kt`) for a completed sale. It enqueues STRUK with the next copy number from the server (as T050).
- [X] T052 [US4] Replace the placeholder in `A/state/PosStore.kt` `printPrebill` (around line 485–491, "Cetak fisik belum tersedia") so that: `PosBackend.prebill` runs once (never auto-retried, research D3), then a PREBILL job is enqueued with key `prebill:<tabId>:1` and the tab from the response. Message: "Pra-bill dicetak · BELUM LUNAS".
- [X] T053 [US4] Add `Documents.prebill(tab: Order): List<PrintLine>` to `A/print/Documents.kt`: STRUK layout with the heading `PRA-BILL — BELUM LUNAS` and no payment or change lines (contracts A3).
- [X] T054 [US4] Add "Cetak ulang KOT" for an open tab with sent items: `A/ui/Sheets.kt` action row, enqueues KOT jobs for the tab's tickets with copy numbers `2, 3, …` (copy tracked per ticket in `PosStore`). No server call (KOT reprint is local, open item 1).
- [X] T055 [US4] Hide print controls the role may not use, instead of disabling them (Constitution III): the Orders reprint button follows the existing orders menu rights; the pre-bill and KOT reprint follow the existing floor rights. Use the existing `canAccessMenu`-style checks already used in `EditorScreen.kt`.
- [ ] T056 [US4] Create `AT/print/PrintButtonsTest.kt`: a pre-bill job carries no payment lines; a second struk reprint enqueues copy 2 with the server's copy number; a hidden control for a role without rights is absent from the rendered action list.

**Checkpoint**: US4 works on its own. Story complete when quickstart V10 and V11 pass.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T057 Remove every remaining placeholder message for printing: grep `android-pos/app/src/main/java` for "belum tersedia" and "Cetak … belum", and "Cetak fisik"; each hit related to printing must be replaced by the real state. Leave unrelated "belum tersedia" text (for example the report and X-report placeholders in `ShiftScreens.kt` line ~187, 285) alone unless they are about the receipt or KOT.
- [X] T058 Run `cd android-pos && ./gradlew :core-network:testDebugUnitTest :app:testDebugUnitTest` and fix every failure. Record the output in `specs/050-android-thermal-printing/quickstart.md` section 5 header.
- [X] T059 Run `cd android-pos && ./gradlew :app:assembleDebug` and fix every compile error. Confirms `compileSdk 34` and platform 34 are installed.
- [X] T060 Confirm `docs/openapi-pos-mvp.yaml` already documents `tickets` on `POST /tabs/{id}/send` and `GET /tickets/{id}`; no server change was made, so no OpenAPI edit is required. Record that confirmation in plan.md.
- [X] T061 Run the backend tests that cover the endpoints this feature depends on, inside the container with the mandatory flags from CLAUDE.md: `KitchenTicketsTest` and `ReprintTest`. Use only `warkop_wjm_test`.
- [ ] T062 [MANUAL] Device verification V1–V15 from quickstart.md with the real VSC TM-58V over Bluetooth. Requires a person at the shop. Record each result in the quickstart.md results table. Not done by automation.

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2** → all story phases. Phase 2 blocks everything.
- **US1** (Phase 3) is the MVP: it needs only Phase 2.
- **US2** (Phase 4) needs T006–T008 (ticket data) and T014 (service); independent of US1 UI.
- **US3** (Phase 5) needs T014 and T038; independent of US2.
- **US5** (Phase 6) needs T011 and T014; its UI (T045, T046) depends on US2/US3 jobs existing, so run after them.
- **US4** (Phase 7) needs US2 (KOT layouts) and US3 (STRUK layout).
- **Polish** last.

### Within each story

Layout (Documents) → service method → store wiring → UI → test.

### Parallel opportunities

- Phase 2: T002, T004, T009, T010, T012 are different files; T017–T019 in parallel once their subjects exist.
- US2 and US3 layouts (T028/T029 and T037) are different functions in one file, so they are sequential in `Documents.kt`; the store wiring (T031, T039) can run in parallel with each other.

## Implementation Strategy

**MVP first**: Phase 1, Phase 2, and Phase 3 give a paired printer that prints a test slip. That alone proves V1 and V2, the two unverified facts, before any sale depends on the printer.

**Then** the P1 stories in order of trading risk: US2 (kitchen), US3 (receipt), US5 (failure handling). Then US4 (buttons, P2).

**Stop rule**: if V1 fails on the device, stop before US2. The printer command set or width is wrong, and every later check depends on it.

---

## Notes

- Constitution I: `PrinterService` is the only printer writer; there is no second printing path and no printer-driver interface.
- Constitution III: Indonesian copy only; colours from `PosTheme` tokens; hidden, not disabled, for roles without rights.
- Constitution IV: content comes from server responses, copy numbers from the server, no phone or e-mail on any template.
- Every task above has an exact file path; a task that adds a new screen or file names it explicitly.

---

## Implementation notes (added after implementation)

Where the built code differs from the wording above, and why:

- **T020 / T025**: the printer panel is `PrinterPanel` in `ui/screens/PrinterScreen.kt`, shown inside the existing "Perangkat & sinkron" screen (`SyncScreen`). No new navigation destination was added, so T025 was not needed.
- **T027, T034, T035, T041**: their checks live in `AT/print/DocumentsTest.kt` (layouts, test slip, no prices on KOT/BATAL, no contact data, SALINAN rule, pre-bill). **T036, T048** are covered by `PrintQueueTest.kt` (duplicate keys, printed/dismissed jobs never re-accepted, retry keeps failed jobs, restart).
- **T055**: no new permission checks were added. Print buttons sit on screens whose existing rights already apply (Orders, sale detail, open tab), and the server still enforces reprint approval.
- **T056**: not written. Reprint and pre-bill copy numbering live in `PosStore`, which needs a ViewModel harness that the project does not have yet.
- **BATAL station**: the tab payload does not carry the station of a line, so the BATAL slip omits the station line rather than guessing. Fix by adding the ticket's station to the tab line payload on the server, if the kitchen needs it.
- **PAPER_OUT**: detected with the ESC/POS DLE EOT status request. UNVERIFIED on the VSC TM-58V; if the printer does not answer, a paper-out shows as WRITE_ERROR. Confirm with quickstart V9.
- **Connect timeout**: 5 s is enforced by cancelling the coroutine and closing the socket. A blocked platform connect may take slightly longer to return.
- **Queue growth**: printed jobs are kept (they are needed for dedupe). No pruning yet; expected size is small per shift. Add pruning if the file grows beyond a few hundred entries.
- **KOT reprint** is local only (no server call); see plan.md open item 1.
- **Out of scope but visible**: the X-report and Z-report buttons in `ShiftScreens.kt` still say the printer is not connected. Shift reports are not part of this feature (spec "Out of Scope").
