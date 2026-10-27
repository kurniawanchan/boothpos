# Implementation Plan: Android POS — Three-Column PIN, User Photos Everywhere, Order Note on Documents, Login Lockout Fix

**Classification**: INTERNAL

**Branch**: `078-android-pin-3col-photos-notes-fixes` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

## Summary

Four fixes on top of 077 (still uncommitted on the working tree; commit 077 first):

1. **Login lockout (P1, server)** — root cause confirmed in `vendor/.../ThrottleRequests::resolveRequestSignature`: for unauthenticated requests the throttle key is `domain|ip` with **no route and no limit in it**, so every numeric `throttle:N,1` route (web login `5,1`; roster/branding/outlets/terminals/server-address `30,1`; pin-sessions `30,1`) shares **one counter per IP**. The tablet PIN screen polls roster + branding every 10 s (≈12 hits/min), which alone exhausts the web login's 5/min whenever the browser and tablets share an IP (Docker/NAT, same router) and eats most of the PIN login's 30/min. Fix: **named rate limiters with separate buckets**, web password login counted **per account on failures only**, Indonesian 429 message with the wait time, and the tablet's PIN-screen poll slowed.
2. **PIN screen (P2, Android)** — three columns (store panel | staff list + detail | PIN) on wide screens; each person once (selected row expands in place); the 077 clipping defect (heading/avatar cut off) removed by a fixed header + scrolling list.
3. **Photos (P2)** — the verified data says the demo tablet in the owner's photo showed initials **correctly**: only 1 of 12 users has a photo (a LIVE-outlet cashier; URL serves HTTP 200), and none of the 5 DEMO roster users has one. The real code gaps are the **approver picker** (`Sheets.kt:433` still draws `name.take(2)` and the approvers payload has no photo) and a **stale header photo** (the session user is read once at login). Both fixed.
4. **Order note on documents (P2)** — server `receipt` and kitchen `TicketResource` do not carry `orders.notes`; the web export omits it (web detail modal already shows it). Add the note to the three and print it on the receipt and KOT.

## Technical Context

**Language/Version**: Laravel 13 / PHP (server); Kotlin + Compose (`android-pos/`).
**Primary Dependencies**: existing only. Laravel `RateLimiter` named limiters (built in).
**Storage**: no migration. Rate-limit counters use the existing cache store; notes use `orders.notes`; photos use `users.photo_path`.
**Testing**: PHPUnit feature tests (container run only with `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array`); JUnit in `android-pos/app/src/test`. Device checks manual (adb absent).
**Target Platform**: Android tablet landscape (≥ 960 dp three columns), mid widths two columns, phone stacked.
**Project Type**: Laravel API + Android client + (read-only) web SPA.
**Performance Goals**: PIN screen polling traffic ≤ 8 requests/min/tablet (was ≈12); no new per-request work.
**Constraints**: login protection must not weaken (still ≈5 wrong passwords/min/account and 5-wrong-PIN lock); messages Indonesian; never log note text.
**Scale/Scope**: ~10 tablets per site possible behind one IP.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I Code quality / single path | PASS | One place defines limiters (`AppServiceProvider::boot` → `RateLimiter::for`), one place renders 429 (`bootstrap/app.php`). One store name/logo source (077) untouched. Note read from `orders.notes` everywhere — no second copy. |
| II Testing | PASS (plan) | Lockout: tests for isolation (tablet polling ≠ login budget), per-account counting, success-resets, Indonesian message + wait. Notes: receipt/ticket/export tests. Android: layout rule, dedupe, photo rule, document line builders. Device steps manual. |
| III UX | PASS | Indonesian copy; one entry per person; no clipped text. |
| IV Security | **PASS, with tightening and one residual** | Brute-force protection moves from "per IP for everything" to "per account+IP for failures" (stronger against targeted guessing, weaker only as a flood cap which gets a separate per-IP limiter). Account existence must not leak: the limiter key uses the submitted username whether or not it exists, same 401 message. Approvers `photo_url` is authenticated. Receipt/KOT notes are read through the existing outlet/mode-scoped models. Residual: roster `photo_url` (077) unchanged, still owner decision. |
| V Performance | PASS | Slower PIN poll lowers load. |
| Docs discipline | PASS (plan) | OpenAPI updated in the same commit (receipt, ticket, approvers, 429 shape). |

No unjustified violation; Complexity Tracking not needed. Re-checked after design: unchanged.

## Project Structure

### Documentation (this feature)

```text
specs/078-android-pin-3col-photos-notes-fixes/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/api-and-ui-contract.md
└── tasks.md   # /speckit-tasks
```

### Source Code (files touched)

```text
app/Providers/AppServiceProvider.php                 # RateLimiter::for(): login-flood, tablet-read, pin-login, tablet-write
bootstrap/app.php                                    # render ThrottleRequestsException → Indonesian JSON
routes/api.php                                       # swap throttle:N,1 → named limiters
app/Http/Controllers/Api/AuthController.php          # per-account failed-attempt limiter on login()
lang/{id,en}/auth.php                                # too_many_attempts (seconds)
app/Http/Controllers/Api/OrderController.php         # receipt(): + notes
app/Http/Resources/TicketResource.php                # + order_note
app/Services/SalesTransactionsService.php            # row + notes
app/Http/Controllers/Api/ReportController.php        # export column order_note
app/Http/Controllers/Api/OverrideController.php      # approvers: + photo_url (via OverrideGate payload)
docs/openapi-pos-mvp.yaml                            # same commit
tests/Feature/{LoginThrottleTest,TabletPollingThrottleTest,ReceiptOrderNoteTest,TicketOrderNoteTest,SalesExportOrderNoteTest,ApproverPhotoTest}.php   # NEW

android-pos/
├── core-network/.../dto/{ReceiptDtos,TicketDtos,...}.kt   # notes, order_note, approver photo_url
├── app/src/main/java/com/warkop/pos/
│   ├── model/{PosModels,KitchenPrint}.kt     # ReceiptView.orderNote, PrintableKot.orderNote, Approver.photoUrl
│   ├── data/{ReceiptMapper,TicketMapper,RemoteBackend}.kt   # map note/photo; me() refresh
│   ├── print/Documents.kt                    # struk() + kot() print the order note
│   ├── state/PosStore.kt                     # PIN poll cadence; refresh session user photo
│   ├── ui/PinLayoutRule.kt                   # THREE_COLUMN / TWO_COLUMN / STACKED
│   ├── ui/screens/PinScreen.kt               # 3 columns, one row per person, LazyColumn list
│   └── ui/Sheets.kt                          # approver avatar → UserAvatar
└── app/src/test/java/com/warkop/pos/…        # new/updated tests
```

**Structure Decision**: server rate-limit + document payloads, Android PIN layout and documents. Web SPA untouched (detail modal already shows `order.notes`; the login screen shows the server's message).
