# Implementation Plan: Thermal Printer for the Android POS

**Branch**: `050-android-thermal-printing` | **Date**: 2026-10-17 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/050-android-thermal-printing/spec.md`

**Classification**: INTERNAL

## Summary

Pair one **VSC TM-58V** 58 mm thermal printer with the Android POS tablet over **Bluetooth**, then print four documents without a system dialog: the **struk** (receipt) after payment, the **KOT** (kitchen order ticket) when items are sent, the **pre-bill**, and the **BATAL** slip when a sent item is voided. Manual print buttons cover reprints. A failed print never blocks a sale or a kitchen send: the job goes into a local **Antrean cetak** and is retried later.

The approach is **Android-only**. The server is not changed: the send response already returns the kitchen tickets per station (`tickets[]`), the receipt endpoint and the reprint endpoint already exist, and the pre-bill action already stamps `prebill_at`. The work is (1) parse the tickets the app currently discards, (2) a single printer service that owns the Bluetooth link, a pure ESC/POS encoder, and one serial print queue, and (3) wiring the existing placeholder buttons to that service.

## Technical Context

**Language/Version**: Kotlin 2.0.20, JVM target 17 (`android-pos/gradle/libs.versions.toml`)

**Primary Dependencies**: Jetpack Compose + Material 3 (already present), kotlinx-serialization-json (already present, used for the queue file). **No new third-party dependency**: the Bluetooth link uses the platform `android.bluetooth` API. Printer command encoding is in-house (see research D5).

**Storage**: One local JSON file in app-private storage for the print queue (`filesDir`). The server stores nothing new. `android:allowBackup="false"` is already set, so queued receipt content is not backed up.

**Testing**: JVM unit tests under `android-pos/app/src/test` and `android-pos/core-network/src/test` (JUnit 4, kotlinx-coroutines-test already present). Device verification with the real printer is a separate, required step (Constitution II).

**Target Platform**: Android tablet, minSdk 26 (Android 8.0), targetSdk 34.

**Project Type**: Mobile app (native Android, `android-pos/`), online-first against the existing API.

**Performance Goals**: Kitchen slip printed within 5 s of "Kirim ke dapur" on a normal network with the printer on (SC-002). Payment completion is not delayed by printing: printing runs off the UI and off the payment path.

**Constraints**:
- Paper 58 mm; text must wrap, never clip (FR-012).
- Bluetooth only. The app must work with the printer switched off (FR-017).
- Printed text in Indonesian regardless of app language (FR-016).
- No customer phone or e-mail on any printed document (FR-015).
- The app must not print to a printer the user did not choose (FR-022).

**Scale/Scope**: One printer per tablet. Expected queue size: tens of documents per shift, a few hundred at most. Four document kinds.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Constitution version 2.0.0 (amended 2026-10-11). Each principle, and how this plan meets it:

| Principle | Status | How the plan meets it |
|---|---|---|
| **I. Code quality** — one write path per concern; no speculative abstraction | PASS | One `PrinterService` is the only code that writes to the printer. One `PrintQueue` is the only place print jobs are stored. No printer-driver interface: the spec has one printer model, so the encoder is concrete (the constitution forbids speculative interfaces). |
| **II. Testing** — proven with evidence, not assumed | PASS, with a required follow-up | Encoder, queue and mapping are covered by JVM tests. Device-level behaviour (Bluetooth pairing, paper, width, reconnect) can only be proven with the physical printer and a tablet. **This is a required step before "done"**, recorded in quickstart.md. |
| **III. UX consistency** — tokens, Indonesian copy, 409/403 convention, hidden not disabled | PASS | Print buttons reuse the existing placeholder rows (already in Indonesian: "Cetak pre-bill", "Cetak ulang", "Cetak struk"). Colours come from `PosTheme` (no hex literals). Print controls are hidden for roles without the right, not greyed out. Reprint approval reuses the existing override flow (409 `override_required`). |
| **IV. Security** — server is source of truth; server-side authorization; no PII; snapshots | PASS | All money on paper is copied from server responses; nothing is recalculated on the tablet. Reprints go through `POST /orders/{id}/reprints`, so the server counts copies and enforces approval. Phone and e-mail are not in any template. Queue file is app-private and not backed up. See the open item on KOT reprint audit below. |
| **V. Performance** — no N+1; lean payloads; keyed caches | PASS | No new server calls per row: receipt is one call per printed order; KOT content comes from the send response that already exists. No cache is introduced. |

**Documentation & change discipline**: `docs/openapi-pos-mvp.yaml` already documents `tickets` on `POST /tabs/{id}/send` (line ~6185), and this plan changes no server route or response, so no OpenAPI edit is required. The Android-side change is recorded in this plan and in the research log.

**Gate result**: No violations. Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```text
specs/050-android-thermal-printing/
├── spec.md                  # Feature spec (input)
├── plan.md                  # This file (/speckit-plan output)
├── research.md              # Phase 0 output
├── data-model.md            # Phase 1 output
├── quickstart.md            # Phase 1 output: build, test, device verification
├── contracts/
│   └── print-documents.md   # Phase 1 output: what each document contains and its source
├── checklists/
│   └── requirements.md
└── tasks.md                 # NOT created here (/speckit-tasks)
```

### Source Code (Android app)

```text
android-pos/
├── core-network/src/main/java/com/warkop/core/network/
│   ├── PosApi.kt                      # send() already exists; no endpoint change
│   └── dto/TabDtos.kt                 # SendResponseDto: add `tickets` (kitchen tickets per station)
│   └── dto/TicketDtos.kt              # NEW: TicketDto (id, round_no, station, table, items with modifiers/notes)
├── app/src/main/java/com/warkop/pos/
│   ├── data/PosBackend.kt             # sendToKitchen returns the tickets too; receipt() and reprintOrder() already exist
│   ├── data/RemoteBackend.kt          # map `tickets` from the send response
│   ├── data/TicketMapper.kt           # NEW: TicketDto → PrintableKot (pure)
│   ├── print/                         # NEW package — the only printing code
│   │   ├── EscPos.kt                  # pure ESC/POS byte encoder for 58 mm (research D5)
│   │   ├── Documents.kt               # pure layouts: Struk, Kot, Prebill, BatalSlip → List<Line>
│   │   ├── PrintQueue.kt              # durable JSON queue, status transitions, dedupe key (research D6)
│   │   ├── PrinterService.kt          # Bluetooth link + serial worker; the ONLY writer to the printer
│   │   └── PrinterSettings.kt         # saved printer address (DataStore-free: a small JSON file)
│   ├── state/PosStore.kt              # after send/pay/reprint/prebill: enqueue job (replaces "Cetak … belum tersedia" messages)
│   ├── ui/screens/ShiftScreens.kt     # "Perangkat & sinkron": replace the "Belum tersedia" printer row with the real printer section
│   ├── ui/Sheets.kt                   # "Cetak pre-bill" row, reprint, BATAL note: real wiring
│   ├── ui/screens/OrdersScreen.kt     # "Cetak ulang" button already present: wire it
│   ├── ui/screens/TenderScreen.kt     # "Cetak struk belum tersedia" → status "Tercetak" / "Belum tercetak"
│   ├── ui/screens/PrinterScreen.kt    # NEW: pick paired printer, connection state, "Tes cetak", Antrean cetak list
│   └── AndroidManifest.xml            # BLUETOOTH_CONNECT (API 31+); BLUETOOTH / BLUETOOTH_ADMIN with maxSdkVersion 30
├── app/src/test/java/com/warkop/pos/print/
│   ├── EscPosTest.kt                  # byte-level tests: width, wrap, Rupiah, uppercase KOT/BATAL
│   ├── DocumentsTest.kt               # every template: no phone/email, no prices on KOT, copy label
│   └── PrintQueueTest.kt              # dedupe key, retry never reprints printed jobs, survives restart
└── core-network/src/test/java/com/warkop/core/network/
    └── TicketDtosTest.kt              # send response with tickets decodes; old response without tickets still decodes
