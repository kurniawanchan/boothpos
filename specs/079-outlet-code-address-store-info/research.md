# Research: Outlet Identity and Web Terminal Management

**Classification**: INTERNAL

All items below are grounded in the current code unless marked ASSUMPTION. Nothing in the spec is left as NEEDS CLARIFICATION.

## R1 — Where the 3-character code is baked in

Facts: `outlets.code` is `CHAR(3)` (created as `artists.code`, `2026_10_01_000001`, renamed by `2026_12_01_000001`). `StoreOutletRequest` requires `size:3` + `alpha`; `UpdateOutletRequest` rejects `code` (`prohibited`, message `master_data.outlet_code_permanent`). `ProductCodeGenerator` builds `code_prefix = outlet(3)-category(2)-segment(3)` (10 chars) and `sku = prefix-NNN` (14 chars). Columns: `products.code_prefix CHAR(10)`, `product_variants.sku CHAR(14)`, `order_items.sku_snapshot CHAR(14)`, `preorder_items.sku_snapshot CHAR(14)` (widened by `2026_10_25_000001`).

**Decision D1 — widen, do not truncate.** A new migration widens `outlets.code` to `VARCHAR(5)` (so short codes carry no padding; `CHAR` pads on some collations) and widens `code_prefix` to 12, `sku` and both `sku_snapshot` columns to 16, using the raw `ALTER TABLE` pattern of the earlier widening migration (no `doctrine/dbal`). Existing rows are untouched.
**Rationale**: a 5-character code makes the prefix 12 and the SKU 16; silently truncating would collide prefixes. **Alternatives**: keep the fixed width and pad short codes (rejected: changes every existing SKU); store the code separately from the prefix (rejected: larger redesign).
**To verify at task time**: list every column that copies a SKU/prefix (`information_schema` query over the test DB) so none is missed; a source-scan test should fail if a width assumption such as `CHAR(14)` remains.

## R2 — Editing the code and what depends on it

Facts: product codes/SKUs embed the code at creation and are never regenerated (F19.4). Imports/exports refer to outlets by `code` in sheets (`outlet_code`, `home_outlet_code`, sheet `outlets` itself). Outlet code is shown in lists, product resources (`outlets[].code`), order resources, stock reports.

**Decision D2 — code is a plain mutable attribute, validated by one rule class.** `OutletCodeRule` (3–5 chars, `^[A-Za-z0-9]+$`, unique among non-deleted outlets ignoring case, ignoring the outlet being edited) is used by both Store and Update requests; `Outlet::code()` mutator keeps uppercasing (single normalisation point, already there). `UpdateOutletRequest` drops `prohibited`. No cascade: old SKUs keep the old code. Activity log records `code` old → new inside the update transaction.
**Rationale**: smallest change that satisfies FR-004–FR-006. **Alternative**: immutable code plus a separate display alias (rejected: the user asked for the code itself to be editable).
**Import after edit (FR-007)**: no change needed to the matching logic, which already rejects unknown outlet codes; a test pins the message naming the unknown code. Export reads the current code. ASSUMPTION: no import path matches an outlet by anything other than its current code (to be confirmed by a grep-backed test in tasks).
**Uniqueness**: the existing rule ignores soft-deleted outlets (spec Story 3 scenario 9). The DB unique index on `code` would still block reuse of a soft-deleted code; **must be checked** in tasks (the migration may need to drop the unique index in favour of the validation rule, or null/suffix the code on soft delete). Flagged as risk R-A below.

## R3 — Address field

**Decision D3**: add `outlets.address TEXT NULL` (max 500 chars validated), fillable, in `OutletResource`, forms, outlet sheet of `MasterDataSheets` appended at the **end** of the sheet's column list so old files still import (same trick as 045 stock sheet). Blank cell = "leave unchanged" per the import rule.

## R4 — One place that decides the store identity

Facts: receipts read shared settings directly in `OrderController::receipt` (`store_name`, `store_contact`, `store_address`, `store_contact_phone`, `store_contact_email`, `store_contact_person`, `receipt_footer_text`, logo); `SettingsController::features` uses `StoreBranding::storeName()`; DEMO uses `store_name_demo`.

