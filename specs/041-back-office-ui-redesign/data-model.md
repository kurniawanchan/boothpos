**Classification**: INTERNAL

# Data Model: Back Office UI Redesign

**Feature**: 041-back-office-ui-redesign | **Spec**: [spec.md](spec.md) | **Research**: [research.md](research.md)

Conventions (all phases):

- Money: `decimal(14,2)`, returned as strings `"0.00"`. Quantities: `decimal(14,3)` in the item's base unit.
- Every business/transactional table uses `HasDataMode` (`data_mode demo|live`) as today; **O** = also uses `BelongsToOutlet` (`outlet_id` NOT NULL, indexed, stamped server-side). **M** = organisation-wide master data (no `outlet_id`).
- Uniqueness that is database-wide (document numbers) counts across both data modes (existing rule), and includes the outlet code in the number where the design shows it (for example `WJM3-T01-20261004-A000141`).
- Soft delete only where the existing equivalent has it; documents are never deleted, only cancelled/closed.
- Audit: every write path goes through `ActivityLogger` inside the same transaction (Constitution IV). Audit rows gain `outlet_id`, `ip`.
- Migrations: date-prefixed after `2026_11_02_000003`; reversible; re-runnable after a partial failure; rehearsed on populated data.

## Phase 2a — Organisation foundation

| Entity | Key fields | Relations / rules |
|---|---|---|
| **Outlet** (M) | `code` (unique, receipt prefix), `name`, `address`, `tax_registration_no?`, `timezone`, `opens_at`, `closes_at`, `business_day_cutoff` (time), `service_modes` (set of dine_in/takeaway/counter), `is_active` | At least one active outlet. Default outlet created from current store settings. Code immutable once any document uses it. |
| **UserOutlet** | `user_id`, `outlet_id` | No rows for a user = all outlets. A user scoped to outlets cannot read/write others (global scope). |
| **Role** (existing) + `permissions` JSON | `menu_keys` kept; `permissions` = list of action keys | Migration maps each role's `menu_keys` to equivalent permissions. `users`/`roles` reserved rule stays. Built-in role flags: `is_system_default`, `is_read_only`, `is_non_transacting`. |
| **PermissionCatalog** (code, not table) | key, module, label | Keys as in the design (`sal.order.view`, `sal.return.approve`, `pur.po.approve`, `inv.count.approve`, `pos.shift.variance.approve`, `prv.pii.reveal`, …). Single source for the matrix UI and the gate. |
| **SodRule** | `rule` (enum: requester_ne_approver, role_cannot_transact, role_read_only), `role_id?`, `applies_to` | Evaluated by `ApprovalService`/`PermissionGate`. |
| **TaxRule** (M) | `name`, `rate`, `price_includes_tax`, `service_charge_rate?`, `rounding` (none/100/500), `half_up`, `level` (order/line), `effective_from`, `published_by` | Versioned: a new row per change; a sale stores `tax_rule_id` + computed amounts (immutable snapshot). Overlapping effective dates refused (409). |
| **PaymentMethod** (M) | `code`, `type` (cash/edc/qris/bank_transfer/e_wallet), `scope_outlets` (set, empty = all), `scope_order_types`, `verification_flow` (instant/pending_verification), `clearing_account`, `is_active` | Replaces the hard-coded `payments.method` enum over time; existing `payment_channels` map to methods by migration; cash always exists. |
| **ReasonCode** (M) | `category` (void, return, discount, complimentary, wastage, cash_out, variance, reopen, count_adjustment, other), `code`, `label`, `requires_approval`, `is_active` | `code` unique per category. Deactivated codes remain on history. |
| **ApprovalLimit** | `role_id`, `kind` (discount, void, refund, reprint, variance, cash, offline), `amount?`, `count?` | Offline limit may not exceed the online limit for the same kind and role (lowered automatically with a notice). |
| **Approval** | `kind`, `subject_type/id`, `requested_by`, `decided_by?`, `decision` (pending/approved/rejected), `reason_code_id?`, `note`, `amount`, `outlet_id`, timestamps | **Single approval path.** `decided_by ≠ requested_by` always; role limit checked. Subjects: purchase request, stock count, wastage, refund, cash variance, price release, manual override. |
| **BusinessRule settings** | keys in `settings` grouped `pos_rules` | Table/guest required, lock after pre-bill, reprint limit, idle lock seconds, variance tolerance, cash-out threshold, max offline hours, unsynced cash limit, counter-to-kitchen timing. Versioned through releases in Phase 8; plain settings until then. |
| **Orders/Payments/CashierSessions** (existing) | + `outlet_id`, + `business_date`, + `tax_rule_id`, + tax/rounding snapshot columns | `outlet_id` copied from the session server-side. `business_date` from outlet timezone + cut-off. Backfill from `created_at`. |
| **DayClose** (O) | `outlet_id`, `business_date`, `status` (open/closed/reopened), `closed_by`, `closed_at`, `checklist` JSON, `reopened_by?`, `reopen_reason_code_id?` | Unique (`outlet_id`,`business_date`). Closing refused while any checklist item fails (409 with the blockers). Writes to a closed date refused (`BUSINESS_DAY_CLOSED`). |
| **ActivityLog** (existing) | + `outlet_id?`, + `ip?` | not outlet-scoped by trait (read gated by permission), filtered by the caller's outlets. |

