# Contract — 077 API additions and UI rules

**Classification**: INTERNAL. `docs/openapi-pos-mvp.yaml` MUST be updated in the same commit as the three server changes (PRD §9.5).

## API

### 1. `GET /api/v1/pos-setup/branding` — NEW, no token
Recorded Constitution IV exception (same class as 073): only values already visible to every logged-in role.
- Throttle: `30,1`. Not behind `auth:sanctum`. NOT exempt from `EnsureInstallationIsActivated` (an unactivated install returns 423, like the other `pos-setup` routes).
- 200:
```json
{ "system_mode": "live", "store_name": "Warkop WJM", "store_logo_url": "http://…/storage/…png" }
```
  `store_logo_url` is `null` when no logo. `store_name` is the per-mode key (`store_name_demo` in demo) exactly as `GET /settings/features`.
- No other field may be added without revisiting the exception.

### 2. `GET /api/v1/staff/roster?outlet_id=` — existing, one field added
Each item gains `"photo_url": string|null` (public-disk URL via `ImageUploadService`, null when no photo). Existing fields (`id,name,role,has_pin,locked`) unchanged; still no username/hash.

### 3. `PUT /api/v1/tabs/{order}/note` — NEW, `auth:sanctum`
- Body: `{ "notes": string|null }`, `max:1000`, `nullable`; blank string stored as `null`.
- 200: `TabResource` (now includes `"notes": string|null`).
- 403 outside the user's outlets / role (same guard as editing a tab line); 404 unknown or other-outlet tab; 409 when the tab is not open (paid/closed/voided); 422 when over 1000 chars (`notes`).
- One transaction: update + `ActivityLogger` entry. Idempotent (sets state). `outlet_id`/`data_mode` never read from the body.

### 4. `GET /api/v1/tabs/{order}` — existing
Response now includes `notes` (additive). `GET /api/v1/tabs` (hand-rolled summary) is **unchanged**: the tablet reads the note from the detail.

### Unchanged but relied on
`GET /api/v1/settings/features` → `system_mode`, `store_name`, `store_logo_url`, `outlet_count` (post-login). `GET /api/v1/auth/...` user → `photo_url`.

## Error classification (client)
403 `outlet_forbidden` or 404 on device/outlet, **with a mode different from the one the session started in** → `ModeChanged`, never "terminal revoked". Same codes with an unchanged mode keep today's behaviour.

## UI rules
- **DEMO badge**: visible in `StoreMark` on every screen when mode = DEMO (pre- and post-login); absent in LIVE.
- **StoreMark**: logo (rounded square) + store name (1 line, ellipsis) [+ outlet name when `outlet_count > 1`]; fallback = initials of the store name on a tone tile; fixed size so layout never shifts.
- **PIN screen (landscape)**: left column = StoreMark, outlet · terminal, clock, staff picker, selected user (avatar, name, role); right column = "PIN <name>", 6 dots, error line, keypad, "Ganti outlet / terminal". Compact = one scrolling column in the same order.
- **Divider**: handle ≥ 24 dp wide touch target, visible grip; clamp [0.35, 0.75] and ≥ 320 dp per pane; double-tap → 0.6; side-by-side layout only.
- **Order note**: row "Catatan order" in `OrderPane` above totals; collapsed shows the saved text (1 line) or "Tambah catatan"; expanded = text field (counter `n/1000`) + Simpan; disabled when the order is closed.
- **Price label**: `Rp 7.000 – Rp 15.000`, non-breaking space inside each price, wraps only at the dash; single price on one line; two-line height reserved on entry cards.
- **Avatar**: photo (circle, cropped) else initials; failure → initials.
- Copy (Indonesian): mode dialog "Mode berubah ke DEMO/LIVE. Keluar lalu masuk lagi untuk melanjutkan." · note placeholder "Catatan untuk seluruh order" · over-limit "Catatan maksimal 1000 karakter."
