# Phase 1 Data Model: Customer Data Import & Export

No new tables and no migration. This feature reads/writes the existing
`customers` table exactly as `CustomerController` already does — the two
"entities" below are the existing persisted model and one transient,
response-only shape.

## Customer (existing — `app/Models/Customer.php`, no changes)

| Field | Type | Import behavior |
|---|---|---|
| `name` | string, required | Required on every row (FR-005). Blank on an update row is invalid, not "leave unchanged" (a customer can't have its name blanked out this way) — treated as a row-level error. |
| `phone` | string, nullable | Blank on a create row → `null`. Blank on an update row → left unchanged (FR-013). |
| `address` | text, nullable | Same as `phone`. |
| `email` | string, nullable | The upsert matching key (Resolved Clarification 2). Blank on a create row → `null`, and that row is never matched against anything (FR-012). Blank on an *update* row's export-then-reimport cycle is impossible by construction — a row can only be treated as an update because its email was present and matched. |
| `social_handle` | string, nullable | Same as `phone`. |
| `notes` | text, nullable | Same as `phone`. |
| `data_mode` | enum (`demo`/`live`) | Not user-supplied — stamped by `HasDataMode` from `ModeGate::current()` on create; matching for updates is scoped to the active mode only (the existing global scope already does this — no explicit `withoutGlobalScope` needed, since `email` has no cross-mode unique constraint to defend against, unlike `code` columns elsewhere in this codebase). |

No new validation rule is *stricter* than what `StoreCustomerRequest`/
`UpdateCustomerRequest` already enforce for the one-at-a-time create/edit
form — the importer applies the same field-level rules, just per-row
instead of per-request, so a row that would fail the existing form
validation also fails import validation with an equivalent message.

## Import Result (transient — not a table, the shape returned by `import()`/the API response)

```text
{
  applied: bool           // false when dry_run, or when any row_errors exist
  dry_run: bool
  created_count: int
  updated_count: int
  row_errors: [
    { row: int, errors: string[] }
  ]
}
```

- Mirrors `PreorderExportImportService::import()`'s existing return shape,
  with `updated_count` added (Preorder's importer has no update path, so
  it never needed one) and `created_customer_count`/`preorder_ids` removed
  (Preorder-specific, not applicable here).
- `row: int` is the 1-based spreadsheet row number (accounting for the
  heading row), matching every other importer in this codebase, so a row
  number reported to the user always matches what they see if they open
  the file in Excel/Sheets.

## Export Row Shape

One row per customer, in the currently active data mode, columns in this
exact order (also the template's heading row, so the file round-trips):

```text
name | phone | address | email | social_handle | notes
```

This matches `CustomerResource`'s existing field set (minus `id`, which is
not a user-facing/editable concept for this entity) and the "New customer"
form's fields exactly, so an exported file is immediately valid input for
a subsequent import with no manual reshaping.
