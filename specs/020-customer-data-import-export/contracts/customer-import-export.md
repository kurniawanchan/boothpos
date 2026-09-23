# Contract: Customer Data Import & Export

All three endpoints below sit under the existing `/api/v1` prefix and the
existing `auth:sanctum` + `EnsureInstallationIsActivated` middleware group
that every other authenticated route already uses. Registered **before**
`Route::apiResource('customers', ...)` in `routes/api.php`, following the
same static-route-before-resource ordering comment already present for
`/preorders/export` (defensive — this resource doesn't currently register
a `{customer}` GET route, but keeping the ordering convention costs
nothing and protects against a future resource-route addition).

Authorization: all three endpoints require
`$request->user()->canManageMasterData()` (owner/admin/inventory), checked
inline in the controller — same tier and same style (`abort_unless(...,
403, ...)`) as `MasterDataImportController`, not a new Policy class and
not a new menu key (mirrors `PreorderController::export()`'s existing
inline-check style for a bulk operation layered onto a CRUD resource that
other roles still use for individual records).

## `GET /api/v1/customers/export`

Exports every customer in the currently active data mode (DEMO/LIVE) to an
`.xlsx` file.

- **Query params**: none (unlike Preorder's export, which supports
  filters — the Customers screen shown in the spec has no equivalent
  filter bar today, so export is always "the whole list").
- **Response**: `200`, `Content-Type:
  application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`,
  file download named `customers.xlsx`.
- **Errors**: `403` if the caller lacks `canManageMasterData()`.

## `GET /api/v1/customers/import/template`

Downloads an empty template with just the heading row — the exact same
column order as export, so the file a user gets from Export is already a
valid template.

- **Response**: `200`, file download named `template-customers.xlsx`.
- **Errors**: `403` if the caller lacks `canManageMasterData()`.

## `POST /api/v1/customers/import`

- **Request**: `multipart/form-data`
  - `file` (required): `.xlsx`, max 10 MB (`ImportMasterDataRequest`'s
    existing convention).
  - `dry_run` (optional, boolean, default `false`): when `true`, runs full
    validation and reports what *would* happen, writes nothing.
- **Response `200`** (dry run, or nothing to error on):
  ```json
  {
    "dry_run": true,
    "created_count": 12,
    "updated_count": 3,
    "row_errors": []
  }
  ```
- **Response `201`** (real import applied):
  ```json
  {
    "dry_run": false,
    "created_count": 12,
    "updated_count": 3,
    "row_errors": []
  }
  ```
- **Response `409`** (validation found row-level errors — nothing was
  saved, matching `MasterDataImportController`'s / `PreorderController`'s
  existing "all-or-nothing, nothing saved on any error" convention):
  ```json
  {
    "message": "Tidak ada data yang disimpan karena ditemukan kesalahan.",
    "row_errors": [
      { "row": 4, "errors": ["Nama pelanggan wajib diisi."] },
      { "row": 7, "errors": ["Alamat email 'not-an-email' tidak valid."] }
    ]
  }
  ```
- **Response `422`**: malformed request itself (missing `file`, wrong
  MIME/extension, over the size cap) — Laravel's standard validation-error
  shape, same convention as every other endpoint in this codebase.
- **Response `403`**: caller lacks `canManageMasterData()`.

### Row-level validation rules (mirrors the "New customer" form)

| Field | Rule |
|---|---|
| `name` | Required, string. Missing/blank → row error, row skipped. |
| `phone` | Optional, string. |
| `address` | Optional, string. |
| `email` | Optional, but if present must be a valid email format → row error otherwise. Used as the update-matching key (case-insensitive, exact match, scoped to the active data mode). |
| `social_handle` | Optional, string. |
| `notes` | Optional, string. |

Blank cells: on a **create** row (no matching email), a blank optional
field is stored as `null`. On an **update** row (email matched an existing
customer), a blank cell leaves that field's existing value unchanged — it
is never used to clear a value (FR-013, matching the existing master-data
import's "blank means unchanged" convention).
