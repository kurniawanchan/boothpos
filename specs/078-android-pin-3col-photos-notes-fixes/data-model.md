# Data Model — 078

**Classification**: INTERNAL. No migration; nothing new is stored in the database.

## Existing data, new exposure

| Entity | Field | Source | Change |
|---|---|---|---|
| Order (`orders`) | `notes` | existing column (077 writes it) | added to receipt payload (`notes`), kitchen ticket payload (`order_note`), sales transaction row (`notes`) and export (`order_note`) |
| User (`users`) | `photo_path` → `photo_url` | existing | added to the approvers payload (authenticated) |

## Rate-limit state (cache, not database)

| Counter | Key | Limit | Cleared by |
|---|---|---|---|
| web password login | `login|lower(username)|ip` | 5 failed attempts / 60 s | a successful login (`RateLimiter::clear`) or expiry |
| login flood | ip (named limiter `login-flood`) | 60 requests / min | expiry |
| tablet read | ip (`tablet-read`) | 300 requests / min | expiry |
| tablet write | ip (`tablet-write`) | 10 requests / min | expiry |
| PIN login flood | `ip|outlet|user` (`pin-login`) | 20 requests / min | expiry |
| PIN wrong-attempt lock | existing `PinAuthService` (user) | 5 wrong → 5 min | unchanged |

Buckets are separate because named limiters key as `md5(name . key)`.

## Client shapes (Android)

- **PinLayout**: `THREE_COLUMN (≥ 960 dp) | TWO_COLUMN (720–959) | STACKED (< 720)`.
- **StaffRow**: one per user id; `expanded = (id == selected)`; fields photoUrl, name, role, hasPin, locked.
- **ReceiptView.orderNote / PrintableKot.orderNote**: `String?`, blank → null → nothing printed.
- **Approver.photoUrl**: `String?` resolved through `ServerUrl.resolve`.
- **PosStore.user**: re-read from `GET /auth/me` on refresh so `photoUrl` follows the server.
