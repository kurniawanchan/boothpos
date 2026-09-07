# Research: License & Invoice Management (expanded scope)

**Supersedes**: the original pass's R1-R4 below are kept for history but are
now superseded by R1'-R9 in this update where noted.

## R0 — Naming collision check: "License" (this feature) vs `018-license-activation`

**Decision**: Keep the entity name **License** as the user explicitly requested
("ubah manajemen package menjadi license"), but avoid any class/route name
that collides with feature 018's existing `App\Http\Controllers\Api\
LicenseController` / `App\Models\LicenseActivation` / `App\Support\
LicenseSigning` (the *installation activation gate* — a completely different
concept: one vendor-signed key per installation, unrelated to pricing/plans).

- New model: `App\Models\License` — **would** collide with feature 018 if it
  had a model there too, but 018 only has `LicenseActivation`, so `License` is
  free.
- New controller: **`LicenseCatalogController`**, not `LicenseController` —
  the latter name is taken by 018's activation endpoints (`/license/status`,
  `/license/activate`). Routes: `/license-catalog/*` would read oddly to
  end users, so the user-facing route path stays `/licenses`, only the PHP
  class name is disambiguated.
- New policy: `LicenseCatalogPolicy` (mirrors the controller naming).
- Menu key: `licenses` (new, `MenuKeys::ALL`) — no collision, 018 has no menu
  key (it gates before login, not via the menu-permission system at all).

**Rationale**: A silent class-name collision would be a real, hard-to-spot
bug (Laravel route-model-binding/DI would resolve whichever controller wins
autoloading ambiguity — in practice PHP would just fatal on duplicate class
names, since both would be namespaced `App\Http\Controllers\Api\
LicenseController`). Caught during planning, before any code was written.

## R1' — `Package` → `License`: rename in place, not a new parallel entity

**Decision**: Rename the existing `packages` table to `licenses` (`Schema::
rename`), rename `App\Models\Package` → `App\Models\License`, rename
`Company.package_id` → `Company.license_id` (`renameColumn`), rename the
`package()` relation → `license()`. Add two new columns: `price` (decimal
14,2) and `payment_type` (enum `one_time`/`subscription`).

**Rationale**: This is a genuine rename/expansion of the same entity per the
user's explicit request ("ubah manajemen package menjadi license"), not a
new, second entity living alongside the old one — keeping both would leave
two competing sources of truth for what a company is licensed under.
`license_tier` (pro/master, already existing, already deliberately NOT
auto-applied to `LicenseGate`/`multi_artist_enabled` per 017 R4) is
unchanged and kept — it remains purely descriptive, same reasoning as before.

**Alternatives considered**: Leaving `Package` as-is and adding a new
`licenses` table that references it — rejected, this duplicates data
(name/description would exist in both places) for no benefit; the user
asked for a rename, not a parallel catalog.

**Migration mechanics**: `Schema::rename('packages', 'licenses')`, then
`Schema::table('licenses', ...)` to add `price`/`payment_type`, then a
separate migration renaming `companies.package_id` → `license_id` via
`Schema::table('companies', fn ($t) => $t->renameColumn('package_id',
'license_id'))`. Foreign key constraint name changes are handled
automatically by MySQL on `renameColumn` for an unnamed/auto-named FK; if the
existing FK has an explicit name from 017's migration, drop and re-add it
explicitly in the same migration to avoid an orphaned constraint pointing at
a now-nonexistent column name.

## R2' — Menu separation: `licenses` and `invoices` as two new, independent top-level menu keys

**Decision**: Add `'licenses' => 'Lisensi'` and `'invoices' => 'Invoice'` to
`MenuKeys::ALL`, each with its own default-roles migration granting
Owner/Admin only (mirrors `2026_10_16_000005_add_companies_menu_key_to_
default_roles.php` exactly, one migration per key since that's the
established one-key-per-migration convention in this codebase). Both
`LicenseCatalogPolicy` and `InvoicePolicy` gate on their own new menu key,
replacing the old `InvoicePolicy`'s reuse of `companies` (FR-002, FR-006).

**Rationale**: Directly requested ("jadikan menu utama sendiri" for License,
"buat menu invoice terpisah" for Invoice) — Company Onboarding's own
`companies` menu key stays scoped to company records only.

