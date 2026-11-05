# Research: Per-Menu Action Permissions

Facts read from the code on 2026-11-05 (nothing assumed).

- `roles.menu_keys` (JSON list) is the only grant. `User::canAccessMenu($key)` is the single menu primitive; `isOwnerOrAdmin()` is just `canAccessMenu('settings')` — there is **no separate owner flag**; Owner and Admin are seeded roles with every menu (migration `2026_10_09_000002…`), Kasir/Inventory have fixed menu lists.
- Authorization lives in three places (constitution IV): FormRequest `authorize()`, inline checks (147 `canAccessMenu`/`isOwnerOrAdmin` calls in `app/`), Policies. Many mutating routes have **no route-level gate** (it is inside the controller/request), and some GET routes are shared across menus (e.g. `GET /reports/sales` feeds Dashboard and Sales and has no menu gate).
- `GET /auth/me` already returns `menu_keys` for the SPA (`auth.canAccessMenu`, cosmetic mirror).
- Role lock-out guard: `RolePolicy::wouldLeaveNoRoleCapableOfManagingAccess` (reserved `users`+`roles`), surfaced as 409.
- Roles also travel through the master-data workbook (`roles` sheet, `menu_keys` as a comma list in one cell).

## D1 — Storage shape

Decision: add nullable JSON `roles.menu_actions` = `{ "<menu>": ["create","edit","void", …] }` (View is implicit for any menu in `menu_keys`; `[]` = view only). **NULL, or a menu present in `menu_keys` with no entry, = full access** (legacy). `menu_keys` is unchanged and stays the source of "which menus".
Rationale: zero data migration and zero behaviour change for existing roles (FR-006/007 hold by construction); old clients/imports keep working; reversible.
Rejected: replacing `menu_keys` with a permission matrix table (big migration, breaks import/export and 147 call sites' mental model); one boolean column per action (22×N columns).
Fail-open note: a missing entry means full only for roles never saved through the new editor; the editor always writes an explicit entry for every selected menu.

## D2 — One primitive

Decision: `User::canDo(string $menu, string $action = 'view'): bool` → `Role::allows()`; `canAccessMenu($m)` ≡ `canDo($m,'view')` and is left as is (147 callers untouched). The registry validates `(menu, action)` pairs and **throws in tests** on an unknown pair so typos can't silently grant/deny.

## D3 — Enforcement point

Decision: route middleware alias `ability`: `ability:sales` = standard action derived from the HTTP method (POST→create, PUT/PATCH→edit, DELETE→delete), `ability:sales,void` = a named action. Attached **per route** in `routes/api.php`. Answer on denial: `403 {message, code:'action_not_allowed', menu, action}`.
Rationale: explicit and greppable; independent of controller internals; additive to existing FormRequest/Policy/inline checks (they still run after it).
Rejected: a global middleware that guesses the action from the URI (implicit, fragile); editing 147 inline checks (large, risky, still misses routes with no check).

## D4 — Guarantee nothing is forgotten

Decision: `MenuActionsRouteCoverageTest` iterates `Route::getRoutes()`: every route under `api/v1` that is not GET/HEAD must carry exactly one `ability` middleware OR be in an explicit, commented exemption list (login/logout, own-profile `PUT /auth/*`, `POST /license/activate`, `POST /payment-proofs` upload helper, …). Export-style GET routes (`…/export`, `catalog`, `exports/{entity}`) are also required to carry a named `export` action. A new mutating route without a gate fails CI — this is what makes SC-004 true for future work too.

## D5 — GET (view) enforcement is unchanged

Decision: this feature does not add new view gates. Today's view behaviour (menu gate in the controller, or open for shared endpoints like `/reports/sales`) stays byte-identical; changing it would break the Dashboard and other cross-menu screens. Recorded as a known limitation; a follow-up can add `ability:<menu>` to specific GET routes once their consumers are mapped (also closes the missing `sales` gate on `GET /reports/sales`).

## D6 — "Never widen"

Decision: the new gate runs first and ANDs with existing rules. Owner/admin-only and recorder rules are untouched. `isOwnerOrAdmin()` (= `settings` menu) keeps its meaning: a role with `settings` still counts as privileged for those rules, but every mutation also needs its own `ability` grant, so granting actions can never exceed today's matrix (test `NoWideningTest`: a role with every action on every menu but NOT `settings` still gets today's refusals).

## D7 — Owner can never be locked out (FR-013, FR-010)

