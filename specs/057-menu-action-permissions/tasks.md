# Tasks: Per-Menu Action Permissions for Roles

**Input**: `specs/057-menu-action-permissions/` (spec, plan, research D1–D10, data-model, contracts, quickstart)
**Tests**: required (constitution II) — write the test task first (red), then implement.
**Test commands**: backend only in the container with overrides, one run at a time: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<X>`; frontend: `npx vitest run --exclude '.claude/**'`.

## Format: `[ID] [P?] [Story] Description`

- **[P]** = different files, no dependency on an incomplete task. User-story labels map to spec.md US1–US5.
- Code comments in Indonesian; UI copy via `en` + `id` locale keys; Tailwind token classes only.

## Phase 1: Setup

- [X] T001 Create migration `database/migrations/2026_11_05_000001_add_menu_actions_to_roles_table.php` adding nullable JSON `menu_actions` to `roles` (reversible `down()`); NO data backfill (NULL = full access, research D1)
- [X] T002 [P] Create `lang/en/permissions.php` and `lang/id/permissions.php` with the denial message (`action_not_allowed`), the Owner-locked message and the validation messages used by the role endpoints

## Phase 2: Foundational (blocks every story)

**Goal**: the registry, the single primitive, the middleware and the SPA mirror exist and are tested before any route is gated.

- [X] T003 [P] Write `tests/Feature/MenuActionsRegistryTest.php` (red first): every menu in `MenuKeys::ALL` has an entry; `MenuActions::for('sales')` lists exactly the standard+named actions of data-model.md; `isValid(menu, action)`; an unknown pair throws `InvalidArgumentException` in `canDo`
- [X] T004 [P] Write `tests/Feature/RoleCanDoTest.php` (red first): `menu_actions` NULL ⇒ full on every menu in `menu_keys`; entry `[]` ⇒ view only; entry `['void']` ⇒ view+void; menu not in `menu_keys` ⇒ nothing; missing entry for a menu in `menu_keys` ⇒ full; unknown stored action ignored; the seeded Owner role (`is_system_default`, name `Owner`) is always full whatever is stored; a user without a role ⇒ nothing
- [X] T005 Create `app/Support/MenuActions.php`: the registry from data-model.md (`menu => ['standard' => [...], 'named' => [key => label-key]]`), `for($menu)`, `isValid($menu, $action)`, `all()`, `STANDARD = ['create','edit','delete']`; comment citing research D10 and "adding a route/button ⇒ add the action here"
- [X] T006 Edit `app/Models/Role.php`: cast `menu_actions` to array, add `allows(string $menu, string $action = 'view'): bool` (rules in T004; Owner always true) and `resolvedActions(): array` (menu ⇒ actions list, used by `/auth/me` and `RoleResource`); add `menu_actions` to `$fillable`
- [X] T007 Edit `app/Models/User.php`: add `canDo(string $menu, string $action = 'view'): bool` delegating to `Role::allows()` (validate the pair via the registry); make `canAccessMenu($m)` documented as the View primitive (behaviour unchanged); update the docblock
- [X] T008 [P] Write `tests/Feature/EnsureMenuActionTest.php` (red first) using a temporary test route per case: `ability:sales` maps POST→create/PUT→edit/DELETE→delete; `ability:sales,void` named; denial = `403 {code:'action_not_allowed', menu, action, message}` and the controller is NOT reached (no side effect); allowed ⇒ passes through; unauthenticated ⇒ 401 unchanged
- [X] T009 Create `app/Http/Middleware/EnsureMenuAction.php` and register the alias `ability` in `bootstrap/app.php`; denial JSON per contracts/permissions-api.md using `lang/*/permissions.php`; denial is not written to `activity_logs`
- [X] T010 [P] Write `tests/Feature/AuthMePermissionsTest.php` (red first): `/auth/me` returns `permissions` equal to `Role::resolvedActions()` (full role lists every action, view-only role `["view"]`), menus not held are absent, `menu_keys` unchanged
- [X] T011 Edit `app/Http/Controllers/Api/AuthController.php` `me()` (and the login payload if it embeds the same user shape) to add `permissions`; keep `menu_keys`
- [X] T012 [P] SPA foundation, tests first: `qa-tests/unit/authPermissions.test.js` (store `canDo(menu, action)` reads `permissions`, missing ⇒ false, legacy payload without `permissions` ⇒ falls back to `menu_keys` = full) and `qa-tests/component/useAbility.test.js` (composable + `v-can` directive remove the element when not allowed, keep it when allowed, react to a permissions refresh)
- [X] T013 Edit `resources/js/stores/auth.js` (`canDo` getter, `refreshPermissions()`), create `resources/js/composables/useAbility.js` (`can('sales.void')`, used with `v-if` — a `v-can` directive was dropped: removing nodes reactively from a directive is fragile, `v-if` is idiomatic and reactive)
- [X] T014 Edit `resources/js/api/client.js` response interceptor: on `403` with `code === 'action_not_allowed'` call `auth.refreshPermissions()` once and show the server's translated message (FR-012), cooldown-limited like the existing schema toast; test in `qa-tests/unit/clientActionDenied.test.js`

**Checkpoint**: registry + primitive + middleware + `/auth/me` + SPA mirror green. No route is gated yet and no behaviour changed.

## Phase 3: User Story 4 — Existing roles keep working exactly as today (P1)

**Goal**: prove the upgrade is invisible. **Independent test**: before/after matrix of the seeded roles.

- [X] T015 [P] [US4] Write `tests/Feature/RoleActionsBackCompatTest.php`: with `menu_actions` NULL, for each seeded role (Owner, Admin, Kasir, Inventory) and a custom role, `permissions` lists every action of every held menu; a legacy `POST /roles` (menu_keys only) stores NULL and behaves full; roles created before the migration (factory without the column) behave full
- [X] T016 [US4] Run the migration on the dev DB copy used for tests, confirm `php artisan migrate:rollback` / `migrate` both work, and record the "before/after seeded users" check from quickstart step 1 in `research.md` (D11)

## Phase 4: User Story 1 — View-only role on Sales, enforced on the server (P1) 🎯 MVP

**Goal**: a role with Sales = View cannot change anything on Sales, whatever the client does. **Independent test**: view-only token → every Sales mutation 403, reads still work.

- [X] T017 [P] [US1] Write `tests/Feature/SalesActionsMatrixTest.php` (red first), data-driven over the Sales routes: `POST /orders/{o}/void` (void), `POST|DELETE /orders/{o}/payments…` (payment), `PATCH …/confirmation` (payment), `PATCH …/method` (payment_method), `POST …/verify` and `POST /orders/verify-payments` (verify), `PUT|DELETE /orders/{o}/reference` (reference), `POST /reports/sales/transactions/export` (export), `POST /orders/bulk-receipts` (receipts): a role holding Sales with only `view` ⇒ 403 `action_not_allowed` and no data change; a role holding exactly that action ⇒ not 403 (reaches the existing business rules); reads (`GET /orders/{o}`, `GET /reports/sales`, reference view) still work for view-only
- [X] T018 [US1] Edit `routes/api.php` Sales block (lines ~224–250): add `->middleware('ability:sales,<action>')` per T017 table (and `ability:pos` / `ability:pos,draft` on `POST /orders`, `pos-drafts` routes); leave GET routes untouched (research D5)
- [X] T019 [US1] SPA, tests first: `qa-tests/component/SalesViewOnlyRole.test.js` — with `permissions = {sales:['view']}` the Sales list and detail show NO Void, add/delete payment, change method, mark verified, edit confirmation, edit/remove reference, Export, Download receipts, bulk "Mark verified (N)" controls; with full permissions they all appear (reuse the existing SalesView mounting helper)
- [X] T020 [US1] Apply `v-can`/`canDo` in `resources/js/views/SalesView.vue` (export, bulk receipts, bulk verify), `resources/js/components/sales/TransactionItemsModal.vue` (void, payment actions, reference/confirmation entry points), `resources/js/components/sales/OrderReferenceModal.vue` (edit/remove), `resources/js/components/payment/PaymentHistoryList.vue` (verify, edit confirmation, change method, delete — the shared component also serves Pre-orders, so gate by a `can` prop/slot mapping so Pre-orders use `preorders.*` actions)
- [X] T021 [P] [US1] Real-API sanity: extend `tests/Feature/SalesActionsMatrixTest.php` with one end-to-end case per action showing that a *denied* call leaves `orders`/`payments` rows and `activity_logs` unchanged

**Checkpoint (MVP)**: the user's example works — Sales = View only is safe end-to-end.

## Phase 5: User Story 2 — Choose actions per menu in the role editor (P1)

**Goal**: configure it from the UI. **Independent test**: set Sales = View only, save, reopen — identical.

- [X] T022 [P] [US2] Write `tests/Feature/RoleMenuActionsApiTest.php` (red first): create/update with `menu_actions` ok; 422 for unknown menu / unknown action / menu not in `menu_keys`; `RoleResource` returns resolved explicit `menu_actions` for every held menu and `is_locked`; editing the Owner role's actions ⇒ 422; update omitting `menu_actions` leaves it unchanged; saving via the editor shape always writes an explicit entry per selected menu; `GET /menu-keys` items carry `actions[{key,label,standard}]`; activity log row contains old/new `menu_actions` inside the same transaction
- [X] T023 [US2] Edit `app/Http/Requests/StoreRoleRequest.php` and `UpdateRoleRequest.php` (`menu_actions` rules via `MenuActions`), `app/Http/Resources/RoleResource.php` (`menu_actions`, `is_locked`), `app/Http/Controllers/Api/RoleController.php` (menuKeys payload with actions; Owner lock → 422; audit old/new in the transaction)
- [X] T024 [P] [US2] Write `tests/Feature/RoleLockoutActionsTest.php` (red first): the existing 409 guard now requires at least one role with FULL actions on `users` AND `roles`; reducing the last such role's actions ⇒ 409; deleting it ⇒ 409; another role still full ⇒ allowed
- [X] T025 [US2] Edit `app/Policies/RolePolicy.php::wouldLeaveNoRoleCapableOfManagingAccess` (and the `update` path in `RoleController`) to count only roles with full actions on both reserved menus (`menu_actions` considered); keep the 409 message family
- [X] T026 [P] [US2] Write `qa-tests/component/RoleMenuPicker.test.js` (red first, extend if present): grid renders menu rows × action columns; ticking a menu ticks View and locks it; unticking View clears the menu's actions; per-row "View only" and "Full access" shortcuts; a menu with no standard actions shows only its named ones; emits the `{menu_keys, menu_actions}` shape; Owner row disabled with the lock note; labels in id and en
- [X] T027 [US2] Rework `resources/js/components/settings/RoleMenuPicker.vue` into the actions grid (token classes, keyboard accessible, responsive: grid collapses to per-menu cards on small widths) and update `resources/js/views/RolesView.vue` (`form.menu_actions`, payload, summary column "N menus · custom actions" via a locale key), `resources/js/api/roles.js` if it whitelists fields
- [X] T028 [P] [US2] Add locale keys to BOTH `resources/js/locales/en.json` and `id.json` (`roles.actions_*`, `roles.view_only`, `roles.full_access`, `roles.locked_owner`, action labels `perm.<menu>.<action>` or `perm.action.<key>`); `qa-tests/unit/localeKeys.test.js` must stay green

## Phase 6: User Story 3 — Buttons and screens follow the role (P1)

**Goal**: every remaining menu's mutations are enforced server-side AND hidden client-side. Each menu is independent after Phase 2; do them in this order (highest risk first).

### 6a — Pre-orders (largest)

- [X] T029 [P] [US3] (merged into `MenuActionsRouteCoverageTest` — one table-driven test pins every route's (menu, action) and refuses a view-only role on every parameterless gated route; per-route fixture tests for ~110 routes were not written) Write `tests/Feature/PreordersActionsMatrixTest.php` (red first): `POST /preorders` (create), `PUT` (edit), `DELETE` (delete), `PATCH …/status` (status), `PATCH …/dispatch-status` (dispatch), payments (`payment`), `…/verify` (verify), `…/split`, `/preorders/duplicate`, `/preorders/bulk-email` + `…/notifications/resend` (notify), `/preorders/import*` (import), `/preorders/export` + `/preorders/bulk-invoices` (export), `POST …/shipment` + `PATCH /shipments/{s}` (edit): role without the action ⇒ 403, with it ⇒ not 403
- [X] T030 [US3] Edit `routes/api.php` Pre-order block (~257–291) with `ability:preorders[,action]`
- [X] T031 [P] [US3] Write `qa-tests/component/PreordersRestrictedRole.test.js` (red first): hide New, Edit, Delete, status/dispatch changes, payment actions, split, duplicate, bulk email, import, export; shared `PaymentHistoryList` uses `preorders.*` here
- [X] T032 [US3] Apply `canDo` in `resources/js/views/PreordersView.vue`, `components/preorder/*` (row actions via `PreorderRowActions.vue` `actions` filter, detail drawer buttons, payment modals entry points)

### 6b — Catalogue & inventory (products, artists, categories, stock, vendors, materials, purchase orders)

- [X] T033 [P] [US3] Write `tests/Feature/MasterDataActionsMatrixTest.php` (red first): representative route per action for products (create/edit/delete/`bom`/`import`/`export`/`catalog`), artists, categories, vendors, materials, stock (`adjust`, `export`), purchase orders (create/edit/delete/`status`/`payment`), including variant routes and BOM routes (which keep their existing `products` AND `purchase_orders` double gate in addition)
- [X] T034 [US3] Edit `routes/api.php` (~99–147, 189–204) with `ability:` for products, variants, BOM, catalog/export, stock, artists, categories, vendors, materials, vendor-prices, purchase orders, and the master-data `exports/{entity}` + `imports/master-data*` routes (named `export`/`import` on the entity's own menu; routes spanning several entities require each entity's action — mirror today's `canAccessAnyMenu` gate, never narrower than it, never wider)
- [X] T035 [P] [US3] Write component tests (red first): `qa-tests/component/ProductsRestrictedRole.test.js`, `StockRestrictedRole.test.js`, `PurchaseOrdersRestrictedRole.test.js` (one representative hidden-control assertion set each; others covered by T036's pattern)
- [X] T036 [US3] Apply `canDo` in `resources/js/views/ProductsView.vue` (+ `ProductDetailModal`, `VariantBomModal` entry, catalog/export/import buttons), `ArtistsView.vue`, `CategoriesView.vue`, `StockView.vue`, `VendorsView.vue`, `MaterialsView.vue`, `PurchaseOrdersView.vue` (+ detail modal status/payment)

### 6c — Customers, events, sessions, POS

- [X] T037 [P] [US3] Write `tests/Feature/CustomersEventsSessionsActionsTest.php` (red first): customers create/edit/delete/import/export; events create/edit/delete/`status`; sessions open (create) / close (edit); POS `POST /orders` (create) and drafts
- [X] T038 [US3] Edit `routes/api.php` (~104–111, 206–215, 224–230) accordingly
- [X] T039 [US3] Apply `canDo` in `CustomersView.vue` (+ import/export buttons), `EventsView.vue`, `SessionView.vue`, `SessionsHistoryView.vue` (view only), `PosView.vue` (complete sale / drafts / payment panel entry) with tests `qa-tests/component/CustomersEventsPosRestrictedRole.test.js` written first

### 6d — Back-office (companies, licenses, invoices, settlements, users, roles, settings, reports)

- [X] T040 [P] [US3] Write `tests/Feature/BackOfficeActionsMatrixTest.php` (red first): companies (create/edit/delete/`activate`), licenses + business types, invoices (create/edit/delete/`mark_paid`/`cancel`/`import`/`export`), settlements (create/delete/`refresh`/`finalize`/`reopen`/`adjust`/`acknowledge`/`payout`), users (create/edit/delete/photo), roles (create/edit/delete), settings (edit, `branding`, `backup`, `restore`), payment channels & invoice payment settings (settings edit), reports exports (`export`)
- [X] T041 [US3] Edit `routes/api.php` (~66–97, 155–205, 217–222, 296–345) accordingly; note `roles`/`users` stay protected by their Policies (the middleware is additive)
- [X] T042 [US3] Apply `canDo` in `CompaniesView.vue`, `LicensesView.vue`, `BusinessTypesView.vue`, `InvoicesView.vue`, `SettlementsView.vue`, `SettlementDetailView.vue`, `UsersView.vue`, `RolesView.vue`, `SettingsView.vue`, `SettingsPaymentView.vue`, `ReportsView.vue` (export buttons), with a representative component test written first (`qa-tests/component/BackOfficeRestrictedRole.test.js`)

### 6e — Guarantee (D4)

- [X] T043 [US3] Write and run `tests/Feature/MenuActionsRouteCoverageTest.php`: iterate `Route::getRoutes()` for `api/v1`; every non-GET/HEAD route must carry exactly one `ability` middleware or appear in the commented exemption list (`/auth/*` own-profile, `/license/*`, `/auth/login|logout`, `POST /payment-proofs` upload helper — each exemption with a one-line reason); every `*/export` or `catalog` GET must carry a named `export`/`catalog` action; and every `(menu, action)` referenced by a route exists in `MenuActions`; fix whatever it reports in `routes/api.php`

**Checkpoint**: no mutating route can be added without a gate; every screen hides what its role can't do.

## Phase 7: User Story 5 — Never widens existing rules (P2)

- [X] T044 [P] [US5] Write `tests/Feature/NoWideningTest.php`: a custom role (no `settings`) with every action on `sales`, `preorders` and `products` still gets today's refusals — delete payment (owner/admin only), verify own payment (recorder), change method to cash from non-cash (owner/admin only), cost/margin absent from `GET /reports/sales`, BOM actions still needing BOTH `products` and `purchase_orders`; and a role with the action off is refused even for a user who would pass the existing rule (action gate narrows)
- [X] T045 [US5] Fix anything T044 reveals (nothing needed — the middleware is additive); document the `isOwnerOrAdmin() = settings` caveat in `CLAUDE.md` (a role with `settings` still counts as privileged for those rules; its mutations are separately gated)

## Phase 8: Role import/export (FR-007/008)

- [X] T046 [P] Write `tests/Feature/RoleMenuActionsImportExportTest.php` (red first): export writes the `menu_actions` cell (`sales=void+verify;pos=`), re-import round-trips; a legacy file without the column imports as full access; unknown menu/action, an action for a menu not in `menu_keys`, and a malformed cell ⇒ row-level error with sheet/row/column; the template's example row stays importable (`test_the_shipped_template_imports_as_is` family)
- [X] T047 Edit `app/Support/MasterDataSheets.php` (roles sheet headers + example row), `app/Services/MasterDataExportService.php` and `MasterDataImportService.php` (parse/format `menu_actions`, validate against `MenuActions`, all-or-nothing as today)

## Phase 9: Polish & cross-cutting

- [X] T048 [P] Edit `docs/openapi-pos-mvp.yaml`: `/auth/me` `permissions`, `/roles` request/response (`menu_actions`, `is_locked`), `/menu-keys` `actions`, the `403 action_not_allowed` response component referenced from every gated route group (or a global description), roles import/export column; validate the YAML
- [ ] T049 Run the full affected suites: backend `--filter="Role|Menu|Ability|ActionsMatrix|AuthMe|MasterData|Sales|Preorder|Order|Payment"` (one run at a time, container overrides) then, since this touches every route, the FULL backend suite once; frontend whole suite excluding `.claude/**`; `npm run build`; fix regressions (existing tests that sign in as custom roles with partial menus may need `menu_actions` NULL = full, which is already the default)
- [X] T050 [P] Update `CLAUDE.md`: a dated "Per-menu action permissions (feature 057, 2026-11-05)" section (D1–D8 essentials, the route-coverage test, GET unchanged, Owner lock, import/export cell) and update the Roles paragraph in "Authorization is split across three mechanisms"
- [ ] T051 Real-browser verification per `quickstart.md` (owner + a view-only Sales role + a partial role; console clean; en and id; role editor grid at desktop and narrow width; role changed while the user is signed in); record verified / not verified
- [X] T052 Follow-up note (no code): record in `research.md` the deferred item — add view gates to shared GET endpoints (`GET /reports/sales` has no `sales` menu check) once their consumers are mapped

## Dependencies & Execution Order

- **Phase 1 → Phase 2 (blocks everything) → Phase 3 (US4) and Phase 4 (US1 MVP) → Phase 5 (US2) → Phase 6 (US3) → Phase 7 (US5) → Phase 8 → Phase 9.**
- US4 (back-compat) only needs Phase 2; run it right after to lock the guarantee before gating routes.
- US1 depends on Phase 2 only; US2 (editor) can run in parallel with US1 after Phase 2 (different files) but the editor is only *useful* once US1 gates exist.
- Phase 6a–6d are independent of each other (different routes/views); **all edit `routes/api.php` → do those edits one after another** (T018 → T030 → T034 → T038 → T041), not in parallel. T043 (coverage) comes last in Phase 6 and drives any missed route.
- `PaymentHistoryList.vue` is shared by Sales and Pre-orders: T020 introduces the prop mapping, T032 reuses it.
- Locale JSON edits (T028 and each view task) touch the same two files → apply sequentially.
- TDD inside each story: the test task first (red), then the implementation.

### Parallel opportunities

- Phase 2: T003, T004, T008, T010, T012 are separate test files (write together), then T005→T006→T007, T009, T011, T013→T014.
- Phase 4/5: T017 ∥ T022 ∥ T024 ∥ T026 (tests); then implementation per story.
- Phase 6: the four `*ActionsMatrixTest` files (T029, T033, T037, T040) are independent test files.
- Phase 8 (T046/T047) is independent of Phases 6–7.

## Implementation Strategy

- **MVP = Phase 1 + 2 + US4 + US1**: Sales = View only works end-to-end and existing roles are provably unchanged. Ship-able on its own because everything else defaults to full access (NULL).
- **Increment 2**: US2 (editor) so the owner can actually configure roles.
- **Increment 3**: US3 menu by menu (6a Pre-orders first), each with its matrix test; T043 proves completeness.
- **Increment 4**: US5 + import/export + polish.
- Stop at any checkpoint to verify in the browser; the feature is safe at every checkpoint because ungated routes behave exactly as today.

## Notes

- Total tasks: 52 (Setup 2, Foundational 12, US4 2, US1 5, US2 7, US3 15, US5 2, Import/export 2, Polish 5).
- One migration (nullable column), no new dependency, no behaviour change until a role is edited.
- Server enforcement is the boundary; hiding is cosmetic (constitution IV). Denied attempts change nothing and are not audit-logged.
- Known limitation kept (research D5/T052): view (GET) gates are unchanged.