```

**Structure Decision**: One new package `com.warkop.pos.print` inside the existing `app` module, because printing is a UI-adjacent device concern and the app module already holds the device-specific code (`setup/`, `state/HeartbeatWorker.kt`). The DTO and the API are in `core-network` (shared DTO layer). No new Gradle module: the constitution forbids speculative structure, and a second module would only add build cost for one consumer.

## Complexity Tracking

> Empty. No constitution violation requires justification.

## Open items (decisions for the product owner, not blocking planning)

1. **KOT reprint audit.** The server logs struk reprints (`ReprintService` writes an activity-log row). A KOT reprint has no server call, so it is not logged. Options: (a) accept it as a local-only event, (b) add a server endpoint that logs it. Recommendation: (a) for now, since the kitchen slip carries no money. Confirm.
2. **Queue number on KOT.** `TenderScreen.kt` says "Nomor antrean juga tercetak di struk dan KOT", but the queue number is assigned at payment, after the KOT is printed. Either the KOT prints without it (recommended) or the KOT is reprinted after payment. Confirm the wording.
3. **Cashier name on KOT.** FR-014 asks for the terminal/cashier name. `TicketResource` does not send `sent_by`. Recommendation: print the name of the PIN user signed in on the tablet at send time, which needs no server change. Confirm.
4. **One printer for all stations.** The server already splits tickets per station (`KitchenService::send`). This plan prints every ticket to the one paired printer, as the spec assumes.
