# Implementation Plan: Android POS Phase 3 — Static QRIS Sheet Layout

**Branch**: `056-android-qris-sheet-layout` (not created; work sits on `054-android-pin-layout-fixes` in the tree) | **Date**: 2026-10-18 | **Spec**: [spec.md](./spec.md)

**Input**: `specs/056-android-qris-sheet-layout/spec.md`

## Summary

The static QRIS sheet (`QrisRecordDialog` in `ui/Sheets.kt`) changes presentation only:

1. **Title** reads "{provider} {account holder}" from the channel (FR-001/002).
2. **Warning** text smaller than the title, fully visible (FR-004).
3. **QR image** is the channel's real image, centred; the placeholder icon and the hard-coded footer "WARKOP WJM 3 · NMID ID10••••" are removed; the standee text is removed (FR-005–007).
4. **Total** on its own line, one line, sized to fit; the instruction wraps fully below it (FR-008, FR-009).
5. **Button** label "Catat · menunggu verifikasi" fits its button on one line (FR-010).

Root cause of the data gap, verified in code: `RemoteBackend.qrisChannel()` keeps only `provider` (`QrisChannel(id, name)`). The server already returns `account_name` and `qr_image_url` on `GET /payment-channels` (`PaymentChannelController::index`), and the tablet's DTO drops them. The sheet also hard-codes the footer text and draws a placeholder icon instead of the image.

Backend: **no change**. Android only.

## Technical Context

**Language/Version**: Kotlin, Jetpack Compose (Material 3 1.3.0; Compose BOM 2024.09.00), `android-pos/`

**Primary Dependencies**: existing OkHttp 4.12.0 (already in `core-network`) for the image download; Android `BitmapFactory`; existing `ChipFit` (054) and `FitPillText` (055) for fitting text. No new library (Coil is not in the offline cache; decision R2).

**Storage**: none new. Image bytes held in an in-memory cache for the process lifetime, keyed by URL.

**Testing**: Android JVM unit tests (`testDebugUnitTest`) for the title formatter, the channel mapping, and the fit decisions; manual tablet check for the layout and image.

**Target Platform**: Android tablet (pilot, landscape reference).

**Project Type**: mobile app change only.

**Performance Goals**: sheet opens without waiting for the image; the QR appears when the download finishes; one download per URL per process.

**Constraints**: the image URL is served from the public disk (server comment, `PaymentChannelController`), so no authorization header is needed. The account number is masked server-side and is not shown on the sheet.

**Scale/Scope**: one static QRIS channel per store; a few screens' worth of code.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I. Code quality | One QRIS channel mapping (`RemoteBackend.qrisChannel` → `QrisChannel`); the title rule is one pure function used by the sheet; one image loader used by the sheet. Text fitting reuses `chooseFontSize` (054) and `FitPillText` (055). | PASS |
| II. Testing | Unit tests for the title rule, the channel mapping, and fit decisions; the tablet check is recorded as manual. | PASS (tablet check pending) |
| III. UX | Design tokens, Material 3; Indonesian copy ("QR belum diatur"); the warning keeps its wording. | PASS |
| IV. Security | The QR URL is the public image the POS already loads (same disk as before); no secret reaches the tablet; the account number is not added to the mapping. Recording rules are unchanged (FR-011). | PASS |
| V. Performance | One request per channel list (already made); image cached per URL; no N+1; the sheet does not block on the image. | PASS |
| Stack shape | Works in both shapes: the URL comes from the same API, local or shared. | PASS |
| Documentation | No route change, so no OpenAPI change; the existing `payment-channels` response already documents `account_name` and `qr_image_url`. | PASS |

**Gate result**: PASS. No violations; Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/056-android-qris-sheet-layout/
├── spec.md
├── plan.md              # this file
├── research.md          # R1–R6
├── data-model.md        # channel fields on the tablet
├── quickstart.md        # verification steps
├── contracts/
│   └── payment-channels-tablet.md  # fields the tablet reads (server unchanged)
├── checklists/requirements.md
└── tasks.md             # NOT created here (/speckit-tasks)
```

### Source Code (repository root)

```text
android-pos/core-network/src/main/java/com/warkop/core/network/dto/ActionDtos.kt
    # EDIT: PaymentChannelDto gains qr_image_url (String?) — account_name already present

android-pos/app/src/main/java/com/warkop/pos/model/PosModels.kt
    # EDIT: QrisChannel(id, name) → QrisChannel(id, provider, accountName, qrImageUrl)

android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt
    # EDIT: qrisChannel() maps provider, account_name, qr_image_url (through QrisMapper)

android-pos/app/src/main/java/com/warkop/pos/data/QrisMapper.kt       # NEW: pure mapping + sheet title rule
android-pos/app/src/main/java/com/warkop/pos/data/QrImageLoader.kt    # NEW: OkHttp download + BitmapFactory decode + in-memory cache

android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt
    # EDIT: keep the loaded channel (qrisChannel) next to qrisLabel; title and footer read it

android-pos/app/src/main/java/com/warkop/pos/ui/Sheets.kt             # EDIT: QrisRecordDialog layout (title, warning size, centred QR, total block, instruction, button)
android-pos/app/src/main/java/com/warkop/pos/ui/QrisSheetLayout.kt    # NEW: pure fit decisions for the total and the title (unit-testable)

android-pos/app/src/test/java/com/warkop/pos/data/QrisMapperTest.kt       # NEW
android-pos/app/src/test/java/com/warkop/pos/ui/QrisSheetLayoutTest.kt    # NEW
```

**Structure Decision**: extend the existing channel mapping and the existing sheet. Two small new files hold the pure rules (mapping and layout decisions) so they can be tested without a device, and one loader holds the image download. No new screen, no migration, no backend change.

## Complexity Tracking

> No constitution violations. Nothing to justify.
