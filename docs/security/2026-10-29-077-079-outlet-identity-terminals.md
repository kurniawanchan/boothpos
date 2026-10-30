**Classification: CONFIDENTIAL** (control internals of an internal codebase; no live secrets, no PII reproduced)

# Security Review: BoothPOS backend, features 077–079 (outlet identity, outlet code, Terminals page, login/PIN throttles)

**Reviewer:** Claude (Sonnet 5.5), requested by Chandra Kurniawan
**Date:** 2026-10-29
**Scope:** `git diff 06c60c41~1..7619c242` limited to `app/`, `routes/`, `database/`. This is 079 plus the 077/078 backend work that rode in with PR #66. Android, Vue SPA and tests were not reviewed in depth.
**Method:** White-box review against `owasp-checklist.md` and `api-security-top10.md`. No code was executed: backend tests, Vitest and browser checks were not run.

**Module triage (logged, per SKILL step 3)**
- API module: LOADED. The diff declares routes and controllers (`routes/api.php`, `DeviceController`, `PosSetupController`).
- LLM module: SKIPPED. Grep for LLM SDK names in `composer.json`, `package.json`, `app/`, `config/` and `routes/` returned no hits.
- CI/CD module: SKIPPED. No pipeline or Dockerfile in the diff.

---

## Executive Summary

No Critical or High findings. The new Terminals endpoints are well guarded: owner/admin only through `FormRequest::authorize()`, `OutletScope` on every lookup, `not_found` returned uniformly for out-of-scope ids, and the outlet never read from the body except for the validated move target. The two findings worth fixing are both about data leaving its intended audience. Deactivating or deleting a terminal does not cut off a tablet that is already signed in (Medium), and outlet `notes` now print on customer receipts and reach every role (Medium). The rest are Low or Info.

| Severity | Count |
|----------|-------|
| 🔴 Critical | 0 |
| 🟠 High | 0 |
| 🟡 Medium | 2 |
| 🔵 Low | 4 |
| ⚪ Info | 3 |

**Recommendation:** 🟡 Ship with mitigations (fix SEC-M1 and SEC-M2 in the next cycle). The code is already merged to `develop-warkop-wjm`, so "ship" means promoting to a release.

## Threat Model

### System summary
Locally installed POS (Laravel API + Vue SPA, optional shared server for several outlets). Handles sales, shifts, staff PIN hashes and store identity. Customer contact data exists in orders and customers but is not touched by this diff.

### Top threats (prioritized)
1. **A lost or stolen tablet, or a dismissed cashier,** keeps operating after an admin "deactivates" the terminal, via a still-valid `pos-pin` token (SEC-M1).
2. **A cashier or customer** reads outlet notes that the owner wrote as internal, through `GET /settings/features` or a printed receipt (SEC-M2).
3. **An unauthenticated LAN client** floods tablet-setup routes to create terminal rows or probe staff PINs (SEC-L2; the PIN path is mitigated by the 5-wrong lock and two-tier throttle).
4. **A cashier** abuses the Terminals bulk endpoints across outlets (mitigated: `OutletScope`).

### Trust boundaries
- Browser → API (`auth:sanctum`, role gate in `FormRequest::authorize()`, outlet narrowed by `X-Outlet-Id` middleware).
- Tablet → API without a token (`/pos-setup/*`, `/staff/roster`, `/auth/pin-sessions`): the recorded Constitution IV exception. Only names, logo and mode leave these routes.
- API → MySQL (Eloquent; two raw queries, both parameterised).

---

## Findings

### 🟡 Medium

#### SEC-M1 — Deactivating or deleting a terminal does not end its signed-in session
- **Where:** `app/Services/DeviceService.php` `revoke()` (≈ line 318), `deleteIfUnused()`, `bulk()`; `app/Services/PinAuthService.php:25,31`
- **CWE:** CWE-613 (insufficient session expiration), area: access control / session management
- **Fact:** `revoke()` only sets `revoked_at`. `deleteIfUnused()` has no "must be revoked first" rule, so bulk `delete` can remove an ACTIVE terminal that has no order or shift yet. Neither touches Sanctum tokens. In `PinAuthService`, `PinAuthController` and the middleware I grepped, the `pos-pin` token (12 h) is created by name only and carries no device id.
- **ASSUMPTION:** a token issued from that terminal therefore stays valid until expiry. I did not execute this, and I did not read `issueToken()` or every middleware.
- **Impact:** an admin who deactivates a lost tablet from the new page sees "Dicabut", but the tablet can keep taking orders for up to 12 hours. The `activeTerminalOf` check only runs at PIN login.
- **Recommendation:** put the device id in the token (`createToken(..., ['device:'.$id])`), reject the request in a middleware when that device is revoked or missing, and delete the device's tokens in `revoke()`. Also require `revoked_at !== null` in `deleteIfUnused()` or revoke first.
- **Verify before fixing:** a feature test that logs in by PIN, revokes the terminal, then calls `GET /auth/me` with the old token.

