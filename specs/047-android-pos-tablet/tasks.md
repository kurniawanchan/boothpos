# Tasks: Aplikasi POS Android untuk Tablet

**Input**: Design documents from `/specs/047-android-pos-tablet/`

---

## Phase 1: Setup (Shared Infrastructure)

- [x] T001 Create Android project structure in `android-pos/` (settings.gradle.kts, build.gradle.kts)
- [ ] T002 Initialize Kotlin Gradle build with required plugins in `android-pos/build.gradle.kts`
- [ ] T003 [P] Configure linting and formatting tools (ktlint, .editorconfig) in `android-pos/`
- [ ] T004 [P] Add version catalog in `android-pos/gradle/libs.versions.toml`

---

## Phase 2: Foundational (Blocking Prerequisites)

- [ ] T005 Setup DataStore encryption utilities in `android-pos/core-domain/src/util/DataStoreEncryptor.kt`
- [ ] T006 [P] Setup Hilt dependency injection in `android-pos/app/src/main/java/com/warkop/di/AppComponent.kt`
- [ ] T007 [P] Configure Retrofit API client in `android-pos/core-network/src/network/ApiService.kt`
- [ ] T008 [P] Define ApiCapabilities model in `android-pos/core-domain/src/model/ApiCapabilities.kt`
- [ ] T009 Implement token storage helper in `android-pos/core-domain/src/util/TokenStore.kt`
- [ ] T010 [P] Setup logging with Timber in `android-pos/app/src/main/java/com/warkop/util/Logging.kt`

---

## Phase 3: User Story 1 – Masuk dan membuka shift (Priority: P1)

**Goal**: Staff can log in with PIN and open shift.

- [ ] T011 [US1] Create Session model in `android-pos/core-domain/src/model/Session.kt`
- [ ] T012 [US1] Implement login UI composable in `android-pos/app/src/main/java/com/warkop/ui/login/LoginScreen.kt`
- [ ] T013 [US1] Implement PIN entry screen in `android-pos/app/src/main/java/com/warkop/ui/pin/PinScreen.kt`
- [ ] T014 [US1] Add authentication flow in `android-pos/core-network/src/repo/AuthRepository.kt` (depends on T007, T009)
- [ ] T015 [US1] Implement shift opening UI in `android-pos/app/src/main/java/com/warkop/ui/shift/ShiftOpenScreen.kt`
- [ ] T016 [US1] Implement shift repository in `android-pos/core-network/src/repo/ShiftRepository.kt`
- [ ] T017 [P] [US1] Add idle lock timer utility in `android-pos/app/src/main/java/com/warkop/util/IdleTimer.kt`

---

## Phase 4: User Story 2 – Membuat pesanan dan mengirim ke dapur (Priority: P1)

**Goal**: Staff can create a tab, add items with modifiers, and send to kitchen.

- [ ] T018 [US2] Create MenuItem model in `android-pos/core-domain/src/model/MenuItem.kt`
- [ ] T019 [US2] Implement menu screen UI in `android-pos/app/src/main/java/com/warkop/ui/menu/MenuScreen.kt`
- [ ] T020 [US2] Create Tab model in `android-pos/core-domain/src/model/Tab.kt`
- [ ] T021 [US2] Implement Tab repository in `android-pos/core-network/src/repo/TabRepository.kt`
- [ ] T022 [US2] Add add‑item-to‑tab flow in `android-pos/app/src/main/java/com/warkop/ui/tab/TabViewModel.kt`
- [ ] T023 [US2] Implement modifier UI in `android-pos/app/src/main/java/com/warkop/ui/modifier/ModifierScreen.kt`
- [ ] T024 [US2] Implement send‑to‑kitchen printer integration in `android-pos/core-printing/src/PrintJobManager.kt`
- [ ] T025 [P] [US2] Add retry logic for printer failures in `android-pos/core-printing/src/PrintJobManager.kt`

---

## Phase 5: User Story 3 – Menerima pembayaran dan menutup pesanan (Priority: P1)

**Goal**: Cashiers can process cash, EDC, QRIS payments and close the tab.

