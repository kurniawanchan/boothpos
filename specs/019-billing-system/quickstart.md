# Quickstart: License & Invoice Management (expanded scope)

Manual verification steps (Constitution II — this feature has no automated
UI-testing equivalent for PDF/image download or the Excel round-trip, so
these must be walked through in a real running browser + API, same
discipline as every prior feature in this codebase).

1. **License catalog**: Log in as `owner`. Confirm a new **"Lisensi"** menu
   item exists, separate from "Perusahaan". Open it — confirm the seeded
   "Pro" and "Master" licenses are present with a price, payment type, and
   description. Create a new License with a custom price/payment
   type/description; confirm it saves and appears in the list.

2. **DEMO/LIVE visibility**: Toggle system mode between DEMO and LIVE
   (Settings). Confirm the same Pro/Master License rows are visible
   identically in both modes (not duplicated, not hidden).

3. **Company ↔ License link**: Open an existing Company's record; confirm
   its linked License (name, price, payment type) is shown.

4. **Invoice creation (full form)**: Confirm a new **"Invoice"** menu item
   exists, separate from both "Perusahaan" and "Lisensi". Create a new
   invoice: select a Company, select a License (payment type shown),
   fill Subtotal, an optional Discount, confirm Grand Total computes, pick a
   Due Date, confirm Payment Information pre-fills from Settings → Payment
   (step 8) or is blank if not yet configured, add Notes. Save — confirm an
   `invoice_number` (e.g. `INV-202609-0001`) is generated and shown.

5. **Detail + click-to-open**: From the Invoice list, click the invoice
   number — confirm the full detail view opens showing every field entered
   in step 4 plus its current status ("Unpaid").

6. **PDF/image download**: From the invoice detail view, download as image
   — confirm a `invoice-INV-202609-0001.png` file is produced showing the
   invoice content. Download as PDF — confirm a `.pdf` file is produced.

7. **Statistics**: Return to the Invoice list. Confirm the statistics panel
   shows the correct unpaid count/total, paid count/total, and overall
   count, matching a manual tally of the visible list.

8. **Settings → Payment**: Navigate to Settings → Payment (a new sibling
   item next to Settings/Users/Roles). Enter bank name, account number,
   account holder, and instructions. Save. Create a new invoice — confirm
   its Payment Information section now defaults to what was just saved,
   while still being editable per-invoice.

9. **Mark paid + delete guard**: Mark the step-4 invoice as paid. Confirm
   its status becomes "Paid" and attempting to delete it is refused (a
   clear error, not a silent no-op). Create a second, still-unpaid invoice
   and confirm it CAN be deleted.

10. **Excel export/import round-trip**: From the Invoice list, export to
    Excel — confirm a file downloads with the expected columns. Re-import
    that same file (dry run first, then applied) — confirm no duplicate
    invoices are created (existing `invoice_number`s are recognized and
    treated as updates, skipped where the invoice is already `paid`).

11. **Role gating**: Confirm a cashier/inventory-role user cannot see the
    "Lisensi" or "Invoice" menu items and gets 403 on direct API calls to
    `/licenses`/`/invoices`.

12. **Regression**: Run the full `php artisan test` suite — confirm no
    regressions in `CompanyTest`/existing `InvoiceTest` (updated for the new
    field shape)/`LicenseActivationTest` (feature 018, unrelated but must
    stay green given the naming proximity, R0).

---

# Second expansion (2026-09-06) — additional manual verification

13. **Company edit**: Open an existing Company, edit its name/contact/
    business type/License, save — confirm the changes are reflected in the
    Company list and detail immediately.

14. **Company delete guard**: Attempt to delete a Company that has at least
    one Invoice recorded against it — confirm it's refused with a clear
    message (409), not a silent failure. Then attempt to delete a Company
    with zero Invoices — confirm it succeeds and disappears from the active
    list.

15. **Invoice detail shows business type + payment info**: Open an
    invoice's detail view — confirm the billed Company's business type is
    visible, and the payment-information section clearly shows the
    structured payment details (not just a vague paragraph if that data
    has a recognizable bank-name/account-number/account-holder/
    instructions shape).

16. **Downloads include business type + payment info**: Download that same
    invoice as an image and as a PDF — confirm BOTH show the business type
    and the same payment-information content visible on screen (SC-007).

17. **Invoice edit/delete from the detail view**: Open an unpaid invoice's
    detail view — confirm an Edit button opens the create/edit form
    pre-filled with its current values, saving updates it; confirm a
    Delete button removes it (with a confirm step) when unpaid. Open a
    PAID invoice's detail view — confirm both Edit and Delete are refused
    (disabled in the UI and/or 409 if attempted directly).

18. **Regression**: Run the full `php artisan test` and `npm test` suites —
    confirm no regressions anywhere in this feature or features 017/018.
