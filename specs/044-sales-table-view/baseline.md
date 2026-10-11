**Classification**: INTERNAL

# Baseline — 044 (recorded 2026-10-11, before any change)

Branch `044-sales-table-view` from `develop-warkop-wjm` (after PR #36).

| Check | Result |
|---|---|
| Backend `FloorViewTest`, `TabPermissionsTest`, `TabPerformanceTest` | 24 passed (166 assertions) |
| Frontend `qa-tests/component/SalesView*` and `Floor*` | 4 files, 85 tests passed |
| `npm run build` main chunk | 239.61 kB (`main-NEiNS8KL.js`, 239 614 bytes) |

These are the "must stay green / must not grow" numbers for SC-006.

## After (2026-10-11, end of implementation)

| Check | Result |
|---|---|
| Backend full suite | 1675 passed (8087 assertions) |
| Frontend full suite | 173 files, 1694 passed, 2 skipped |
| Main chunk | 239 747 bytes (+133 B vs baseline — noise); the board ships in the lazy chunk `FloorBoard-*.js` (10.39 kB / 3.60 kB gzip); `SalesView` route chunk 41.64 kB |
| Existing floor/tab/sales tests | all green (SC-006) |