**Decision D4 — `StoreIdentity`** (new, `app/Support`), in the style of `StoreBranding` (which stays for logo and shared fallback name):
- `StoreIdentity::forReceipt(Outlet $outlet): array` → `name, address, phone, email, notes`. Per-field: the outlet's value. If **all four optional details (address, phone, email, notes) are empty**, the whole block (including name) falls back to the shared store values so an untouched single-outlet install prints exactly what it printed before (SC-007). Otherwise empty fields stay `null` and the receipt omits them (FR-011); one outlet never shows another's data.
- `StoreIdentity::forScreen(?Outlet $outlet): array` → same keys; `null` outlet (All outlets) = shared values; empty fields fall back to the shared value per field (FR-013).
- Shared logo, footer, brand name unchanged (FR-012).
**Alternative considered**: per-field fallback on receipts too (rejected for receipts: could print shop A's phone on outlet B's receipt after a half-filled outlet; spec assumption says default to omit).
**Trade-off noted**: in a multi-outlet install an outlet with no details at all shows the shared store name rather than its own outlet name. Accepted: the owner fixes it by filling in the outlet; alternative "outlet name always wins" breaks SC-007 on single-outlet installs where the outlet is called e.g. "Outlet Utama".

**Receipt payload mapping (backward compatible for the tablet and web)** — existing keys keep their names, values change source: `store_name` ← identity name; `store_address` ← identity address; `store_contact_phone` ← identity phone; `store_contact` (legacy single line) ← identity phone; `store_contact_email` ← identity email; **new** `store_notes` ← identity notes. `store_contact_person`, `receipt_footer_text`, `store_logo_url`, `app_name` stay shared. Which outlet: `$order->outlet` (already eager-loaded as `outlet:id,code,name`; the select list must gain the detail columns). FR-010 satisfied because it never consults the request's outlet.

## R5 — Screens follow the selected outlet

Facts: web gets the store name from `GET /settings/features` (`StoreBranding::storeName()`), loaded by the settings store; the picker sends `X-Outlet-Id` (`ResolveOutletContext`). Tablet reads `GET /pos-setup/branding` before login (token-less) and `GET /settings/features` after.

**Decision D5**: `features` adds a `store` object `{name, address, phone, email, notes}` from `StoreIdentity::forScreen(OutletContext single narrowed outlet or null)` and keeps `store_name` equal to `store.name` for old clients. The SPA refetches features when the selected outlet changes (the settings store already owns that data; `useBranding`, `TopBar`, `RailNav` read it). The response is per request, never cached across outlets (Constitution V). A user with exactly one permitted outlet has that outlet as the narrowed one (spec edge case).
**Tablet before login**: add a path-keyed, token-less read `GET /pos-setup/outlets/{outlet}/branding` (name, logo, mode only — the same three values as 077) because the tablet already knows its outlet from the path pattern of 073; the existing no-outlet route stays for the first setup screen. After login the tablet's `features` call already carries the user's outlet.
**Security**: exposure is the outlet name only (already public via `GET /pos-setup/outlets`), so no new data class leaves the token-less surface; recorded as a continuation of the 077 Constitution IV exception.

## R6 — Terminals: what exists and what is missing

Facts: `DeviceController` has `index` (all devices, no filter/pagination, outlet scoping through `OutletScope`), `pair` (outlet from `OutletContext::singleId()` i.e. the header), `rename`, `destroy` (= **revoke**, sets `revoked_at`, idempotent, 204; 9 existing tests and `RUNBOOK` depend on this), `heartbeat`, `serverAddress`. No move, no reactivate, no hard delete, no web page. Orders, shifts (`cashier_sessions`) and cash movements reference `devices.id` with `nullOnDelete`, so a hard delete would silently detach history.

**Decisions D6**
- **Keep `DELETE /devices/{id}` = deactivate.** Add `POST /devices/{id}/reactivate`, `DELETE /devices/{id}/permanent` (hard delete, **409** when any order, shift or cash movement references it; message points to deactivation), and extend `PATCH /devices/{id}` to accept `outlet_id` (move) besides `name`.
  **Rationale**: preserves the documented contract and tests; the destructive operation gets its own explicit route. **Alternative**: flip `DELETE` to hard delete (rejected: silent behaviour change for any caller, e.g. `ServerAddressTest`).
