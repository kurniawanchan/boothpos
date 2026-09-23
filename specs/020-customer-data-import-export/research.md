# Phase 0 Research: Customer Data Import & Export

No `NEEDS CLARIFICATION` markers remain in the Technical Context — both
spec-level ambiguities were already resolved by the user before planning
(standalone flow; email-based upsert). This document instead records the
concrete technical decisions made while translating those resolutions into
this codebase's existing conventions, each grounded in a precedent already
in the repo rather than invented fresh.

## Decision: Reuse `PreorderExportImportService`'s shape, not `MasterDataImportService`'s

**Decision**: New `App\Services\CustomerExportImportService` with
`export()`, `template()`, `import()` — structurally a near-copy of
`app/Services/PreorderExportImportService.php`.

**Rationale**: Two existing bulk-import precedents exist in this codebase.
`MasterDataImportService` handles four (now eight) *interdependent* sheets
in dependency order inside one workbook — the complexity there exists
specifically to solve cross-sheet FK ordering (products need artists/
categories to already exist). Customer has no such dependency on any other
sheet, so copying that machinery would be pure unused complexity (violates
Constitution I — "no speculative interfaces, no unused extension points").
`PreorderExportImportService` is the closer precedent: one workbook, one
sheet, one entity, full-validate-then-one-transaction, `dry_run` for
preview — exactly this feature's shape.

**Alternatives considered**: Add `customers` as a ninth sheet to
`MasterDataSheets::ORDER` — rejected per the spec's own resolved
Question 1 (user chose standalone), and also technically awkward: that
constant/pattern is shared by export, template, and import specifically so
"export files round-trip back through import" for the *product-catalog*
sheets; folding in an unrelated entity would force every future change to
that shared list to reason about an eighth+ninth concern that has nothing
to do with the product catalog.

## Decision: Email-matching upsert, implemented as a single pre-fetch, not per-row queries

**Decision**: Before applying any row, collect every non-blank email in the
file (case-normalized, e.g. `strtolower(trim($email))`), and issue one
`Customer::whereIn('email', $emails)->get()->keyBy(fn ($c) => strtolower($c->email))`
query scoped to the currently active data mode (the default Eloquent
global scope already does this via `HasDataMode`/`DataModeScope` — no
`withoutGlobalScope` needed here, unlike the `code`-uniqueness bugs found
elsewhere in this codebase, because `customers.email` has **no
database-wide unique constraint** to worry about colliding with the other
mode).

**Rationale**: Constitution V explicitly requires avoiding N+1 query
patterns; a naive "for each row, `Customer::where('email', ...)->first()`"
would be exactly that for a large file. A single batched lookup keeps this
O(1) queries regardless of row count (plus the writes themselves).

**Alternatives considered**: Per-row `firstOrNew()` (the pattern
`MasterDataImportService::applyCategories()` uses for `code`) — rejected
here specifically because of row count: master-data sheets are bounded by
a handful of artists/categories, but a customer list realistically has
hundreds to low-thousands of rows (SC-001 sizes it at up to 1,000), where
per-row queries meaningfully matter.

## Decision: Case-insensitive, exact-match email comparison; two rows with the same email in one file update each other in file order

**Decision**: Normalize with `strtolower(trim(...))` before comparing or
matching. If two rows in the same file share a (normalized) email, they
are treated as sequential updates to the same customer — the second row's
non-blank fields win over the first's, matching how a human re-editing a
spreadsheet would expect "the last edit wins."

**Rationale**: This is the simplest rule consistent with the user's
resolved Question 2 answer, and requires no new "duplicate within one
sheet" error path (unlike `MasterDataImportService`'s `code` columns,
where a duplicate *within the file* is always an error — there, `code` is
a chosen identifier the user is expected to keep unique per row; email
naturally is *supposed* to repeat across a person's multiple visits/edits
that got exported and re-imported, so treating a repeat as "the same
person, apply both edits in order" is the behavior that actually matches
the real-world editing workflow this feature exists for).

