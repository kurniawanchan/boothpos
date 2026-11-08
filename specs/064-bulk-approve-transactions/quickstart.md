# Quickstart: verifying Bulk Approve Transactions

**Classification**: INTERNAL

Local dev only. Seeded accounts and the DEMO dataset come from `php artisan db:seed` +
`php artisan db:seed --class=SakanaFridgeDemoSeeder` (see CLAUDE.md). Credentials are in CLAUDE.md; they are not repeated here.

## Automated

```bash
# Backend — inside Docker ONLY with the explicit overrides (a bare `php artisan test` wipes the dev DB)
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app \
  php artisan test --filter='BulkOrderApproval|OrderApproval|MenuActionsRouteCoverage|ForbiddenSurfaces|CloneRegistryDrift'

# Frontend
npx vitest run --exclude '.claude/**' qa-tests/unit/BulkApproveModal.test.js qa-tests/unit/ApproveSellerModal.test.js
```

Run one backend test process at a time (two at once corrupt `boothpos_test`). Migrate the dev DB first
(`php artisan migrate`); the entrypoint only migrates on container start, and a stale schema answers 503 `schema_outdated`.

Backend cases that must exist (each maps to spec scenarios):

1. Stamp / draw / upload bulk over N sales → one approval per eligible seller, `bulk_ref` shared, overall status updated (US1, US2).
2. Mixed batch (pending, already approved, voided, other-seller-only, not visible, other mode) → only eligible recorded; `approved + skipped = selected`; reasons correct (US3, FR-013).
3. Seller-scoped user on a mixed sale → only own seller; other seller stays Pending (US3.4).
4. Owner/admin → every pending seller of each sale, `on_behalf = true`, per-order atomic (rollback one order, others stay).
5. Freshness: change a seller's lines between preview and apply → that order `changed`, others approved.
6. Crafted request: ids the user cannot see / other mode → `not_found`, no state revealed; role without `sales.approve` → 403; non-linked non-admin → nothing recorded.
7. Invalid image (blank/oversized/GIF/stamp+image/missing) → 422 and ZERO rows, ZERO files (FR-007).
8. Repeat the same request, and two concurrent applies → exactly one active row per seller per order (SC-005).
9. One shared file for the batch; deleted when nothing was approved; each row's `can_view_image` still per-row.
10. Over `approvals.bulk_max` → 422 naming the limit, nothing written.
11. Query-count test: preview and the READ part of apply do not grow per order (warm-up call first; mutation-check by lazy-loading).
12. Existing single approval tests still pass unchanged (the `recordSeller()` extraction must not alter behaviour).
13. `GET /orders/{id}/approvals` history shows `via_bulk` for owner/admin; absent for others; no edit/delete route exists.

## Manual — real browser (Constitution II; NOT yet verified at plan time)

1. `php artisan migrate`; log in as a seller-linked user (create a throwaway user linked to a seller; do not reuse real data).
2. Sales → filter Approval = Pending → **Select all** → **Bulk approve (N)** → Stamp → confirm dialog shows counts/seller/amount → confirm.
   Expect the result summary, refreshed icons, filter results matching (SC-009), console clean.
3. Repeat with **Draw signature** (mouse AND trackpad), then **Upload image**. Open one sale from each: signature visible; as another role, image hidden.
4. Cancel at each step → nothing recorded. Double-click Confirm → one approval per seller.
5. Phone width, then an **Android** device/emulator touch drawing (SC-008).
6. Select > 200 rows → limit message. Select only already-approved rows → "nothing to approve", Confirm unavailable.
7. Owner/admin: a 3-seller sale gets 3 approvals on behalf; history shows "via bulk".
8. `npm run build`: confirm `BulkApproveModal`/`SignaturePad` stay separate lazy chunks (Constitution V).

## Done means

All backend/Vitest cases above green with the exact commands run in-session, the manual list executed (or explicitly
reported as not executed), OpenAPI + PRD/CLAUDE.md notes in the same change, and 9 known `Code128` backend failures reported as
pre-existing, not hidden.
