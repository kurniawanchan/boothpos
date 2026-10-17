# Research: Thermal Printer for the Android POS

**Feature**: 050-android-thermal-printing | **Date**: 2026-10-17 | **Classification**: INTERNAL

Each decision lists what was checked in the repository, the choice, the reasoning, and the alternatives rejected. Items marked **UNVERIFIED** need the printer's own documentation or a physical test.

## D1 — KOT content comes from the server's send response

**Checked**: `KitchenService::send()` creates one `KitchenTicket` per station, with `round_no`, and returns `tickets`. `TabController::send()` returns them as `TicketResource` collection. The Android `SendResponseDto` declares only `tab`, and `RemoteBackend.sendToKitchen` drops everything else.

**Decision**: Add `tickets` to `SendResponseDto`, expose it through `PosBackend.sendToKitchen`, and print one KOT per returned ticket.

**Why**: The server already groups items by station and round, so the tablet does not have to guess. The ticket `id` is a stable key for the print job, which makes the "print once" rule (FR-007) enforceable. The send is idempotent via `Idempotency-Key`, and the replay returns the stored response, so a retried send re-yields the same ticket ids.

**Alternatives rejected**:
- *Diff the tab's lines before and after the send.* Fragile: it cannot tell stations or rounds apart and breaks on a voided-then-resent line.
- *Print from the tab payload.* The tab does not record which items went to which station.

## D2 — Receipt and reprint use the existing server endpoints

**Checked**: `GET /orders/{order}/receipt` returns `copy_no`, `receipt_no`, items, totals, payments. `POST /orders/{order}/reprints` (`ReprintService`) takes `copy_no` and an optional `override_token`, returns the server's copy number, and answers 409 `reprint_copy_changed` if the copy number moved. `PosStore.reprint()` already tracks the next copy number.

**Decision**: Struk data is read from the receipt endpoint at print time. A reprint is one server call that reserves the copy number, then a print job that carries that number.

**Why**: The server owns copy numbering and approval (Constitution IV). If the printer is off after the server accepted a reprint, the retry must print the **same** copy number and must not call the reprint endpoint again, which would be refused with 409 and would burn a copy. The job stores `copyNo`.

**Consequence for the spec**: the spec's assumption that queued jobs hold "data already loaded" is too narrow for struk. A struk job holds only the order id and fetches content when it prints, so it can wait for the network as well as the printer. Update the spec assumption when tasks are generated.

## D3 — Pre-bill: the server marker exists, the paper is new

**Checked**: `TabController::prebill()` stamps `prebill_at`, and the floor view already shows "Bill tercetak" (`TablesScreen.kt`, `billPrinted`). `PosStore.printPrebill()` says "Cetak fisik belum tersedia" (no physical print yet).

**Decision**: Keep calling `prebill` first (it is the marker, and it is idempotent in effect), then enqueue a pre-bill job from the returned tab. Do not call it again on a retried print.

**Why**: The marker and the paper are separate facts. The existing code avoids automatic retry of `prebill` so that the count of printed copies is not doubled (`PosStore` line ~485). That reasoning carries over: only the print job is retried.

## D4 — Bluetooth via the platform API, no third-party library

**Checked**: No Bluetooth code or permission exists. `minSdk = 26`, `targetSdk = 34`. The project declares `hilt` in the version catalog but does not use it.

**Decision**: Use `BluetoothAdapter` and an RFCOMM socket on the standard Serial Port Profile UUID (`00001101-0000-1000-8000-00805F9B34FB`). The user pairs the printer once in Android's Bluetooth settings. The app lists **bonded** devices and lets the user pick one; it does not run discovery.

**Why**: Listing bonded devices needs no location permission, which avoids the most common Android Bluetooth pitfall on API 26 to 30. Permissions:
- API 31 and up: `BLUETOOTH_CONNECT` (runtime).
- API 26 to 30: `BLUETOOTH` and `BLUETOOTH_ADMIN` with `android:maxSdkVersion="30"`.

