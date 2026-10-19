# Research: Payment-Done Screen — Queue Number Fixes and Call Sound

Status: resolved 2026-10-18. Sources read: `android-pos/app/src/main/java/com/warkop/pos/ui/screens/TenderScreen.kt` (`DoneScreen`), `ui/ChipFit.kt` (`chooseFontSize`), `ui/QrisSheetLayout.kt` (`titleSizeFor`/`totalSizeFor`), `state/PosStore.kt` (constructor, `say`), `MainActivity.kt`, `PosApplication.kt`, and the screenshot `IMG_2200`.

## R1. Why the queue number is cropped

- **Verified**: `DoneScreen` draws `o.queue` at a fixed `168.sp` (landscape) or `88.sp` (compact) with `maxLines = 1` and `softWrap = false`, and nothing measures it against the card. A number wider than the card is drawn past the card edge, so its last character is cut.
- **Decision**: measure the text and pick the largest size that fits the card width, the same measure-and-pick pattern `QrisSheetLayout` already uses (`titleSizeFor`, `totalSizeFor`), with `chooseFontSize` from `ChipFit.kt`.
- **Sizes** (sp, largest first): 168, 150, 132, 120, 104, 88, 72, 64. Fallback when none fits: the smallest size, so the number is never cut (spec FR-002).
- **Rationale**: no hard-coded size can match every queue length (`A-012` vs `A-1234`), and the card width differs between landscape and compact.
- **Alternatives rejected**: `softWrap = true` (splits `A-012` over two lines, which reads wrong when called out); `Modifier.basicMarquee` (scrolls, so the number is not readable at a glance).

## R2. Checkmark and "Lunas" on one line

- **Verified**: the check is a 56 dp `Box` above a `Pill` in one `Column`, so they sit on two rows.
- **Decision**: put the check and the badge in one `Row` (vertical centre). The badge takes the remaining width and shrinks its text to fit (spec FR-004) through a new `FitPill(text, container, content)` in `ui/Common.kt`: the same look as `Pill` (coloured, rounded), with its size chosen by the existing `chooseFontSize`.
- **Correction (2026-10-18)**: `FitPillText` (055) is a button-label helper with its own colours, not a coloured badge, so it can't be reused here. `Pill` itself does not shrink; it is fixed at 12 sp.
- **Rationale**: one fitting rule (`chooseFontSize`), with no copy of the sizing logic.

## R3. "Panggil pelanggan" is not tappable today

- **Verified**: the header is `Row { Icon; Text("Panggil pelanggan") }` inside the card `Surface`, with no `onClick`. Tapping it does nothing.
- **Decision**: turn the header into a real button (a `Surface` pill with `onClick`, icon, and label). The rest of the card stays static, so an accidental tap on the number does nothing.
- **Alternative rejected**: making the whole card clickable (large target that is easy to hit by mistake on a busy counter).

## R4. Speech engine

- **Decision**: Android `TextToSpeech` (system engine), with locale `id-ID`. If the device has no Indonesian voice, fall back to the device's default locale (spec assumption).
- **Rationale**: speaks any queue code without recording audio for each digit and letter; no network needed; the device's own engine is already present on Android tablets.
- **Alternatives rejected**: bundled audio clips for the ten digits and the letters (needs recording and maintenance, and breaks if a new prefix is added); a chime only (spec says the number is spoken).
- **Audio**: default TTS stream (media volume, spec edge case). The app does not change the device volume.
- **Trade-off to verify on the tablet**: the Indonesian voice quality depends on the installed engine. Recorded in quickstart.

## R5. What is said

- **Decision**: a pure function `QueuePhrase.spoken(queue)`. It keeps letters and digits only, reads each digit as its Indonesian word (0 nol, 1 satu, 2 dua, 3 tiga, 4 empat, 5 lima, 6 enam, 7 tujuh, 8 delapan, 9 sembilan), and joins the parts with ", ". Example: `A-012` → `A, nol, satu, dua`. Letters are spoken as the letter itself.
- **Rationale**: pure and testable on the JVM without an Android engine; the spoken form lives in one place.

## R6. Tap during an announcement

- **Decision**: a `QueueAnnouncer` holds a `busy` flag. `announce()` returns `false` and does nothing while busy; the flag is cleared on the engine's done or error callback. Engine not ready or missing → `false`, no crash.
- **Rationale**: spec FR-008 (no overlapping announcements) and FR-007 (no block when sound fails). The engine sits behind a small interface, so the state logic is testable with a fake engine.

## R7. "Recorded as done" (FR-007)

- **Verified**: there is no server endpoint for calling a queue number, and the queue number already comes from the server on the sale.
- **Decision**: the call is local feedback. `PosStore.callQueue` shows the store message "Dipanggil A-012" and does not write to the server. Nothing is persisted.
- **Assumption**: "recorded as done" in the spec means the app shows the call as done on screen. If the shop wants the call logged on the server, that is a new API and a separate feature.

## R8. Where the code lives

- **Decision**: `QueueAnnouncer` (speech state and engine) and `QueuePhrase` (spoken text) in `state/` or a small `audio/` package; `PosApplication` creates one `TextToSpeech`-backed announcer for the app's life, like the printer; `MainActivity` passes it into `PosStore`; `PosStore` gets one new constructor parameter with a no-op default so existing tests keep compiling.
- **Rationale**: one speech path (constitution I); the engine is created once, not per tap (constitution V).
