# Quickstart: verifying 057

## Backend

1. Migrate the dev and test databases (the new migration sorts after `2026_12_24_000003`). Run `php artisan migrate`.
2. Feature tests (container command with the flags on `docker compose exec`):
   ```bash
   docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter='DiscountStoresPercentReasonTest|ReprintCopyFromOrderTest'
   ```
3. Apply an order discount of 11 % with reason "promotion" through the API; `GET /tabs/{id}` shows `discount_percent` 11 and the reason. Remove it; both fields are null.

## Tablet (manual)

4. Pending QRIS on the payment screen: "Verifikasi" is readable; status and amount on readable lines (US1).
5. QRIS verify sheet: Verifikasi on its own full-width row; full label visible (US2).
6. Cetak ulang on a completed order: the copy prints; the counter reads "cetak ulang 1×"; a second tap prints the next copy (US3).
7. Order detail: the Refund button reads "Refund" in full (US4).
8. Completed order list: closing time reads HH:MM; no "null" anywhere (US5).
9. Tandai habis on a product: reasons appear; with the session expired, a message and Coba lagi appear (US6).
10. Offline for a minute, back online, Coba lagi on the sign-in screen: the staff list loads or a sign-in message appears; no dead button (US7).
11. Apply a discount with a percentage and reason: the order detail and receipt show them (US8).