**UNVERIFIED**: That the VSC TM-58V uses classic Bluetooth SPP. Printers in this class are usually classic SPP, but the printer manual or a test pairing must confirm it. If it is Bluetooth Low Energy, this decision changes and the spec's connection assumption must be revisited.

**Alternatives rejected**: a third-party ESC/POS Bluetooth library (adds a dependency that was not evaluated and is unverified here); discovery-based pairing from the app (needs location on older Android and adds UI the shop does not need).

## D5 — ESC/POS encoding written in-house, tested as bytes

**Checked**: No printer code exists to reuse. The printer label says "VSC Desktop thermal printer", which in this class is normally driven by ESC/POS commands.

**Decision**: A small pure-Kotlin encoder (`EscPos.kt`) that produces the byte stream: initialise, text lines, bold for headings, a cut or feed at the end, and a width of 32 characters per line for the default font. It has no Android dependency, so it is covered by JVM unit tests.

**Why**: The app needs only a handful of commands. A pure encoder is testable without a printer, which matters because the device is not always available. It satisfies "verify, don't assume" by pinning the exact bytes.

**UNVERIFIED**: That the printer accepts ESC/POS, and that 32 characters per line is right for 58 mm at its default font. Both are confirmed by the **Tes cetak** step on the real device. If the printer uses another command set, only `EscPos.kt` changes, because `Documents.kt` works in plain lines.

**Alternatives rejected**: a vendor SDK (none found for this model); raw bitmap printing (slower and much larger payload per slip).

## D6 — One serial queue, durable, dedupe by key

**Checked**: No queue exists. The app has no Room and no DataStore in its dependencies (DataStore is in the catalog but not used by the app module). `kotlinx-serialization-json` is present.

**Decision**: `PrintQueue` keeps every job in one JSON file in `filesDir`, written atomically (write to a temp file, then rename). A single worker coroutine drains the queue in order, one document at a time, through `PrinterService`. Each job has a key `kind:refId:copyNo` (for example `kot:TICKET_ID:1`, `struk:ORDER_ID:2`). Enqueuing a key that already exists as `printed` is a no-op.

**Why**: One printer means one writer, so a serial worker is the simplest correct design. A file-based queue avoids adding Room for a few hundred small records. The dedupe key enforces FR-007 at the storage layer, so a double tap or a restart cannot print twice.

**Alternatives rejected**: Room (a new dependency and schema for a small, single-writer list); keeping the queue in memory only (lost on restart, violates FR-021).

## D7 — Print triggers and failure behaviour

**Decision**:
- **Kirim ke dapur**: after the server returns the tickets, enqueue one KOT job per ticket. The send itself is not blocked by printing.
- **Pay**: after the server confirms payment, enqueue a struk job. Payment is never delayed or rolled back by printing (FR-017).
- **Void of a sent item**: the server's void response gives the item's station; enqueue a BATAL slip for that station's ticket. **UNVERIFIED** that the void response carries enough to build the slip. If not, the slip is built from the tab payload and the item snapshot.
- **Reprint and pre-bill**: as in D2 and D3.
- **Failure**: a job becomes `failed` with a reason (`bluetooth_off`, `out_of_range`, `paper_out`, `write_error`) and stays in the queue until printed or dismissed (FR-018 to FR-021).

## D8 — Settings and roles

**Decision**: The chosen printer address is stored in a small JSON file next to the queue (`PrinterSettings`). Only roles that already have the **settings** or **floor** rights see the printer controls; the reprint button stays visible to the roles that already see the order list.

**Why**: Reuses the existing role-visibility model (Constitution III: hidden, not disabled). No new server permission is needed, since printing is device-local.

## D9 — What the server does not give us (gaps)

- **Cashier name on a KOT.** `TicketResource` has no `sent_by`. Use the PIN user signed in on the tablet at send time. Open item 3 in plan.md.
- **KOT reprint audit.** No server call; logged locally only. Open item 1 in plan.md.
- **Queue number on a KOT.** Assigned at payment, after the KOT prints. Open item 2 in plan.md.

No server change is planned. If open items 1 or 2 are decided the other way, a server endpoint or field would be added and `docs/openapi-pos-mvp.yaml` updated in the same change.
