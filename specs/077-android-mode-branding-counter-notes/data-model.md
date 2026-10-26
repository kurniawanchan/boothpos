# Data Model — 077

**Classification**: INTERNAL. No database migration. Server tables are unchanged; everything below is a field exposed or a client-side shape.

## Server (existing storage, new exposure)

| Entity | Field | Source | Change |
|---|---|---|---|
| Order (`orders`) | `notes` (text, nullable, ≤ 1000 enforced at write) | existing column, already `$fillable` | **write path added** (`PUT /tabs/{order}/note`); `TabResource` now returns it |
| Setting | `system_mode` (`live`/`demo`) | existing | exposed pre-login via branding |
| Setting | `store_name` / `store_name_demo`, `store_logo_path` | existing | exposed pre-login via branding (same selection rule as `features`) |
| User | `photo_path` → `photo_url` | existing | added to the token-less roster item |

Mode scoping is unchanged: `Order`, `Outlet`, `Device`, menu models stay `HasDataMode`; `User`/`Setting` stay unscoped. The order note inherits the order's `data_mode` and `outlet_id` — never taken from the request.

## Android (client shapes)

- **SystemMode** — `LIVE | DEMO`. Source: branding (pre-login) or `features` (post-login). Held in `PosStore`; never written by the tablet.
- **Branding** — `{mode: SystemMode, storeName: String, logoUrl: String?}` (logo already passed through `ServerUrl.resolve`). Refreshed on PIN-screen load, each `features` read, and pull-to-refresh.
- **TerminalConfig** — unchanged fields; **stored per mode**: `Map<SystemMode, TerminalConfig>` in the vault. Rules: reading uses the current mode's entry; `forgetTerminal()` removes only the current mode's entry; legacy single config (pre-077 install) is migrated into the entry of the mode first observed (ASSUMPTION: the install was set up in the mode active then).
- **StaffUser** — `+ photoUrl: String?`.
- **Order** — `+ note: String` (empty = none). Pending edit is held in the `send` intent queue, not in `Order`.
- **SplitPreference** — `fraction: Float` (product pane share, default 0.6, valid [0.35, 0.75] and ≥ 320 dp per pane, re-clamped on read).

## State transitions

- **Mode change mid-session**: `Normal → ModeChanged(newMode)` when a `features`/API result shows a mode different from the session's mode; in `ModeChanged` all order writes are refused and the only action is sign-out → setup/PIN of the new mode's saved config. Nothing is deleted on either side.
- **Order note**: `none ⇄ text` while the tab is open; read-only once the order is paid/closed/voided (409 from the server, shown as a message; the field is disabled in the UI).
