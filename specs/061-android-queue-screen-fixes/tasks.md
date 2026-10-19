# Tasks: Payment-Done Screen — Queue Number Fixes and Call Sound

**Input**: `specs/061-android-queue-screen-fixes/` (plan.md, spec.md, research.md R1–R8, quickstart.md)

**Tests**: JVM unit tests for the pure and state logic (`QueuePhrase`, the size choice, `QueueAnnouncer` with a fake engine, `PosStore.callQueue`). Layout and sound are device checks (quickstart), since no unit test can see the screen or hear the voice.

**Organization**: by user story. US1 = full queue number (P1), US3 = call sound (P1), US2 = check and badge on one line (P2). US1 and US3 both edit `DoneScreen` in `TenderScreen.kt`, so they run one after another, not in parallel.

**Paths**: `android-pos/app/src/main/java/com/warkop/pos/` is written as `…/pos/` below; tests live under `android-pos/app/src/test/java/com/warkop/pos/`.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (baselines)

- [X] T001 Baseline from `android-pos/`: `./gradlew --offline :app:assembleDebug` and `./gradlew --offline :app:testDebugUnitTest`. Record both results and the test count (feature 060 left 167 tests, 0 failures).

---

## Phase 2: Foundational (shared pure pieces)

**Purpose**: the size choice and the spoken text, which US1 and US3 both need, testable before any screen edit.

- [X] T002 [P] Create `…/pos/ui/QueueNumberLayout.kt` with `fun queueSizeFor(textWidthAt: (Float) -> Int, available: Int): Float` — `chooseFontSize` over `listOf(168f, 150f, 132f, 120f, 104f, 88f, 72f, 64f)`; returns the smallest size when none fits (never cuts the number).
- [X] T003 [P] Create `android-pos/app/src/test/java/com/warkop/pos/ui/QueueNumberLayoutTest.kt`: the largest size is picked when it fits; a narrower width picks a smaller size; nothing fits → 64; a fake width function that grows with size.
- [X] T004 [P] Create `…/pos/audio/QueuePhrase.kt` with `object QueuePhrase { fun spoken(queue: String): String }`: keeps letters and digits only; each digit becomes its Indonesian word (0 nol, 1 satu, 2 dua, 3 tiga, 4 empat, 5 lima, 6 enam, 7 tujuh, 8 delapan, 9 sembilan); parts joined with ", " (`A-012` → `A, nol, satu, dua`).
- [X] T005 [P] Create `android-pos/app/src/test/java/com/warkop/pos/audio/QueuePhraseTest.kt`: `A-012` → `A, nol, satu, dua`; `B-7` → `B, tujuh`; `A012` gives the same as `A-012`; lower case `a-1` → `A, satu`; `A-` → `A`; blank → empty string.

**Checkpoint**: size choice and spoken text exist and are tested.

---

## Phase 3: User Story 1 - Read the queue number in full (Priority: P1) 🎯 MVP

**Goal**: the queue number on the payment-done card is never cut; it shrinks to fit (spec FR-001, FR-002).

**Independent Test**: quickstart step 2 (landscape and compact, long queue such as `A-1234`).

- [X] T006 [US1] Edit `…/pos/ui/screens/TenderScreen.kt` `DoneScreen`: around the `Text(o.queue, …)` in the queue card, use `BoxWithConstraints` for the card's inner width; `rememberTextMeasurer()` and `LocalDensity` give `textWidthAt(sp)` in pixels (`TextStyle(fontSize = sp.sp, fontWeight = FontWeight.Light)`); size = `queueSizeFor(…, availablePx)`. Replace the fixed `if (compact) 88.sp else 168.sp` with that size. Keep `maxLines = 1`, `softWrap = false`. Depends on T002.
- [ ] T007 [US1] Manual check (device): quickstart step 2 — the whole number is visible in landscape and compact; a long queue shrinks. Record evidence. Depends on T006.

**Checkpoint**: US1 independently checkable.

---

## Phase 4: User Story 3 - Hear the queue number when calling the customer (Priority: P1)