- **List**: `GET /devices` gains `outlet_id[]` (narrowed via `OutletContext::narrowedOrNull`, requested **without** `X-Outlet-Id` — `skipOutletHeader`, same pattern as 070/071), `status=active|revoked|all` (default `all`, so deactivated terminals can be found and reactivated, and an un-filtered call keeps returning everything as today), `q` (name contains, case-insensitive), `page`/`per_page`, envelope `{data, meta}`; each row adds `outlet {id, code, name}`, `paired_at`, `revoked_at`. Eager-load outlet. Compatibility: the unpaginated shape is kept when no `page`/`per_page` is sent, so existing callers and tests do not change.
- **Create**: unchanged contract — outlet from `X-Outlet-Id` (the Terminals page sets the header from its picker; header outside the user's outlets or inactive → 403 `outlet_forbidden` from the middleware). Decided during implementation: an earlier draft took `outlet_id` in the body, which would have reversed the 048 rule pinned by `DevicePairingTest::test_outlet_cannot_be_chosen_from_the_body`. Only **move** takes `outlet_id` in the body (a move target cannot be a header), validated ∈ `permittedIds()`, same precedent as 071.
- **Move**: `OutletMoveGuard::deviceBlocker()` → `open_shift` when the terminal has an open shift (`cashier_sessions` with `device_id`, status open, no global scopes, same style as `tableBlocker`). Name uniqueness re-checked in the **target** outlet under the existing `lockOutlet`. Target must be active and permitted. Old orders/shifts keep their `outlet_id`. Activity log `device_moved` with old/new outlet ids.
  **Consequence to document**: the tablet is bound to its old outlet in its own storage; after a move its next request is refused with the existing `device_id` 422 and it must be set up again for the new outlet. This is the same recovery path as a revoked terminal. ASSUMPTION: acceptable to the owner; if not, a future "re-home from server" flow would be a separate feature.
- **Reactivate**: allowed when the name is free among active terminals of its outlet (409/422 `name_taken` otherwise) and its outlet is active.
- **Roles**: owner/admin only, via the existing `isOwnerOrAdmin()` checks in `FormRequest::authorize()` / controller, as today. **Menu key (decided during implementation)**: the Terminals route and nav entry use the `settings` menu key, not `outlets`. Menu keys are role-defined, and a role may hold `outlets` without being owner/admin; `settings` is what owner/admin hold and the same gate 074 used for PIN setting. The server stays the real gate (`isOwnerOrAdmin()`); the nav entry is hidden entirely for other roles (tested).
- **Audit**: new log actions `device_moved`, `device_reactivated`, `device_deleted` in `DeviceService`, inside the same transaction; existing `device_paired`, `device_renamed`, `device_revoked` unchanged. Names only, no secrets.

## R7 — Web terminal page pattern

Reuse `usePaginatedList`, the shared filter composable used by `DiningSetupView` (`useOutletPageFilter`), the central error handler and the table components of `OutletsView`. New `TerminalsView.vue` + API module; route under the same group as `outlets`. Filters live in the URL query like the other list pages. No design-system dependency (Constitution III).

## R8 — Android

Facts: receipt rendering uses `ReceiptDtos.kt` → `ReceiptMapper.kt` → `Documents.kt`/`PrinterService.kt`; `BrandingMapper`/`PosStore` hold the shown store name. The receipt payload keeps its keys, so address/phone/email follow the outlet with no client change; only the **new** `store_notes` needs a DTO field, mapper line and a wrapped line on the 58 mm layout (omit when empty). Branding: call the path-keyed read for the stored outlet. The 58 mm printer layout cannot be verified here (no device/adb per earlier sessions); the plan records it as an open verification, not a claim.

## R9 — Base branch

078 changes (order note on receipt) are not in develop. This feature touches the same receipt code. **Decision**: implement on top of a base that contains 077 and 078 (merge/rebase before starting `/speckit-implement`); not a code decision, but a sequencing one. ASSUMPTION: the branch point of `079-…` already contains them (git status shows it branched after 077 commits); verify with `git merge-base` at task time.

## R10 — Terminal bulk actions (T062)

**Decision**: one endpoint, `POST /devices/bulk` with `{action: activate|deactivate|delete, ids[]}` (static route beside `/devices/pairings`, no clash with `/devices/{device}` which only has PATCH/DELETE). `ids` max 100 = largest page size, distinct. Response is always 200 with per-terminal `{id, name, status: changed|skipped|refused|not_found, reason}` plus a `summary` count. `DeviceService::bulk()` loops the existing single-terminal paths (`reactivate`, `revoke`, `deleteIfUnused`), each in its own transaction and activity-log entry, catching only `ValidationException` per item — so there is no second copy of the in-use or name-taken rules. Scope comes from `Device::query()` (OutletScope), so ids outside the user's outlets are `not_found`, indistinguishable from missing. **Rejected**: three per-action routes (more surface, same behaviour); all-or-nothing in one transaction (one used terminal would block unused ones, spec FR-028).

## Risks and unknowns

- **R-A** DB unique index on `outlets.code` vs the "deleted outlet's code can be reused" rule — verify and fix in the migration (D2).
- **R-B** A forgotten SKU/prefix width somewhere (other snapshot columns, spreadsheet column validators, label/barcode formatting) — covered by the information_schema listing and a source-scan test (D1).
- **R-C** Features payload outlet-awareness may hit any cache/`ETag` in front of `GET /settings/features` — check when implementing; none found in the controller read above.
- **R-D** Terminal move leaves the tablet needing re-setup (R6) — document in RUNBOOK and in the move confirmation dialog copy.