**State — DayClose**: `open → closed → reopened → closed`. Reopen needs permission + reason code; every transition audited.

## Phase 3 — Menu & stock

| Entity | Key fields | Relations / rules |
|---|---|---|
| **MenuItem** = existing `Product` (+ fields) | `short_name` (≤24), `description`, `tax_category`, `open_price`, `station_id`, `prep_minutes`, `daily_quota?` | Variants stay `ProductVariant` (+ `track_batch` n/a). |
| **OutletItem** (pivot) | `variant_id`, `outlet_id`, `is_available`, `price_override?`, `channels` (set), `unavailable_reason_code_id?`, `unavailable_since?` | "86" = `is_available=false` with reason. Quota reached sets it automatically for the business date. Effective price = override ?? base, resolved server-side. |
| **Combo / ComboGroup / ComboChoice** (M) | combo item, choice groups with `min/max`, choices with `upgrade_price?` | Combos sell as one line; stock/cost resolved through chosen items. |
| **ModifierGroup/Option** (existing) | unchanged | Price deltas already snapshotted onto the order line. |
| **Material** (existing) → ingredient | + `category`, `storage_type`, `purchase_unit`, `purchase_factor`, `factor_is_approximate`, `track_batch`, `shelf_life_days?`, `kind` (ingredient/semi_finished/packaging/retail), `barcode?`, `preferred_supplier_id?` | Base unit = existing `unit`. Approximate factor means weighing is mandatory at receipt. Semi-finished carries `batch_yield`. |
| **Recipe** (replaces ad-hoc BOM lines over time) | `variant_id`, `version`, `effective_from`, `yield`, `change_note`; lines `material_id`, `qty_per_portion` | New version never edits old; cost/margin computed live from current average cost. Existing `ProductVariantBomLine` data migrated as version 1. |
| **StockLocation** (O) | `outlet_id`, `name`, `type` (warehouse/kitchen/bar/chiller/freezer), `is_default` | One default per outlet created by migration. |
| **StockBalance** (O) | `stockable_type/id` (variant or material), `location_id`, `qty`, `avg_cost`, `reserved` | Unique (`stockable`,`location`). `current_stock` on variant/material = Σ balances, written only by `StockService`. |
| **StockBatch** (O) | `stockable`, `location_id`, `lot_ref`, `received_at`, `expires_at`, `qty` | Only for `track_batch`; FEFO consumption. |
| **StockMovement / MaterialStockMovement** (existing) | + `location_id`, + types `transfer_out`, `transfer_in`, `wastage`, `count_adjustment`, `consumption`; + `document_type/id` | Append-only. Unified behind `StockService::applyMovement`. |
| **StockCount** (O) | `number`, `location_id`, `type` (full/cycle/spot), `blind`, `scheduled_for`, `status`, `counter_id`, `approval_id?` | Lines: `stockable`, `system_qty` (hidden while blind and not submitted), `counted_qty`, `variance_qty/value`. Within tolerance auto-ok; else needs approval by ≠ counter. |
| **Transfer** (O) | `number`, `from_location_id`, `to_location_id`, `status`, `sent_at`, `received_at`, `courier?` | Lines `stockable`, `qty_sent`, `qty_received`. In-transit stock held until receipt; difference recorded as a movement with reason. |
| **WastageRecord** (O) | `number`, `location_id`, `reason_code_id`, `photo_path?`, `value`, `approval_id?` | Above the configured value → pending until approved; stock moves on approval. |

**State — StockCount**: `scheduled → counting → submitted → (approved|recount) → posted`. **Transfer**: `draft → in_transit → received | received_with_difference`. **Wastage**: `recorded | pending_approval → approved|rejected`.

## Phase 4 — Purchasing & suppliers

