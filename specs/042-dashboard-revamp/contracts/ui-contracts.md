**Classification**: INTERNAL

# Contract: Dashboard UI and kit components

## Layout (top to bottom; matches `design/screens/…/01-Dashboard.png`)

1. `PageHeader` — title "Dashboard"; meta line: business date (formatted with the store timezone) · "Diperbarui HH.MM" (`computed_at`) · device notice (Stage B, only when `devices.behind_count > 0`, hover/focus shows the details); actions: period segmented control (`Hari ini | 7 hari | 30 hari`, `role=tablist`) and "Ekspor".
2. KPI panel (`KpiStrip`) — four cells: label, unit note, value, `ChangeChip`, `TrendLine`.
3. `AttentionPanel` — header: "Perlu tindakan", count chip, scope line "{scope} · per HH.MM · klik untuk menindaklanjuti"; grid of `AttentionItem` (icon, title, value, meta); item is a button/link only when `link` is present.
4. "Order terbaru" — subtitle "{n} order hari ini · {scope}", "Real-time" marker, "Ekspor CSV"; `TabBar` with counts; `FilterBar` (search + Tipe order, Pembayaran, date range menus, Reset filter); removable chips; `DataTable` (row click → `TransactionItemsModal`); pager.
5. "Per event" section (`PerEventSection`) — the previous Dashboard panels, unchanged, lazy-loaded.

## New kit components (`components/page/`)

| Component | Props | Behaviour / a11y |
|---|---|---|
| `TrendLine` | `series`, `comparison`, `tone` (`brand`/`danger`), `label` | inline SVG, solid actual + dashed comparison; `role="img"` with an `aria-label` that states first, last and trend direction; no chart library |
| `ChangeChip` | `value`, `kind` (`percent`/`items`), `goodWhen` (`up`/`down`), `noComparisonText` | arrow glyph + signed number + colour + visually-hidden "naik/turun"; `null` → neutral "no comparison" |
| `AttentionItem` | `kind`, `severity`, `title`, `value`, `meta`, `link?` | `ok` severity shows the all-clear text with a check icon; link absent → plain text, not a disabled button |
| `AttentionPanel` | `items`, `count`, `scopeText`, `computedAt`, `state` | owns the header chip; its own loading/error/empty |

Tokens only (no hex): `--color-success-*`, `--color-warn-*`, `--color-danger-*`, marker tokens from 041.

## States (per region, independent)

`loading` (`LoadingState`), `error` (`ErrorState` with **Retry** for that region only), `empty` (zero is a value: KPI shows `0`/`Rp 0`, table shows `EmptyState`), `data`. A failed region never blanks another.

## URL state (`useQueryState`)

`?period=7d&tab=partial&q=…&type=dine_in&method=qr_ewallet&pstat=pending&from=…&to=…&page=2` — unknown values ignored, reload restores the view (FR-003, FR-015). The selected outlet is **not** in the URL; it lives in the outlet store and the `X-Outlet-Id` header (Stage B).

## Refresh

`useAutoRefresh(fn, 30_000)`: pauses while `document.hidden`, refetches summary + attention + the current table page, keeps filters and the open row detail, and drives the "Real-time" marker (shown only while the timer runs).

## Permissions and hiding

- Route stays gated by the `dashboard` menu key (cosmetic mirror; the server decides).
- Per-event panels keep their existing gates (`reports` for profit/settlement panels).
- Outlet selector appears only when `GET /me/scope` returns more than one outlet; device notice and device settings only when devices exist / role has `settings`.

## Copy

Indonesian source with English locale parity (`dashboard.*` keys extended; `localeKeys` test must pass). Amounts/dates via `utils/formatters.js`.
