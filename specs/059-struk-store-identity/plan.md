# Implementation Plan: Store Identity on the Printed Struk

**Branch**: `059-struk-store-identity` (not created; work sits on `054-android-pin-layout-fixes` in the tree) | **Date**: 2026-10-18 | **Spec**: [spec.md](./spec.md)

## Summary

Tablet-side change; the server already sends the identity.

1. **Contact (US1):** the receipt model and mapper keep the contact person, phone, and email, and the struk prints them under the address, skipping blank lines.
2. **Logo (US2):** when the server's `store_logo_url` is set (it is null when the admin turns the receipt-logo setting off), the tablet downloads the logo, converts it to a black-and-white raster, prints it above the store name, and stores it with the print job so it survives a restart.
3. **Name and address (US3):** already from the server; the reprint uses the same receipt (FR-007).

No server change. No migration.

## Technical Context

**Language/Version**: Kotlin (Android POS, `android-pos/`), Compose BOM 2024.09.00

**Primary Dependencies**: existing `ReceiptMapper`/`ReceiptView`, `Documents.struk`, `EscPos` encoder, `PrintQueue`/`PrintJob` (serialisable lines), `QrImageLoader` (OkHttp + BitmapFactory), `QrisMapper.resolveQrUrl` (server-URL rebase from 056)

**Storage**: no new store. A struk job holds only its reference (order id, copy number); the receipt and the logo are fetched and converted at print time (research R4).

**Testing**: JVM unit tests for the mapper, the struk layout, the raster conversion, and the URL rebase; manual printer check on the TM-58V

**Target Platform**: Android tablet with a 58 mm Bluetooth thermal printer (printable width 384 dots)

**Constraints**: a missing or failed logo never stops the struk (FR-005); the queue must stay small enough for a 58 mm job (logo at most about 384 × 96 dots).

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I. Code quality | One URL rebase (move `resolveQrUrl` into a shared `ServerUrl` used by QRIS and the logo); one image download path (`QrImageLoader`); one raster encoder inside `EscPos`. | PASS |
| II. Testing | Tests for contact lines (blank skipped), logo raster (width, threshold), URL rebase, and the struk layout with and without a logo. | PASS (printer check pending) |
| III. UX | Indonesian labels unchanged; the struk layout gains only the identity lines. | PASS |
| IV. Security | The logo URL comes from the server and is fetched over the server address the tablet already uses; no secret is printed. | PASS |
| V. Performance | One logo download per process (cached); the raster is computed once per print job. | PASS |
| Stack shape | Works with a local or shared server (same URL rebase as 056). | PASS |
| Documentation | No API change, so no OpenAPI change. | PASS |

**Gate result**: PASS. No violations.

## Project Structure

```text
specs/059-struk-store-identity/
├── spec.md
├── plan.md        # this file
├── research.md    # R1–R5
├── data-model.md  # receipt fields, raster line
├── quickstart.md
├── contracts/payment-receipt-fields.md  # receipt fields the tablet reads (server unchanged)
├── checklists/requirements.md
└── tasks.md       # NOT created here

android-pos/core-network/src/main/java/com/warkop/core/network/dto/   # EDIT: receipt DTO gains contact and logo fields
android-pos/app/src/main/java/com/warkop/pos/data/ReceiptMapper.kt     # EDIT: map contact and logo URL
android-pos/app/src/main/java/com/warkop/pos/model/PosModels.kt        # EDIT: ReceiptView gains contact and logo URL
android-pos/app/src/main/java/com/warkop/pos/data/ServerUrl.kt         # NEW: resolve(serverBase, url) moved from QrisMapper
android-pos/app/src/main/java/com/warkop/pos/data/QrisMapper.kt        # EDIT: delegates to ServerUrl
android-pos/app/src/main/java/com/warkop/pos/print/Logo.kt             # NEW: bitmap -> 1-bit raster (threshold, 384 dots max) and hex encoding
android-pos/app/src/main/java/com/warkop/pos/print/EscPos.kt           # EDIT: raster line (GS v 0) and contact-line support
android-pos/app/src/main/java/com/warkop/pos/print/Documents.kt        # EDIT: struk prints logo, contact lines
android-pos/app/src/main/java/com/warkop/pos/print/PrinterService.kt    # EDIT: printOne fetches the logo next to the receipt and converts it (R4); reprint uses same path
android-pos/app/src/test/.../data/ReceiptMapperStoreTest.kt            # NEW
android-pos/app/src/test/.../print/LogoRasterTest.kt                   # NEW
android-pos/app/src/test/.../print/StrukIdentityTest.kt                # NEW
android-pos/app/src/test/.../data/ServerUrlTest.kt                     # NEW (moved cases from QrisMapperTest)
```

**Structure Decision**: extend the existing receipt mapping, the struk document, and the ESC/POS encoder. Two new small files: the server-URL rule (shared with QRIS) and the logo conversion.

## Complexity Tracking

> No constitution violations.
