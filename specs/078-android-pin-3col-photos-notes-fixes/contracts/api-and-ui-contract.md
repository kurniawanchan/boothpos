# Contract — 078 API changes and UI rules

**Classification**: INTERNAL. `docs/openapi-pos-mvp.yaml` moves in the same commit as the server tasks.

## API

### 1. 429 (rate limit) shape — all API routes
```json
{ "message": "Terlalu banyak percobaan. Coba lagi dalam 42 detik.", "code": "throttled", "retry_after": 42 }
```
Status 429, header `Retry-After: 42` kept. English locale: "Too many attempts. Try again in 42 seconds." The existing `pin_locked` 429 (code `pin_locked`, `locked_until`) is unchanged.

### 2. `POST /auth/login`
- Counts **failed** attempts per `lower(username)` + IP: 5 per 60 s. The 6th attempt within the window → 429 above (even if the password would be right, until the window passes — standard lock-out). A successful login clears the counter.
- 401 message for unknown user and wrong password is the same as today (no enumeration); both increment the counter.
- Route-level `throttle:login-flood` (60/min/IP) only as a flood cap.

### 3. Tablet unauthenticated routes
`GET /staff/roster`, `GET /pos-setup/branding|outlets|{id}/terminals`, `GET /server-address` → `throttle:tablet-read` (300/min/IP). `POST /pos-setup/outlets/{id}/terminals` → `tablet-write` (10/min/IP). `POST /auth/pin-sessions` → `pin-login` (20/min/IP+outlet+user). Counters are independent of each other and of the web login.

### 4. `GET /orders/{id}/receipt` — field added
`"notes": string|null` (the order-level cashier note). Existing fields unchanged.

### 5. Kitchen ticket (`TicketResource`) — field added
`"order_note": string|null`, read live from the order. Item-level `note` unchanged.

### 6. Sales transactions
`orderRow` gains `"notes": string|null`. Export `POST /reports/sales/transactions/export`: new column `order_note` after `items`.

### 7. `GET /overrides/approvers` — field added
Each approver gains `"photo_url": string|null`.

## UI rules

- **PIN screen, THREE_COLUMN**: col 1 (28 %) store panel as before 077 — `StoreMark` + DEMO badge, outlet · terminal, big clock and date, connection chip, settings icon; col 2 (32 %) fixed heading "Siapa yang bertugas?" + scrolling list, one row per person, selected row expanded (72 dp avatar, name, role), others compact (48 dp), no-PIN rows dimmed with "Belum ada PIN"; col 3 (40 %) "PIN <name>", 6 dots, error line, keypad (≤ 340 dp), "Ganti outlet / terminal". Errors only in col 3.
- **TWO_COLUMN / STACKED**: same content and order; the staff list is never duplicated.
- **Receipt print**: after the items, a bold line `Catatan order:` and the wrapped text; omitted when blank. **KOT**: under the header (station/table/round), before items: bold `CATATAN ORDER:` + wrapped text; omitted when blank.
- **Approver picker**: `UserAvatar` (photo or initials).
- **Copy**: "Terlalu banyak percobaan. Coba lagi dalam N detik."
