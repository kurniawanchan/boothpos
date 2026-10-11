**Classification**: INTERNAL

# Data Model: Products Page Redesign

Conventions follow 041/042: money `decimal(14,2)` returned as strings; business tables use `HasDataMode`; migrations dated after `2026_11_04_000003`, reversible, re-runnable after a partial failure (`Schema::hasColumn` guards), rehearsed on populated data. Raw `DB::table()` reads carry an explicit `data_mode` filter. **O** = outlet-aware in Stage B (nullable `outlet_id` from Stage A).

## Stage A

| Change | Detail |
|---|---|
| `products` + columns | `short_name` string(24) null, `tax_category` string(40) null (key from a small list), `prep_minutes` unsigned small int null, `is_retail` boolean default 0, `published_at` timestamp null, `discontinued_at` timestamp null. Backfill: `published_at = created_at` for all existing; inactive existing rows get `discontinued_at = updated_at`. |
| `materials` + columns | `kind` enum(`ingredient`,`semi_finished`,`packaging`) default `ingredient`; `standard_cost` decimal(14,4) null. |
| `reason_codes` | `id`, `category` (string 30), `code` (string 40), `label_id`, `label_en`, `is_active`, `sort`; unique (`category`,`code`). Not mode-scoped (administrative). Seeded defaults (D13). |
| `item_availability_marks` (O) | `product_id`, `outlet_id` null, `reason_code_id`, `note` null, `marked_by`, `marked_at`, `expires_at`, `cleared_at` null, `cleared_by` null, `data_mode`. Index `(data_mode, expires_at, cleared_at)`. Active = `cleared_at IS NULL AND expires_at > now`. |
| `price_history` (O) | `variant_id`, `outlet_id` null, `old_price` decimal null, `new_price`, `source` enum(`created`,`edit`,`bulk`,`import`,`release`), `user_id`, `approved_by` null, `effective_at`, `release_id` null, `change_id` null, `note` null, `created_at`, `data_mode`. Index `(variant_id, created_at)`. Append-only. |
| `menu_changes` (O) | `kind` enum(`price`,`item_publish`,`item_discontinue`,`item_reactivate`,`modifier_link`), `subject_type`, `subject_id`, `outlet_id` null, `payload` JSON (old, new), `effective_from` date null, `status` enum(`pending`,`awaiting_approval`,`approved`,`rejected`,`released`,`cancelled`), `requested_by`, `approval_id` null, `release_id` null, `batch_id` null, `data_mode`, timestamps. Index `(data_mode, status)`. |
| `price_change_batches` | `scope` JSON (category ids, outlet ids), `type` (percent|amount), `value`, `rounding` (none|500), `effective_from`, `created_by`, `data_mode`, timestamps. |
| `menu_releases` | `version` unsigned int (unique per data mode, sequential), `status` enum(`scheduled`,`published`,`partial`,`failed`,`cancelled`), `publish_at`, `published_at` null, `note`, `requested_by`, `approved_by` null, `released_by`, `data_mode`, timestamps. `menu_changes.release_id` links the contents. |
| `approvals` | `kind` (`menu_price`), `subject_type/id` (a `menu_change`), `requested_by`, `decided_by` null, `decision` enum(`pending`,`approved`,`rejected`), `reason_code_id` null, `note`, `difference_pct` decimal(6,2), `amount` decimal(14,2), `decided_at`, timestamps. Rule enforced in `ApprovalService`: `decided_by ≠ requested_by`. |
| `modifier_group_product` + columns | `is_required` boolean null, `min_select` tinyint null, `max_select` tinyint null, `display_order` smallint null (null = use the group's value). |
| `recipes` | `product_id`, `version` unsigned int, `yield` decimal(10,3) > 0, `effective_from` date, `note` text null, `created_by`, `data_mode`, timestamps. Unique (`product_id`,`version`). Immutable after creation (a change is a new row). |
| `recipe_variants` | `recipe_id`, `variant_id` (unique pair per recipe). A variant may be in many recipe versions; the active one per variant = latest `effective_from ≤ today`. |
| `recipe_lines` | `recipe_id`, `material_id`, `quantity` decimal(12,4) > 0, `shrinkage_pct` decimal(5,2) default 0 (0–99.99), `display_order`. Line unit = `materials.unit`. |
| `roles` + column | `permissions` JSON null (list of keys from `PermissionCatalog`). Migration seeds owner/admin with all keys, inventory with `menu.item.86`. |
| Settings keys | `menu_release_enabled` (bool, default false), `price_approval_threshold_pct` (decimal, default 15), `price_rounding_default` (`none`\|`500`, default `500`). |

**State — Product**: `draft → active → discontinued → active (reactivate)`; 86 is orthogonal and temporary. **Menu change**: `pending → released`; `pending → awaiting_approval → approved → released | rejected`; any non-released → `cancelled`. **Release**: `scheduled → published` (Stage B adds `partial/failed`). **Approval**: `pending → approved | rejected`, terminal.

**Rules**: a variant belongs to exactly one cost source (BOM-complete xor recipe-driven). `price_history` and recipes are never edited in place. Recorded `order_items` keep their own price and cost.

## Stage B (after the 042 amendment and Phase 7)

| Change | Detail |
|---|---|
| `outlet_item_settings` (O) | `outlet_id`, `product_id`, `is_available` (default 1), `daily_quota` unsigned null, `channels` text null; unique (`outlet_id`,`product_id`). |
| `variant_outlet_prices` (O) | `outlet_id`, `variant_id`, `price` decimal(14,2); unique pair. Empty = follows base. |
| `menu_release_outlets` | `release_id`, `outlet_id`, `state` (`pending`,`applied`,`failed`), `applied_at`, `error`; unique pair. |
| nullable `outlet_id` → NOT NULL | on `item_availability_marks`, `price_history`, `menu_changes`; backfilled to the default outlet; `OutletScope`/`OutletFilter` applied (042 design). |

**Backfill order (B)**: outlets exist (042) → add outlet tables → backfill null `outlet_id` to the default outlet → add NOT NULL/FKs → indexes; each step re-runnable.
