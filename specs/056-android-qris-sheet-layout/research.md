# Research: Android POS Phase 3 — Static QRIS Sheet (056)

**Verified** = read in code this session. **ASSUMPTION** = not yet confirmed.

## R1. Where the channel data comes from

- **Verified**: `GET /payment-channels` (`PaymentChannelController::index`) returns `provider`, `account_name`, `qr_image_url`, and a masked `account_number` for each channel. The tablet's `PaymentChannelDto` (`ActionDtos.kt:52`) has `provider` and `account_name` but no `qr_image_url`. `RemoteBackend.qrisChannel()` maps only `provider` into `QrisChannel(id, name)`.
- **Decision**: extend the DTO with `qr_image_url`, and carry `provider`, `accountName`, and `qrImageUrl` into `QrisChannel`. The server is unchanged.
- **Alternatives rejected**: a new endpoint for the QRIS channel (the list already has everything); reading the account number (masked server-side; not needed on the sheet).

## R2. Loading the QR image without a new library

- **Verified**: the app has no image-loading library. Coil is not in the offline Gradle cache (`~/.gradle/caches/modules-2/files-2.1/` has OkHttp but no Coil). Product images are stored as URLs but not drawn (`MenuCard` uses icons).
- **Decision**: `QrImageLoader` downloads the URL with the existing OkHttp client, decodes it with `BitmapFactory`, and caches the `ImageBitmap` in memory by URL. States: loading, shown, none (no URL) and failed.
- **Rationale**: no new dependency, works offline in this environment, the image is small (a QR).
- **Alternatives rejected**: Coil (not cached, adds a dependency for one image); a bundled QR asset (the admin changes the QR).

## R3. Title rule

- **Decision**: `QrisMapper.sheetTitle(provider, accountName)` returns "$provider $accountName" when both are non-blank, the provider alone when the account is blank (FR-002), and "QRIS" when the provider is blank.
- **Rationale**: one pure rule, unit-tested.

## R4. Layout of the sheet

- **Verified**: `QrisRecordDialog` (`Sheets.kt` ~486–520) places the QR card (210 dp) left and the text column right; the warning is a Row with an icon in an error container; the total is `fontSize = 40.sp` in one `Text`; the instruction is a 16 sp `Text`; the button is a `Button` with a 17 sp `Text`.
- **Decision**:
  - Column, horizontally centred: title (30 sp), warning (14 sp, error container), QR card centred (about 240 dp), total block (one line, fitted from 40 sp down to 28 sp with `chooseFontSize`), instruction (16 sp, wraps fully), then the action row.
  - Title is fitted to the sheet width (`chooseFontSize`, sizes 30 → 22 sp).
  - Button label uses `FitPillText` (055), which is a `RowScope` helper, so it fits inside the Button's Row.
- **Alternatives rejected**: keeping the side-by-side layout (the user asked for a centred QR); a fixed total size (it wraps for large amounts).

## R5. Footer and placeholder

- **Verified**: the card footer "WARKOP WJM 3 · NMID ID10••••" is a literal string; the QR is `Icons.Filled.QrCode2` as a placeholder.
- **Decision**: footer shows the account holder name (already in the title, so it is short); the placeholder is replaced by the loaded image. When there is no URL or the download fails, the card shows "QR belum diatur" in the same centred place (spec FR-007 and the edge case).
- **ASSUMPTION**: a failed download shows the same message as "no image" (spec edge case). Confirm on the tablet.

## R6. Text sizes

- **Decision**: warning 14 sp (title 30 sp); instruction 16 sp; total fitted 40 → 28 sp; button label fitted by `FitPillText` (16 → 12 sp).
- **ASSUMPTION**: these exact sizes are a starting point confirmed on the tablet (spec assumption).

## Open items

- Confirm R5 (failed download shows "QR belum diatur").
- Confirm R6 sizes on the pilot tablet.
- Tablet check of the centred QR and the total on one line with a large amount.
