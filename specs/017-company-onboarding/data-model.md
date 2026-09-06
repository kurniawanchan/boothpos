# Data Model: Company Onboarding

## `business_types`

Reference/configuration list — NOT `HasDataMode`-scoped (research.md R6).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string(100) | e.g. "Retail", "F&B", "Services" |
| `code` | string(20), unique | short slug, e.g. `RETAIL` |
| `is_active` | boolean, default true | inactive types are hidden from new-company selection, never deleted |
| `created_at`/`updated_at` | timestamps | |
| `deleted_at` | soft delete | referenced-type delete guard, mirroring Vendor/Category |

No behavior/feature-gating logic attached (per spec.md's explicit scope decision) — purely descriptive.

## `packages`

Reference/configuration list — NOT `HasDataMode`-scoped (research.md R6).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string(100) | e.g. "Starter", "Pro", "Enterprise" |
| `description` | text, nullable | |
| `license_tier` | enum(`pro`,`master`) | descriptive only — never auto-applied to `LicenseGate`/`Setting` (research.md R4) |
| `is_active` | boolean, default true | deactivated packages stay selectable-history-only (FR-009) |
| `created_at`/`updated_at` | timestamps | |
| `deleted_at` | soft delete | referenced-package delete guard (FR-010) |

## `companies`

Business/transactional data — `HasDataMode`-scoped (research.md R6), same as `Customer`/`Vendor`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `business_type_id` | FK → `business_types.id` | `restrict` on delete at the DB level is insufficient alone (soft-delete) — app-level guard mirrors Vendor's pattern |
| `package_id` | FK → `packages.id` | same guard pattern |
| `name` | string(150) | company name |
| `address` | text, nullable | company details |
| `contact_name` | string(100) | primary contact |
| `contact_email` | string(150) | receives the activation code — NOT unique (spec.md edge case: two companies may share a contact) |
| `contact_phone` | string(30), nullable | |
| `owner_user_id` | FK → `users.id` | the created (initially inactive) owner login |
| `status` | enum(`pending_activation`,`active`) | |
| `activation_code_hash` | string, nullable | `Hash::make()` of the current 6-digit code; null once consumed-and-not-reissued is not a real state — always present while `pending_activation` |
| `activation_code_expires_at` | timestamp, nullable | 24h from (re)generation |
| `activated_at` | timestamp, nullable | set once, on successful activation |
| `data_mode` | string | via `HasDataMode` |
| `created_at`/`updated_at` | timestamps | |
| `deleted_at` | soft delete | |

**State machine**: `pending_activation` → `active` (one-way; no deactivation/reversal in this feature's scope — not requested by spec.md).

## `company_activation_notifications`

Append-only audit log — NOT `HasDataMode`-scoped (derived from the already-scoped `Company`, mirrors `PreorderNotification`, research.md R3).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | FK → `companies.id` | |
| `trigger` | enum(`created`,`resend`) | which action produced this send attempt |
| `recipient_email` | string(150) | snapshot of `contact_email` at send time (survives a later contact-email edit) |
| `status` | enum(`sent`,`skipped_not_configured`,`failed`) | mirrors `PreorderNotification.status` values exactly |
| `error_message` | text, nullable | |
| `sent_at` | timestamp, nullable | null when `skipped_not_configured` |
| `created_at` | timestamp | (no `updated_at` — append-only, mirrors `PreorderNotification`) |

## Relationships

```
BusinessType 1---* Company
Package      1---* Company
Company      1---1 User (owner_user_id)
Company      1---* CompanyActivationNotification
```

## Validation rules (from spec.md's functional requirements)

- `Company.contact_email`: required, valid email format — NOT unique (FR/edge case: shared contact across companies allowed).
- Owner `User.username`: required, unique among non-deleted users (same rule as `StoreUserRequest` today) — FR-002.
- Owner `User.password`: required, min 8 chars (same rule as `StoreUserRequest` today).
- `Company.business_type_id`/`package_id`: must reference an *active* record at creation time (an inactive package/business type cannot be newly selected — FR-009), but an existing company's already-referenced package/business type may itself later become inactive without breaking that company's own display (FR-009's second half).
- Activation code: exactly 6 digits, matched via `Hash::check` against `activation_code_hash`; rejected as expired if `now() > activation_code_expires_at`; rejected as already-used if `status === 'active'` (FR-008).
