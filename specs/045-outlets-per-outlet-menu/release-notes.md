**Classification**: INTERNAL

# Release notes — feature 045 (outlets)

## What ships

Seller → outlet rename (Stage 1), outlet scope, assignments, shifts, tables (2), per-outlet menu/prices/86 (3), ordering from the outlet's data (4), stock per outlet (5), transfers (6), reports by selling outlet (7), hardening (8). A single-outlet install keeps working unchanged and shows no outlet control.

## Rehearsal (2026-10-12)

Pre-045 backup (`2026-10-11_092245`, demo data: 5 orders, 27 variants) restored into a scratch DB, full migration chain (all 2026-11-05 … 2026-12-05 migrations) in **14 s**, `outlets:reconcile` before/after/compare **green** (row counts, money, per-variant stock, I1/I2, outlet columns, Stage 3 settings). The shipped `rehearse.sh` (copy of the current dev DB) also passes. Volume check (20 outlets, 509 items, 1 202 tables): all measured endpoints < 1 s with constant query counts — see `evidence-stage-8.md`.

## What is reversible

| Migration | `down()` | Notes |
|---|---|---|
| `12_01` rename | yes | renames back; wording and keys are code, not data |
| `12_02` operational outlets | yes | drops `outlet_id`/`outlet_user`; data created after the migration loses its outlet |
| `12_03` menu per outlet | yes | drops settings/prices; outlet prices are lost |
| `12_04` stock per outlet | yes | drops balances and `outlet_id` on movements; **per-outlet distribution is lost** (the cached totals remain correct) |
| `12_05` transfers | yes | drops transfers; **do not roll back with goods in transit** (receive or cancel them first) |

Rollback after real use means a restore, not `migrate:rollback`: take `app:backup` first (and keep the folder).

## Known issue (not caused by 045)

`app:backup` dumps (MariaDB client) include the generated column `orders.open_table_key` in `INSERT INTO orders VALUES`, so restoring such a file into MySQL 8 fails with error 3105 once orders exist. Rehearsal workaround: strip the `GENERATED` definition from the dump; real recovery: dump with `mysqldump` from the MySQL container. Permanent fix proposed: `--complete-insert` and skipping generated columns in `MysqlCliDumper`. **Fix before relying on `app:restore` in a store.**

## Decisions still open for the owner

- Recipe effectivity uses UTC `today()` while price changes use the business day (00:00–07:00 WIB mismatch).
- After migration non-admin users are assigned to **all** outlets; narrow them in the Users screen.
- Browser evidence for Stages 2–7 and the final matrix pass (T105) has not been recorded.
