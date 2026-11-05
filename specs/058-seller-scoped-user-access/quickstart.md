# Quickstart: verifying seller scope

INTERNAL

1. `php artisan migrate`, seed two sellers A and B with products, one mixed A+B sale, one B-only sale.
2. Assign user `kasir01` to seller A (Users screen). Log in as `kasir01`.
3. Check: Products/Stock/Stock history show only A; Sales lists the mixed sale (whole order) and not the B-only sale; Dashboard/Reports/exports equal A's own lines; seller filters list only A.
4. As `kasir01` call `GET /products/{B product id}`, `/orders/{B-only id}` → 404. Create a product → seller A forced. `POST /stock/adjust` with a B variant → 404.
5. `GET /users`, `/activity-logs`, `/backups` → 403 `seller_scope_forbidden`.
6. Clear the link: behaviour returns to today's. Deactivate seller A: login works, empty view with notice.
7. Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=SellerScope` and `npm test`. Browser check with console open (constitution II).