**Frontend**: `AppSidebar.vue`'s `NAV_DEFS` gets two new top-level entries
(not nested under `companies` or `settings-group`), each with its own route
(`/licenses`, `/invoices`).

## R3' — Invoice numbering: mirror `PreorderService::generateNumber()`, cross-mode uniqueness

**Decision**: `InvoiceService::generateNumber()` (public, same reasoning as
`PreorderService`'s own public visibility — an eventual
`InvoiceExportImportService::import()` needs the identical numbering path
for imported rows, not a second one), using `Invoice::withoutGlobalScope(
DataModeScope::class)` when checking uniqueness, since `invoice_number`
carries a database-wide UNIQUE constraint (`invoices.invoice_number`) but
`Invoice` itself stays `HasDataMode`-scoped (research decision R3 from the
original pass, unchanged — Invoice is transactional business data, License
stays administrative/reference data like `Package` was).

**Format**: `INV-{YYYYMM}-{sequence}` (e.g. `INV-202609-0001`), mirroring
`preorder_number`'s human-readable, sortable convention (see
`PreorderService`) rather than `order_number`'s different format — chosen
because invoices, like preorders, are looked up by humans (support staff
reading them aloud, referencing them in email), not scanned.

## R4' — Invoice line-item model: single-license snapshot, not multi-line

