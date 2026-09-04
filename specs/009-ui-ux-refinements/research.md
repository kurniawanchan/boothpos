# Phase 0 Research: UI/UX Refinements Batch

## R1 — Sidebar menu color inconsistency (FR-002)

**Decision**: Treat this as a verification-first task, not an assumed CSS bug. Re-inspect `AppSidebar.vue` in a running browser against the two screenshots before writing any CSS change; only then patch the specific class(es) that differ.

**Rationale**: `AppSidebar.vue` L149-150 and L166-167 already apply the *identical* class expression to every top-level item and group (`text-muted-4` default, `bg-mint-100 font-bold text-brand-active` when active) — including Purchase (L44), Inventaris (L54), and Pengaturan (L70). There is no per-item override, hardcoded hex, or gray-specific class visible in the source for these three groups versus any other. The visual gap the screenshots show could be: (a) a stale/cached build the user is looking at, (b) a genuine runtime difference not visible from static reading (e.g. `ph-duotone` icon glyphs rendering with a baked-in secondary-tone opacity that only becomes visually obvious on certain icons), or (c) already fixed by the time this feature ships. A code-only fix without visually re-confirming the live discrepancy risks "fixing" code that isn't actually broken.

**Alternatives considered**: Blindly diff every `nav-item` class and force a `text-brand`/theme-token class onto the three named groups — rejected because the source shows no asymmetry to correct, and forcing color could make those three items *inconsistent* with the rest if the real cause is elsewhere (e.g. icon rendering, not the text/icon color class).

**Follow-up for planning**: First implementation step for this FR is a live-browser screenshot comparison against the two provided images; only if a real diff is found does a CSS class change proceed.

## R2 — Where username/logout currently live vs. where they must move (FR-020)

**Decision**: Relocate the user block + logout button from `AppSidebar.vue` (L195-217, sidebar footer) into `AppTopbar.vue` (top-right), removing them from the sidebar footer entirely rather than duplicating.

**Rationale**: Confirmed via code read — today's username/logout live in the sidebar footer, wired through `AppShell.vue`'s `@logout="handleLogout"` → `auth.logout()` (`resources/js/stores/auth.js` L57-59). `AppTopbar.vue` is presentational (`title`/`subtitle` props + `SystemModeBadge` + `LanguageSwitcher` + an `actions` slot) and has no user/logout affordance today. Moving (not duplicating) avoids two logout entry points confusing users and keeps a single source of truth for the logout action.

**Alternatives considered**: Keep sidebar logout and add a *second* logout/user display in the topbar — rejected, spec FR-020 says "pindahkan" (move), and two logout buttons is worse UX (Constitution III: predictable, non-duplicated affordances).

## R3 — Logout label i18n (FR-021)

**Decision**: Add a new `common.logout` (or `nav.logout`) key to both `resources/js/locales/id.json` (`"Keluar"`) and `en.json` (`"Log out"`), and route the relocated logout button's label through `t()` instead of the current hardcoded literal.

**Rationale**: Confirmed via grep — no `logout`/`Keluar`/`sign_out` key exists in either locale file today; the current "Keluar" text in `AppSidebar.vue` L216 is a hardcoded literal, unlike every other sidebar label which already goes through `nav.*` keys. This is a genuine, pre-existing i18n gap this feature closes as a side effect of the relocation (FR-021 requires it explicitly).

**Alternatives considered**: Leave "Keluar" hardcoded and only translate for English via a `v-if="locale==='id'"` ternary inline — rejected as inconsistent with how every other string in this app is localized (`t()` + locale JSON), and harder to maintain.

## R4 — Sales page transaction popup vs. existing components (FR-004, FR-005)

**Decision**: Build one new component, `TransactionItemsModal.vue`, that fetches/derives the sold-items table for a given order id and renders it in a `BaseModal`; wire product-name clicks inside it to open the **existing** `ProductDetailModal.vue` (already used elsewhere in `SalesView.vue` via `openRowDetail()`), passing `product-id`. Retire the `ReceiptModal` wiring from the transaction-number click path (receipt printing, if kept, moves to an explicit "print receipt" action elsewhere — out of scope here per spec FR-004, which only says the transaction-number click must stop opening the receipt).

**Rationale**: `SalesView.vue` already has two relevant, working pieces: `ProductDetailModal` (reusable, driven only by `product-id` — directly satisfies FR-005 with zero new component) and a `ReceiptModal` currently bound to the transaction-number click (`openReceipt()`, L106-109) that FR-004 requires unbinding from that trigger. The confirmed `GET /reports/sales` response (`resources/js/api/reports.js`) returns transaction *headers* (`order_number, customer_name, item_count, total_amount, id`, …) but **not** order-item-level detail — so `TransactionItemsModal` needs its own data source.

