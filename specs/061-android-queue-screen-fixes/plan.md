# Implementation Plan: Payment-Done Screen — Queue Number Fixes and Call Sound

**Branch**: `061-android-queue-screen-fixes` (not created; the repository is on `054-android-pin-layout-fixes`, and the setup script reports this) | **Date**: 2026-10-18 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/061-android-queue-screen-fixes/spec.md`

## Summary

On the "Pembayaran selesai" screen, three changes in `TenderScreen.kt` (`DoneScreen`) plus one new speech path:
1. The queue number is measured and sized to fit its card (today it is a fixed 168 sp that clips).
2. The green check and the "Lunas" badge sit in one row, and the badge text shrinks to fit.
3. "Panggil pelanggan" becomes a real button. Tapping it speaks the queue number in Indonesian through the device's text-to-speech engine, one announcement at a time.

No server or API change. Sale, payment, stock and receipt logic are not touched.

## Technical Context

**Language/Version**: Kotlin, Jetpack Compose (Material 3); Android module `android-pos/app`.

**Primary Dependencies**: Android `android.speech.tts.TextToSpeech` (system engine). No new library.

**Storage**: none. The call is local feedback; nothing is persisted (research R7).

**Testing**: JVM unit tests for the pure parts (`QueuePhrase`, `QueueAnnouncer` with a fake engine, and the size choice); the layout and the sound are verified on the tablet (quickstart).

**Target Platform**: Android tablet (Warkop WJM 3 pilot), landscape and compact widths.

**Project Type**: mobile app (one screen plus one small speech component).

**Performance Goals**: sound starts within 1 second of the tap (spec SC-003); the engine is created once per app run (constitution V).

**Constraints**: device media volume only; no network; a failed or missing engine never blocks the next sale (spec FR-007).

**Scale/Scope**: one screen (`DoneScreen`), 3 small composables, 2 new classes, 1 constructor parameter, 1 application-level instance.

## Constitution Check

*Gate evaluated before Phase 0 and again after Phase 1.*

| Principle | Assessment | Status |
|-----------|------------|--------|
| I. Code Quality | One speech path (`QueueAnnouncer`), one spoken-text rule (`QueuePhrase`), one fit rule (`chooseFontSize`, `FitPillText`). Reused, not copied. | PASS |
| II. Testing | Pure logic has JVM tests (phrase, busy guard, unavailable engine, size choice). Layout and audio are device checks, stated as such. | PASS (device check pending) |
| III. UX Consistency | Indonesian copy ("Dipanggil A-012"); the call button uses the existing pill style. | PASS |
| IV. Security | No network, no data leaves the device; the queue number is not personal data. | PASS |
| V. Performance | One `TextToSpeech` instance per app run, created in `PosApplication` like the printer; no per-tap engine. | PASS |
| Stack constraints | No server, migration, or API change. | PASS |

No violations, so no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/061-android-queue-screen-fixes/
├── plan.md          # this file
├── research.md      # R1–R8 decisions
├── quickstart.md    # device verification steps
├── spec.md
└── checklists/requirements.md
```

No `data-model.md` (no entities, the queue number is an existing field) and no `contracts/` (no interface; the server is not changed).

### Source Code (repository root)

```text
android-pos/app/src/main/java/com/warkop/pos/
├── ui/screens/TenderScreen.kt     # EDIT: DoneScreen — fitted queue number, one-row check+badge, tappable "Panggil pelanggan"
├── ui/Common.kt                   # reuse: FitPillText (for the badge)
├── ui/ChipFit.kt                  # reuse: chooseFontSize (for the queue number)
├── audio/QueueAnnouncer.kt        # NEW: busy guard + engine interface + TextToSpeech implementation
├── audio/QueuePhrase.kt           # NEW: pure "A-012" -> "A, nol, satu, dua"
├── state/PosStore.kt              # EDIT: callQueue(o); new constructor param announcer (default no-op)
├── MainActivity.kt                # EDIT: pass announcer into PosStore
└── PosApplication.kt              # EDIT: create one QueueAnnouncer for the app's life

android-pos/app/src/test/java/com/warkop/pos/
├── audio/QueuePhraseTest.kt       # NEW
├── audio/QueueAnnouncerTest.kt    # NEW: fake engine (busy, done, error, unavailable)
└── state/PosStoreQueueCallTest.kt # NEW: callQueue speaks once and shows "Dipanggil …"
```

**Structure Decision**: a small `audio/` package for the two new classes, because the speech engine is neither data nor UI. The screen change stays in `TenderScreen.kt`, next to the existing queue card.

## Design decisions

- **Queue number fit** (research R1): `BoxWithConstraints` gives the card width; `textMeasurer` measures the number at each size in `[168, 150, 132, 120, 104, 88, 72, 64]`; `chooseFontSize` picks the largest that fits; fallback is the smallest. The fit is one function, pure, so it is testable with a fake width function.
- **Check + badge row** (research R2): one `Row`; the badge uses `FitPillText` inside a weighted slot.
- **Tappable call** (research R3): the "Panggil pelanggan" header is a `Surface` pill with `onClick = { store.callQueue(o) }`.
- **Speech** (research R4–R6): `QueueAnnouncer` wraps a `SpeechEngine` interface. `TtsSpeechEngine` implements it with `TextToSpeech` and locale `id-ID` (fallback to default). `announce(text)` returns `false` while busy or when the engine is not ready; the busy flag clears on the engine's done or error callback.
- **Store** (research R7–R8): `PosStore.callQueue(o)` speaks `QueuePhrase.spoken(o.queue)` and shows "Dipanggil ${o.queue}". The new constructor parameter defaults to a no-op announcer, so existing store tests compile unchanged.

## Complexity Tracking

Not applicable; no violations.