**Goal**: tapping "Panggil pelanggan" speaks the queue number in Indonesian, one announcement at a time, and a failure never blocks the next sale (spec FR-005 to FR-009).

**Independent Test**: quickstart steps 4–8.

- [X] T008 [P] [US3] Create `…/pos/audio/QueueAnnouncer.kt`: `interface SpeechEngine { val ready: Boolean; fun speak(text: String, onDone: () -> Unit, onError: () -> Unit) }`; `object SilentSpeech : SpeechEngine` (not ready, speaks nothing); `class QueueAnnouncer(private val engine: SpeechEngine)` with a `busy` flag and `fun announce(text: String): Boolean` — returns false when busy or not ready; sets busy before speaking; clears busy in both callbacks.
- [X] T009 [US3] Create `…/pos/audio/TtsSpeechEngine.kt`: `class TtsSpeechEngine(context: Context) : SpeechEngine` wrapping `android.speech.tts.TextToSpeech`; `setLanguage(Locale("id", "ID"))`, falling back to `Locale.getDefault()` when the Indonesian voice is missing; `ready` true after init succeeds; `speak` uses `UtteranceProgressListener` for `onDone` / `onError`. Depends on T008.
- [X] T010 [US3] Create `android-pos/app/src/test/java/com/warkop/pos/audio/QueueAnnouncerTest.kt` with a `FakeSpeechEngine` (records calls, lets the test fire done/error): speaks when idle; a second call while busy returns false and does not speak; done clears busy; error clears busy; not ready → false, nothing spoken, no exception. Depends on T008.
- [X] T011 [US3] Edit `…/pos/state/PosStore.kt`: add constructor parameter `private val announcer: QueueAnnouncer = QueueAnnouncer(SilentSpeech)` (last parameter, default keeps existing tests compiling); add `fun callQueue(o: Order)`: if `o.queue` is null or blank, do nothing; otherwise `announcer.announce(QueuePhrase.spoken(o.queue))` (result ignored, FR-007) and `say("Dipanggil ${o.queue}")`. Depends on T004, T008.
- [X] T012 [US3] Create `android-pos/app/src/test/java/com/warkop/pos/state/PosStoreQueueCallTest.kt`: `callQueue` on an order with `A-012` speaks `A, nol, satu, dua` and sets the message `Dipanggil A-012`; an order with no queue does not speak and sets no message; a tap while the announcer is busy still sets the message (FR-007). Depends on T011.
- [X] T013 [US3] Edit `…/pos/PosApplication.kt` `AppContainer`: add `val queueAnnouncer = QueueAnnouncer(TtsSpeechEngine(app))`, one per app run, next to `printer` (constitution V). Depends on T009.
- [X] T014 [US3] Edit `…/pos/MainActivity.kt`: pass `container.queueAnnouncer` into the `PosStore(…)` call (the `store` factory, next to `container.printer`). Depends on T011, T013.
- [X] T015 [US3] Edit `…/pos/ui/screens/TenderScreen.kt` `DoneScreen`: turn the "Panggil pelanggan" header row into a `Surface` pill (`RoundedCornerShape(50)`) with `onClick = { store.callQueue(o) }`, `Modifier.semantics { role = Role.Button }`, containing the existing icon and label. Only the header is clickable; the number stays static. Depends on T006.
- [X] T016 [US3] Run `./gradlew --offline :app:testDebugUnitTest --tests '*QueueAnnouncerTest*' --tests '*PosStoreQueueCallTest*' --tests '*QueuePhraseTest*' --tests '*QueueNumberLayoutTest*'`. Record results. Depends on T003, T005, T010, T012.
- [ ] T017 [US3] Manual check (device): quickstart steps 4 (tap → spoken number, time to first sound), 5 (double tap → once), 6 (muted → "Dipanggil …", next sale works), 7 (voice used), 8 (no queue → nothing). Record evidence. Depends on T015.

**Checkpoint**: US3 independently checkable.

---

## Phase 5: User Story 2 - Paid status on one line (Priority: P2)