**Alternatives considered**: Extend `GET /reports/sales`'s `transactions` array to eagerly embed full item lists for every row — rejected as a Constitution V violation (bloats a list endpoint's default payload for a feature that only needs item detail on-demand for the one row clicked); instead the modal calls the existing per-order detail endpoint (`GET /orders/{order}` via `resources/js/api/orders.js`, which already eager-loads `items.variant.product`, matching the `OrderController`'s existing `with()` pattern used elsewhere) keyed by the clicked transaction's `id`. This is opt-in-per-click, matching the existing "opt-in heavier payload" convention (CLAUDE.md: `GET /products` variants are opt-in the same way).

## R5 — Artist → Penjual/Sellers rename scope (FR-006–FR-008)

**Decision**: Rename only user-facing string *values* in `resources/js/locales/{id,en}.json` and the matching `lang/{id,en}/*.php` files (`master_data.php`, `reports.php`, `policies.php`, etc.) wherever the value reads "Artist"/"Artists" as a label. Do **not** rename JSON/PHP *keys* (`nav.artists` stays `nav.artists`; only its value changes), route names, menu keys (`menuKey: 'artists'` in `AppSidebar.vue`), model/table/column names, or JS variable names.

**Rationale**: Confirmed the `Artist` concept is controlled through locale files (`nav.artists`, `master_data.artist_updated/created/deactivated`, `reports.artist`, `reports.artist_label`, `reports.artist_profit_note*`, plus backend `lang/id/{master_data,reports,policies}.php`) alongside code-identifier occurrences (`menuKey: 'artists'`, route names, prop/variable names) that are **not** translatable UI text and must not change — renaming those would be an unscoped, high-risk refactor of routing/authorization plumbing (`canAccessMenu('artists')` etc.) for a copy-only request. This matches spec FR-008 and the Edge Cases note explicitly ruling that out.

**Alternatives considered**: Rename the `Artist` Eloquent model/table/menu-key to `Seller`/`penjual` for full consistency — rejected; explicitly out of scope per spec (FR-008), and would cascade into `ArtistPolicy`, `LicenseGate` (Pro/Master tier is keyed on "artist" language throughout CLAUDE.md), and dozens of route/test references for no requested behavior change.

**Verification approach**: After the key-value edits, grep both locale files, all `lang/*/*.php` files, and rendered `.vue` templates (not `.js`/`.vue` identifiers) for the literal strings "Artist"/"Artists" to confirm zero remaining *label* occurrences, per SC-004.

## R6 — Event/Customer delete: which pattern to mirror (FR-009–FR-013)

**Decision**: Mirror `ArtistController::destroy()` / `CategoryController::destroy()` exactly: `EventPolicy`/`CustomerPolicy` gain a `delete()` ability (owner/admin, matching the existing `isOwnerOrAdmin()`/`canManageMasterData()` convention already used for these two entities' other mutations); `EventController`/`CustomerController` gain `destroy()` methods that (1) authorize via the policy, (2) check for any existing `orders()`/`preorders()` rows referencing the record (including soft-deleted/any-status rows — spec Edge Cases requires this), returning `409` with a translated conflict message if found, (3) otherwise wrap the soft delete in `DB::transaction()` with an `ActivityLogger` entry, matching `ArtistController`'s `$model->only($model->getFillable())` snapshot pattern.

**Rationale**: Both `Event` and `Customer` already use `SoftDeletes` (confirmed in both models), so "delete" here means the existing soft-delete convention already used by Artist/Category — not a new hard-delete mechanism (resolves the spec's "Assumptions" section explicitly in favor of matching existing precedent, since CLAUDE.md and the codebase's own Artist/Category delete already establish this pattern for comparable master data).

**New relations required**: `Customer` model currently has **no** `orders()`/`preorders()` relations (confirmed) despite `orders.customer_id`/`preorders.customer_id` FK columns existing — these two `hasMany` relations must be added as a prerequisite for both the delete guard (this research item) and the transaction-history feature (R7). `Event` already has `orders()` (L43-46) but is **missing `preorders()`** — also needed for its delete guard, since a preorder can reference an event.

**Alternatives considered**: Implement the guard as a raw `DB::table('orders')->where(...)->exists()` query instead of an Eloquent relation — rejected; an Eloquent relation is reusable by R7's transaction-history feature too, avoiding duplicated query logic (Constitution I: one implementation per concern).

## R7 — Customer transaction history data source (FR-014–FR-015)

**Decision**: New `GET /customers/{customer}/transactions` endpoint (or `CustomerController::transactions()`) that loads the customer's `orders` and `preorders` (via the R6 relations) and returns a merged, type-tagged, date-sorted list of lightweight summaries (id, type, number, status, total, date). The frontend view opens the existing order-detail / preorder-detail views/modals for a selected row rather than building new detail UI — reuse, not duplication, per spec FR-015.

**Rationale**: `Order`/`Preorder` both already expose full detail via their existing single-resource endpoints and existing frontend detail components (`ReceiptModal` for orders' printable form is separate from a generic order-detail fetch already used by `TransactionItemsModal` per R4; Preorder already has its own detail/print view per the shipped `007-preorder-import-export-notify` feature). Building a third, customer-scoped detail renderer would duplicate logic the Constitution (Principle I) flags as a defect.

**Alternatives considered**: Have the frontend independently call `GET /orders?customer_id=X` and `GET /preorders?customer_id=X` and merge client-side — rejected as a heavier two-round-trip pattern with client-side merge/sort logic duplicated per screen; a single backend endpoint keeps the merge/sort logic in one place and matches this codebase's existing hand-rolled `present()`-style response shaping for comparable read endpoints (`ReportController`, `PreorderController`).

## R8 — Dashboard per-customer stats data source (FR-016)

**Decision**: Extend `ReportController::sales()`'s existing `group_by` parameter to accept `customer` (alongside its confirmed existing `product|category|artist|day` options), aggregating by `orders.customer_id` (joined to `customers.name`) the same way the existing `artist` grouping already aggregates by artist. Reuse this from the Dashboard exactly as `DashboardView.vue` already reuses `salesReport()` for its other panels (per CLAUDE.md's documented precedent from feature 005, which added a `group_by=event` option to this same endpoint rather than a new `DashboardController`).

**Rationale**: Confirmed `salesReport()`/`GET /reports/sales` is already the shared data source for both `SalesView.vue` and `DashboardView.vue`, and this codebase has already extended its `group_by` enum once before (event grouping, per CLAUDE.md's feature-005 note) rather than standing up a parallel endpoint — this is the established, lowest-risk extension point.

**Alternatives considered**: A dedicated `GET /reports/customer-stats` endpoint — rejected as an unnecessary parallel path when the existing grouped-aggregation endpoint already generalizes cleanly to one more `group_by` value, consistent with prior art in this exact file.

**Performance note (Constitution V)**: Must be one grouped `GROUP BY orders.customer_id` query, not a per-customer loop — same shape as the existing `artist`/`day` grouping branches already in `sales()`.

## R9 — Stock-by-artist drilldown shape (FR-017)

**Decision**: Extend `ReportController::stockByArtist()` to accept an optional `artist_id` (or add a sibling method `stockByArtistDetail($artistId)`) that returns the same query one level deeper — grouped by `product_variants` for that one artist — reusing the existing artist→products→variants join already present in `stockByArtist()` (L499-532) rather than a new join path. Frontend: clicking a row in the existing stock-by-artist report table calls this on-demand (lazy, per spec's "at most 1 additional click", SC-008) rather than the summary endpoint eagerly returning every artist's variant list.

**Rationale**: Confirmed `stockByArtist()` currently returns only `artist_id, artist_name, variant_count, total_stock` per artist (aggregate-only) — variant-level rows are not in that payload today. Fetching detail on click (not eagerly) matches Constitution V's "list endpoint stays lean, heavier payload is opt-in" rule, the same rule already applied to `GET /products`'s `with_variants` param per CLAUDE.md.

**Alternatives considered**: Return full variant-level rows for every artist in the base `stockByArtist()` response — rejected as bloating a report that's meant to give a fast per-artist overview.

## R10 — Settings "Data Backup" removal (FR-018)

**Decision**: Delete the self-contained block at `SettingsView.vue` (`t('settings.data_backup')` section, between the Store Identity form and the system-mode `ConfirmDialog`) — a template-only removal, no store/composable/API changes needed since this section was always static informational copy (no backend call backing the "Cadangkan sekarang" button per CLAUDE.md's existing note that it's intentionally unwired).

**Rationale**: Confirmed self-contained and not interleaved with other functional sections; safe contiguous-block deletion. The now-orphaned `settings.data_backup`/`settings.run_from_server_console`/`settings.backup_command_note`/`settings.backup_files_note` locale keys should also be removed from both locale JSON files as part of the same change (dead-key cleanup), unless referenced elsewhere (verify via grep before deleting keys).

## R11 — Navbar store name / active event name (FR-019)

**Decision**: Pass `storeName` (from the existing store-settings/profile data already available to `SettingsView.vue`'s Store Identity form — likely a `useSettingsStore()` or equivalent Pinia store already loaded app-wide) and the currently active `Event` (from whatever store/composable already tracks "active event" for POS/session screens — confirm exact source during implementation, likely `useEventStore()` or a `activeEvent` computed already used by `CashierSessionController`-backed screens) into `AppTopbar.vue` as new props, rendered next to the existing `title`/`subtitle` slot content.

**Rationale**: `AppTopbar.vue` is already prop-driven and per-view (`title`/`subtitle`), so this is additive props rather than a new state-management pattern. Exact source of "active event" needs a one-time lookup during implementation (not fully traced in this research pass) — likely already surfaced somewhere since cashier session screens must already know the active event to open a session.

**Follow-up for planning**: Implementation's first sub-task for this FR is locating the existing "active event" state source (grep `activeEvent`/`currentEvent`/`event_id` in Pinia stores) before wiring the prop — flagged as a small residual unknown, not a blocking one (the data indisputably exists somewhere already, since POS/cashier-session flows depend on it).
