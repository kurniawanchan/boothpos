# Evidence: 079

**Classification**: INTERNAL

## T001 — base branch
- `079-outlet-code-address-store-info` was created from `develop-warkop-wjm` (tip `5fe8044f`, identical commit). Contains 077 (`0e2737c1`). Does **not** contain 078 (`2acf16c0`, draft PR #62): decision of the developer on 2026-10-27, "create new branch checkout from develop-warkop-wjm".
- Consequence: the order-note lines of 078 on receipts are absent from this base; expect merge conflicts in `OrderController::receipt` and the Android receipt printing when 078 lands. Not blocking.

## T003 — columns that embed outlet code/SKU (test DB `warkop_wjm_test`)
| Column | Type |
|---|---|
| `outlets.code` | char(3), unique index `artists_code_unique` |
| `products.code_prefix` | char(10) |
| `product_variants.sku` | char(14) |
| `order_items.sku_snapshot` | char(14) |
| `preorder_items.sku_snapshot` | char(14) |
No other `%sku%` / `code_prefix` column exists.

## T004 — unique index vs soft delete (R-A)
`artists_code_unique` is a plain UNIQUE on `code`, so a soft-deleted outlet still holds its code at the DB level while validation ignores deleted rows: reusing a deleted code would pass validation and fail with a DB error. Fix in the migration: replace it with a virtual generated column `code_live = IF(deleted_at IS NULL, code, NULL)` carrying the unique index (NULLs do not collide; collation is case-insensitive).

## T019 — other consumers of store identity
`grep` of `store_address|store_contact|store_notes` in `app/` and `resources/js/`: only the receipt (`OrderController::receipt`) and its web renderer (`ReceiptModal.vue`) read the store block, plus the Settings form (`StoreIdentitySection.vue`, which now says the shared identity is the "All outlets" view and fallback). No other customer-facing document builds a store block (pre-order documents were removed in 046). Android prints through the same receipt payload.

## T041 deviation (simpler than planned)
The SPA does not need a watcher to refetch on picker change: `AppShell.onOutletChange` already calls `window.location.reload()`, so `settings.load()` runs again with the new `X-Outlet-Id`. `settings.storeName` now reads `store.name` and a new `settings.storeInfo` holds address/phone/email/notes.

## Decisions taken during implementation (also in research.md / plan.md)
1. **Create terminal keeps the header contract.** A first draft took `outlet_id` in the body of `POST /devices/pairings`; `DevicePairingTest::test_outlet_cannot_be_chosen_from_the_body` pins the opposite (048). Reverted: the Terminals page sets `X-Outlet-Id`. Only **move** takes `outlet_id` in the body, validated against `OutletContext::permittedIds()` (precedent 071).
2. **Terminals menu key is `settings`**, not `outlets` (owner/admin gate; a role can hold `outlets` without being admin).
3. **Reactivating a terminal of an inactive outlet is a 404**, not a 409: `OutletScope` hides terminals of inactive outlets, so a separate branch was unreachable and was removed.
4. **Spec scenario US2-3 reworded**: with outlet B selected, an outlet A sale is not reachable at all (404, outlet scoping), so no receipt can carry B's details for A's sale; asserted in `ReceiptStoreIdentityTest`.
5. **Not touched on purpose**: `WjmCatalog` outlet-code regex widened to 3–5 (catalog tooling only). Seeders and `OutletFactory` already produce valid 3-letter codes.
6. **Existing tests changed to the new rules** (intent kept): `OutletTest` (code 3–5 alnum, editable), `OrderTest::test_receipt_includes_full_store_profile_when_configured` (outlet details emptied so the shared block applies — the outlet-own behaviour is in `ReceiptStoreIdentityTest`).
7. **Activity log for outlet updates did not exist** (`OutletController::update` wrote none); it now logs `updated` with old/new values inside the transaction, for any change, not only the code.

## Verification run in this session
| Check | Result |
|---|---|
| Targeted backend baseline before changes | 161 passed |
| Regression run after US1/US3 changes (`Product|MasterData*|OutletMoveGuard|OutletScopeRegistry|WjmCatalog|OutletDeleteGuard|OutletIsolation`) | 356 passed |
| Terminals/receipt/features/branding/identity run (first) | 195 passed, 5 failed → all 5 triaged: 2 test bugs, 1 unreachable branch removed, 1 deliberate behaviour change in an existing receipt test, 1 deliberate reversal of the 048 body-outlet rule (reverted, see decision 1) |
| Same set after fixes (`DeviceManagementTest|DevicePairingTest|ReceiptStoreIdentityTest|OrderTest`) | 81 passed |
| Vitest (full) | 1771 passed, 1 failed (`DashboardViewShell … failing summary`) — passes alone (21/21); timing flake under load, unrelated to 079 |
| `npm run build` | OK; `TerminalsView` is its own chunk |
| Android `./gradlew test` | 834 results (debug + release variants), 0 failures |

## T059 — security pass (Constitution IV)
- Outlet never trusted from a body: terminal create = header (middleware 403 outside the user's outlets or when inactive); terminal move = body `outlet_id` checked against `permittedIds()` (403 `outlet_forbidden`), tested incl. inactive target and an outlet-assigned admin.
- Token-less `GET /pos-setup/outlets/{outlet}/branding` returns exactly `system_mode`, `store_name`, `store_logo_url`; asserted by key set and by absence of the outlet address in the body (`PosSetupOutletBrandingTest`).
- Customer phone/email remain customer fields only; asserted in `ReceiptStoreIdentityTest`.
- Hard delete cannot detach history (FKs are SET NULL); asserted with an order and with a shift.
- Activity-log entries carry names and outlet ids only; outlet-update entries carry old/new business fields (name, address, business phone/email, notes) — business contact data, not customer PII. No tokens, PINs or server addresses.
- Free-text outlet notes/address render through Vue interpolation (escaped) on web; the tablet prints plain text.

## T062–T068 — terminal bulk actions
- Backend: `DeviceBulkActionsTest` (7 tests) passes in the container with the safe overrides: deactivate/activate/delete per item, skipped vs refused vs changed, a refused item in the middle does not stop the rest, outlet-assigned admin gets `not_found` for another outlet's ids and for unknown ids, `outlet_id` in the body is ignored, cashier/inventory 403, payload validation (action, empty, duplicate, >100, non-integer), one activity-log entry per changed terminal.
- Frontend: `qa-tests/component/TerminalsBulk.test.js` (5 tests) and the existing `TerminalsView` (10) and locale parity tests pass: bar hidden without selection, header box selects the page, activate runs without confirmation, deactivate/delete confirm with the count and send the right ids, mixed result keeps refused rows selected with their reasons, filter change clears the selection.

## NOT verified (do not read as done)
- **Terminal bulk actions in a browser** (quickstart step 4b): not run; only component tests with a mocked API.
- **T058 browser walk-through** (quickstart steps 1–5): no browser automation was available in this session (Playwright and Chrome DevTools MCP servers failed to connect), and the dev database `warkop_wjm` was deliberately **not** migrated without the developer's say-so. Run `php artisan migrate` on the dev container and walk through quickstart.md with the console open.
- **T060 device-only checks**: tablet PIN screen shows the outlet name, 58 mm receipt wrapping of address/notes, a moved terminal returning to setup. No device/adb here.
