**Classification**: INTERNAL

# Research: Products Page Redesign

Labels: **FACT** = read in this repository or in the screenshots on 2026-10-11; **ASSUMPTION** = to be confirmed. Spec: [spec.md](spec.md). Plan: [plan.md](plan.md).

## D1 — Three stages; the outlet dimension is the only part that waits

- **Decision**: Stage A builds everything that works on the single default outlet, including the release and approval engines; Stage B adds the outlet dimension (per-outlet availability, quota, prices, release targets, scope); Stage C is polish. Stage B consumes the 042 Stage B Phase 7 foundation and adds nothing to it.
- **Rationale**: Q1 = C asked for outlets, releases and approvals; only outlets are gated by the constitution amendment. Releases and approvals need users and permissions, not outlets.
- **Alternatives rejected**: everything after the amendment (blocks the visible core for no technical reason); building a private outlet model for products (a second foundation, forbidden by Principle I).

## D2 — Releases are an install setting, off by default

- **FACT**: today a variant price edit applies immediately (`PUT /variants/{id}`), and booth installs depend on that.
- **Decision**: setting `menu_release_enabled` (default **false**). Off: every change applies immediately and is still written to `price_history` and the activity log; no banner, no release dialog, no approval requirement. On: gated changes become pending `menu_changes`, the banner and release dialog appear, and over-threshold prices need approval.
- **Gated change kinds** (when on): variant price (base now; per outlet in B), item publish (draft → active), discontinue/reactivate, modifier link edits. **Not gated** (apply at once): 86, descriptive fields (name, short name, image, description, station, prep time), recipe versions (they have their own effective date), group contents on Dining setup (open decision O1).
- **Rationale**: keeps every existing install unchanged and lets the restaurant opt in; one engine, two modes, covered by tests in both.

## D3 — Applying a release: write-through to the live columns, no second price source

- **FACT**: `product_variants.sell_price` is read directly by order creation, POS cards, reports and tests.
- **Decision**: a release **writes** the new value into the live column (`sell_price`, `is_active`, link tables) and stores the previous value in the change and in `price_history`. There is no parallel "effective price" lookup at read time. A release with a future `publish_at` is a row waiting in `menu_releases`; it is applied by `ReleaseService::publishDue()`.
- **Lazy publishing** (no scheduler on a laptop): `publishDue()` is called, behind a ≤ 5 s cache, at the entry points that need current data — the POS product list, order/tab creation, `GET /menu/availability`, and the Products list. An optional `php artisan menu:publish-due` is provided for installs that have cron.
- **Rationale**: keeps `OrderService` and reports untouched; a laptop without a scheduler still honours scheduled prices at the first request after the due time.
- **Alternatives rejected**: read-time price resolution (touches every price read, two sources of truth); a queue/cron dependency.

## D4 — One availability rule

