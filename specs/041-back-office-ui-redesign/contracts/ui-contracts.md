**Classification**: INTERNAL

# UI contract — frame, page kit, interaction rules (Phase 1)

Source of truth for look and structure: the 13 captures in `design/screens/SaaS Restaurant Admin Dashboard/` and the 12 page definitions in `design/screens/Warkop WJM Back Office.html`. The design bundle's `_ds/modernist-…` folder is a different design system and MUST NOT be used.

## 1. Frame

| Part | Contract |
|---|---|
| **Rail** | dark, icon-only 64 px wide collapsed, 240 px expanded (values read from the design page); modules grouped (overview; operations; inventory & purchasing; people; configuration) with thin separators; active item highlighted with an accent bar; a status dot appears on a module when its attention count > 0; collapse control at the bottom; collapsed state remembered per user; hover/focus shows the module name. Items shown only if the role holds a permission of that module (hidden, never disabled). Includes every existing module (pre-orders, events, sellers, licensing/company admin, activity log, backup) in the groups. |
| **Top bar** | left: breadcrumb `Beranda / <page>`; centre: global search with a keyboard hint (⌘K / Ctrl+K opens it); right: outlet selector (hidden when the user has one outlet), notification bell with count, user menu (name, role, profile, language, sign out). Existing language switcher and DEMO/LIVE badge move into the user menu / bell area without losing visibility of the mode. |
| **Outlet selector** | lists the user's permitted outlets plus "Semua outlet (n)" when more than one; choice stored per user and sent as `X-Outlet-Id`; changing it reloads the current page's data. |
| **Content** | page background token; max content width; consistent 24 px page padding. |
| **Cashier-facing screens** | POS, session, floor/tab, kitchen use the same tokens and components (Q2 = B) inside the frame; their tap targets and text sizes are not reduced; no flow gains steps. |

## 2. Page kit (reusable components in `components/page/`)

| Component | Contract |
|---|---|
| **PageHeader** | title (large), one-line description, actions slot: at most one primary (filled) and the rest secondary (outlined); responsive wrap. |
| **KpiStrip / KpiCard** | exactly four cards in a 2×2 (≥ 1024 px: 4 columns where the design shows a row); each: coloured marker (semantic colour token), label, large value, one-line note; optional click target that switches to the related tab; number formatting `Rp` with dot separators; an unavailable figure shows "—" with a tooltip, never `0`. |
| **TabBar** | tabs with count chips; counts describe the whole section (not the filtered view); keyboard operable (arrow keys), `role="tablist"`. |
| **FilterBar** | search field + select filters + "N data" counter; filters per tab; resets with one action; state mirrored in the address bar (existing `useSalesFilters` convention) so lists are shareable. |
| **DataTable** (extended) | sortable headers, row hover, optional selection column with select-all-on-page, sticky header, horizontal scroll inside the table, empty/loading/error rows, pagination text `Menampilkan X–Y dari Z`. |
| **BulkBar** | appears when rows are selected; lists only actions valid for **all** selected rows; shows per-row success/failure afterwards. |
| **DetailPanel** | right-hand panel built on `BaseDrawer` (focus trap, Escape, backdrop): kicker, title, status chips, actions allowed for the caller and the record's status, key facts grid, sections (tables, notes, timeline). |
| **ReasonDialog** | confirmation that requires a reason chosen from `GET /reason-codes?category=` plus an optional note; confirm disabled until chosen; destructive variant uses the danger token; the chosen code is sent as `reason_code_id`. |
| **SettingsLayout** | left grouped section list (with issue count badges) + right content; used by Pengaturan and Konfigurasi POS. |
| **StatusChip** | maps status → tone (neutral, info, success, warning, danger, accent); the existing `StatusPill` is extended, not duplicated. |
| **FormDrawer / form sections** | create/edit forms in a drawer with titled sections, required markers, inline `422` messages from the central error handler. |
| **Toasts** | outcome messages via the existing toast store; one at a time. |

## 3. Interaction rules

1. **Error convention** (existing): `422` → field messages, `409` → business-rule message naming the conflict, `403` → permission message, `401` → login. No per-screen variants.
2. **Hidden, not disabled**: actions the caller may not use are absent. Exception: actions blocked by record state may be shown disabled with a tooltip that says why (as the existing pre-order Split does), never blocked by permission.
3. **Risky actions** (void, reject, reopen, deactivate, delete personal data, force close, revoke) always go through ReasonDialog.
4. **Approvals**: an item awaiting the caller's approval shows an approve/reject action in its DetailPanel; items the caller created show "waiting for approval by someone else".
5. **Loading, empty, error** states exist for every page (see edge cases in the spec); the frame never renders an empty content area.
6. **Live data**: pages that poll (floor, kitchen, open orders) show an "updated at" time and a "Real-time" indicator as in the design; polling uses the existing `usePolling` with a version token.
7. **Copy**: Indonesian is the source text; English through the existing i18n files; parity tests keep passing.
8. **Money/dates**: `Rp 18.420.500`, `Minggu, 4 Okt 2026` where a full date is shown.

## 4. Tokens

- All styling uses `@theme` tokens in `resources/css/app.css`; no raw hex in components (constitution III).
- New Back Office tokens (rail background, accent, accent-soft, marker colours for success/warning/danger/info/neutral, table header/row hover, panel border) are **sampled from the reference captures in the first Phase 1 task** and recorded in a token table in `research.md`; no values are invented in this plan.
- `--color-brand*` remain the accent that the store's theme-colour setting overrides at runtime (`utils/theme.js`); the new frame reads the accent through those variables so the setting keeps working (FR-020/FR-064).
- Font: Plus Jakarta Sans (already the product font; the Back Office page loads the same family). Icons: Phosphor (existing); no new icon dependency.

## 5. Accessibility

- Text and control contrast meets WCAG 2.1 AA (verified with an automated contrast check over the token pairs and a manual pass per page).
- Every interactive element reachable by keyboard; visible focus ring token; dialogs and drawers trap focus and restore it on close (existing `useFocusTrap`); tabs and tables expose proper roles; the rail items have accessible names when collapsed.
- Reduced-motion preference disables rail/drawer animations.

## 6. Test contract

- A Vitest + Testing Library test per kit component (render, keyboard, states) under `qa-tests/component/`.
- Existing view tests keep passing after each view migrates; locale parity tests keep passing.
- A real-browser walk-through per phase against the real API with a clean console (see quickstart).