There is no owner flag, so: (a) the seeded **Owner** role (`is_system_default`, name `Owner`) is never reduced — `menu_actions` edits to it are refused (422) and `allows()` returns true for it regardless of stored data; (b) the existing reserved-menu lock-out guard is extended: at least one role must keep **full actions** on `users` and `roles` (409 otherwise, same pattern).

## D8 — Resolved permissions for the SPA

Decision: `GET /auth/me` adds `permissions: { "<menu>": ["view","create",…] }` computed from the same `Role::allows()` (so SPA and server can't disagree). The SPA's `canDo(menu, action)` reads it; on any `403 action_not_allowed` the client refetches `/auth/me` (SC-006 screens catch up) and shows the translated message (FR-012). `menu_keys` stays for compatibility.

## D9 — Role editor + import/export

Editor: `RoleMenuPicker` becomes a grid — menu rows × action columns (View locked on when the menu is ticked; unticking View clears the rest; per-row "View only" / "Full access"; header "all"). Import/export: one new optional `menu_actions` cell, `sales=void+verify;pos=` (empty after `=` = view only); **absent column/cell = full access** (FR-007/008); an action without View, or an unknown action/menu, is a row-level error. Old files import unchanged.

## D10 — Which actions per menu

Derived from the existing buttons/routes (full table in data-model.md): standard C/E/D only where the menu has them, plus named actions where the button is not plain CRUD (Sales: void, payment, payment_method, verify, reference, export, receipts; Pre-orders: status, dispatch, payment, verify, split, duplicate, import, export, notify; Stock: adjust; Session: close; Settlements: finalize, reopen, payout, …). Each route maps to exactly one action; ambiguity resolved in favour of the narrower action. Final list is confirmed task-by-task against the route table so none is missed.

## Open items to verify at implement time

- Inventory of every view's buttons (≈25 screens) → map to actions; shared components (`PaymentHistoryList`, `TransactionItemsModal`, `PreorderRowActions`) get the check once, at the component.
- Routes that are `POST` but read-only (e.g. `POST /preorders/bulk-invoices`, `POST /orders/bulk-receipts`, `POST /reports/sales/transactions/export`) map to named read-style actions (`export`, `receipts`), not Create.
- Whether Dashboard/other widgets call mutating endpoints (none expected).

## D11 — Findings while implementing (2026-11-05)

- **Back-compat held**: after the migration every seeded role has `menu_actions` NULL and `allows()` is true for every action of every menu it holds (`RoleActionsBackCompatTest`). The full backend suite before/after: 1686 passed; the only failures were pre-existing (9 Code128 reference-library tests fail on the clean `develop` base too — `picqer` is `require-dev`) and one query-count test that depended on an accident (below).
- **The gate requires the menu to be held.** `allows()` is false for a menu not in `menu_keys` even with NULL actions, so a role that reached an endpoint WITHOUT holding its menu (undocumented/direct calls) now gets 403. The seeded roles all hold the menus of the routes they use; custom roles are the only ones that could notice.
- **`MasterDataImportController`/`ImportMasterDataRequest` + `MasterDataExportController`**: one workbook spans many entities, so the route-level gate can't express it. Export needs `export` on the entity's menu (`MenuActions::MASTER_DATA_MENU`: vendor_prices→materials, bom→products); import needs `import` on EVERY master-data menu the user holds (`MenuActions::userMayImportMasterData`). The route-coverage test exempts `POST /imports/master-data` with that reason.
- **Roles import/export**: new optional `menu_actions` cell `sales=void+export;pos=`; absent/blank = full for new roles, unchanged for existing; Owner can't be restricted by import. MySQL JSON does not keep key order, so comparisons ignore it.
- **`canDo()` on the SPA with no `permissions` payload returns true** (cosmetic only): older servers / tests that mount a view with a minimal user keep every button; with a payload it is strict.
- **Query-count test exposed an accident**: `StockMovementsTest::test_resolving_references_does_not_add_a_query_per_row` used to pass only because the first request lazily loaded the user's role (+1) and cancelled the extra `preorder_items` query in the larger run. The new gate warms the role earlier. The test now uses one data set, a warm-up and two page sizes with the same mix.
- **Deferred (T052)**: view (GET) gates unchanged — `GET /reports/sales` still has no `sales` menu check and is shared with the Dashboard; add view gates once every consumer is mapped.
- **Not done**: per-route fixture tests for every gated route (the route-coverage test pins the mapping and refuses a view-only role on every parameterless route; behaviour with real fixtures is covered for Sales), real-browser check (T051), per-screen component tests beyond Sales, Pre-orders mapping and the role editor.
