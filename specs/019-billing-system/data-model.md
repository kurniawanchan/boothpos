# Data Model: Billing Records for Company Onboarding

## `invoices`

`HasDataMode`-scoped (research.md R3).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | FK → `companies.id` | `restrictOnDelete` |
| `amount` | decimal(14,2) | Fixed snapshot at creation (research.md R4) — never recomputed |
| `due_date` | date | May be in the past (spec.md Edge Cases — backdated record-keeping allowed) |
| `status` | enum(`unpaid`,`paid`,`cancelled`) | default `unpaid` (FR-002) |
| `paid_at` | date, nullable | Set only when status becomes `paid` |
| `notes` | text, nullable | |
| `data_mode` | string | via `HasDataMode` |
| `created_at`/`updated_at` | timestamps | |
| `deleted_at` | soft delete | Financial record — no hard delete (spec.md Edge Cases) |

## State machine

```
unpaid ──markPaid()──> paid       (terminal)
unpaid ──cancel()────> cancelled  (terminal)
```

Any other transition (e.g. `paid → unpaid`, acting on an already-`cancelled` invoice) is rejected with `409` (research.md R1).

## Validation rules

- `amount`: required, numeric, > 0.
- `due_date`: required, valid date (no future-only restriction — backdating allowed).
- `company_id`: must reference an existing, non-deleted `Company`.
