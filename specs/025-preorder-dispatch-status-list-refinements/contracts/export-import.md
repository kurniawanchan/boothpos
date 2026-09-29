# Contracts: Excel export / import / template

Owner and admin only (unchanged). Single sheet, one row per pre-order.

## Column set

| # | Column | Export | Template | Import |
|---|---|---|---|---|
| 1–12 | `customer_name` … `notes` | yes | yes | yes (unchanged) |
| 13 | `dispatch_status` | yes | yes (`pending`) | yes, optional |
| 14 | `invoice_sent_at` | yes | yes (blank) | yes, optional |
| 15 | `shipping_at` | yes | yes (blank) | yes, optional |
| 16 | `created_at` | yes | **no** | **ignored** |
| 17 | `updated_at` | yes | **no** | **ignored** |

Columns 16–17 are read-only: they appear after all importable columns in an exported file so the file still imports as-is (the importer looks columns up by heading name and skips ones it doesn't use). An imported pre-order is stamped with the import time, never with a value from the file.

## Value formats

- `dispatch_status` — `pending` / `invoice_sent` / `shipping`; matched case-insensitively and ignoring surrounding spaces; blank = `pending`.
- Dates — exported as ISO 8601 with offset, e.g. `2026-09-27T03:00:00+00:00` (the application timezone is UTC). Imported as ISO 8601 text (offset honoured) **or** an Excel date cell; the instant is normalised to the application timezone before saving.

## Import validation (row errors reject the whole file with 409)

| Condition | Message key |
|---|---|
| unknown `dispatch_status` | `import_dispatch_status_invalid` |
| `shipping` on a `pickup` row | `import_dispatch_shipping_mail_order_only` |
| unreadable date | `import_dispatch_date_invalid` |
| `invoice_sent_at` on a `pending` row, or `shipping_at` on a `pending` / `invoice_sent` row | `import_dispatch_date_not_applicable` |

Fill rules after validation: `invoice_sent` with a blank `invoice_sent_at` → import time; `shipping` with a blank `shipping_at` → import time; `shipping` with a blank `invoice_sent_at` stays blank (never invented). `dry_run=1` runs the identical validation.

## Export filters (`GET /preorders/export`)

`status`, `fulfillment` and `dispatch_status` accept a single value **or** an array (`status[]=a&status[]=b`), values OR-ed, all filters ANDed — the shape the list's "Export .xlsx" button forwards. Previously multi-value `status` / `fulfillment` made the export throw. Still ignored by the export: `artist_id` (pre-existing).
