# Tasks: QRIS "Bayar" Button with Automatic Struk

**Input**: `specs/058-qris-bayar-auto-struk/` (plan.md, spec.md, research.md, data-model.md, quickstart.md)

**Tests**: Included for the label rule and the routing decision (constitution II). The printer and the device-side closing check are manual tasks.

**Organization**: by user story (spec.md US1–US3). Tablet-only change; no server change, no contracts.

**Open item carried in**: R1 — whether a pending QRIS payment closes the order. Task T008 records the result on the device; if the order stays open, T006 changes (see note there).

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (baselines)

- [X] T001 Android baseline: `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Record the result.

---

## Phase 2: Foundational

No shared blocking work: the label helper (`ui/CounterLabels.kt`, 055) and the struk queue (`print/PrinterService.kt`) already exist and are reused.

---

## Phase 3: User Story 1 - Button says "Bayar" (Priority: P1) 🎯 MVP

**Goal**: the QRIS sheet button reads "Bayar" in full.

**Independent Test**: quickstart step 1.

- [X] T002 [US1] In `android-pos/app/src/main/java/com/warkop/pos/ui/Sheets.kt` `QrisRecordDialog` (line ~534): replace `FitPillText("Catat · menunggu verifikasi")` with `FitPillText(bayarLabel(waiter = false))`. Keep the arrow icon, the spacer, and `Modifier.height(52.dp)`.
- [X] T003 [US1] Add a test to `android-pos/app/src/test/java/com/warkop/pos/ui/CounterLabelsTest.kt` (created in 055): `bayarLabel(waiter = false)` is "Bayar" with no "verifikasi" and no amount (the existing waiter case stays).
- [ ] T004 [US1] Manual check: quickstart step 1 (label whole, both orientations). Record evidence.

---

## Phase 4: User Story 2 - "Bayar" takes the payment and prints the struk (Priority: P1)

**Goal**: after a QRIS payment is recorded, the struk is queued once for printing, and the screen moves to the finished view when the sale is closed; otherwise it shows the remaining amount.

**Independent Test**: quickstart steps 2, 4 and 5.

- [X] T005 [US2] Test first: create `android-pos/app/src/test/java/com/warkop/pos/state/QrisRecordRoutingTest.kt`. Extract the routing decision into a pure function in `PosStore.kt` (or a top-level `internal fun qrisAfterPayment(closed: Boolean, remaining: Int): QrisNext`) with cases: closed → `QrisNext.Finish`; open → `QrisNext.ShowRemaining(remaining)`. The test must fail before T006 (the function does not exist yet).
- [X] T006 [US2] In `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` `recordQris()` (line ~934): after `upsert(updated)` and `sheet = null`, branch on `qrisAfterPayment(updated.closed, updated.remaining)`: `Finish` → `onSaleClosed(updated)` then `dest = Dest.DONE`; `ShowRemaining` → `say("Sisa ${rp(updated.remaining)}.")`. Keep the existing "QRIS dicatat · menunggu verifikasi." message for the Finish branch. Keep `keys.settled(intent)`, `conn` and the error branch unchanged.
  - **Note (R1)**: if the device check (T008) shows the order stays open after a pending QRIS payment, instead enqueue the struk right after a successful payment (`onSaleClosed(updated)` without changing `dest`), and record the change in research.md R1.
- [X] T007 [US2] Run `./gradlew --offline :app:testDebugUnitTest --tests '*QrisRecordRoutingTest*'`. Record the result.
- [ ] T008 [US2] Manual check on the device with the printer connected: take a QRIS payment for the full amount. Record whether the order closes (screen moves to the finished view) and whether one struk prints without another tap (quickstart step 2). Update research.md R1 with the result.
- [ ] T009 [US2] Manual check: printer disconnected, take a payment: the struk waits in the queue and prints after reconnecting (quickstart step 4).
- [ ] T010 [US2] Manual check: double tap "Bayar" quickly: one payment and one struk (quickstart step 5).

---

## Phase 5: User Story 3 - Payment still needs verification (Priority: P2)

**Goal**: a QRIS payment taken with "Bayar" still shows "Menunggu verifikasi" until verified.

**Independent Test**: quickstart step 3.

- [X] T011 [US3] No code change: `verifyQris` and the server rules stay as they are (FR-004, FR-009). Confirm by reading `PosStore.kt` `verifyQris` that `recordQris` is the only change in this feature.
- [ ] T012 [US3] Manual check: quickstart step 3 (sale shows "Menunggu verifikasi" until verified). Record evidence.

---

## Final Phase: Polish & cross-cutting

- [X] T013 Full Android unit suite: `./gradlew --offline :app:testDebugUnitTest`. Record the result.
- [X] T014 Confirm the diff is limited to `Sheets.kt`, `PosStore.kt`, and the two test files (no server or API files changed).
- [ ] T015 Walk through `quickstart.md` on the pilot tablet and record evidence. Remove debug logging.

---

## Dependencies & Execution Order

- **Setup (T001)** first.
- **US1 (T002–T004)** and **US2 (T005–T010)** touch different functions (`QrisRecordDialog` label and `recordQris` routing); T005 must be written before T006.
- **US3 (T011–T012)** after US2.
- **Polish (T013–T015)** last.

### Parallel opportunities

- T002 and T005 (label and routing test) can be done in parallel: different files.
- T003 with T005: both are test files.

## Implementation Strategy

- **MVP**: US1 + US2 — the label and the automatic struk on a full QRIS payment.
- **Increment 2**: US2 edge cases (T009, T010) and US3 check.
- Each increment ends with its device evidence.

## Task count

- Total: **15 tasks** (T001–T015).
- Setup: 1 · Foundational: 0 · US1: 3 · US2: 6 · US3: 2 · Polish: 3.
- Parallel-marked: T002/T005, T003/T005.
- Format check: every task has a checkbox, a sequential ID, a `[USn]` label in story phases only, and a file path or named command/manual check.
