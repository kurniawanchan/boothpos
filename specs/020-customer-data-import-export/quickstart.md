# Quickstart: Verifying Customer Data Import & Export

Manual verification steps once the feature is implemented — this is the
"actually run it in a browser" check Constitution II requires beyond the
automated test suite.

## Backend tests

```bash
docker compose exec app php artisan test --filter=CustomerImportExportTest
```

Expect all cases to pass, including: happy-path create, happy-path update
(email match), blank-cell-leaves-unchanged on an update row, a missing
`name` reported as a row error with no partial save, a non-spreadsheet
file rejected, `dry_run=1` reporting without writing, and the DEMO/LIVE
mode isolation case (a DEMO-mode customer's email must not be matched by
a LIVE-mode import, and vice versa).

## Manual walkthrough (real browser, per Constitution II)

1. Log in as `owner` (or `admin`/`inventory`) at `http://localhost:8000`.
2. Go to **Customers**. Confirm the export/import controls are visible for
   this role.
3. Log in as `kasir01` instead (a role *not* in `canManageMasterData()`).
   Confirm the export/import controls are **not visible at all** on this
   screen (Constitution III — hidden, not disabled/403-on-click).
4. Back as `owner`: click **Export**. Confirm an `.xlsx` file downloads
   with a header row `name, phone, address, email, social_handle, notes`
   and one row per existing customer in whichever mode (DEMO/LIVE) is
   currently active.
5. Click **Download template**. Confirm it downloads the same header row
   with no data rows.
6. Edit the exported file: add two new rows (one with an email, one
   without), and change one field on an existing row that has an email.
7. Import with **preview/dry run** first. Confirm the modal reports the
   correct created/updated counts and zero errors, and that no new
   customer appears on the Customers list yet.
8. Import for real. Confirm: the two new rows appear as new customers; the
   edited existing-customer row's changed field is updated and its
   untouched fields are unchanged; the customer count matches what the
   preview reported.
9. Re-import the exact same file a second time without any further edits.
   Confirm no new duplicate customers are created for the rows that have
   an email (they update the same customers again, no-op in practice),
   while the row without an email creates *another* new customer (this is
   expected — see spec's Edge Cases).
10. Try importing a `.txt` file renamed to `.xlsx`. Confirm it is rejected
    with a clear error and nothing is created.
11. Try importing a row with a blank `name`. Confirm the whole import is
    rejected (`409`, all-or-nothing) and the row/field is identified in
    the error list — no customers from the *rest* of that file are
    created either.
12. Check `storage/logs/laravel.log` / the Activity Log screen for an
    entry recording the import (user, counts), and confirm it is present
    only when the import actually applied (not present for a `dry_run`
    that reported errors and saved nothing).