| Entity | Key fields | Relations / rules |
|---|---|---|
| **Vendor** (existing → Supplier, M) | + `category`, `pic`, `phone`, `email`, `tax_no`, `payment_terms`, `lead_time_days`, `halal_cert_no?`, `halal_cert_expires_on?`, `status` | Halal expiry within 60 days flagged. Deactivation blocks new POs, open POs continue. |
| **SupplierPrice** (existing `VendorMaterialPrice` + fields) | `vendor_id`, `material_id`, `price`, `min_order_qty?`, `valid_until?`, `is_preferred` | "Contract price" shown beside last paid price on PO lines. |
| **PurchaseRequest** (O) | `number`, `outlet_id`, `requested_by`, `source` (reorder_suggestion/manual), `status`, `approval_id?` | Lines `material_id`, `qty`, `estimated_value`. `awaiting_approval → approved|rejected → converted`. Creator cannot approve. |
| **PurchaseOrder** (existing, O) | + statuses (see below), `request_id?`, `ship_to_location_id`, `terms`, `eta`, `late_flag` derived | Existing lines (`material`/`service`) kept. Price differing from contract needs a reason code. |
| **GoodsReceipt** (O) | `number`, `po_id`, `received_at`, `received_by`, `delivery_note_photo?`, `qc_result` (pass/partial/reject), `difference_note?` | Lines `po_line_id`, `qty_received`, `qty_rejected`, weighed qty when factor approximate. Posts stock movement per line. |
| **SupplierInvoice** (O) | `number` (supplier's), `supplier_id`, `po_id`, `invoice_date`, `due_on`, `amount`, `match_status` (matched/price_diff/qty_diff/exception_approved/returned) | Match compares PO line, receipt line, invoice line within tolerance; exception approval needs reason + approver. |
| **SupplierReturn** (O) | `number`, `receipt_id`, `reason_code_id`, `status` (sent/credit_received) | Posts negative stock movement; tracks credit amount. |
| **SupplierScorecard** (derived) | `on_time_pct`, `fill_rate_pct`, `rejection_pct`, `price_variance_pct` | Computed on read from receipts/invoices for a window; flag below target (target configurable, default 85% on-time per design sample). |

**State — PurchaseOrder** (existing → extended): `draft → awaiting_approval → sent → partly_received → fully_received → closed`, `cancelled` from any pre-close state. Migration map: `draft→draft`, `ordered→sent`, `received→fully_received`, `paid→closed`, `cancelled→cancelled`.

## Phase 5 — Sales, cash, shifts, orders monitor

| Entity | Key fields | Relations / rules |
|---|---|---|
| **Order** (existing) | + `tax/rounding snapshot`, `config_release_id?`, `reprint_count` | Closed (`completed`/`voided`) rows are immutable: updates refused at service level; DB trigger considered only if tests show bypass (ASSUMPTION). |
| **SalesReturn** (O) | `number`, `order_id`, `status` (pending/approved/failed/done), `reason_code_id`, `stock_decision` (not_restored/discarded/restored), `value`, `approval_id`, `refund_payment_id?` | Refund executes only after approval; failure is a visible state with retry. Never edits the original order. |
| **ComplimentaryRecord** (O) | `kind` (complimentary/staff_meal), `employee_id?`, `reason_code_id`, `notional_value`, `approval_id?` | Excluded from revenue; per-employee daily limit; separate listing. |
| **CashierSession** (existing) | + `outlet_id`, `business_date`, `variance_approval_id?`, `scheduled_slot_id?` | `cash_difference` beyond tolerance → approval required to finalise. |
| **CashCount** | `session_id`, per-denomination rows `denomination`, `qty` | Blind count; compared with expected after submission. |
| **CashMovement** | `session_id`, `type` (cash_in/cash_out), `amount`, `reason_code_id` | Above the cash-out threshold needs approval. |
| **ShiftReport (X/Z)** (derived snapshot) | stored on Z: totals by method, float, in/out, expected vs counted | X is computed on read; Z is stored once at close. |
| **Open orders / table board** (derived) | from `orders(status=open)`, `dining_tables`, `kitchen_tickets` | Over-45-minute threshold from settings. No create/edit from back office. |

## Phase 6 — Dashboard, search, attention (derived, no new tables)

- **AttentionItem** computed on read: pending QRIS verifications, voids after fire, offline devices, shift variances over tolerance, stock below reorder point, approvals waiting for the caller, halal certificates expiring, late POs, invoice exceptions.
- Dashboard series from `orders.business_date` with outlet scope. Summary cached ~30 s keyed by user, outlet set and period.
- Indexes added where search/dashboards need them: `orders(outlet_id, business_date, status)`, `orders(order_number)`, `customers(name)`, `customers(phone)`, `dining_tables(outlet_id, name)`.

## Phase 7 — Customers, reports, people

| Entity | Key fields | Relations / rules |
|---|---|---|
| **Member** (extends `Customer`, M) | + `birth_date?`, `tier_id`, `points`, `visits`, `lifetime_spend`, `favourite_outlet_id?`, `joined_at`, `anonymised_at?` | PII masked unless `prv.pii.reveal`. Deletion anonymises but keeps transaction totals. |
| **MemberConsent** | `member_id`, `channel`, `purpose` (e_receipt/promo), `granted`, `granted_at` | Required before sending promos/e-receipts on a channel. |
| **LoyaltyTier** (M) | `name`, `min_spend`, `window_months`, `earn_rate`, `perks`, `redemption_rules`, `points_expire_months` | Tier recomputed on each qualifying sale and daily. |
| **PointLedgerEntry** | `member_id`, `delta`, `reason_code_id?`, `source` (sale/adjustment/redeem/expiry), `order_id?`, `by_user_id?` | Append-only; balance = Σ. Adjustment needs a reason. |
| **Feedback** | `outlet_id`, `rating 1–5`, `comment`, `source`, `followed_up_by?`, `followed_up_at?` | |
| **ReportDefinition** (code registry) | `id` (REP-nnn), `group`, `params`, `formats`, `default_frequency` | Source of the catalogue page. |
| **ReportSchedule** | `report_id`, `frequency`, `outlet_ids`, `format`, `recipients`, `created_by`, `next_run_at` | Runs from the scheduler. |
| **ReportExport** | `report_id`, `params`, `format`, `file_path`, `status` (processing/ready/expired/failed), `requested_by`, `expires_at` | Private disk, authorised download. Export of personal data requires a reason. |
| **User** (existing) | + `pin_hash?`, `mfa_secret?`, `mfa_required`, `locked_until?`, `failed_pin_count`, `invited_at?` | 5 wrong approver PINs → `locked_until` 15 min for override; account shows in "Akun terkunci" until unlocked. |

## Phase 8 — POS operations

| Entity | Key fields | Relations / rules |
|---|---|---|
| **Device** (O) | `code` (e.g. WJM1-T01), `type` (pos, handheld, customer_display), `model`, `status`, `last_sync_at`, `applied_release_id?`, `pending_events`, `revoked_at?` | Revoking needs a reason; existing web POS appears as an online-only device. |
| **ActivationCode** | `device_id?`, `outlet_id`, `code_hash`, `expires_at` (15 min), `used_at?` | Shown once; single use; stored hashed. |
| **Printer** (O) | `code`, `role` (receipt/kitchen/bar), `model`, `paper_mm`, `connection`, `station_id?`, `status` | |
| **ShiftSlot** (O) | `name`, `start`, `end`, `day_pattern`, assigned staff | Shifts opened after schedule +grace flagged overdue. |
| **SyncEvent / SyncException** (O) | event: `device_id`, `seq`, `type`, `payload`, `received_at`; exception: `code` (NUMBER_CONFLICT, TABLE_CONFLICT, CALC_VARIANCE, REVOKED_USER_OFFLINE, BUSINESS_DAY_CLOSED, NOT_FOUND), `status` (quarantined/flagged/resolved/rejected), `resolution_reason_code_id?` | Unique (`device_id`,`seq`) gives idempotency; conflicts never merge silently. |
| **ConfigRelease** | `version`, `status` (draft/scheduled/published/superseded), `scheduled_at?`, `published_by`, `snapshot` JSON, `diff` JSON, `rollback_of?` | Immutable once published. Rollback = new release from an older snapshot. |
| **PriceDraft** | `variant_id`, `outlet_id?`, `new_price`, `release_id?` | Applied atomically at publish. |
| **DiscountPreset** (M) | `code`, `label`, `type` (percent/amount), `value`, `cap?`, `min_role`, `reason_code_id` | |
| **CashDenomination** (M) | `value`, `kind` (note/coin), `is_active` | Drives opening float and blind count rows. |
| **ReceiptTemplate** (O) | header, footer, copies, `show_tax`, `show_cashier`, `show_queue`, `kot_show_modifiers`, `kot_large_font` | Reprints carry the "SALINAN" marker. |
| **AppVersionPolicy** | `minimum_version`, `latest_version`, `channel`, `grace_hours`, `force_update` | Read by clients at sync. |

## Existing data carried over (Phase 2a migration contract)

1. Create default outlet from store settings; assign all existing users to all outlets.
2. Backfill `outlet_id` on orders, sessions, tables, stations, purchase orders from the default outlet; backfill `business_date` from `created_at` (app timezone).
3. Create a default stock location; move `current_stock` into balances (Σ preserved; movements keep history).
4. Map `roles.menu_keys` → `roles.permissions`; verify every user's effective access is unchanged by test.
5. Map `payment_channels` → `payment_methods`; keep ids for existing payments.
6. Rehearse on a copy of populated data; migrations re-runnable after partial failure.
