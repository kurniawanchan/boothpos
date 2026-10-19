# Quickstart: verifying 056

## Backend (no change)

1. Confirm the channel list returns the fields: `GET /api/v1/payment-channels` with a signed-in session shows `provider`, `account_name`, `qr_image_url` for the QRIS channel.

## Android unit tests

2. `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Expected: `QrisMapperTest` (title rules, mapping of blank fields) and `QrisSheetLayoutTest` (total size and title fit decisions) pass.

## Tablet (manual)

3. Open the QRIS sheet with the channel "QRIS" / "Warkop WJM 3": title reads "QRIS Warkop WJM 3" (US1).
4. Clear the account name in the admin form, reopen: title reads "QRIS" only (FR-002).
5. Warning text is smaller than the title and fully readable (US2).
6. QR image is centred; the text "Standee QRIS statis di meja kasir" is gone (US3, SC-002, SC-003).
7. Remove the QR image in the admin form, reopen: "QR belum diatur" in the centred place (FR-007).
8. Total amount on one line, the largest sized one, e.g. Rp 1.250.000 (US4, FR-008).
9. Instruction sentence fully visible, not cut at the bottom (FR-009).
10. Button label "Catat · menunggu verifikasi" on one line with its arrow (US5, FR-010).
11. Record a static QRIS payment: the result is the same as before (FR-011, SC-005).
