**Classification**: INTERNAL

# Design tokens sampled from the Back Office captures

**Source**: pixel sampling of `design/screens/SaaS Restaurant Admin Dashboard/*.png` (924 × 540 palette-PNG captures) on 2026-10-11; rail widths read from the page definition in `design/screens/Warkop WJM Back Office.html`. **Not** taken from `design/_ds/modernist-*` (a different design system: red accent, Archivo, square corners).

Captures are palette-quantised, so a value is accepted only where it was seen unchanged on **at least two** captures or is a flat fill area; anything seen once is marked *(single sample)*.

## Colour (sampled)

| Role | Hex | Where sampled |
|---|---|---|
| Rail background | `#0f172a` | Dashboard, Pesanan (large flat area) |
| Rail active item background | `#1e293b` | Dashboard (active "Dashboard" item) |
| Rail active indicator (left bar) | `#848cf6` | Dashboard (1 px bar) *(single sample)* |
| Page background | `#f1f5f9` | Dashboard, Laporan, Inventori |
| Top bar / soft surface | `#f8fafb` / `#f8fafc` | Dashboard top bar; Pengaturan table area |
| Card / panel surface | `#ffffff` | all |
| Accent (primary button, logo, active tab, links) | `#4f46e5` | Dashboard, Pesanan, Pengaturan |
| Accent soft (selected section background) | `#eef2ff` | Pengaturan |
| Heading / strong text | `#0f172a` | Dashboard |
| Muted text | `#94a3b8` (markers/neutral) | Pesanan |
| Marker — info | `#0284c7` | Pesanan, Pembelian |
| Marker — accent | `#4f46e5` | Pesanan |
| Marker — success | `#059669` | Pesanan |
| Marker — warning | `#d97706` | Inventori, Pembelian |
| Marker — danger | `#dc2626` | Inventori, Pembelian |
| Marker — neutral | `#94a3b8` | Pesanan |
| Chip — success background | `#d1fae5` (text `#186d54`) | Dashboard (↑ 6,8%) |
| Chip — warning background | `#fef3c7` | Dashboard ("4" count chip) |
| Notification badge | `#da5e5e` | Dashboard (bell count) *(single sample)* |

## Dimensions

| Item | Value | Source |
|---|---|---|
| Rail collapsed width | 64 px | page definition (`width:64px`) and captures (rail 64 px wide) |
| Rail expanded width | 240 px | page definition (`width:240px`) |
| Capture viewport | 924 px | minimum comfortable width assumed by the spec |

## Typography

Plus Jakarta Sans (weights 400–800) — the page loads this family; the product already uses it. Sizes are not sampled; the page title is the largest text on each capture and body text is the 13–14 px class. Exact scale to be matched visually in the Phase 1 browser check (T034 / T054).

## Mapping to project tokens (decision for T009)

| Project token (`resources/css/app.css`) | New value | Note |
|---|---|---|
| `--color-brand` | `#4f46e5` | default accent; still overridden at runtime by the store theme-colour setting (`utils/theme.js`) |
| `--color-brand-hover` / `--color-brand-active` | darkened by the same function `utils/theme.js` uses (20 / 40), not sampled | keeps default and runtime-override consistent |
| `--color-mint-*` (tints used for selected/soft states) | indigo tints, `--color-mint-50`/`-100` aligned to `#eef2ff` family | the name stays (renaming 100+ usages is out of scope); values follow the design so un-migrated views do not show green next to indigo |
| new `--color-rail`, `--color-rail-active`, `--color-rail-indicator` | `#0f172a`, `#1e293b`, `#848cf6` | frame only |
| new `--color-page` | `#f1f5f9` | content background |
| new `--color-marker-info/-accent/-success/-warning/-danger/-neutral` | as in the table | KPI markers, status dots |
| new `--color-chip-success-bg` / `--color-chip-warning-bg` | `#d1fae5` / `#fef3c7` | chips |

Colour pairs that must pass WCAG 2.1 AA (checked by `qa-tests/unit/tokenContrast.test.js`): white on accent, ink on page, ink on card, muted on card, chip text on chip background, rail text on rail background.

## Facts read from the design page CSS (second source, 2026-10-11)

| Item | Value | Source |
|---|---|---|
| Corner radius | `0` everywhere (square corners) | `radius:0` in the page CSS; confirmed visually on all captures |
| Border | `2px solid #e2e8f0` (cards, tables); inputs `#cbd5e1`; focus/selected `#4f46e5` | page CSS (52 / 12 / 9 uses) |
| Elevation | hard offset shadows `2px 0 0 #e2e8f0, 0 2px 0 #e2e8f0` and `inset 0 -2px 0 #e2e8f0`; drawers/popovers `0 12px 32px rgba(15,23,42,.14)` | page CSS |
| Type scale | 11 px (labels, chips), 12 px (body, table), 13–14 px (emphasis), 16 px, 18 px, 22 px (page title), 24 px (KPI value) | page CSS counts: 12 px ×50, 11 px ×24 |
| Weights | 600 (most text), 700 (headings, values) | page CSS |
| Neutral scale | exactly the standard slate steps (`#0f172a`, `#1e293b`, `#cbd5e1`, `#e2e8f0`, `#f1f5f9`, `#f8fafc`, `#94a3b8`) | page CSS + samples agree on every neutral |

**Decision**: neutral steps that were not sampled directly are taken from the same slate scale (`#475569`, `#64748b`, `#334155`); accent steps from the indigo scale (`#4338ca`, `#3730a3`, `#e0e7ff`, `#c7d2fe`, `#818cf8`). This is an inference from every sampled value matching that scale (label: ASSUMPTION), and each such pair is covered by the contrast test.

**Visual consequence**: the product moves from rounded green cards (4–18 px radii) to a boxy indigo/slate look. All `--radius-*` tokens become `0`.
