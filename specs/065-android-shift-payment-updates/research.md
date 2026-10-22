# Research: Android Shift, Payment and Setup Updates

Facts read from the code on 2026-10-22; decisions are D1–D12.

## Current state (fact)

- **Web**: `SessionView.vue` shows only the caller's own shift (`GET /sessions/current` filters `user_id`); `?id=` shows a read-only summary card. There is no shift list, no cash-movements screen, no dashboard shift panel.
- **Server**: `CashierSessionController` has `current`, `store`, `close`, `summary`, `xReport`; `CashMovementController` has per-shift `index/store`; `cashier_sessions` already stores `device_id`, `business_date`, `closing_cash`, `expected_cash`, `cash_difference`, `counted_by_denomination`, `counted_total`, `variance`, `z_report`, `edc_batch_total`. `store()` rejects a 2nd open shift per USER (any source); `PaymentService`/`RefundService` pick the user's open shift with `where(user)->open->first()`.
- **Close**: blind protocol = `counted_by_denomination` (checks pending QRIS, variance approval); legacy `closing_cash` skips both. `summary`/`x-report` deliberately hide `expected_cash`. EDC batch total is accepted and stored.
- **Server address**: `/settings/server-address` (admin) stores `{local,online,selected}`; `/devices/server-address` needs a paired device (`X-Device-Id`) and a session token; the tablet adopts a changed choice on its next check (054). `TerminalSetupScreen` has the typed address.
- **QRIS**: tablet picks the first active `qr_ewallet` channel from `GET /payment-channels`, rewrites the image URL host to its own server base. The dev channel (id 3) has `qr_image_path` set now; the screenshot showed "QR belum diatur", so the QR was either uploaded after, or the image fetch failed silently (shown identically to "not set").
- **Android layout**: open-shift rows are 2-up with the label at `weight(1f)` beside a stepper and a fixed 92 dp subtotal, `maxLines=1, softWrap=false` → labels clip ("Rp 100.").

## Decisions

**D1 — Web lives in the existing Cashier Session page.** Owner/admin get tabs *Open · Closed · Cash in/out* above/below the current "my shift" card (hidden for other roles). Detail opens in a drawer reusing `GET /sessions/{id}/summary` + `/cash-movements`. Rejected: a new top-level menu (user allowed either; the page already is the shift home).

**D2 — New read endpoints, no new tables.** `GET /sessions` (list), `GET /cash-movements` (list across shifts), `GET /dashboard/shifts` (open shifts panel). All reuse existing columns. Non-owner/admin: list restricted to own `user_id` server-side (an explicit `user_id` of someone else is ignored/403). OutletScope + DataModeScope already apply to `CashierSession`/`CashMovement`; raw aggregate queries use `OutletFilter` and are registered in `OutletScopeRegistry`.

**D3 — "Sales so far" per row via batched queries** (one grouped query each for completed orders, cash received, cash movements, voids keyed by `session_id`), reusing `ShiftReportService` formulas (`expectedCash`, `cashReceived`, `changeGiven`, movement totals) through batch variants — no second cash formula (constitution I). Closed rows read the stored `z_report`/columns, never recomputed.

**D4 — Separate app and web shifts.** Source is derived, not stored: app ⇔ the request carries a valid, active `X-Device-Id` of the user's visible outlet (already how `store()` stamps `device_id`); web ⇔ no header. One helper `ShiftSource` returns the source and `openFor(User)` = the user's open shift of that source. Used by `current`, `store` (the "already open" 409 becomes per source), `PaymentService` (later cash payment), `RefundService`. An order/tab keeps its own `session_id`, so settling a tab continues to use the tab's shift. Rejected: a stored `source` column (redundant with `device_id`, would need backfill); trusting a client-sent `source` body field (Constitution IV).

**D5 — Typed total on close stays inside the controlled protocol.** New body field `counted_total` (whole rupiah) as an alternative to `counted_by_denomination`; `$blind` becomes "either is present", so pending-QRIS blocking and variance approval apply. The variance override hash binds to the exact submitted count (`counted_total` added next to `counted_by_denomination`). Legacy `closing_cash` unchanged for the web SPA. Rejected: Android sending `closing_cash` (would skip QRIS/variance checks).

**D6 — Expected cash visible before counting (Q2 = B).** `summary` and `x-report` include `expected_cash`; the Android close screen shows sales information, payment split, cash in/out, voids and expected cash. This ends the 048 blind count (owner decision). Variance approval still applies above tolerance. Recorded in CLAUDE.md as superseding the 048 rule.

**D7 — EDC removed from the close flow.** Android stops sending `edc_batch_total`; the server stops accepting it (ignored if sent by an old APK), stops writing it, and drops it from new `z_report`s. The column and old values stay as history (no migration of data). The EDC *payment method* in the tender screen is unrelated and unchanged.

**D8 — Server address bootstrap (Q1 = A).** `BuildConfig.DEFAULT_LOCAL_URL` / `DEFAULT_ONLINE_URL` come from Gradle properties (not committed; documented in the Android quickstart, with an emulator fallback `10.0.2.2:8010`). New public, throttled `GET /server-address` returns `{selected, url}` — only the selected address. Setup asks the defaults one after the other (local first, then online, 3 s timeout each) and uses the first one that answers, stores the returned `url` (or, when `url` is null, tells the admin to set it in the web), and from then on every request uses the stored value. Nothing re-reads the server until app data is cleared. The 054 "adopt changed choice on next check" logic (`adoptSelectedServer`, `/devices/server-address` call) is removed from the app; the server route is kept (old APKs) but unused by this build. Consequence (accepted): switching tablets between local and online later needs each tablet's data cleared. Rejected: QR scan, fallback input (user chose A).

**D9 — Open-shift screen.** Fix clipping by giving the label a minimum width and the subtotal column less width, and fall back to one denomination per row when a 2-up cell is narrower than ~440 dp; add one "Total modal (ketik)" field. Source of truth rule: typing sets the total and clears the steppers; touching a stepper clears the typed value — only one is submitted (`opening_cash` for typed, `opening_denominations` for steppers; the server already accepts either). Remove the "Lewati, ambil order saja" button; orders need a shift.

**D10 — QRIS sheet.** Title (provider + account name via `QrisMapper.sheetTitle`) moves into the header row beside the QR icon; warning row removed in both sheet variants (Sheets.kt ~515 and ~598); instruction text replaced; the QR box distinguishes *not set* (no URL) from *could not load* (URL set, fetch failed) with a "Muat ulang" retry. Root-cause check during implementation: verify against the real dev channel (id 3) whether the earlier failure was a missing upload or an unreachable `/storage/...` URL (public symlink exists in container and host).

**D11 — Dashboard panel** = one row per open shift (cashier, source/terminal name, outlet, since, sales so far), max 50, newest first, each linking to `/session?id=`; shown to owner/admin only (dashboard menu already gated).

**D12 — Upgrade compatibility.** Already-set-up tablets keep their stored URL (`TokenVault.SERVER_URL`), so the removal of the input and of 054 adoption changes nothing for them. Old APK + new server: close with `edc_batch_total` still works (ignored); old APK close with denominations works. New APK + old server is unsupported (server and APK ship together).

## Open points (not blocking)
- Owner to supply the real default local/online addresses for the APK build.
- If an owner later wants tablets to follow a changed local/online choice automatically, 054's adoption can be reinstated; deliberately not kept here.
