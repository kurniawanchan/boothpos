# Quickstart / Verification: Transaction Approval & Seller Audit

**Classification**: INTERNAL

## Setup

```bash
php artisan migrate                       # adds order_approvals
npm run build                             # or npm run dev
# Backend tests (host):          php artisan test --filter=OrderApproval
# Inside the app container ONLY with the safety flags:
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=OrderApproval
npx vitest run --exclude '.claude/**'
```

Dev data: a sale with items from ≥ 3 sellers (the Sakana Fridge demo seeder has several sellers). Create users: `userA` (role with `sales`, `artist_id` = Seller A), `userB` (Seller B), plus seeded `owner`/`admin`/`kasir01`.

## Scenarios (map to spec)

1. **Single seller, stamp (US1)** — as `userA` open a Seller-A-only sale → Approval section shows Pending → *Stamp Approved* → confirm → Approved, status `Fully Approved (1/1)`. Reload: still approved; the sale was never auto-approved by viewing.
2. **Draw / upload (US1)** — draw with mouse, then on an Android device with touch; upload a PNG and a JPG; a `.gif`/`.pdf`/6 MB file and a blank drawing are refused with clear messages.
3. **Multi-seller (US2)** — sale: Indomie Goreng + Es Teh (A), Kopi Susu (B), Roti Bakar (C). Three rows, not four. `userA` can act only on A (B/C show no action; a crafted POST for B → 403). Approve A, B → `Partially Approved 2/3`; approve C as `owner` (on behalf) → `Fully Approved 3/3`, row shows "on behalf".
4. **Double submit (US1/SC-004)** — fire two POSTs for the same seller at once → one 201, one 409 `already_approved`, one row.
5. **List (US3)** — Sales page: icons per row; click one → popover with counts + per-seller approver/time/method; filter `Partially Approved` + a date + a search term; reload keeps `?appr=partial`; page count and "select all" follow the filter.
6. **Stale / invalidation (US4)** — in tinker/test change one `order_items.qty` of Seller B on an approved sale: B reads Pending ("re-approval required"), A stays Approved; POST for B with the old `items_hash` → 409 `approval_stale`; reload GET, approve again → new row, old row kept (`invalidated_at`).
7. **Void** — void an approved sale: no approving; list shows it only with "show voided".
8. **Audit/protection (US5)** — as owner open history (all cycles); as `userB` request Seller A's image → 403; no PUT/DELETE route exists.
9. **Legacy** — an old sale (no rows) shows `Pending 0/N` and appears under the Pending filter.
10. **DEMO clone** — `php artisan app:clone-live-to-demo` then open a cloned approved sale: still Approved (hash survives id remap), image opens.

## Must be run in a real browser (Constitution II) — OPEN until done

Drawing works with mouse, touchpad and an Android touch device; popover keyboard/Escape behaviour; phone-width layout of the Approval section; console free of errors; production build shows the approval dialog as its own chunk.