**Decision** (documented as an Assumption in spec.md, restated here for the
technical rationale): one invoice bills exactly one `license_id` at a fixed
`subtotal`/`discount`/`grand_total` snapshot recorded at creation time —
never recomputed if the linked License's `price` later changes (FR-013,
carrying over original FR-007's reasoning). `grand_total = subtotal -
discount`, both plain decimal amounts (not a percentage-based discount),
matching this codebase's existing `discount_amount` convention on POS
`orders`.

**Rationale**: Matches the exact level of detail requested — the expansion
asked for "info paket, info pembayaran... diskon, total, subtotal,
grandtotal", not a general-purpose multi-line invoicing system. A future
multi-line-item invoice is separate, larger work (not requested).

## R5' — PDF/image download: reuse `ReceiptModal.vue`'s exact client-side pattern

**Decision**: `InvoiceDetailModal.vue` (or equivalent detail view) implements
its own `downloadImage()`/`downloadPdf()` using the identical dynamic-import
pattern already proven in `resources/js/components/receipt/ReceiptModal.vue`:

```js
const { default: html2canvas } = await import('html2canvas');
const canvas = await html2canvas(invoiceEl.value, { backgroundColor: '#ffffff', scale: 2 });
// image: canvas.toDataURL('image/png') → trigger a download
// pdf: const { jsPDF } = await import('jspdf'); wrap canvas.toDataURL('image/png') into one page
```

Filename pattern: `` `invoice-${invoice.invoice_number}.png` `` /
`` `invoice-${invoice.invoice_number}.pdf` ``, mirroring `` `struk-
${order_number}.png` ``. Two loading-state refs (`downloadingImage`,
`downloadingPdf`), same as `ReceiptModal.vue`.

**Rationale**: FR-009 explicitly requires PDF and image download. This
codebase has already made and proven the "no server-side PDF generation"
decision (feature 007 research.md) — introducing a server-side PDF library
here would contradict an established, working precedent for no new benefit.

## R6' — Export/import: a separate, single-sheet workbook, mirroring `PreorderExportImportService`

**Decision**: New `App\Services\InvoiceExportImportService` + `App\Imports\
InvoiceImport`, structured identically to `PreorderExportImportService`/
`PreorderImport`:

- **Headings**: `invoice_number` (blank on create, matched on update),
  `company_name`, `license_name`, `subtotal`, `discount`, `grand_total`,
  `due_date`, `status`, `payment_information`, `notes`.
- **Upsert key**: `invoice_number` when present and found → update (only
  when not `paid`, FR-011's guard applies here too); blank `invoice_number`
  → create a new invoice (server generates the number via `InvoiceService::
  generateNumber()`, exactly as `PreorderExportImportService::import()`
  reuses `PreorderService::generateNumber()` for created rows).
- **All-or-nothing** validation pass, `dry_run=1` support — identical
  convention to `MasterDataImportService`/`PreorderExportImportService`.
- **Company/License resolution**: by name lookup; a row referencing a
  nonexistent Company or License is a per-row error, the whole import is
  rejected (spec.md Edge Cases).
- **Routes**: static routes registered BEFORE the `{invoice}` wildcard
  routes, mirroring `/preorders/export`, `/preorders/import/template`,
  `/preorders/import` ordering (`routes/api.php` comment explains why:
  `'export'`/`'import'` must not be captured as a route-model-bound
  `{invoice}` id).

**Rationale**: Directly matches spec.md's Assumption — "a separate,
single-purpose workbook (like feature 007's preorder import/export), not
folded into the existing master-data workbook" — since invoices are
transactional records, not master data (`MasterDataSheets::ORDER` stays
untouched).

**Data-protection note** (mirrors `GenericArrayExport.php`'s existing
comment convention): the export never includes the billed company's
contact fields (phone/email) beyond its name — invoices already carry a
`payment_information` field that could contain sensitive banking details on
the *issuer* side (the store's own), which is fine to export since it's the
store's own configured data, not customer PII.

## R7' — Settings → Payment: a new sibling route under `settings-group`, not a new section on the single-page `SettingsView.vue`

**Decision**: `SettingsView.vue` is confirmed (via inspection) to be a
single scrolling page with named sections — it is NOT already sub-routed.
Since the user explicitly asked for a "submenu" (a distinct, separately
reachable nav entry, matching how `settings`/`users`/`roles` already sit as
sibling `children` under the existing `settings-group` `AppSidebar.vue`
entry), this adds a **new sibling route** rather than another section on the
existing page:

```js
{
  key: 'settings-group',
  children: [
    { name: 'settings', label: 'nav.settings', menuKey: 'settings' },
    { name: 'settings-payment', label: 'nav.settings_payment', menuKey: 'settings' },
    { name: 'users', label: 'nav.users', menuKey: 'users' },
    { name: 'roles', label: 'nav.roles', menuKey: 'roles' },
  ],
},
```

New route `/settings/payment` → new `SettingsPaymentView.vue`. Gated on the
**existing** `settings` menu key (not a new one) — consistent with how
Company/Package/BusinessType already share the single `companies` menu key
(017 research.md R1's reasoning: these are all facets of the same
administrative surface, not independently permissionable screens). Payment
settings are configuration, same tier as the rest of `SettingsView.vue`.

**Rationale**: A genuinely separate nav entry needs a genuinely separate
route in this SPA's router (Vue Router, one component per route) — bolting
it onto `SettingsView.vue`'s existing single-page-with-sections design would
not satisfy "submenu" (a distinct, clickable item in the sidebar), and would
also entangle an unrelated new entity's CRUD form into a page whose existing
sections are all simple key-value settings toggles, not a full form.

## R8' — `InvoicePaymentSetting`: one administrative row, not `HasDataMode`-scoped, not a `settings` key-value row

**Decision**: New table `invoice_payment_settings` — **not** reusing the
existing generic `settings` key-value table (`Setting::get()`/`Setting::
set()`) despite superficially looking like a fit, and **not**
`HasDataMode`-scoped.

**Rationale for a real table, not more `settings` rows**: the existing
`settings` table stores single scalar values (`system_mode`,
`multi_artist_enabled`, `store_name`, etc.) — payment information here is a
small *structured* record (bank name, account number, account holder,
instructions), which is a worse fit for several independent scalar
`settings` rows (no natural grouping/validation, and every field addition
would mean another ad-hoc key). A dedicated single-row table (`id` always
`1`, enforced by only ever `updateOrCreate(['id' => 1], ...)`) keeps the
shape explicit and validated via a `FormRequest`, matching how
`payment_channels` (also administrative, also not `HasDataMode`-scoped) is
modeled as its own table rather than folded into `settings`.

**Not `HasDataMode`-scoped**: administrative/reference data (the store's own
payment configuration, not a business/transactional record), same category
as `payment_channels`/`packages`/`companies`/`licenses` — visible identically
in DEMO and LIVE mode.

## R9' — Seeded Pro/Master Licenses: single rows, default price + payment_type, both modes

**Decision**: A new seeder step (either extending the existing
`DatabaseSeeder` or a small dedicated seeder run once via migration-time
`DB::table('licenses')->insertOrIgnore(...)`, mirroring how `packages`
itself likely already has seed rows from 017 — confirmed by checking
`DatabaseSeeder.php`/existing seeders before implementation) creates exactly
two `licenses` rows: "Pro" (`license_tier=pro`, a default price, `payment_type`
defaulting to `subscription` as a reasonable default for a recurring-feel
tier) and "Master" (`license_tier=master`, a higher default price,
`payment_type` `one_time` as a reasonable default for a top-tier one-time
purchase) — both with a short feature-description string. Not duplicated
per DEMO/LIVE (License remains reference data, R1' above) — same single rows
are visible in both modes, consistent with 017's original design for
`Package`.

**Rationale**: FR-003 requires this to exist on a fresh install. Exact
default prices are placeholder/example values (documented as such in the
seeder's own comment) since no real pricing was specified in the request —
an owner/admin can edit them via the License CRUD screen afterward.

---

# Second expansion (2026-09-06): Company edit/delete, Invoice detail enrichment

## R10 — Company delete-guard: Invoice references only, not License

**Decision**: `CompanyOnboardingService::delete(Company $company)` throws a
`ValidationException` (mapped to 409) only if `$company->invoices()
->exists()`. It does NOT check `license_id` at all.

**Rationale**: Every Company always has exactly one linked License by design
(FR-004, `license_id` is `required` on create and stays required on edit) —
guarding delete on "does this Company reference a License" would make
literally every Company permanently undeletable, which is not a guard, it's
a no-op dressed up as one. The real "financial record must survive" concern
here is the same one already established for License (FR-005, blocked by
Company/Invoice reference) and paid Invoices (FR-011) — a Company with
billing history behind it must remain traceable. A Company with zero
Invoices has no such history and is safe to remove.

## R11 — `UpdateCompanyRequest`: a new class, not a reuse of `StoreCompanyRequest`

**Decision**: New `app/Http/Requests/UpdateCompanyRequest.php` — same
`business_type_id`/`license_id`/`name`/`address`/`contact_name`/
`contact_email`/`contact_phone` rules as `StoreCompanyRequest`, but WITHOUT
`owner_username`/`owner_password` (creation-only fields — the owner `User`
row and its activation flow are feature 017 concerns, entirely orthogonal
to editing a Company's own business/contact details, per spec.md's new
Assumption "editing a Company does not require re-activation").

**Rationale**: Reusing `StoreCompanyRequest` for update would either force
every edit to re-supply a username/password (bad UX, and confusing —
editing a company's address should not touch its owner's login) or require
making those fields conditionally-required based on an update-vs-create
flag threaded through the request class, which is exactly the kind of
implicit-conditional-validation this codebase avoids elsewhere (compare
`StoreInvoiceRequest`/`UpdateInvoiceRequest` from the first expansion —
already two separate classes for the same reason).

## R12 — Invoice detail's "business type" and "Settings→Payment structured fields": read-time enrichment, not a snapshot change

**Decision**:
- **Business type**: `InvoiceController` eager-loads `company.businessType`
  (a NEW nested relation load — `businessType` is NOT itself snapshotted on
  Invoice, it's read live through `company()->businessType()` at request
  time). This is intentionally NOT a snapshot like `license`/`subtotal` are
  — a Company's business type describes the Company itself, not a fact
  about the billing event, so there is no "the business type changed after
  the invoice was created" correctness concern the way there is for License
  pricing (FR-013).
- **Payment information**: NO backend change at all. `invoice.payment_information`
  is ALREADY a fixed snapshot (first expansion's FR-015/T068) that already
  defaults from `InvoicePaymentSetting` at creation time. This expansion's
  "add Settings→Payment data to the invoice detail" requirement is
  satisfied entirely by `InvoiceDetailModal.vue` presenting that EXISTING
  field more clearly (e.g. as labeled sub-fields if the stored value has a
  recognizable structure, or at minimum ensuring it's never hidden/truncated)
  — not by re-fetching `GET /settings/payment` live into the detail view,
  which would reintroduce exactly the "changes after creation leak into an
  already-issued invoice" bug FR-013's snapshot rule exists to prevent.

**Rationale**: Keeps the "financial documents are immutable snapshots"
invariant (FR-013) intact for money-and-billing-identity fields, while
correctly treating "who is this company" (business type) as current-state
metadata, not a billing fact frozen in time — the same category distinction
this codebase already draws between `Company.name`/`Order.customer_name`
(a snapshot) elsewhere.

## R13 — Invoice detail Edit/Delete: UI-only gap, zero backend change

**Decision**: No backend changes. `PUT /invoices/{invoice}` and
`DELETE /invoices/{invoice}` have existed since the first expansion
(T047/T052/T053), correctly guarded (409 on `status === 'paid'`). The gap is
entirely in `InvoiceDetailModal.vue`, which currently offers only Mark
Paid/Cancel/Download — this plan adds an Edit button (opens the
already-capable `InvoiceFormModal.vue` in its existing edit mode) and a
Delete button (calls the already-existing `deleteInvoice()` API function,
with a confirm dialog mirroring this codebase's existing delete-confirm
convention, e.g. `LicensesView.vue`'s).

**Rationale**: Confirmed by reading the actual current file content before
writing this plan — `InvoiceFormModal.vue` already has a working
`isEdit`/`updateInvoice()` path (built in the first expansion's T056, ready
but never triggered from anywhere), and `resources/js/api/invoices.js`
already exports `updateInvoice`/`deleteInvoice` (T054). Building a second,
parallel edit/delete mechanism would be pure duplication.

---

# Third expansion (2026-09-07): Company activation gate replaced

## R14 — Activation is gated on a paid Invoice, not an emailed code

**Problem found** (not hypothesized — confirmed by reading the actual route
group in `routes/api.php`): `POST /companies/{company}/activate` sits
inside the same `auth:sanctum` + `canAccessMenu('companies')`-gated block
as every other Company Onboarding endpoint. That menu key is owner/admin
staff of the PROVIDER — the company being onboarded is the CLIENT. Feature
017's original design emailed a 6-digit code to the client's own
`contact_email` and required THAT code to be typed into this admin-only
endpoint. The provider's staff has no legitimate route to ever see that
code (they don't have the client's inbox) — the only way the modal ever
worked was the client reading the code aloud over phone/chat, which is not
what "activation code sent to the client" was designed for.

**Decision**: Replace the code check entirely with a condition the
provider's own staff genuinely CAN verify in the same system they're
already using: `$company->invoices()->where('status', 'paid')->exists()`.
`CompanyOnboardingService::activate(Company $company)` no longer takes a
`$code` parameter at all. `onboard()` no longer generates/hashes a code or
sends `CompanyActivationMail`. `resendActivationCode()` is removed
entirely (nothing to resend). The `activation_code_hash`/
`activation_code_expires_at` columns on `companies` are left in place
(nullable, simply unwritten going forward) rather than dropped via a new
migration — removing them touches an already-shipped, already-migrated
table for zero functional benefit and adds unnecessary risk; the columns
are inert, not "half-finished."

**`can_activate` computed field**: `CompanyResource` exposes `can_activate:
status !== 'active' && paid_invoices_count > 0`, computed from a
`withCount()`/`loadCount()` aggregate set in `CompanyController` (not a
per-row query in the Resource, to avoid N+1) — the frontend Activate button
is only ever shown when clicking it will actually succeed, rather than
always showing on every non-active row and letting the 409 surface as a
confusing error toast.

**Deactivate, added at the same time**: `POST /companies/{company}/deactivate`
— the reverse of activate: locks the owner user (`is_active = false`),
reverts `status` to `pending_activation`, clears `activated_at`. Does
NOT touch Invoice history at all (a lapsed/misused Company can be locked
without losing its billing record) — re-activating later still requires
a paid Invoice to already exist, which it does (Invoice's own delete guard,
FR-011, already prevents that record from disappearing).

**Removed as dead code**: `app/Mail/CompanyActivationMail.php`,
`resources/views/emails/company-activation.blade.php`,
`CompanyOnboardingService::generateCode()`/`sendActivationCode()`/
`recordNotification()`. `App\Models\CompanyActivationNotification` and its
migration/table are left in place (nothing writes to it anymore, but
dropping it is a separate, higher-risk change not requested here).
