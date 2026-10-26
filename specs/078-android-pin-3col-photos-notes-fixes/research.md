# Research — 078

**Classification**: INTERNAL. Facts were read from the repo or the dev database (read-only) on 2026-10-27. Anything else is marked ASSUMPTION/unverified.

## R1 — Why "Too Many Attempts."
- **Fact**: `ThrottleRequests::resolveRequestSignature`: authenticated → user id; otherwise `route->getDomain().'|'.$request->ip()`. The limit and the route are **not** part of the key, so all numeric `throttle:N,1` unauthenticated routes of one IP increment one counter; each route only differs in the ceiling it compares against.
- **Fact (routes/api.php)**: `/auth/login throttle:5,1`; `/staff/roster`, `/auth/pin-sessions`, `/pos-setup/branding|outlets|terminals`, `/server-address` all `throttle:30,1`.
- **Fact**: the PIN screen probe loads the roster every 10 s (054) and, since 077, `pos-setup/branding` every 10 s → ≈12 hits/min per tablet on the same counter. Two tablets ≈ 24/min of the shared 30/min; the web login ceiling (5) is passed after one idle minute.
- **Inference (unverified)**: the owner's browser and the tablets appear as the same `ip()` (Docker Desktop NAT, or one router). Not reproduced; the plan's tests reproduce the *counter sharing* with a feature test (same IP, different routes).
- **Fact**: Laravel's default 429 body is `{"message":"Too Many Attempts."}` (English, no wait time); `bootstrap/app.php` has no renderer for it. Android maps 429 → `ApiError.TooMany(message)`; `PinAuthService` has its own 5-wrong-PIN lock (separate).

**Decision**
1. **Named limiters** (`AppServiceProvider::boot`, `RateLimiter::for`): named limiters key as `md5(name . key)`, so buckets are separate by construction.
   - `tablet-read`: `Limit::perMinute(300)->by('ip')` — roster, branding, outlets, terminals, server-address (read-only, flood cap only; ~10 tablets × 12/min fits).
   - `tablet-write`: `perMinute(10)->by('ip')` — register terminal (as today).
   - `pin-login`: `perMinute(20)->by(ip.'|'.outlet_id.'|'.user_id)` — flood cap per person per place; the real brake stays `PinAuthService` (5 wrong → 5 min lock).
   - `login-flood`: `perMinute(60)->by(ip)` — coarse safety net for the web login so it cannot be hammered across many usernames.
2. **Web password login: per-account, failures only**, in `AuthController::login`: key `login|strtolower(username)|ip`; before checking credentials `RateLimiter::tooManyAttempts(key, 5)` → 429; on wrong password `hit(key, 60)`; on success `clear(key)`. Same key for unknown usernames (no enumeration; same 401 text). Remove `throttle:5,1` from the route and replace with `throttle:login-flood`.
3. **One 429 renderer** in `bootstrap/app.php` for `ThrottleRequestsException` (API only): `{message: __('auth.too_many_attempts', ['seconds' => N]), code: 'throttled', retry_after: N}` + keep the `Retry-After` header. Message: "Terlalu banyak percobaan. Coba lagi dalam N detik." (id) / English equivalent. `pin_locked` (429 with its own code) is not touched.
4. **Android**: slow the PIN-screen poll — roster every 10 s as now, **branding every 3rd tick (30 s)** → ≈8 hits/min. 429 messages come from the server (already Indonesian) — `ApiErrorMapper` unchanged except it should prefer the server message when `code == "throttled"`.
- **Rejected**: just raising `throttle:5,1` (keeps coupling, weakens brute-force protection); keying everything per user agent/device header (client-controlled); disabling throttle on roster (unbounded flood).
- **Risk**: counters live in the cache store. In tests the safe flags use `array`, so counters reset per test process — tests clear limiters explicitly.

## R2 — Photos: what is actually wrong
- **Fact (dev DB, read-only)**: 12 users, **1** has `photo_path` (id 3). Roster: LIVE outlet 9 → [3] (has photo), LIVE outlet 10 → [13], DEMO outlet 4 → [10,12,8,11], DEMO outlet 14 → [9]; none of the DEMO users has a photo. The photo file exists and `GET /storage/users/….jpg` returns 200. The tablet in the owner's picture was on the DEMO outlet ("Warkop WJM Demo · Kasir Depan Demo") → **initials were correct** for those users.
- **Fact**: `AuthController::userPayload` already returns `photo_url`; pin-login returns it; 077's `UserAvatar` is used in `StaffChip` and the shell header.
- **Gaps found**:
  1. `Sheets.kt:433` (approver picker) draws `ap.name.take(2)`; `GET /overrides/approvers` has no photo.
  2. The header user is read once at login (`session.user`); a photo changed later is not picked up until the next login (spec FR-013 asks for the next refresh).
  3. Only these two `initials` renders remain (`grep`): `StoreMark` (store initials — intentional) and `ProductPhoto` (items).
