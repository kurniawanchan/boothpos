# Data Model: License & Invoice Management (expanded scope)

## `licenses` (renamed from `packages`, expanded)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string(100) | unchanged |
| `description` | text nullable | unchanged — now doubles as the "feature description" (FR-001) |
| `license_tier` | enum(`pro`,`master`) | unchanged, still descriptive-only (017 R4, unchanged) |
| `price` | decimal(14,2) | **new** (FR-001) |
| `payment_type` | enum(`one_time`,`subscription`) | **new** (FR-001) — descriptive label only, R0/FR-017 |
| `is_active` | boolean default true | unchanged |
| `created_at`/`updated_at` | timestamps | unchanged |
| `deleted_at` | soft delete | unchanged |

Not `HasDataMode`-scoped (reference/administrative data, unchanged from
`Package`). Index on `is_active` (unchanged).

**Migration steps** (two migrations, in order):
1. `2026_10_19_000001_rename_packages_to_licenses_and_add_pricing.php` —
   `Schema::rename('packages','licenses')`, then add `price`/`payment_type`
   columns.
2. `2026_10_19_000002_rename_company_package_id_to_license_id.php` —
   `Schema::table('companies', fn ($t) => $t->renameColumn('package_id',
   'license_id'))`.

**Model rename**: `App\Models\Package` → `App\Models\License`
(`companies()` relation unchanged in shape). `Company::package()` →
`Company::license()`, `Company.$fillable`'s `package_id` → `license_id`.

## `invoices` (expanded from the original small-scope table)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | unchanged |
| `invoice_number` | string, **unique across both data modes** | **new** (FR-007) — generated, `INV-{YYYYMM}-{seq}` |
| `company_id` | FK `companies.id`, restrictOnDelete | unchanged |
| `license_id` | FK `licenses.id`, restrictOnDelete | **new** (FR-008) — snapshot reference, not re-priced if License changes later |
| `subtotal` | decimal(14,2) | **new**, replaces `amount` (FR-008) |
| `discount` | decimal(14,2) default 0 | **new** (FR-008) — fixed amount, not percentage (R4') |
| `grand_total` | decimal(14,2) | **new** — `subtotal - discount`, stored (not computed on read) so a later `licenses.price` change never drifts an existing invoice |
| `due_date` | date | unchanged |
| `status` | enum(`unpaid`,`paid`,`cancelled`) default `unpaid` | unchanged |
| `paid_at` | date nullable | unchanged |
| `payment_information` | text nullable | **new** (FR-008) — defaults from `invoice_payment_settings` at creation, per-invoice overridable (FR-015) |
| `notes` | text nullable | unchanged |
| `data_mode` | enum(`demo`,`live`) | unchanged — `HasDataMode` |
| timestamps + `deleted_at` | | unchanged |

**Removed**: `amount` (replaced by `subtotal`/`discount`/`grand_total`).

**Migration**: `2026_10_19_000003_expand_invoices_table.php` — adds
`invoice_number` (unique), `license_id` (FK), `subtotal`, `discount`,
`grand_total`, `payment_information`; drops `amount`, backfilling
`subtotal = amount, grand_total = amount, discount = 0` for any existing row
in the same migration (a `DB::table('invoices')->update(...)` before the
`amount` column is dropped) so no data is silently lost if this runs against
a database that already has small-scope-pass invoices in it.

**Delete guard** (FR-011): `InvoiceService::delete(Invoice $invoice)` throws
`ValidationException` (mapped to 409) if `$invoice->status === 'paid'` —
enforced in this ONE service method used by both the single-delete
controller action and the import path's update/skip logic, so no path can
bypass it (SC-005).

**State machine** (unchanged shape from the original pass):

```
unpaid --markPaid()--> paid
unpaid --cancel()--> cancelled
unpaid --update()--> unpaid   (editable while still unpaid)
paid, cancelled --> (terminal; delete blocked only for paid, FR-011)
```

## `invoice_payment_settings` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK, always `1` | single-row table (R8') |
| `bank_name` | string nullable | |
| `account_number` | string nullable | |
| `account_holder` | string nullable | |
| `instructions` | text nullable | free-text payment instructions |
| `created_at`/`updated_at` | timestamps | |

Not `HasDataMode`-scoped (administrative, same category as
`payment_channels`). No soft delete — this is a singleton configuration
record, never "deleted," only updated.

## Company (unchanged fields, one relation rename)

- `license_id` (renamed from `package_id`, FK `licenses.id`)
- `license(): BelongsTo` (renamed from `package()`)

---

# Second expansion (2026-09-06): no schema changes

No new tables or columns. This expansion is entirely new controller
actions on `Company` (already has all needed columns) plus a read-time
eager-load addition on `Invoice`'s existing `company`/`license` relations
(`company.businessType`) — no migration required.

**Company delete-guard** (R10): `CompanyOnboardingService::delete()` checks
`$company->invoices()->exists()` only — NOT `license_id` (every Company
always has exactly one License by design, so that check would be a
permanent no-op guard).
