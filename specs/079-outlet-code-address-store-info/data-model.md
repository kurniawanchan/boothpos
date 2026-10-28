# Data Model: Outlet Identity and Web Terminal Management

**Classification**: INTERNAL

## Outlet (`outlets`, existing; soft-deleted; `HasDataMode`)

| Field | Change | Rules |
|---|---|---|
| `code` | `CHAR(3)` → `VARCHAR(5)`; editable | 3–5 chars, `[A-Za-z0-9]` only, stored uppercase (model mutator), unique among non-deleted outlets ignoring case; the edited outlet is excluded from its own uniqueness check |
| `name` | unchanged | required, ≤100 |
| `address` | **new**, `TEXT NULL` | optional, ≤500 validated |
| `contact_phone`, `contact_email`, `notes` | unchanged | existing rules |
| `is_active` | unchanged | |

State: none. Code change is an ordinary update logged as `outlet_updated` with `code` old → new.

Open (R-A): the existing unique index on `code` must not block reuse of a soft-deleted outlet's code.

## Embedded-code identifiers (widened, never rewritten)

| Column | Before | After |
|---|---|---|
| `products.code_prefix` | CHAR(10) | CHAR(12) |
| `product_variants.sku` | CHAR(14) | CHAR(16) |
| `order_items.sku_snapshot` | CHAR(14) | CHAR(16) |
| `preorder_items.sku_snapshot` | CHAR(14) | CHAR(16) |

Existing rows keep their values (inert history for preorders). Newly generated prefix = `outlet.code(3–5)-category(2)-segment(3)`; SKU = `prefix-NNN`. Any other column found to copy these by the information_schema check is added here.

## StoreIdentity (value object, not stored)

`{name, address, phone, email, notes}` computed per request.

| Context | Source | Empty field |
|---|---|---|
| Receipt of order O | `O.outlet` | omitted (null); if address, phone, email and notes are **all** empty → whole block from shared settings |
| Screen, one outlet selected | that outlet | falls back per field to shared |
| Screen, All outlets | shared settings | n/a |

Shared source = settings `store_name` (`store_name_demo` in DEMO), `store_address`, `store_contact_phone`, `store_contact_email`, plus `store_contact` (legacy) mapped to phone. Logo, footer, brand name, contact person remain shared only.

## Terminal (`devices`, existing; `BelongsToOutlet`, `HasDataMode`)

No new column. Existing: `name`, `outlet_id`, `paired_at`, `revoked_at`, `last_seen_at`, `app_version`, `paired_by`.

Rules:
- `name`: required, ≤80, trimmed; unique among **active** terminals of the same outlet and mode, ignoring case (`DeviceService::activeByName`).
- Move changes only `outlet_id`; refused while an open shift (`cashier_sessions.device_id`, open) exists; target outlet must be active and permitted; name rechecked in target.
- Status is derived from `revoked_at` (null = active).
- Hard delete only when no `orders` or `cashier_sessions` row references the device (these FKs are SET NULL, so the guard is what protects history; `terminal_counters` cascade). `cash_movements` carries no device column.

State transitions: `active ⇄ deactivated` (deactivate = set `revoked_at`; reactivate = clear it, name must be free); `active|deactivated → deleted` only when unused.

Relationships: Terminal → Outlet (belongs to); Order, Shift, CashMovement → Terminal (nullable reference, history).

## Activity log actions

Existing `device_paired`, `device_renamed`, `device_revoked`; new `device_moved`, `device_reactivated`, `device_deleted`. An outlet update is logged as `updated` with old/new values (including a code change). No tokens, PINs or server addresses in any entry.