**Goal**: the green check and the "Lunas · …" badge share one row; the badge text shrinks rather than wrapping (spec FR-003, FR-004).

**Independent Test**: quickstart step 3.

- [X] T018 [P] [US2] Edit `…/pos/ui/Common.kt`: add `@Composable fun FitPill(text: String, container: Color, content: Color, modifier: Modifier = Modifier)` — same look as `Pill` (rounded `Surface`, padding 10 dp × 3 dp, medium weight, `maxLines = 1`, `softWrap = false`), font size from `chooseFontSize` over `listOf(12f, 11f, 10f)` with `BoxWithConstraints` for the available width; fallback 10 sp. `Pill` stays unchanged for other callers. Depends on T001.
- [X] T019 [US2] Edit `…/pos/ui/screens/TenderScreen.kt` `DoneScreen`: replace the `Box` (check) + `Spacer` + `Pill("Lunas · …")` column with one `Row(verticalAlignment = Alignment.CenterVertically)`: the check `Surface` stays as it is, then `Spacer(Modifier.width(10.dp))`, then `FitPill("Lunas · ${o.receiptNo}", …)` in a `Modifier.weight(1f)` slot. Depends on T018, T006.
- [ ] T020 [US2] Manual check (device): quickstart step 3 — the check and the badge share one row; a long transaction number shrinks in the badge. Record evidence. Depends on T019.

**Checkpoint**: US2 independently checkable.

---

## Final Phase: Polish & cross-cutting concerns

- [X] T021 Full unit suite: `./gradlew --offline :app:testDebugUnitTest` from `android-pos/`. Record the result (count = T001 + new tests; 0 failures).
- [X] T022 Build: `./gradlew --offline :app:assembleDebug`. Record the result.
- [X] T023 Scope check: `git status` shows changes only under `android-pos/app/src/main/java/com/warkop/pos/` (ui, audio, state, PosApplication, MainActivity) and `android-pos/app/src/test/…/pos/` (new tests), plus `specs/061-android-queue-screen-fixes/`. No change under `app/` (Laravel), `routes/`, `docs/openapi-pos-mvp.yaml`, or `android/`. Record the result.
- [X] T024 [P] Confirm the Indonesian copy: the message is `Dipanggil …` and the spoken text matches `QueuePhrase`; no English string added to the screen.
- [ ] T025 Walk through `quickstart.md` steps 1–8 on the pilot tablet; record evidence and the voice used. Depends on T007, T017, T020.

---

## Dependencies & Execution Order

- **Setup (T001)** first.
- **Foundational (T002–T005)**: all four in parallel (different files).
- **US1 (T006–T007)**: T006 after T002; T007 after T006.
- **US3 (T008–T017)**: T008 first; T009 and T010 after T008 (T009 and T010 in parallel); T011 after T004 and T008; T012 after T011; T013 after T009; T014 after T011 and T013; T015 after T006 (same file as T006, so after it); T016 after the tests; T017 after T015.
- **US2 (T018–T020)**: T018 after T001 (in parallel with US1/US3 since it is a different file); T019 after T018 and T006 (same file as T006/T015, so sequential after them).
- **Polish (T021–T025)** last. T024 can run alongside T021.

### Parallel opportunities

- T002, T003, T004, T005 (foundational pure pieces).
- T008 with T018 (different files).
- T009 and T010 after T008.

## Implementation Strategy

- **MVP**: Setup + Foundational + US1 — the queue number always visible. This is the cropping fix the cashier hits first.
- **Increment 2**: US3 — call sound and the tappable "Panggil pelanggan".
- **Increment 3**: US2 — check and badge on one row.
- **Final**: Polish, scope check, device walkthrough.

## Task count

- Total: **25 tasks** (T001–T025).
- Setup: 1 · Foundational: 4 · US1: 2 · US3: 10 · US2: 3 · Polish: 5.
- Parallel-marked: T002, T003, T004, T005, T008, T018, T024.
- Format check: every task has a checkbox, a sequential ID, a `[USn]` label only in story phases, and a file path or named command.