#### SEC-M2 — Outlet `notes` are now printed on customer receipts and exposed to all roles
- **Where:** `app/Support/StoreIdentity.php` (`ownDetails`, `forReceipt`), `OrderController::receipt`, `SettingsController::features`, `StoreOutletRequest` (`'notes' => ['nullable','string']`, no max)
- **CWE:** CWE-200 (information exposure), area: data classification / least privilege
- **Fact:** `outlets.notes` was owner/admin-visible (`OutletResource`). Since 079 it prints as `store_notes` on the receipt of that outlet, and `GET /settings/features` returns it to every authenticated role (cashier included). `forReceipt` switches to the outlet's own block as soon as ANY of address/phone/email/notes is filled, so an outlet with only an internal note changes both its name block and its receipts on upgrade.
- **ASSUMPTION:** existing outlets contain internal notes. Not checked in any database; none read.
- **Impact:** internal text (supplier remarks, staff notes) printed to customers or visible to cashiers. The field also has no length cap, so a long value reaches the thermal printer and the receipt PDF.
- **Recommendation:** do not reuse `notes`. Add a dedicated `receipt_note` column (max 200) with its own form field, leave `notes` internal, and fall back to the shared block when only internal notes exist. Cheaper interim: add a UI warning and `max:200` on `notes`.

### 🔵 Low

- **SEC-L1 — TOCTOU on permanent delete.** `DeviceService::deleteIfUnused()` checks `Order`/`CashierSession` existence and then deletes without locking the device row. FKs are `SET NULL`, so an order created between check and delete silently detaches from its terminal. Needs a live terminal plus a concurrent order. Fix: `Device::lockForUpdate()->find()` first, and take the same lock on the order-create path (or re-check after delete inside the transaction). CWE-367.
- **SEC-L2 — Unauthenticated terminal creation.** `POST /pos-setup/outlets/{outlet}/terminals` (`tablet-write`, 10/min/IP) can mint about 14,400 rows/day from one LAN client. This is the accepted 073 exception, but 079 makes cleanup a manual bulk-delete chore. Fix: cap active terminals per outlet (for example 50) in `DeviceService::findOrRegister`. CWE-770.
- **SEC-L3 — Username enumeration by timing.** `AuthController::login` (`! $user || ! Hash::check(...)`) skips the hash for unknown users, so response time reveals valid usernames. The 078 evidence already lists it as not fixed. Fix: run `Hash::check` against a fixed dummy hash when `$user` is null. CWE-208.
- **SEC-L4 — Residual formula injection in re-imported exports.** `GenericArrayExport` is still used by `CustomerController`, `InvoiceExportImportController` and `SheetArrayExport` (master data). The exemption is deliberate (round-trip import), but a customer or item name beginning with `=` still becomes a formula. ASSUMPTION: a non-admin role can create customers (not checked against `CustomerPolicy`). Fix: neutralise on export and strip the prefix on import, or restrict who can write those fields. CWE-1236.

### ⚪ Info / Defense-in-depth

- `outlet_code` rule checks uniqueness before insert; the DB unique index `outlets_code_live_unique` is the real guard. ASSUMPTION: a collision race returns a 500 rather than a 422. Catch `QueryException` code 1062 and map it to 422.
- `PATCH /devices/{device}` and `POST /devices/bulk` have no per-user rate limit beyond the global API default (not reviewed).
- `docs/openapi-pos-mvp.yaml` was reported updated in the session log, but I did not diff the router against it (API9).

---

## Things That Looked Good

- Terminals: every route is owner/admin-gated in a `FormRequest`; lookups go through `OutletScope`; unreachable ids collapse to `not_found` (no existence oracle); `ids` capped at 100 and `distinct`.
- Outlet move target is validated against `OutletContext::isPermitted()`, so it is active, in the current data mode and permitted. The name is re-checked in the target outlet, and open shifts block the move (`OutletMoveGuard`).
- Activity-log writes sit inside the same transaction as the mutation, and the PIN never reaches a log or response.
- `LIKE` input is escaped (`addcslashes`); the two raw `DB::table` uses are parameterised.
- Unauthenticated `outletBranding` returns exactly three values and 404s for inactive outlets; address, phone, email and notes are not exposed there.
- 078 throttles: named buckets avoid the shared-IP bucket bug, login counted per account, `hit()` before compare (atomic), PIN login two-tier.
- `SafeStringExport` writes strings as explicit text cells for the two sales exports.
- Migration: soft-deleted outlets no longer hold codes at DB level (generated column), and `down()` refuses lossy narrowing.

## Out of Scope

- Android app, Vue SPA components (a grep of `resources/js` for `v-html` returned no hits), `public/build`.
- Dependency audit (A06), security headers, CORS, TLS and cleartext-HTTP decision (open item from 073).
- Runtime verification of any finding; no tests were run.
- `OverrideGate::issue` PIN leak noted open on 2026-10-26: not re-checked.

## Suggested Follow-ups

- [ ] Feature test for SEC-M1 (revoked or deleted terminal's token is rejected).
- [ ] Decide on the SEC-M2 field split before the next release; check existing `outlets.notes` values on the WJM installs.
- [ ] Add a `GenericArrayExport` formula-prefix regression test (SEC-L4) and a dummy-hash login timing test (SEC-L3).
- [ ] Re-run this review on the Android 079 changes (receipt printing of `store_notes`).