- **FACT**: orders and tabs check `$variant->is_active` in two places (`OrderService` line ~88, `TabService` line ~131); the POS list filters by activity.
- **Decision**: `MenuAvailability::assertOrderable($variant)` (and `isOrderable`, `unavailableReason`) is the only place that answers "can this be ordered": product `is_active`, not discontinued, variant active, no active 86 mark (Stage B: for the order's outlet). `OrderService`, `TabService`, `GET /variants/lookup`, the POS list and `GET /menu/availability` all call it. Characterisation tests of today's rejections are written first.
- **Existing lines are never altered** by an 86 or a discontinue (spec edge case).

## D5 — Status stays derived; `is_active` keeps its meaning

- **Decision**: add `products.published_at` and `products.discontinued_at`. Status: `draft` = never published (`published_at` null); `discontinued` = `discontinued_at` not null; otherwise `active`. `is_active` remains the orderable flag and is changed only by the lifecycle service (publish → 1, discontinue → 0). Backfill: existing active products get `published_at = created_at`; existing inactive ones become discontinued (`discontinued_at = updated_at`).
- **Rationale**: see plan Complexity Tracking; `is_active` is read widely (imports, tests, POS).
- **Edge**: discontinued wins over 86; discontinuing clears the active 86 mark.

## D6 — 86 is a mark with an expiry, not a status

- **Decision**: `item_availability_marks` (product, outlet null in A, reason, note, who, when, `expires_at` = end of the current business day from `BusinessDay`, cleared fields). Active = not cleared and `expires_at` in the future. Undo clears it. Expiry needs no job: it is a timestamp compared at read time.
- **Propagation ≤ 5 s** (FR-027): terminals poll `GET /menu/availability?since=<etag>` every 5 s (ETag over the marks table; cached ≤ 2 s) and grey out/refuse the item; the server also refuses it at order time, so the poll is a convenience, not the guard. **Online channels**: no integration exists; the dialog states only the terminal behaviour.
- **FACT** (042): the business-day end comes from `BusinessDay`; the mark follows its cut-off.

## D7 — Prices: one writer, history as a table

- **FACT**: price edits leave only an `activity_logs` row.
- **Decision**: `PriceService::setBasePrice()` is the only writer of `product_variants.sell_price`; it writes a `price_history` row (variant, old, new, source `edit|bulk|import|release|created`, who, approver, effective at, release/change links) in the same transaction and the audit row. `ProductController::updateVariant`, the master-data import and the bulk service call it. The detail timeline reads `price_history`, not the audit log.
- **Cost price** is a separate field and is **not** part of this writer (it follows BOM-complete or recipe sync).

## D8 — Bulk price change: preview, then all-or-nothing

- **Decision**: `BulkPriceService::preview(scope, type, value, rounding)` → list of `{variant, old, new}` computed set-based on the server; `apply()` re-computes from the request (never trusts the preview), validates (no scope; value empty; effective date in the past; any result < 0 or two results collapsing to a zero price), and creates one `price_change_batches` row plus one `menu_change` per variant (or applies immediately in immediate mode). Rounding "nearest Rp 500": `round(x / 500) * 500`, half up, applied after the percentage/amount.
- **Scope**: categories (including children, `categories.parent_id`), outlets (Stage B; "Semua (harga dasar)" = base price).

## D9 — Approval: one path, requester ≠ approver

- **Decision**: `ApprovalService` (the single path from 041's design, narrowed to `menu_price` for now): a price change whose `|new − base| / base` exceeds `price_approval_threshold_pct` (default 15, design text) is created `awaiting_approval`; only a user with `menu.price.approve` who is **not** the requester may approve or reject (reason code on reject); an approved change becomes eligible for release; rejected ones are kept with who/why. Only when `menu_release_enabled` is on.
- **Permissions**: first use of action-level permissions: `roles.permissions` JSON next to `menu_keys`, `PermissionCatalog` for the keys, `User::hasPermission()`; owner/admin hold all by default; the Roles screen gets a permissions block. Server-enforced; the UI hides what the role cannot do.

## D10 — Modifier links carry per-item rules

- **FACT**: rules live on the group; the pivot has none.
- **Decision**: add nullable `is_required`, `min_select`, `max_select`, `display_order` to `modifier_group_product`; `ModifierResolver` uses the link value when set, else the group's. `PUT /products/{id}/modifier-links` replaces the item's links in one transaction (validated: 0 ≤ min ≤ max, max ≥ 1 or null = unlimited; group exists; order = row order). Group contents remain on Dining setup. Link edits are release-gated when on.
- **Category links** (existing `modifier_group_category`) are untouched and remain the fallback.

## D11 — Item types: material kind + retail flag

- **Decision** (Q2 = B): `materials.kind` enum (`ingredient` default, `semi_finished`, `packaging`); `products.is_retail`. Tab counts: Menu item = non-retail products; Item ritel = retail products; Bahan baku / Setengah jadi / Kemasan = materials by kind; Modifier & combo = modifier groups (combos do not exist; the tab name stays and counts groups). A `semi_finished` material may be a recipe ingredient and has an optional `standard_cost` used when it has no vendor price.
- **Not built**: a recipe or yield for a semi-finished item (out of scope).

## D12 — Recipes: versioned, beside the legacy BOM

- **FACT**: BOM is per variant, from PO lines, whole quantities, with complete-and-sync; no yield, shrinkage, versions.
- **Decision** (Q3 = B): new tables `recipes` / `recipe_variants` / `recipe_lines`. A new save creates a new `version` (immutable once saved) with `effective_from`; the active version for a variant is the latest whose `effective_from` ≤ today. Line = material, `quantity` (> 0, up to 4 decimals), `shrinkage_pct` (0–99.99); the line **unit is the material's unit** (O4).
- **Formula** (one place, `RecipeCost`): line cost = `qty ÷ (1 − shrinkage/100) × unit_cost`; portion cost = `Σ line cost ÷ yield`; food cost % = portion cost ÷ base price × 100; gross margin = base price − portion cost. `unit_cost` = the material's reference price (the existing preferred-else-cheapest vendor price) or `standard_cost` for semi-finished without a price.
- **Cost source per variant**: exactly one of recipe or BOM-complete. Saving a recipe for a variant that is BOM-complete is refused (409 `cost_source_conflict`); completing a BOM for a recipe-driven variant likewise. A variant with neither shows no cost.
- **Cost price sync**: `RecipeService` is the only writer of `cost_price` for recipe-driven variants, writing it when a version becomes effective and on an explicit "Hitung ulang biaya" action; displayed cost is always live. Recorded `order_items.cost_price` never changes.
- **Stock deduction** (design drawer text): not built (O6); the drawer shows the versioning sentence without the deduction promise.
- **Alternatives rejected**: replacing the BOM (breaks 034/036 invariants); a free-text unit with conversion (unbounded scope).

## D13 — Reasons: a small administrable list

- **Decision**: `reason_codes` (category `sold_out|discontinue|approval_reject|…`, code, label id/en, `is_active`, `sort`), seeded with defaults (sold out: out of stock, equipment broken, quality issue, other; discontinue: removed from menu, replaced, seasonal end, other; approval reject: price too high, price too low, other), editable in Settings, shared with `ReasonDialog`'s coded mode (`GET /reason-codes?category=`). Deactivated codes stay on history.
- **Rationale**: the full 041 reason-code catalogue is a bigger feature; this is its first slice using the same table name so it grows into it.

## D14 — List, figures and tabs in few queries

- **Decision**: `ProductCatalogQuery`: one paginated query for rows with eager-loaded variants (min/max/base price, count), modifier names (first 3 + "+n"), availability (marks), and a derived quality flag set; one grouped query for tab counts; one for figures. No per-card request. Sort keys: name, SKU (first variant), base price (min variant), status. Search: name, short name, any variant SKU, code prefix.
- **Figures**: active (status active, non-retail menu items) with total/draft/discontinued; "86 hari ini" = active marks with the first outlet/item names; "Belum dirilis" = pending changes (0 and hidden when releases are off); "Kualitas data" = items with a missing required field (no station anywhere — product and category both empty; no category; no price; no recipe/BOM where the category expects cost) with the first two names.

## D15 — Item detail as a page

- **Decision**: route `/products/:id` (menu key `products`), sections load independently (`GET /products/{id}` core + `/price-history`, `/recipes`, availability inside core). Edit/86/Discontinue and the three drawers are actions on this page. The old modal remains reachable from places that already use it (stock screen SKU, history) until they are moved, to avoid breaking 036's flows.

## D16 — Stage B shape (outlets)

- **Decision**: `outlet_item_settings` (outlet, product, `is_available`, `daily_quota`, channels text), `variant_outlet_prices` (outlet, variant, price), `menu_release_outlets` (release, outlet, state `pending|applied|failed`, at, error). Stage A's mark and release rows carry a nullable `outlet_id`/target that Stage B backfills to the default outlet. Reads use `OutletScope`/`OutletFilter` from 042 Stage B. A per-outlet price is applied by a release to that outlet's row; the live `sell_price` remains the **base** price.
- **Order pricing in B**: order creation takes the outlet's price override when present, else the base, through one `PriceService::priceFor(variant, outlet)`; recorded `order_items.sell_price` snapshot unchanged.

## D17 — Release delivery

- **Decision**: a release = version number (sequential), `publish_at`, status (`scheduled|published|partial|failed|cancelled`), contents (list of change ids), requester/approver/releaser. Stage A has one implicit target. Stage B adds per-outlet states; "terminals receive the menu" means the web POS reloads its menu when its `menu_version` (returned by `GET /menu/availability`) changes.

## D18 — Import/export and existing flows

- **Decision**: Excel import of products keeps working: price columns go through `PriceService` (source `import`), new columns (short name, tax category, prep minutes, retail flag, status) are optional and appended after the existing ones so old files still import; the export gains the same columns. The edit drawer's "apply markup" remains until the bulk drawer replaces its use; stock adjustments from the drawer are untouched.

## Risks

| Risk | Mitigation |
|---|---|
| Wide change to how prices are written | characterisation tests of current edit/import behaviour first; one writer; both modes tested |
| `is_active` consumers broken by status work | status derived, `is_active` untouched except by lifecycle service; enumerating test of consumers |
| Lazy publishing missed on a path | `publishDue()` called in one middleware-like helper used by the four entry points; test per entry point |
| Recipe cost drift when material prices change | live display, explicit recalc action, documented cost-sync moments |
| Size of Stage A | staged tasks, US1/US2 first, engine behind a setting |
| Stage B blocked on governance | Stage A stands alone; Stage B schema rows are nullable-outlet from the start |
