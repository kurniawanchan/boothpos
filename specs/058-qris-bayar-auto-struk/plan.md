# Implementation Plan: QRIS "Bayar" Button with Automatic Struk

**Branch**: `058-qris-bayar-auto-struk` (not created; work sits on `054-android-pin-layout-fixes` in the tree) | **Date**: 2026-10-18 | **Spec**: [spec.md](./spec.md)

## Summary

Tablet-only change; no server change.

1. **Label (US1):** the QRIS sheet's button reads "Bayar" (`ui/Sheets.kt`, the `QrisRecordDialog` button, currently `FitPillText("Catat · menunggu verifikasi")`). `bayarLabel` (from 055's `CounterLabels.kt`) keeps the waiter wording.
2. **Payment (US2):** `PosStore.recordQris()` already records the QRIS payment through `pay(…, "qr_ewallet", …, channelId)`; keep that call and its idempotency key.
3. **Automatic struk (US2):** today `recordQris` never queues a struk; the cash (`payCash`) and EDC paths do, through `onSaleClosed`, when the order is closed. The QRIS success branch gets the same handling: when the updated order is closed, call `onSaleClosed(updated)` (one struk, copy 1, deduplicated by the print queue key) and go to `Dest.DONE`; otherwise show the remaining amount, as the EDC branch does.
4. **Verification unchanged (US3):** the server keeps the QRIS payment pending; no change to `verifyQris`.

## Technical Context

**Language/Version**: Kotlin, Jetpack Compose (material3 1.3.0, BOM 2024.09.00), `android-pos/`

**Primary Dependencies**: existing `PosStore`, `PrinterService.enqueueStruk`, `PrintQueue` (dedupe by job key), `FitPillText` (055), `bayarLabel` (055)

**Storage**: none new

**Testing**: Android JVM unit tests for the label rule and the post-payment routing rule; manual printer check on the tablet

**Target Platform**: Android tablet (pilot)

**Project Type**: mobile app change only

**Constraints**: the payment rules and the server are unchanged; a print failure must not undo the payment (FR-008).

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I. Code quality | One struk enqueue path (`onSaleClosed`) reused for QRIS; no second print path. The label uses the existing `bayarLabel` rule. | PASS |
| II. Testing | Unit tests for the label and the routing decision (closed → struk and DONE; open → remaining amount, no struk); manual printer check. | PASS (device check pending) |
| III. UX | Indonesian copy; the label change is the only text change. | PASS |
| IV. Security | No change to who can pay or verify; the server still computes amounts. | PASS |
| V. Performance | One extra queue entry per sale, no extra network call. | PASS |
| Stack shape | Works in both server shapes; tablet-only change. | PASS |
| Documentation | No API change, so no OpenAPI change. | PASS |

**Gate result**: PASS. No violations.

## Project Structure

```text
specs/058-qris-bayar-auto-struk/
├── spec.md
├── plan.md        # this file
├── research.md    # R1–R3
├── data-model.md  # no new entities
├── quickstart.md
├── contracts/     # none: no interface change
├── checklists/requirements.md
└── tasks.md       # NOT created here

android-pos/app/src/main/java/com/warkop/pos/
├── ui/Sheets.kt            # EDIT: QRIS button label -> bayarLabel(waiter=false)
├── ui/CounterLabels.kt     # EDIT (if needed): reuse bayarLabel only
└── state/PosStore.kt       # EDIT: recordQris success -> onSaleClosed + DONE when closed
android-pos/app/src/test/java/com/warkop/pos/state/QrisRecordRoutingTest.kt   # NEW
```

**Structure Decision**: a two-line behaviour change in the existing QRIS path and a label change; no new file except the routing rule test.

## Complexity Tracking

> No constitution violations.