- [ ] T026 [US3] Create Tender model in `android-pos/core-domain/src/model/Tender.kt`
- [ ] T027 [US3] Implement payment UI in `android-pos/app/src/main/java/com/warkop/ui/payment/PaymentScreen.kt`
- [ ] T028 [US3] Implement payment repository in `android-pos/core-network/src/repo/PaymentRepository.kt`
- [ ] T029 [P] [US3] Add cash change calculator utility in `android-pos/app/src/main/java/com/warkop/util/CashChangeCalculator.kt`
- [ ] T030 [P] [US3] Implement QRIS/EDC pending handling in `android-pos/app/src/main/java/com/warkop/ui/payment/PaymentPendingHandler.kt`

---

## Phase 6: User Story 4 – Persetujuan atasan (Priority: P1)

**Goal**: Sensitive actions require supervisor approval.

- [ ] T031 [US4] Create approval dialog UI in `android-pos/app/src/main/java/com/warkop/ui/approval/ApprovalDialog.kt`
- [ ] T032 [US4] Implement approval API call in `android-pos/core-network/src/repo/ApprovalRepository.kt`
- [ ] T033 [US4] Integrate approval flow into sensitive actions (e.g., void line) in `android-pos/app/src/main/java/com/warkop/ui/tab/TabViewModel.kt`

---

## Phase 7: User Story 5 – Menjalankan dan menutup shift (Priority: P2)

**Goal**: Managers can view shift summary and close shift with cash denomination.

- [ ] T034 [US5] Implement shift summary UI in `android-pos/app/src/main/java/com/warkop/ui/shift/ShiftSummaryScreen.kt`
- [ ] T035 [US5] Implement shift close repository in `android-pos/core-network/src/repo/ShiftCloseRepository.kt`
- [ ] T036 [P] [US5] Add cash denomination input UI in `android-pos/app/src/main/java/com/warkop/ui/shift/CashDenominationScreen.kt`

---

## Phase 8: User Story 6 – Daftar order, struk, dan 86 (Priority: P2)

**Goal**: Staff can view order list, generate receipts, and print them.

- [ ] T037 [US6] Implement order list screen in `android-pos/app/src/main/java/com/warkop/ui/orders/OrderListScreen.kt`
- [ ] T038 [US6] Implement receipt generation utility in `android-pos/app/src/main/java/com/warkop/util/ReceiptGenerator.kt`
- [ ] T039 [US6] Add print receipt functionality via `android-pos/core-printing/src/PrintJobManager.kt`

---

## Phase 9: User Story 7 – Status perangkat dan koneksi (Priority: P3)

**Goal**: UI shows device health and connection status.

- [ ] T040 [US7] Implement device health monitor in `android-pos/app/src/main/java/com/warkop/util/DeviceHealthMonitor.kt`
- [ ] T041 [P] [US7] Show connection status bar in `android-pos/app/src/main/java/com/warkop/ui/status/ConnectionStatusBar.kt`

---

## Phase N: Polish & Cross‑Cutting Concerns

- [ ] T042 Documentation updates in `docs/README.md` [P]
- [ ] T043 Code cleanup and refactoring across the codebase [P]
- [ ] T044 Performance optimizations for UI rendering [P]
- [ ] T045 Security hardening (secure token handling, no logs of sensitive data) [P]
- [ ] T046 Run quickstart validation script (`quickstart.md`) [P]

---

## Dependencies & Execution Order

- Phase 1 must complete before Phase 2.
- Phase 2 blocks all user story phases.
- User stories can be worked on in parallel after Phase 2.
- Polish phase depends on completion of selected user stories.

---

## Parallel Opportunities

- All tasks marked `[P]` are parallelizable across files with no inter‑dependency.
- After foundational phase, each user story (US1‑US7) can be assigned to separate developers.
- Tests (if added) can run concurrently.

---

## Implementation Strategy

- **MVP**: Complete Phase 1, Phase 2, and User Story 1 (US1) then validate.
- Incrementally add remaining user stories following priority order.

---

## Summary

- Total tasks: 46
- Tasks per user story:
  - US1: 7
  - US2: 8
  - US3: 5
  - US4: 3
  - US5: 3
  - US6: 3
  - US7: 2
- Parallel‑ready tasks: 22
- Suggested MVP scope: User Story 1 only.

---

## Extension Hooks

**Optional Pre-Hook**: git
Command: `/speckit-git-commit`
Description: Auto-commit before task generation

Prompt: Commit task changes?
To execute: `/speckit-git-commit`

**Optional Hook**: git
Command: `/speckit-git-commit`
Description: Auto-commit after task generation

Prompt: Commit task changes?
To execute: `/speckit-git-commit`
