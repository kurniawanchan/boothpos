**Classification: INTERNAL**

# Quickstart / Verification: Transaction Reference — Thumbnails and Multiple Images (055)

## Baseline (2026-11-04, before any 055 change)

Dev DB: 0 pending migrations. `OrderReferenceTest|CloneRegistryDriftTest|LiveToDemoClonerTest`: 30 passed (207 assertions). Vitest `SalesReference` + `SalesReferenceLink`: 11 passed.

## Results (2026-11-04)

- Feature backend suites (`OrderReference*`, `DemoClone`, `SalesTransactions`, `SalesPageData`): **151 passed**. `DemoClone` (incl. `CloneRegistryDriftTest`): 77 passed.
- Full frontend suite (`npx vitest run --exclude '.claude/**'`): **1109 passed, 2 skipped, 0 failed**; Sales component files after the link move: 114 passed.
- Full backend suite: **NOT completed**. Two runs were cut off by Docker stack restarts (15:31, 16:53 WIB) and a third was stopped deliberately because the host was overloaded (load average 138, swap nearly full). Before stopping: 584 passed, 9 failed — all 9 in `Code128Test`, environmental (`picqer/php-barcode-generator` is in composer.lock but absent from the container's vendor/; same 9 failed before this feature). 96 test classes did not run.
- Dev DB migrated: 13 existing 054 reference images (all LIVE) moved to `order_reference_images`, every file present, old columns dropped.
- Real-browser checks below: **NOT run** (Playwright / Chrome DevTools tools unavailable in the session).

## Prerequisites

```bash
docker compose up -d                     # entrypoint runs the new migration on start
# or, if the container was already running:
docker compose exec app php artisan migrate
```

Tests inside the container — ONLY with the explicit overrides (CLAUDE.md, the dev DB has been wiped twice):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=OrderReference
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=CloneRegistryDriftTest
npx vitest run --exclude '.claude/**' qa-tests/component/SalesReference.test.js qa-tests/unit/localeKeys.test.js
```

## Backend checks (automated, `tests/Feature/OrderReferenceTest.php` + new `OrderReferenceImagesTest.php`)

1. Migration: an order with a 054 single image ends with exactly one `order_reference_images` row (position 1, same path/mime) and the old columns are gone; both DEMO and LIVE rows migrate.
2. Upload 3 images in one request → 3 rows, positions 1-3, one activity-log row with `images_added: 3`.
3. Add 2 more later → positions 4-5, first three untouched.
4. Stored 9 + 2 new → 409 `reference_image_limit`, `remaining: 1`, no row/file written.
5. 11 files in one request → 422.
6. One of three files is a renamed `.txt` (real temp file, not `UploadedFile::fake()` — it reports MIME from the extension) → 422 `images.1`, none of the three stored, no stray file on disk.
7. Remove image #2 of 3 → rows #1 and #3 remain in order, file #2 deleted after commit, `GET …/images/{#2}` → 404.
8. Remove ids of ANOTHER order's images → ignored (their rows/files intact), request still succeeds.
9. Remove the only image of a note-less reference via PUT → 422 `reference_empty`.
10. `GET /orders/{A}/reference/images/{imageOfB}` → 404 (scoped binding); user without `sales` menu → 403; cross-mode order → 404.
11. Cashier who is not the sale's cashier → 403 on PUT/DELETE, but can GET the block and images.
12. Exception after files were written (force via a failing logger/DB) → no rows, no files left.
13. `GET /reports/sales` `has_reference` true for image-only references; query count does not grow with the number of rows.
14. `OrderResource` (`GET /orders/{id}`) and `GET …/reference` return the identical block.
15. Amounts, stock movements, payments unchanged by every reference save.
16. Clone LIVE→DEMO: each image is copied to a new file; the DEMO row never points at a LIVE path; `CloneRegistryDriftTest` green.

## Real-browser checks (Constitution II — console must be clean)

Log in as `kasir01` (`password123`, local dev only), create a sale, then on the Sales page:

1. Open the sale detail → reference card shows "Add reference". Add a note + select 3 images at once → Save.
2. Detail now shows 3 thumbnails (no extra click). Click the 2nd → full-size lightbox, ←/→ and buttons move between images, `2 / 3` shown, Escape/close works.
3. Edit reference: select 8 more images → refused with "7 more images allowed" (or the client trims with a message); the valid selection up to the limit is kept.
4. Pick a `.pdf` and a 6 MB JPEG along with a valid PNG → the two are named as refused, the PNG stays selected and saves.
5. Mark one existing image for removal, close without saving → reopen, still there. Mark again, Save → gone, the others keep their order.
6. Log in as `kasir02` → open the same sale: thumbnails visible, no add/remove controls.
7. Customer history screen → open the same transaction (shared `TransactionItemsModal`) → same thumbnails.
8. Delete a file by hand from `storage/app/private/order-references/` → its thumbnail shows the placeholder, others load.
9. Sales list: the reference link (📎 Referensi) sits directly BELOW the transaction number in the same cell (moved from the actions column, 2026-11-04 request); a sale with no reference shows none.
10. DevTools Network: image requests are `/api/v1/orders/{id}/reference/images/{imageId}` with `Cache-Control: private, no-store`; at most 3 in flight at once.
