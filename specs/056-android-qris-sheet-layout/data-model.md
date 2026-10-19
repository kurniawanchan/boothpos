# Data Model: 056 — static QRIS channel on the tablet

## Server (unchanged)

`GET /payment-channels` → `data[]`: `id`, `type` (`qr_ewallet` for QRIS), `provider`, `account_name`, `account_number` (masked for non-privileged users; not used here), `qr_image_url` (public disk URL or null), `is_active`.

## Tablet (changed)

| Type | Field | Source | Notes |
|---|---|---|---|
| `PaymentChannelDto` | `qrImageUrl: String?` | `qr_image_url` | new |
| `QrisChannel` | `id: Long` | `id` | unchanged |
| `QrisChannel` | `provider: String` | `provider` | was `name`; blank → "QRIS" |
| `QrisChannel` | `accountName: String?` | `account_name` | new; trimmed; blank → null |
| `QrisChannel` | `qrImageUrl: String?` | `qr_image_url` | new |

`PosStore` keeps the loaded channel (`qrisChannel`) next to `qrisLabel` (which stays the provider name for the other screens).

## Rules

- **Title** = `sheetTitle(provider, accountName)`: "provider account" / "provider" / "QRIS".
- **QR state**: `None` (no URL) · `Loading` · `Shown(bitmap)` · `Failed`. Both `None` and `Failed` show "QR belum diatur".
- **Total** is shown on one line; font size is the largest of 40, 36, 32, 28 sp that fits the card width.
