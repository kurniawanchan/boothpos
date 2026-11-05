# Data Model: Per-Menu Action Permissions

## Stored

`roles.menu_actions` — nullable JSON, `{ "<menuKey>": ["<action>", …] }`. Semantics: see research D1 (NULL / missing entry = full; `[]` = view only; View implicit for menus in `menu_keys`).
Validation (server): each key ∈ `MenuKeys` and ∈ the role's `menu_keys`; each action ∈ `MenuActions::for(menu)`; a menu with any action must have View (implicit, so only "menu present" matters); unknown action keys already stored are ignored, never granted (spec edge case).

## Registry (code, not DB) — `App\Support\MenuActions`

`menu → { standard: [create|edit|delete subset], named: [key => label] }`. First version (to be re-checked against `routes/api.php` in tasks):

| Menu | Standard | Named actions |
|---|---|---|
| dashboard, reports | — | `export` (reports) |
| pos | create (complete a sale) | `draft` |
| session | create (open), edit (close) | — |
| events | create, edit, delete | `status` |
| products | create, edit, delete | `bom` (all BOM changes), `catalog` (export), `import`, `export` |
| artists, categories, vendors, materials | create, edit, delete | `import`, `export` |
| stock | — | `adjust`, `export` |
| purchase_orders | create, edit, delete | `status`, `payment` |
| customers | create, edit, delete | `import`, `export` |
| preorders | create, edit, delete | `status`, `dispatch`, `payment`, `verify`, `split`, `duplicate`, `notify`, `import`, `export` |
| sales | — | `void`, `payment`, `payment_method`, `verify`, `reference`, `export`, `receipts` |
| companies, licenses, invoices | create, edit, delete | `activate`, `mark_paid`, `cancel`, `import`, `export` |
| settlements | create, delete | `refresh`, `finalize`, `reopen`, `adjust`, `acknowledge`, `payout` |
| users, roles | create, edit, delete | `export`/`import` (users) |
| settings | edit | `backup`, `restore`, `branding` |

## Derived (per request / per user)

`permissions: { menu → [action…] }` = for every menu in `menu_keys`: `["view"] ∪ grants` (or all of the menu's actions when full). Single source for server checks and `/auth/me`.

## Role import/export cell

`menu_actions` = `menu=a+b;menu2=` ; absent cell/column = full (legacy). Row errors: unknown menu/action, menu not in `menu_keys`.

## State/rules

No state machine. Rules: View is a prerequisite; additive to existing rules; Owner (system default) always full; at least one role keeps full actions on `users` + `roles`.
