**Classification**: INTERNAL

# Evidence — Phase 1 (frame, page kit, restyle) real-browser verification

Environment: local dev stack (`warkop_wjm`, DEMO mode, seeded data), production build served by Laravel on `http://localhost:8010`, headed Chromium driven by Playwright, 2026-10-11. Screenshots are in `evidence/`.

## Limitation (important)

Partway through, the browser stopped receiving injected **mouse input** (a raw `page.mouse.click` delivered no DOM events at all; a fresh browser reproduced it). Keyboard-free DOM calls still worked. The earlier cashier-flow runs used real clicks; the checks from "collapse/expand" onward used DOM-level `element.click()` and `fetch`. Rendering, layout, CSS, API calls and the console are still real; the real-pointer path for those later steps is **not** proven and should be re-checked by hand once.

## T035 — frame (US1), quickstart Phase 1 steps 1–2, 5–7

| Check | Result |
|---|---|
| Rail inside the new frame for every role | `owner`, `admin`, `kasir01`, `inventory` all render the rail, breadcrumb and user menu |
| Visibility equals the server's menu keys | owner/admin: 24 keys → 10 top-level links + 5 groups; inventory: 15 keys → 8 links + groups Restaurant (dining setup only), Inventory, Purchase, no Reports/Settings/Admin; kasir01 matches its keys |
| Forbidden URL | `inventory` opening `/reports` is redirected to `/dashboard`; the API still enforces: `reports/profit`, `reports/purchases`, `activity-logs`, `users` → **403**; `reports/sales` → 200 with **no cost/margin** in the payload (by design: the Sales page is open to all roles) |
| Collapse persistence | collapsed rail is 64 px, preference stored as `boothpos:rail-collapsed:<userId>`, a fresh page load restores the collapsed state; expanding returns 240 px and stores `expanded` |
| Language switch | `Sales`/`Main navigation`/`User menu`/`Collapse navigation` ⇄ `Penjualan`/`Navigasi utama`/`Menu pengguna`/`Ciutkan navigasi`, persisted per user, switched back to English afterwards |
| Theme accent | setting `--color-brand` changes the primary colour from `rgb(79,70,229)` to `rgb(5,150,105)`; the rail logo, badges and primary buttons follow |
| Console | 27 pages visited as `admin` (every route): **no errors or warnings**. The only errors in the session are the four deliberate 403 probes above, and `404 /sessions/current` when no shift is open (existing behaviour) |

## T067 — page structure on the migrated pages

Reviewed visually: Dashboard, Sales, Products, Settings, Pre-orders, Reports (screenshots `after-*.png`). Each shows the page header with actions, a KPI strip where one exists, tabs/filters with an "N items" counter, and the table; the collapsed rail renders icon-only. Summary figures equal their lists for Sales (5 transactions, Rp 405.000) and Reports (grand total Rp 405.000), checked against the same demo data.

## Defects found by this review and fixed

1. **Rail links unreadable** (dark indigo on dark): the global `a { color }` rule was unlayered and overrode every Tailwind text utility on links. Moved into `@layer base` in `resources/css/app.css`.
2. **Theme-colour picker defaulted to the old green** `#2f9e6e` in three places, so opening and saving it would switch the app to green. Single `DEFAULT_ACCENT_COLOR` in `utils/theme.js`, guarded by `qa-tests/unit/themeDefault.test.js`.
3. **Mode badge showed "LIVE" before settings loaded** (store default), even on a DEMO install, for several seconds on the slow dev server. The badge now waits for `settings.loaded`.

## Observations, not fixed here

- The dev PHP server answers each API call in ~0.5 s, so a page takes a few seconds to mount; screenshots taken immediately after navigation are blank. This is pre-existing.
- In a short window the rail's last group ("Settings") is partly clipped under the collapse control; the list scrolls, but the clipping is untidy. Candidate polish item.
- The backend test suite and the dev app share one file cache (see `baseline.md`).
