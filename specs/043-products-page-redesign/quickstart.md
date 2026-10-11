**Classification**: INTERNAL

# Quickstart: verifying the Products redesign

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app php artisan test --filter='Product|Availability|PriceService|BulkPrice|MenuRelease|Approval|Recipe|ModifierLink|ReasonCode'
npm test && npm run build      # compare main chunk with the measured start-of-branch value (+10 kB budget)
```

## Stage A

1. **Baseline before changes**: run the full backend and frontend suites; write characterisation tests of today's ordering rejections (inactive product/variant on `POST /orders` and tab lines) and of `PUT /variants/{id}` price edits, product import prices, and the BOM complete/cost-lock behaviour — they must pass on untouched code.
2. **Migrations** on a populated copy: existing active products become `published_at = created_at`, inactive ones `discontinued_at`; re-run after forcing a failure; `modifier_group_product` keeps working with null rule columns.
3. **List**: seed 24 items (categories, one draft, one discontinued, three with 86, two without station). Compare each figure with the rows; switch grid/table, search by name and SKU, filter category and status, page; reload restores the view.
4. **Item page**: open an item with variants, modifier links, a recipe; check each section, the price history ordering, food cost % and margin against a hand calculation, role-hidden actions (cashier sees no cost).
5. **86 and discontinue**: mark 86 → next order attempt returns 409, an open tab line is untouched, the POS greys the item within 5 s (poll), the mark ends at the business-day boundary (travel the clock), undo works; discontinue → unorderable, history/reports intact, reactivate works; both logged.
6. **Bulk price**: +5% on one category with Rp 500 rounding and a future date → preview equals applied result; invalid inputs refused with the field named; all-or-nothing on a forced failure; history rows exactly one per variant; past orders unchanged.
7. **Release mode on**: edit prices (one above 15%), a new item, a modifier link → banner lists them; the over-threshold one cannot be released; the requester cannot approve it; another approver can; release v{n} applies everything, `price_history` shows source `release` and version; a scheduled release applies at the first request after its time (clock travel) from each of the four entry points; release mode off → everything immediate and no banner.
8. **Recipes**: save v1, edit to v2; v1 kept with dates; formulas incl. shrinkage and yield 2; a BOM-complete variant refuses a recipe (409) and vice-versa; `cost_price` follows only at activation/recalculate; recorded order costs unchanged.
9. **Browser** (Constitution II): list in both modes/languages, every dialog and drawer, keyboard path, console clean, roles `owner`, `admin`, `inventory`, `kasir01`; record screenshots under `evidence/`.

## Stage B (after the 042 amendment and Phase 7)

1. Apply the outlet migrations on populated data; a single-outlet install shows no new controls and every Stage A behaviour is unchanged.
2. Three outlets: per-outlet 86 and price; release to two outlets; a user limited to one outlet cannot read or change another's availability/prices; forged outlet in a body refused.
3. Repeat the browser pass with the outlet selector and per-outlet chips.

## Evidence

Record results in `evidence-stage-a.md` / `evidence-stage-b.md` (commands, counts, screenshots). Do not claim a stage done without them.
