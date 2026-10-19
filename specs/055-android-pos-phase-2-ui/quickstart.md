# Quickstart: verifying 055

Each step names the story or requirement it proves. Record evidence in tasks.md, not in the repository.

## Backend

1. Run the new feature test in the container (flags go on `docker compose exec`, see CLAUDE.md note):
   ```bash
   docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=PosCategoriesTest
   ```
   Expected: mode scoping, inactive hidden, zero-product category kept, gate 403 for a non-POS role.
2. Read the endpoint in each mode (R2 confirmation):
   - Set the mode to DEMO (Settings → System mode), call `GET /api/v1/pos/categories`; expect the DEMO categories (5 from the seed).
   - Set LIVE, call again; expect the LIVE categories (compare with the admin Categories page in LIVE).
   - Restore the mode the dev install had before.

## Tablet (manual; needs the pilot tablet and the server)

3. Counter in landscape: products left, order list right; the category strip shows every category from step 2, scrolls sideways, no name cut (US1, US2, FR-001, FR-004, FR-005).
4. Switch the server to LIVE, pull down on the counter: the categories and products change to LIVE (US3, SC-003).
5. Totals area: tap hide, then show; values unchanged; the choice survives an app restart (US5, FR-008).
6. Kirim and Bayar: full words visible; Bayar shows no amount (US6, FR-009, FR-010). Test with a long label size setting.
7. Order list text is 2 sp smaller than before (US7); compare with a screenshot of the old build.
8. Tax line shows the server's `tax_label` and `tax_rate` (FR-015): change the rate in settings, pull to refresh, confirm the change.
9. Pull to refresh on Meja, Counter, Order, Shift, Perangkat: indicator shows; failure keeps the last data (US4, FR-016–FR-019).
10. Server unreachable: counter shows the failure state with "Coba lagi", no sample items (FR-014, FR-013a).

## Android unit tests

11. `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`. Expected: all pass, including the category-source test (categories are not derived from products) and the totals-preference test.
