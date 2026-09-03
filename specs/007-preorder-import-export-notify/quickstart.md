# Quickstart: Pre-order Import/Export, Printing, Email Notification & Search

Manual walkthrough to validate the feature end-to-end after implementation. Run against the real API (`php artisan serve`) with the SakanaFridge demo data seeded (`php artisan db:seed --class=SakanaFridgeDemoSeeder`) or real data.

1. **Search (US1)** — Log in as `owner`. Open Pre-orders. Type part of a known customer's name into the new search field. Confirm only that customer's pre-order(s) show, and that combining it with the status filter narrows further.
2. **Print (US2)** — Open a pre-order with status `ordered` or `dp_paid`; click "Cetak" and confirm the generated document is labeled **Invoice** with an outstanding balance shown. Advance a (different) pre-order to `settled` or `handed_over` and confirm its printed document is labeled **Struk/Kwitansi** with no outstanding balance. Cancel a third and confirm its document is clearly marked cancelled.
3. **Export (US3)** — As owner, apply a status filter on the list, click "Ekspor .xlsx", and open the downloaded file — confirm row count matches the filtered list and figures match what's on screen.
4. **Import (US3)** — Download the import template, fill in 2–3 new pre-orders (including one row with a customer name that doesn't exist yet), submit via "Impor". Confirm the new pre-orders appear in the list at status `ordered`, and that the not-yet-existing customer name now exists as a `Customer`. Then submit a file with one intentionally bad row (unknown SKU) and confirm **nothing** from that file was created and the bad row is reported.
5. **Email on status change (US4)** — Ensure a test pre-order's customer has a real, reachable email (or check `storage/logs/laravel.log` if `MAIL_MAILER=log`, the shipped default). Change the pre-order's status and confirm a notification was attempted — check the pre-order detail screen shows the outcome (sent/failed/skipped).
6. **Email — no address on file** — Change status on a pre-order whose customer has no email. Confirm the status change still succeeds and the screen shows "skipped_no_email", not an error.
7. **Manual resend (US4)** — Without changing status, click "Kirim ulang notifikasi" on a pre-order and confirm a new attempt is logged.
8. **Role gating (FR-015)** — Log in as `kasir01`. Confirm export/import buttons and the resend action are not visible on the Pre-orders screen, and that directly calling `GET /preorders/export`/`POST /preorders/import`/`POST /preorders/{id}/notifications/resend` returns 403.
9. Check the browser console on every screen touched — zero errors.
