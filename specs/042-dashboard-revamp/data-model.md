**Classification**: INTERNAL

# Data Model: Dashboard Revamp

Conventions follow 041: money `decimal(14,2)` returned as strings; business tables use `HasDataMode`; migrations dated after `2026_11_02_000003`, reversible, re-runnable after a partial failure (MySQL DDL is not transactional — guard with `Schema::hasColumn`, as `2026_10_31_000009` does), rehearsed on populated data.

## Stage A

| Change | Detail |
|---|---|
| `orders.completed_at` | `timestamp null`; stamped when status becomes `completed` (checkout = creation instant; tab settle/split = that instant). Backfill per research D3. |
| `orders.business_date` | `date null`; set at creation, **re-stamped at completion** for tabs; index `(business_date, status)`. Backfilled for all rows. |
| `payments.business_date` | `date null`; from `paid_at`. Index `(verification, method)` for the attention query. |
| `cashier_sessions.business_date` | `date null`; from `opened_at`. |
| `kitchen_ticket_items.unit_price` | `decimal(14,2) null`; stamped when the ticket is sent (`KitchenService::send`); never backfilled (older cancellations show "amount unknown"). |
| Settings keys (existing `settings` table) | `store_timezone` (IANA name, default `Asia/Jakarta`), `business_day_cutoff` (`HH:MM`, default `00:00`), `cash_difference_tolerance` (decimal, default `5000.00`), `device_offline_after_minutes` (integer, default `5`). Validated in `UpdateSettingsRequest`; editable in Settings by owner/admin. |
| New support classes (no tables) | `BusinessDay`, `SalesFigures`, `AttentionService`, `DashboardService`, `DashboardOrdersQuery` |

**Rules**: a client can never send `business_date`, `completed_at` or `outlet_id` (not in any `$fillable` that a request reaches; asserted by a test posting them). Changing the timezone or cut-off affects only rows stamped afterwards.

**Derived, not stored**: dashboard summary, attention items, tab counts, payment state (set-based fragment inside `PaymentSummary`).

## Stage B (after T001)

| Entity | Fields | Rules |
|---|---|---|
| **Outlet** | `id`, `code` (unique, immutable once any document exists), `name`, `timezone`, `business_day_cutoff`, `offline_cash_limit` (decimal, default 3000000.00), `cash_difference_tolerance` (decimal, default 5000.00), `is_active`, timestamps | At least one active outlet. A default outlet is created from the Stage A settings and the four values above are **moved** there. Not deletable if any order, session or device references it (409); deactivate instead. |
| **UserOutlet** | `user_id`, `outlet_id` (unique pair) | No rows for a user = all outlets. |
| `outlet_id` on `orders`, `payments`, `cashier_sessions`, `dining_tables`, `stations`, `kitchen_tickets`, `devices` | `unsignedBigInteger NOT NULL` after backfill to the default outlet, FK, indexed; composite index `(outlet_id, business_date, status)` on orders | Stamped server-side (D10). Payments and kitchen tickets copy from their order/session. |
| **Device** | `id`, `outlet_id`, `name`, `type` (`pos`), `status` (`pending|active|retired`), `last_seen_at`, `pending_events` (int), `offline_cash` (decimal), `app_version?` (string), `retired_at?`, `retired_reason?`, `data_mode`? — **no** (administrative like `payment_channels`, visible in both modes) | Authenticates with a Sanctum token (tokenable = device, ability `device:report`). `offline` is derived: `status=active` and `last_seen_at < now − threshold` (or null after activation grace). |
| **DeviceActivationCode** | `device_id`, `code_hash`, `expires_at` (15 min), `used_at?` | Shown once, stored hashed, single use; a new code invalidates the previous unused one. |
| `activity_logs` | existing | Every outlet/device/limit change logged inside its transaction (`outlet_created`, `outlet_updated`, `device_registered`, `device_activated`, `device_retired`, `settings_updated`). |

**State — Device**: `pending → active → retired`; `retired` is terminal (re-register instead). Offline/online is derived, never stored.

**Backfill order (B1)**: create default outlet from settings → add nullable `outlet_id` columns → backfill to default → set NOT NULL + FKs → add indexes. Each step re-runnable.

**Outlet scope inventory** (enumerating test): Order, Payment, CashierSession, DiningTable, Station, KitchenTicket, Device. Order items and kitchen ticket items follow through their parent in joins; raw queries use `OutletFilter` on the parent table.
