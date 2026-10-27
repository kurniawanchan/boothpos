# Evidence — 078 (2026-10-27)

**Classification**: CONFIDENTIAL (security residual risks below; do not publish outside the team).

## Automated
- Android `:app:testDebugUnitTest` 363 pass / 0 fail (baseline 335, +28 new); `:core-network:testDebugUnitTest` 74 pass; `:app:assembleDebug` succeeds.
- New server tests (safe flags): `TabletPollingThrottleTest` 4, `LoginThrottleTest` 8, `ReceiptOrderNoteTest` 4, `TicketOrderNoteTest` 4, `SalesExportOrderNoteTest` 2, `ApproverPhotoTest` 3 — all pass.
- Existing tests updated on purpose: `PosSetupTest` and `ServerAddressPublicTest` (old 30/min cap → new `tablet-read` 300/min), `SalesTransactionsExportTest` (column list now has `order_note` after `items`), `OverrideGateTest` (approver keys now include `photo_url`).
- Regression groups pass: PIN (28), PosSetup/branding/auth, kitchen/sales/order (107), override (14).
- Full backend suite: **NOT completed** — the background run was killed (exit 137). Only targeted groups are verified (listed above). Must be re-run before merge (T034 stays open).
- `docs/openapi-pos-mvp.yaml` parses; adds `TooManyRequests`, login policy text, receipt `notes`, ticket `order_note`, sales row `notes`, export column, approvers `photo_url`.

## Live check against the dev server (localhost:8010)
- Before (baseline.md T002): 8 roster reads, then a wrong-password login → 429 "Too Many Attempts." (English).
- After: 15 roster reads (all 200), then 6 wrong logins with a throwaway username → 401 ×5, 6th → `{"message":"Terlalu banyak percobaan. Coba lagi dalam 58 detik.","code":"throttled","retry_after":58}` (429). The `owner` account was never used for failing attempts.

## T010 — web login screen
`resources/js/utils/errors.js::normalizeAxiosError` takes `response.data.message`, and `LoginView.vue` shows `err.message`, so the Indonesian 429 text with seconds appears unchanged. No web change needed.

## T024 — avatar audit (`grep -rn "initials\|take(2)\|initialsOf" app/src/main`)
Remaining initials renders: `UserAvatar` (users, photo-first), `StoreMark` (store initials, intentional), `ProductPhoto` (products/variants). The only other user-name initials render was the approver picker (`Sheets.kt`) — now `UserAvatar`. No other user avatar found.

## Photos finding (research R2) — and what is still unverified
- Dev DB (read-only): 12 users, 1 with a photo (LIVE outlet user); the 5 DEMO roster users have none → initials were correct on the owner's DEMO tablet. Photo URL serves HTTP 200.
- **Not verified**: that the LIVE user's photo renders on the PIN list, expanded row, header and approver picker on a device (quickstart step 5).

## NOT verified (no device — adb absent)
All Compose UI (three/two/one column PIN layouts, expanded row, no clipping, photo avatars, approver picker) is compile- and unit-tested only. Kitchen/receipt note layout on the 58 mm printer not checked on paper. T035 stays open.

## Deviations from the plan
- `PosStore.refreshUser()` is called from `refreshCounter`/`refreshCurrent` (not from the PIN roster refresh — no session there; the roster already carries `photo_url`).
- Mode check in `refreshCounter` now runs before `refreshUser()`; unchanged otherwise.

## Follow-up (automated security review of the 078 commit, 2026-10-27)
- Login counter now increments BEFORE verifying the password (atomic) and adds a per-username cap of 30/min across all IPs (kept far above 5 so an attacker cannot lock the owner out). Tests: `LoginThrottleTest` (+2).
- `pin-login` gained a per-IP total of 30/min besides the per-person 20/min (regression from the old shared per-IP counter). Tests: `PinLoginFloodTest` (3).
- Report exports store strings as literal text via `SafeStringExport` (spreadsheet formula injection from cashier-typed notes/names). Customer/invoice exports stay on `GenericArrayExport` because they are re-imported. Tests: `SafeStringExportTest` (4, incl. a control proving the default binder creates formulas).
- Verified: 94 affected tests pass; one `PosSetupTest` failure during the run was residue from killing the full suite and passed on rerun (12/12). The full backend suite still has to be re-run (T034).

## Follow-up 2 (second automated review, 2026-10-27)
- The per-account bucket now counts only CONFIRMED failures and is not touched by requests already rejected per-address, so one source cannot fill it and lock the owner out everywhere. Both buckets key on the user id the database resolves (unknown names: trimmed, lower-cased, accent-stripped), so case/accent variants collapse. Accepted tradeoff: a botnet over many addresses can still reach 30 failures/min on one account.
- Throttle tests that fire hundreds of requests now freeze time (they failed once in a slow full run because the 60 s window expired).
- **Full backend run (2026-10-27, 3054 s):** 2129 passed, 1 skipped, 29 failed. 27 failures were `Deadlock found` (two suites overlapped on the same test database after an earlier kill did not stop the first run); the 21 affected classes pass alone (208 tests). 1 was the speed-dependent throttle test (fixed above). 1 is the pre-existing `ShiftReportParityTest`. A clean uncontended full run is still pending.

## Follow-up 3 (third automated review, 2026-10-27)
- The account counter is now reserved atomically (`hit` then compare) after the per-address check passes, and cleared on success, replacing check-then-hit-after-failure. Requests already rejected per-address still never touch it.
- **Accepted residual risk:** the limiter key is `id:N` for existing accounts and a normalised name (trim, lower-case, accent-stripped) for unknown ones. A name variant that the database collation treats as equal but the normalisation does not shares the real account's counter, so throttling onset can reveal that such a variant resolves to an account. Making both identical would reintroduce the bypass where each variant gets its own counter.

## Security recheck (T036) — against specs/073-.../security-findings.md
- **Brute force**: still bounded — 5 failed passwords / account(+IP) / minute, 60 requests / IP / minute flood cap (web login); PIN: 20 / minute / person+outlet+IP flood cap plus the unchanged 5-wrong-PIN lock. Stronger against targeted guessing than before; the old shared per-IP counter is gone.
- **Enumeration**: the response is identical for unknown vs wrong password and both consume the same counter. **Pre-existing timing difference (not changed here)**: for an unknown username `AuthController::login` skips `Hash::check`, so it returns faster than a known username with a wrong password. Suggested small hardening (separate decision): hash a dummy value for unknown users.
- **tablet-read 300/min/IP**: a flood cap, not an access control; the unauthenticated roster/branding data is unchanged apart from 077's `photo_url`.
- **Approvers `photo_url`**: authenticated route, outlet-scoped, same URL class as `users.photo_url` already returned by `/auth/me`.
- **Receipt/KOT/export notes**: read via outlet- and mode-scoped order models; the note text is never written to logs (077 behaviour kept); no new PII fields added to tests/fixtures.
- **Residual (owner decision pending)**: roster `photo_url` widens 073 finding 3 (token-less roster); unchanged by 078.
- **Operational note**: counters live in the cache store; if the store is cleared, limits reset (acceptable).
