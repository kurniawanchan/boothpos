# Research: Store Identity on the Printed Struk (059)

**Verified** = read in code on 2026-10-18.

## R1. Contact is dropped on the tablet

- **Verified**: the server's receipt (`OrderController::receipt`) returns `store_contact_person`, `store_contact_phone`, and `store_contact_email`. `Documents.struk` says phone and email "tidak pernah masuk ke ReceiptView (ReceiptMapper), sehingga tidak bisa tercetak" — a deliberate gap in the tablet mapping, not a server gap.
- **Decision**: add the three fields to the receipt DTO, `ReceiptView`, and `ReceiptMapper`, and print them under the address, skipping blanks.

## R2. The printer encoder has no image support

- **Verified**: `EscPos.kt` encodes text lines only (`PrintLine` has text, alignment, bold, big, right). There is no raster command.
- **Decision**: add a raster line type that renders with `GS v 0` (raster bit image). The logo is converted to 1-bit by a fixed luminance threshold after scaling to at most 384 dots wide (58 mm, 203 dpi, about 48 mm printable).
- **Alternatives rejected**: dithering (noisy on thermal paper, extra work for a small logo); a separate print job for the logo (two jobs for one struk).

## R3. Logo URL and download

- **Verified**: the server's `store_logo_url` is null when `receipt_show_logo` is off (`OrderController::receipt`), and otherwise the public disk URL with the APP_URL host (`localhost`) — the same problem as the QRIS image in 056.
- **Decision**: move `QrisMapper.resolveQrUrl` into `ServerUrl.resolve` and use it for the logo too. Download with `QrImageLoader` (the existing OkHttp path; the name is generic enough, rename only if a later cleanup asks).
- **Rationale**: the admin's receipt-logo setting already controls the URL: null means no logo, so no second setting is needed (answers Q1 option A).

## R4. Where the struk is built (verified 2026-10-18)

- **Verified**: `PosStore` only enqueues a struk by reference: `PrinterService.enqueueStruk(orderId, copyNo)` stores `refId = orderId` and `copyNo`, with `lines = null`. The lines are built at PRINT time in `PrinterService.printOne`: `Documents.struk(fetchReceipt(job.refId).copy(copyNo = job.copyNo))`, then `EscPos.render`. A struk job never carries lines in the queue.
- **Consequence (this corrects the earlier decision)**: the logo does NOT need to be stored in the queue. It is fetched and converted in the same print-time step, after `fetchReceipt`, before `Documents.struk`/`EscPos.render`.
- **Decision**: `printOne` loads `receipt.storeLogoUrl` with `QrImageLoader.load` and converts it with `Logo.toRaster`. Any failure or a null URL gives a text-only struk (FR-005); the cashier is never blocked, and printing is not held back by the logo.
- **Why this works offline**: the receipt fetch already needs the server, so the logo, from the same server, is no less available than the receipt itself. A logo that cannot be fetched only drops the logo line.
- **Queue persistence**: the raster line still has to serialise, because KOT or other jobs may carry lines. T023 therefore tests the raster line round trip in `PrintQueue`, not a stored struk logo.
- **Alternatives rejected**: storing the logo raster in the job at enqueue (the job holds no lines, so this would add a second, parallel struk build path); re-downloading at print time as a separate call (unnecessary once the receipt is already fetched there).

## R5. Reprints

- **Verified 2026-10-18 (T028)**: `PosStore.reprint` only calls `printer.enqueueStruk(orderId, copyNo)`; the struk is built in `PrinterService.printOne` from `fetchReceipt(orderId)`, the server's receipt. No local order data is used, so a reprint shows the same identity as the first copy. No change was needed.
- **Name and address**: they come from the server receipt (`store_name`, `store_address`, from Store identity for the active mode, FR-004/007). The tablet does not override them (`ReceiptMapperStoreTest`).

## Open items

- Printer check on the TM-58V: logo width, darkness threshold (start at 128), and print time.
