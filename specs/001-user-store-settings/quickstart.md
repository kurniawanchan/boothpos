# Quickstart: Pengaturan Pengguna dan Toko

Manual smoke-test path once this feature is implemented — matches this
repo's established "verify in a real browser, not just unit tests"
Constitution requirement (Principle II). Assumes the app is already
running per `docs/RUNBOOK.md` (`php artisan serve` + `npm run dev`,
seeded dev accounts).

1. **Confirm today's behavior is unchanged for existing accounts.**
   Log in as each seeded account (`owner`, `admin`, `kasir01`,
   `inventory`) and confirm the sidebar/available screens are identical
   to before this feature shipped. This is the most important check —
   it proves the 4 seeded default roles correctly reproduce current
   access (research.md Decision 5 / Constitution Principle IV gate).

2. **Create a custom role.** As `owner`, open Pengaturan → Peran, create
   a role named "Kasir Event A" with `menu_keys` limited to `pos` and
   `session` only. Confirm `roles` and `users` are correctly absent from
   the checkbox selection working as expected (they can be selected —
   nothing stops an owner from doing that on a *different* role, since
   FR-013 only blocks the case where doing so would leave *zero* capable
   roles).

3. **Assign it and verify real enforcement, not just hidden UI.** Create
   a user with that new role. Log in as that user: confirm the sidebar
   shows only Kasir and Sesi Kasir. Then, in a separate tab/incognito
   session, call a disallowed endpoint directly (e.g.
   `GET /api/v1/products` with that user's token) and confirm it
   returns `403` — proving the restriction is enforced server-side, not
   just a hidden nav link (Constitution Principle IV).

4. **Test the two lockout guards.**
   - Log in as `owner`, try to deactivate your own account → expect a
     clear rejection, not a crash.
   - Try to delete or strip `users`+`roles` access from the *only*
     remaining role that has it → expect a clear rejection.

5. **Test the delete-in-use guard.** Try deleting the "Kasir Event A"
   role while the user created in step 3 still has it → expect `409`
   naming how many users are affected. Deactivate that user, retry →
   expect success.

6. **Complete the store profile.** Pengaturan → Toko: fill address, logo,
   contact person, phone, email; save; reload the page; confirm every
   field persisted. Complete a POS sale and open its receipt — confirm
   the store name/logo/contact now appear correctly.

7. **Bulk export/import.** Export users, open the file, add two new rows
   with a role name that exists — re-import, confirm the two new
   accounts appear and can log in. Then edit the file to reference a
   role name that does **not** exist and re-import — confirm the entire
   import is rejected with a row-level error naming the bad role, and
   that nothing else in the file was partially applied.

8. **Photo upload edge case.** Attempt to upload a `.txt` file renamed to
   `.jpg` as a user photo — confirm it's rejected by real MIME
   inspection, not just the file extension (matching the existing
   `ImageUploadService` defensive-upload convention).
