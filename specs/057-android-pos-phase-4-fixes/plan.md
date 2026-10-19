# Implementation Plan: Android POS Phase 4 — Payment, Order and Shift Fixes; Discount Stored on the Order

**Branch**: `057-android-pos-phase-4-fixes` (not created; work sits on `054-android-pin-layout-fixes` in the tree) | **Date**: 2026-10-18 | **Spec**: [spec.md](./spec.md)

**Input**: `specs/057-android-pos-phase-4-fixes/spec.md`

## Summary

Phase 4 has one backend change and several tablet fixes.

1. **Discount stored on the order (US8, FR-012/013).** `DiscountService::apply` already computes the amount on the server. The percentage and reason are written only to the activity log. The plan adds the percentage and reason columns to `orders` (whole-order discount) and `order_items` (line discount), writes them in the same transaction, clears them when the discount is removed, and returns them in the order payload. Older discounts keep showing the amount only.
2. **Reprint (US3).** The tablet sends no copy number, and the server requires `copy_no = receipt_copies + 2`. The tablet now takes the copy number from the order's server count (not from its own counter) and sends it. A 409 `reprint_copy_changed` re-reads the order.
3. **Closed-order time (US5).** The closed-order list has no closing time the tablet can read. The plan maps the server's closing timestamp in the order list (verified fields to be confirmed, research R4), and omits the time when absent.
4. **Refund button label (US4)** and **Verify button row (US2)**: layout only, reusing the fitted-label helper from phase 2.
5. **Payment left column (US1)**: layout of the pending QRIS tile in `TenderScreen`.
6. **Reconnect (US7)**: on a 401 with the PIN session token, drop that token and retry once with the admin token; the staff list then loads, or the screen asks for sign-in. "Coba lagi" always produces a visible result.
7. **Sold-out reasons (US6)**: the reason load ends in a result or a failure with Coba lagi, never an endless spinner. Same 401 fix as US7.

No change to the refund, void, QRIS, or cash rules.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 13 (backend); Kotlin + Jetpack Compose, material3 1.3.0, Compose BOM 2024.09.00 (`android-pos/`)

**Primary Dependencies**: Eloquent, `DiscountService`, `TabService`, `ReprintService`, `OrderResource` / `TabResource`; Android `PosStore`, `RemoteBackend`, `OrderMapper`, `PosApi`, `ReprintRequestDto`, `TokenVault`, `ChipFit` (054), `FitPillText` (055)

**Storage**: MySQL 8: new nullable columns on `orders` and `order_items` (migration). Android: no new storage.

**Testing**: backend feature tests against MySQL (container command with `-e` flags on `docker compose exec`); Android JVM unit tests; manual tablet checks.

**Target Platform**: Android tablet (pilot, landscape reference); the server on localhost (dev) or a shared server.

**Project Type**: mobile app plus one backend migration and response additions.

**Performance Goals**: discount apply stays one transaction; no extra queries per order in lists (columns read with the order).

**Constraints**: the migration filename must sort after `2026_12_24_000003_add_refund_business_date_to_orders.php` (load-bearing order). Historical discounts are not back-filled (FR-013). Amounts remain server-computed.

**Scale/Scope**: one outlet's counter; a few hundred orders per shift.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I. Code quality | The discount write stays in `DiscountService` (the only writer of discount amounts); the percentage and reason are written in the same branch. The reprint copy rule stays in `ReprintService`; the tablet only sends what the server gave it. | PASS |
| II. Testing | Backend feature tests for: order and line discount store percent and reason; clearing removes them; old orders show amount only; reprint copy from the order count; closed-order time present. Android unit tests for: copy number from the order; 401 drops the session; reasons state never spins. | PASS (device checks pending) |
| III. UX | Existing tokens, Material 3, Indonesian copy; the Verify and Refund buttons reuse the fitted-label helper; no disabled-but-clickable 403 control added. | PASS |
| IV. Security | The percentage and reason come from the request and are validated by the existing rules; the server still recomputes the amount (client amounts never trusted). Refund, void and QRIS checks unchanged. The 401 fallback never bypasses the server's auth; it asks for sign-in. | PASS |
| V. Performance | Two nullable columns on existing rows; no N+1; the closing time comes from the same order query. | PASS |
| Stack shape | Works in shapes (a) and (b): same API; migration runs on the same schema. | PASS |
| Documentation | `docs/openapi-pos-mvp.yaml`: new order fields and the reprint `copy_no` request field, same change. | PASS (task) |

**Gate result**: PASS, no violations. Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/057-android-pos-phase-4-fixes/
├── spec.md
├── plan.md                  # this file
├── research.md              # R1–R7
├── data-model.md            # discount columns, reprint numbering, closed-order time
├── quickstart.md
├── contracts/
│   ├── order-discount.md    # order payload: discount percent, reason
│   └── reprint-copy.md      # reprint request/response copy_no
├── checklists/requirements.md
└── tasks.md                 # NOT created here
```

### Source Code (repository root)

```text
database/migrations/2026_12_25_000001_add_discount_percent_and_reason.php   # NEW: orders + order_items
app/Services/DiscountService.php                  # EDIT: write percent + reason (order and line); clear on remove
app/Http/Resources/OrderResource.php              # EDIT: discount_percent, discount_reason_code (+ label)
app/Http/Resources/TabResource.php                # EDIT: same as order payload where tab payload is built
app/Models/Order.php, app/Models/OrderItem.php    # EDIT: fillable/casts for the new columns (not mass-assigned from request)
app/Services/ReprintService.php                   # REVIEW only: copy rule unchanged
docs/openapi-pos-mvp.yaml                         # EDIT: order discount fields; reprint copy_no; closed-order time
tests/Feature/DiscountStoresPercentReasonTest.php # NEW
tests/Feature/ReprintCopyFromOrderTest.php        # NEW (or extend an existing reprint test)

android-pos/core-network/.../dto/S4Dtos.kt        # EDIT: ReprintRequestDto gets copy_no
android-pos/core-network/.../dto/TabDtos.kt       # EDIT: order discount fields; closed-order timestamp (verified field)
android-pos/app/.../data/RemoteBackend.kt         # EDIT: reprintOrder(copyNo); 401 handling helper for session
android-pos/app/.../data/OrderMapper.kt           # EDIT: map discount percent/reason; closedAt from server time (omit when absent)
android-pos/app/.../state/PosStore.kt             # EDIT: reprint uses order count; session drop on 401; reasons load result
android-pos/app/.../ui/screens/TenderScreen.kt    # EDIT: left column QRIS block layout
android-pos/app/.../ui/Sheets.kt                  # EDIT: Verifikasi row; sold-out reasons failure + Coba lagi
android-pos/app/.../ui/screens/OrdersScreen.kt    # EDIT: detail buttons (Refund label); closed time text
android-pos/app/src/test/.../state/ReprintCopyTest.kt        # NEW
android-pos/app/src/test/.../state/SessionRecoveryTest.kt    # NEW
android-pos/app/src/test/.../state/SoldOutReasonsTest.kt     # NEW
android-pos/app/src/test/.../data/OrderMapperDiscountTest.kt # NEW
```

**Structure Decision**: extend the existing discount service, order resources, reprint and session handling, and the existing screens. One migration for the two column pairs. No new screen or module.

## Complexity Tracking

> No constitution violations. Nothing to justify.
