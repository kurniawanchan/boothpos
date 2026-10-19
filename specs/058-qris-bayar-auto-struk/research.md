# Research: QRIS "Bayar" with Automatic Struk (058)

**Verified** = read in code on 2026-10-18.

## R1. Why the QRIS path prints nothing

- **Verified**: `PosStore.recordQris()` (line ~934) upserts the updated order, closes the sheet, and says "QRIS dicatat · menunggu verifikasi." It never calls `onSaleClosed`. The cash path (`payCash` → `send(... ) { o2 -> if (o2.closed) { onSaleClosed(o2); dest = Dest.DONE } }`) and the EDC path (`if (updated.closed) { onSaleClosed(updated); dest = Dest.DONE } else say("Sisa …")`) do.
- **Decision**: the QRIS success branch follows the EDC pattern exactly.
- **TO VERIFY on the device**: that a pending QRIS payment makes the order `closed`. If the server keeps the order open until verification, the struk must be queued at payment time instead; the device check (quickstart step 2) decides.

## R2. Printing once

- **Verified**: `PrintQueue.enqueue` returns false when the key already exists (`PrintQueue.kt` line ~23), and `PrinterService.enqueueStruk` is keyed by order and copy number.
- **Decision**: rely on the queue key; a double tap or a reopened screen does not print twice.

## R3. Print failure does not undo payment

- **Verified**: `onSaleClosed` enqueues and returns; it never throws into the payment path (`PosStore` comment at line ~787).
- **Decision**: unchanged; the struk waits in the queue (spec FR-007, FR-008).

## Open items

- R1 device check: a pending QRIS payment closes the order.
