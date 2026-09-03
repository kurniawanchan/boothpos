# Phase 0 Research

## R1: Charting approach for dashboard analytics

**Decision**: Add `chart.js` + `vue-chartjs` as a new frontend dependency,
dynamically imported (`defineAsyncComponent` / route-level lazy import)
inside `DashboardView.vue` only, so it does not inflate the initial bundle
for every other screen (Constitution Principle V).

**Rationale**: `package.json` currently has no charting library. `chart.js`
is small (~200KB min, tree-shakeable via its module registration API),
Canvas-based (cheap to render, no SVG DOM bloat for repeated re-renders on
filter change), and has a thin official Vue 3 wrapper (`vue-chartjs`) that
fits this codebase's Composition API style. A bar/line/doughnut chart is all
this feature needs (sales-per-day line, sales-per-category/artist/event
bar/doughnut) — no need for a heavier charting suite.

**Alternatives considered**:
- **ApexCharts (`vue3-apexcharts`)**: richer built-in interactivity, but
  heavier bundle and its own CSS — more than this feature needs.
- **Hand-rolled inline SVG bars**: zero dependency, but reimplements
  axis/legend/tooltip logic that Chart.js already solves correctly, and the
  spec's edge case ("empty state when no sales") and accessibility need
  (tooltips, keyboard-navigable legend) are non-trivial to hand-roll well.
- **d3**: far more power than needed; steeper learning curve for future
  maintenance in a small codebase with no prior d3 usage.

## R2: Multi-select dropdown component strategy

**Decision**: Extend `resources/js/components/ui/BaseSelect.vue`'s existing
custom-dropdown shell (already avoids native `<select>` styling
inconsistency per its own header comment) into a new sibling
`BaseMultiSelect.vue` that adds: a search input inside the panel, checkbox
rows, an "All" pseudo-option, and a `modelValue` shape of `Array<string|number>`
(empty array = "All"). `BaseSelect.vue` itself is left unchanged (it has 8+
existing single-select callers per its own comment; do not risk regressing
them).

**Rationale**: Reuses the existing token-based styling, keyboard nav
(`activeIndex`), and click-outside/positioning logic already proven in
`BaseSelect.vue`, satisfying Constitution Principle I (no duplicated
component-shell logic) while not touching the 8 existing single-select call
sites — lower regression risk than modifying `BaseSelect.vue` in place.

**Alternatives considered**:
- **Modify `BaseSelect.vue` in place** with a `multiple` prop: rejected —
  the component's own docblock says its emit contract intentionally matches
  the old version "supaya 8 pemanggil yang sudah ada tidak perlu berubah";
  bolting a second modelValue shape onto the same component risks exactly
  the regression that comment was written to avoid.
- **Third-party multi-select library**: rejected — introduces a new
  dependency for a small amount of behavior the existing dropdown shell
  already solves 80% of, and would likely need its own token/CSS overrides
  anyway to match the design system.

## R3: Filter transport shape for multi-value artist/category

**Decision**: `GET /products` (and the POS product-listing call, which
shares the same endpoint) accepts `artist_id[]=1&artist_id[]=2` (PHP/Laravel
native array query-param convention) instead of the current single
`artist_id=1`. Empty/absent array = "All" (no filtering on that axis),
matching current omitted-param behavior for the single-select version.

**Rationale**: This is Laravel's idiomatic array query-param handling
(`$request->array('artist_id')`), requires no new request format, and keeps
the change backward-compatible: a single scalar `artist_id=1` still parses
into a one-element array in Laravel, so nothing else calling this endpoint
breaks.

**Alternatives considered**:
- **Comma-separated string** (`artist_id=1,2,3`): rejected — requires manual
  splitting/validation and is less idiomatic in this codebase's existing
  `FormRequest` validation style (`'artist_id' => ['array']` + `'artist_id.*' => ['integer', 'exists:artists,id']`
  is a one-line addition using Laravel's native array validation).

## R4: Dashboard aggregate endpoint ownership

**Decision**: New `DashboardController` + `DashboardService`, not new
methods bolted onto `ReportController`. `DashboardService` reuses the same
mode-scoping pattern documented in CLAUDE.md (`order_items.data_mode`
explicit filter on any raw/grouped query) and may call into existing
`ReportController`-adjacent query patterns for consistency, but as a
dashboard-specific summarized shape (top N per axis, small date-range
defaults) rather than the full paginated report shape `ReportController`
already returns.

**Rationale**: `ReportController` already mixes `JsonResource` and
hand-rolled `present()` styles per CLAUDE.md's own documented tech debt;
adding dashboard's distinct summary shape (top-5 categories, single "today"
scope, shortcut permission metadata) to that controller would make it do
two different jobs. A dedicated controller keeps Single Responsibility
(Constitution Principle I) and makes the DEMO/LIVE mode-scoping surface
easy to audit in one place, addressing the constitution's flagged
highest-risk item directly.

**Alternatives considered**:
- **Extend `ReportController`**: rejected for the SRP reason above, though
  the service layer underneath may still share a private query-builder
  helper with `SettlementService`/`ReportController` if genuine duplication
  emerges during implementation.

## R5: Self-service password change route shape

**Decision**: `PUT /auth/password` (sibling to the existing
`PUT /auth/language` self-service route), auth-only, no Policy — mirrors
`UpdateLanguageRequest`'s existing documented rationale ("preferensi bahasa
bukan hak akses menu; setiap akun boleh mengubah miliknya sendiri") applied
to password: every account may change its own password regardless of role,
gated only by correct current-password re-entry inside the request/service,
not by `canAccessMenu`.

**Rationale**: Directly parallels an existing, already-reviewed pattern in
this codebase (`AuthController::updateLanguage` + `UpdateLanguageRequest`),
minimizing new authorization surface to reason about.

**Alternatives considered**:
- **Route through `UserController::update`** (which already handles admin
  password resets for other users, gated by `UserPolicy`): rejected — that
  path is for an admin/owner changing *someone else's* password without
  knowing their current password; self-service requires current-password
  re-verification, a different validation contract, so reusing the same
  endpoint would conflate two distinct authorization stories.

## R6: Self-service photo upload route shape

**Decision**: `POST /auth/photo` (auth-only, operates on
`$request->user()` — never a route-model-bound `{user}`), reusing
`ImageUploadService` and the existing `mimes:jpeg,png` + `ImageUploadService::MAX_KILOBYTES`
validation rule already proven in `UserController::uploadPhoto`.

**Rationale**: The existing `POST /users/{user}/photo` route is gated by
`UserPolicy::update`, i.e. `canAccessMenu('users')` — which a cashier or
inventory-role user does NOT have. If the Profile screen called that route,
a cashier could never change their own photo, contradicting spec User
Story 3 ("Any logged-in user"). A self-scoped route sidesteps that gate
entirely by construction (it can only ever target the caller's own row),
which is safe precisely because it takes no `{user}` parameter from the
client at all.

**Alternatives considered**:
- **Loosen `UserPolicy::update` to allow `$actor->id === $target->id`**:
  rejected — would also let a cashier hit *every other* field
  `UserController::update` manages (role, is_active, etc.) unless that
  controller method is itself split, which is a much larger and riskier
  change than adding one new self-scoped route reusing an existing service.
