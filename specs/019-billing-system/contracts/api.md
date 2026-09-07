# API Contracts: License & Invoice Management (expanded scope)

All endpoints below are gated by their own new menu key (`licenses` or
`invoices`) via `LicenseCatalogPolicy`/`InvoicePolicy` — Owner/Admin only
(FR-016), replacing the original pass's reuse of `companies`.

## License catalog (renamed/expanded from Package's existing endpoints)

Route paths stay `/licenses` (user-facing), backing class is
`LicenseCatalogController` (R0 — avoids colliding with feature 018's
existing `LicenseController` for installation activation).

| Method | Path | Notes |
|---|---|---|
| GET | `/licenses` | list, `?search=`, `?is_active=`, `?license_tier=` (unchanged filters from Package) |
| POST | `/licenses` | create — `name`, `description`, `license_tier`, `price`, `payment_type` (new fields) |
| GET | `/licenses/{license}` | show |
| PUT | `/licenses/{license}` | update |
| DELETE | `/licenses/{license}` | 409 if referenced by any Company or Invoice (FR-005, unchanged guard convention) |

## Invoice (expanded)

| Method | Path | Notes |
|---|---|---|
| GET | `/invoices` | list, `?status=`, `?company_id=`, `?license_id=` |
| GET | `/invoices/summary` | **new** (FR-012) — `{unpaid: {count, total}, paid: {count, total}, overall_count}` |
| POST | `/invoices` | **new shape** — `company_id`, `license_id`, `subtotal`, `discount`, `due_date`, `payment_information` (nullable, defaults from `invoice_payment_settings`), `notes`. Server computes `grand_total` and `invoice_number`. |
| GET | `/invoices/{invoice}` | **new** — full detail (FR-007) |
| PUT | `/invoices/{invoice}` | **new** — same fields as create; 409 if `status === 'paid'` (FR-011/Assumptions) |
| DELETE | `/invoices/{invoice}` | **new** — 409 if `status === 'paid'` (FR-011) |
| POST | `/invoices/{invoice}/mark-paid` | unchanged from original pass |
| POST | `/invoices/{invoice}/cancel` | unchanged from original pass |
| GET | `/invoices/export` | **new** (FR-010) — Excel, filtered same as `index()` |
| GET | `/invoices/import/template` | **new** |
| POST | `/invoices/import` | **new** — `?dry_run=1` supported, all-or-nothing (R6') |

Static routes (`/invoices/summary`, `/invoices/export`,
`/invoices/import/template`, `/invoices/import`) are registered **before**
`Route::apiResource('invoices', ...)`'s `{invoice}` wildcard routes,
mirroring the existing `/preorders/export` ordering comment in
`routes/api.php`.

No dedicated PDF/image endpoint — both are generated **client-side** only
(R5'), consistent with feature 007's "no server-side PDF generation"
decision; nothing to add to the OpenAPI spec for that capability beyond the
existing `GET /invoices/{invoice}` detail response the frontend already has
loaded.

## Settings → Payment (new)

| Method | Path | Notes |
|---|---|---|
| GET | `/settings/payment` | current `invoice_payment_settings` singleton |
| PUT | `/settings/payment` | update `bank_name`, `account_number`, `account_holder`, `instructions` |

Gated on the existing `settings` menu key (R7' — no new permission surface).

## Response shape changes

`InvoiceResource` gains: `invoice_number`, `license` (nested `id`/`name`/
`payment_type`), `subtotal`, `discount`, `grand_total`, `payment_information`
— replacing the old single `amount` field.

`docs/openapi-pos-mvp.yaml` must be updated in the same commit as any of the
above route/response changes (per CLAUDE.md's existing rule).

---

# Second expansion (2026-09-06)

## Company (new — feature 017 never had update/destroy)

| Method | Path | Notes |
|---|---|---|
| PUT | `/companies/{company}` | **new** — `name`/`address`/`contact_name`/`contact_email`/`contact_phone`/`business_type_id`/`license_id`, validated by a new `UpdateCompanyRequest` (NOT `StoreCompanyRequest` — no owner_username/password fields, R11) |
| DELETE | `/companies/{company}` | **new** — 409 if any Invoice references this Company (R10) |

## Invoice (response shape only — no new routes)

`InvoiceResource` now nests `company.business_type` (`{id, name}`) alongside
the existing `company` (`{id, name}`) and `license` (`{id, name,
payment_type}`) nested objects. `InvoiceController` eager-loads
`company.businessType` in `index()`/`show()`/`store()`/`update()`.

No new endpoints for Edit/Delete — `PUT`/`DELETE /invoices/{invoice}`
already existed since the first expansion (contracts/api.md above); this
expansion only adds the corresponding buttons in `InvoiceDetailModal.vue`.

`docs/openapi-pos-mvp.yaml` must be updated for the two new Company routes
and the `Invoice` schema's `company` nested-object shape change.
