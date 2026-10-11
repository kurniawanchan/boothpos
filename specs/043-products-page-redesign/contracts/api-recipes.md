**Classification**: INTERNAL

# Contract: Recipes (versioned) and cost

Permission: read = `products` menu; write = `products` menu + `menu.recipe.edit` (default owner, admin, inventory). Cost fields appear only for callers with the `reports` or `products` menu **and** `menu.recipe.edit`/owner/admin — a cashier sees no cost (as today).

| Route | Purpose |
|---|---|
| `GET /products/{id}/recipes` | versions newest first: `{id, version, yield, effective_from, note, created_by, variants[], is_active_now, portion_cost, food_cost_pct}` |
| `GET /recipes/{id}` | one version with `lines[]` (`material_id, name, unit, quantity, shrinkage_pct, unit_cost, line_cost`), `portion_cost`, `food_cost_pct`, `gross_margin` |
| `POST /products/{id}/recipes` | body `{yield, effective_from, variant_ids[], lines: [{material_id, quantity, shrinkage_pct}], note?}` → creates the **next version**; earlier versions are kept; `409 cost_source_conflict` if a chosen variant is BOM-complete; `422` for yield ≤ 0, no lines, quantity ≤ 0, shrinkage outside 0–99.99, unknown material, empty variant list, effective date more than 30 days in the past |
| `POST /products/{id}/recipes/recalculate` | refreshes `cost_price` of recipe-driven variants from the live portion cost (the explicit "Hitung ulang biaya") |

**Formula** (server, one place `RecipeCost`): `line_cost = quantity ÷ (1 − shrinkage_pct/100) × unit_cost`; `portion_cost = Σ line_cost ÷ yield`; `food_cost_pct = portion_cost ÷ base_price × 100` (one decimal); `gross_margin = base_price − portion_cost`. `unit_cost` = the material's reference price, or `standard_cost` for a semi-finished material without a vendor price; a line without any cost shows `unit_cost: null` and marks the portion cost `incomplete: true` (never a silent zero).

**Cost price sync**: when a version becomes effective (on save with a due date, or lazily at first read after `effective_from`) and on recalculate, `RecipeService` writes `product_variants.cost_price` for the variants it covers; recorded `order_items.cost_price` never change. `PUT /variants/{id}` with a different cost price for a recipe-driven variant → `409 cost_price_locked_by_recipe` (as for BOM-complete).

**Not included**: ingredient stock deduction, unit conversion, a recipe for a semi-finished material.
