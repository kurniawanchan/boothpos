# Research: Terminal Identified by Name Only

Facts below were read from the code on 2026-10-22; decisions are marked.

## Current state (fact)

- `devices` has `code` (NOT NULL, ≤12, unique per `outlet_id + data_mode`) and `name` (≤80, not unique). `DeviceService::nextCode()` auto-fills `T01…`; `PairDeviceRequest` accepts an optional `code`.
- `ReceiptNumbering::receiptFor(Device)` = `<code>-<seq 6 digits>` from `terminal_counters` (per device). `orders.receipt_no` is UNIQUE across the table. A receipt is allocated only for orders that have a `device_id` (web-SPA orders get none).
- `orders.device_id` and `cashier_sessions.device_id` already exist (nullable FK, nullOnDelete). `TabResource.device = {id, code}`, `FloorQuery.device_code`, `DeviceController::present()` returns `code`.
- Android: `TerminalConfig.deviceCode` (stored in `TokenVault.DEVICE_CODE`) is shown on PIN, shift screens, chips; `otherDevice` labels use the code; `OrdersScreen` has a hard-coded fallback `"T01"`; sample/debug data and tests use `T01-000124`.
- The web SPA has no terminal list/management screen and no dashboard device panel; the Sales page shows no terminal today.

## Decisions

**D1 — Terminal identity = `name` only; `code` becomes legacy.** Make `devices.code` nullable, stop generating/accepting/returning it, keep old values untouched. Rejected: dropping the column (irreversible; the old unique index and old rows are harmless, same "delete code, keep data" approach as feature 046).

**D2 — Name uniqueness: active terminals, per outlet + data mode, case/space-insensitive; enforced in `DeviceService` inside the transaction with the outlet row locked.** Name is trimmed in the FormRequest; the table's default collation already compares case-insensitively. A revoked terminal frees its name (spec edge case), so a plain DB unique index would be wrong, and a generated-column unique index is avoided because generated columns already break backup restores here (`orders.open_table_key`, see CLAUDE.md 045 note). Rejected: global-per-outlet unique including revoked rows (blocks re-registering a replaced tablet).

**D3 — Migration dedupes existing names before the rule applies.** Within an outlet+mode, later active duplicates get " 2", " 3"… appended (spec assumption); blank names become "Terminal" + suffix. `code` is made nullable; `down()` re-fills codes (`T01`…) so it is reversible. No row is deleted.

**D4 — Receipt number = `%06d` per outlet (Q1 = A).** New `outlet_receipt_counters(outlet_id PK, receipt_seq)` locked with `lockForUpdate` in the settling transaction (same discipline as `queueFor`). Because two outlets can now both issue `000001`, the global UNIQUE on `orders.receipt_no` is replaced by UNIQUE `(outlet_id, receipt_no)`; legacy `T01-…` values cannot collide with 6-digit numbers. **Consequence to confirm with the owner:** a bare receipt number is no longer unique installation-wide (the outlet is). Rejected alternatives: one installation-wide sequence (keeps the old unique index, but numbers interleave across outlets, which contradicts "per outlet" as chosen); name prefix (renames/spaces/58 mm width, rejected earlier in Q1).

**D5 — Eligibility to get a receipt number is unchanged** (order has a device). Only the counter key changes (outlet from the order, not the device). `terminal_counters` stays, no longer written (inert history); `DeviceService::pair()` stops inserting into it.

**D6 — "Link to order transaction" = expose the existing `orders.device_id` link by name.** No new column, no name snapshot: a rename shows on old orders by design (spec US2.4). If a device is ever hard-deleted the FK nulls and the UI shows the neutral label "Tanpa terminal" (Android) / "—" (web). Eager-load `device:id,name` wherever orders are listed (constitution V).

**D7 — API shape.** `device` objects become `{id, name}` (no `code`); `FloorQuery.device_code` → `device_name`; receipt payload and order payloads add `terminal_name`; session/shift payload adds `terminal_name`; `GET /devices` rows lose `code`; `POST /devices/pairings` ignores any `code` (not validated, not stored); new `PATCH /devices/{device}` renames (owner/admin, outlet-scoped, audit `device_renamed`). Android and server must be released together (tablets are a 3-unit pilot); an old APK reading `device.code` would not get it.

**D8 — Already-paired tablets show the right name without re-pairing.** `TokenVault` gets `DEVICE_NAME`; `RemoteBackend.pairTerminal` stores it. For tablets paired before this release the name is missing locally, so the heartbeat response adds `name` and the app writes it to the vault (until then the UI shows the neutral "Terminal"). Rejected: forcing re-pairing; calling the admin-only `GET /devices`.

**D9 — Android display rules.** Replace every `deviceCode` use with `deviceName`; "other terminal" label stays "shown only when the tab's terminal ≠ mine" but shows the name; the `?: "T01"` fallback in `OrdersScreen` becomes this terminal's own name; the struk gets one "Terminal" line (name, truncated to fit 58 mm — long names are cut by the existing `PrintLine` wrapping rule, never overflow).

**D10 — Web scope is deliberately small.** Sales rows gain `terminal_id`/`terminal_name`; the Sales table gets a Terminal column and a filter (client-side, in `useSalesFilters.js`, shown only when rows carry ≥2 distinct terminals — hidden otherwise, per the "hide what's unusable" rule); the Sales export gets the column (it is built from the same service, so the file matches the screen). No terminal-management screen, no dashboard device panel (neither exists; building them is out of scope).

**D11 — Tests that encode the old behaviour are rewritten, not deleted:** `DevicePairingTest` (code assertions → name rules), `ReceiptNumberingTest` (`T01-000001` → `000001` per outlet), `TabOpenPosFieldsTest` (`device.code`, `receipt_no`), `TabPrebillTest` (`device_code`), Android `ReceiptMapper*`, `PrintQueue`, `Documents`, `FakePosBackend`, `DebugPresets`.

## Open points (not blocking the plan)

- Owner to confirm D4's consequence (receipt number unique per outlet, not installation-wide).
- Renaming has no UI yet (API only, FR-004 scoped accordingly).