**Alternatives considered**: Reject a file with a repeated email as a
row-level error (mirroring the `code`-duplicate-in-sheet behavior) —
rejected because it would make Story 3's core scenario (re-import a
previously-exported, lightly-edited file) fail in the ordinary case where
a customer's row simply reappears with one field changed.

## Decision: Gate export/import/template to the same menu-key tier as bulk master-data (owner/admin/inventory), not `isOwnerOrAdmin()`

**Decision**: Reuse `$request->user()->canAccessAnyMenu(['artists',
'categories', 'products', 'stock', 'vendors', 'materials', 'roles',
'users'])` — the exact same check (and exact same array) already gating
`MasterDataExportController`/`ImportMasterDataRequest` — rather than
`PreorderController`'s narrower `isOwnerOrAdmin()`. **Correction found
during implementation**: CLAUDE.md and several existing files' comments
refer to this tier informally as `User::canManageMasterData()`, but no
such method actually exists anywhere in this codebase — the real,
existing mechanism restricting bulk master-data operations to owner/
admin/inventory is this `canAccessAnyMenu()` menu-key check (owner/admin
hold every menu key via `MenuKeys::keys()`; inventory holds this specific
list; cashier does not — see the role-seeding migration
`2026_10_09_000002_add_role_id_and_photo_to_users_table.php`). `Customer`
CRUD's own `'customers'` menu key is deliberately excluded from this list
even though inventory/cashier both hold it too — including it would let
cashier through, which is not the intended tier for bulk PII export.

**Rationale**: The spec's own FR-009, written and accepted before
planning, explicitly calls for "the same roles already trusted with other
bulk master-data operations" (owner/admin/inventory). `Customer` also
carries `App\Models\Customer`'s own docblock warning that phone/email/
social_handle are personal data that must never leak into artist-facing
exports — a stricter internal-only gate (excluding cashier, who can
already view/edit individual customers one at a time for pre-order intake)
is the safer default for a *bulk* PII-extraction endpoint, consistent with
how this codebase already treats master-data bulk export/import as
"deliberately stricter than the per-entity read endpoints."

**Alternatives considered**: `isOwnerOrAdmin()` (Preorder's convention,
narrower still, excluding inventory) — considered because Preorder's
export also carries customer contact fields, but rejected in favor of
matching the spec's explicit FR-009 wording rather than silently
tightening scope beyond what was already resolved with the user.

## Decision: Reuse `ImportMasterDataRequest`'s 10 MB cap and `mimes:xlsx`, not Preorder's uncapped `mimes:xlsx`-only rule

**Decision**: `Rule::file()->max(10240)->rules(['mimes:xlsx'])` (10 MB),
matching `ImportMasterDataRequest::MAX_KILOBYTES`.

**Rationale**: Two existing precedents disagree slightly — Preorder import
validates only `mimes:xlsx` with no explicit size cap; master-data import
defines an explicit 10 MB cap. The spec's own Assumptions section commits
to "File size and format limits mirror the existing master-data import,"
so this feature follows that one explicitly, rather than the ungoverned
Preorder precedent.

**Alternatives considered**: No explicit cap (Preorder's current
behavior) — rejected, both because the spec already resolved this and
because leaving it uncapped is the kind of accidental-not-deliberate gap
Constitution V warns against for anything performance-adjacent.

## Decision: Activity log entry inside the same transaction as the import

**Decision**: `ActivityLogger::log(...)` call sits inside the same
`DB::transaction()` closure that creates/updates customers, recording
counts (created/updated) and the importing user — mirroring how
`MasterDataImportService::apply()` already logs "the import writes an
activity log entry" inside its own transaction (there's an existing test,
`the import writes an activity log entry`, guarding that exact pattern).

**Rationale**: Constitution IV requires sensitive mutations (bulk imports
explicitly named) to log inside the same transaction as the mutation, so
a rolled-back import never leaves a log claiming it happened. This is a
direct, non-optional application of an existing, already-tested pattern —
not a new design decision.