- **Decision**: approvers payload + `UserAvatar` in the picker; `PosStore` re-reads `GET /auth/me` on `refreshCurrent`/`refreshCounter` and when the PIN roster refreshes, updating `user.photoUrl`. Cache key is the URL; uploads store a new uuid filename (`ImageUploadService::store` with uuid — observed), so a replaced photo is a new URL and is not stale.
- **Not done / needs a device**: confirm LIVE user 3 shows the photo on the PIN screen and header (quickstart step 4). If it does not, the cause is on-device image loading, not data.

## R3 — Three-column PIN screen
- **Fact (owner photo)**: landscape tablet; left panel clipped (heading cut at top, avatar cut at bottom) because the 077 panel put a scrolling Column with `weight(1f)` between a header and a footer in a fixed-height card.
- **Fact**: before 077 the left panel = logo mark + outlet/terminal, giant clock (112 sp), date, connection + outlet chips. 077 shrank the clock to 40 sp and put the staff picker inside the panel.
- **Decision (layout rule `pinLayoutFor(widthDp)`)**: `≥ 960` → **THREE_COLUMN** (col1 weight 0.28 store panel as before incl. big clock 72 sp, date, chips, DEMO badge; col2 weight 0.32 staff; col3 weight 0.40 PIN); `720–959` → **TWO_COLUMN** (store panel slim + staff in col 1, PIN col 2 — 077 shape but de-duplicated); `< 720` → **STACKED**. Thresholds are the keypad (340 dp) + staff list (≥ 280 dp) + panel (≥ 280 dp) with margins.
- **Column 2** = fixed heading "Siapa yang bertugas?" + `LazyColumn` of one row per person. Selected row is the **expanded** row (72 dp avatar, name 20 sp, role) and the others compact (48 dp); no separate "selected user" block → no duplicates, no clipping (the list scrolls; the heading never scrolls). Staff without a PIN: dimmed, not selectable, reason line.
- **Same-name people**: rows are keyed by user id; two people with the same name show two rows (role/photo distinguish them).
- **Rejected**: keeping the 077 two-column and only de-duplicating (owner asked for 3); FlowRow of chips (wraps unpredictably, caused the clipped heading).

## R4 — Order note on receipt, kitchen ticket, transaction
- **Fact**: per-line notes already print on receipt/KOT/pre-bill ("   Catatan: …", `Documents.kt` 85/111/122); `orders.notes` is exposed by `OrderResource.notes` and shown in the web detail modal (`TransactionItemsModal.vue:257`). Not present: `OrderController::receipt` payload, `TicketResource`, `SalesTransactionsService::orderRow`, the xlsx export (columns come from array keys).
- **Fact**: `EscPos.sanitize` already replaces non-printable characters and `EscPos.wrap` wraps by width → FR-019 handled by existing code.
- **Decision**:
  - `GET /orders/{id}/receipt` + `notes` (string|null). Android `ReceiptDto.notes` → `ReceiptView.orderNote`; `Documents.struk` prints, after the item lines and before the first totals separator: `Catatan order:` (bold) then the wrapped text; nothing when blank (FR-018).
  - `TicketResource` + `order_note` read **live** from the order (no snapshot, no migration). Android `TicketItems`/`PrintableKot.orderNote`; `Documents.kot` prints it right under the header, before the items, bold, so the kitchen sees it first. *Amends the spec assumption "stored when the ticket is created": live read means a reprint shows the current note, which is better for the kitchen and needs no migration.* Tickets already printed are still not reprinted automatically.
  - Transaction: `orderRow` + `notes`; export gets column `order_note` placed after `items`. Web detail needs no change. Tablet closed-order detail already shows it (077).
  - Pre-bill: not in the spec; left unchanged.
- **Rejected**: snapshotting the note on `kitchen_tickets` (migration + backfill, no user value); printing the note on every KOT round when it did not change (clutters; the note is order-level and appears on each ticket by design — it is one short block).
  - ASSUMPTION: printing the order note on **every** ticket of the order (each round / each station) is acceptable; confirm on first kitchen test.

## R5 — Android 429 and the PIN-screen error
- `ApiErrorMapper` maps 429 to `TooMany` with the server message; `PinLockMessage` handles `pin_locked`. With R1's renderer the `throttled` message is Indonesian already. Task: prefer the server message for code `throttled`, and show it in the PIN column.

## Open items (not blocking)
1. Reproduce the lockout against the Docker dev server (`for i in 1..6; curl roster; curl login`) — quickstart step 1.
2. Device check of photo visibility for the LIVE user.
3. Printer check of the note layout on the 58 mm TM-58V (32 columns).
