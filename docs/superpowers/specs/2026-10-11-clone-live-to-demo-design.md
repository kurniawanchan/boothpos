**CONFIDENTIAL** — describes the structure of real customer data. Contains no customer values.

# Clone LIVE data into DEMO — design

Date: 2026-10-11 · Status: draft, awaiting review · Path: architectural (new command, no schema change)

## 1. Goal

One-off, repeatable command that makes the DEMO dataset an exact mirror of the current LIVE dataset, so demos and testing use realistic data. The existing DEMO set (Sakana Fridge seed) is **replaced**.

Decisions already made by the product owner:

| Question | Decision |
|---|---|
| Existing demo data | **Replace** (wipe demo, then clone) |
| Customer PII | **Copy verbatim** (no masking) |
| `companies`, `invoices`, `pos_drafts` | **Leave out** |

Risk accepted with verbatim PII (UU PDP / access control): DEMO then holds real contact details. Keep this database local; do not share demo screenshots, exports or backups. ASSUMPTION: the dev DB never leaves this machine.

## 2. Non-negotiable invariant: LIVE is never modified

LIVE rows are read-only for this command. Enforced three ways:

1. **By construction.** Every read of source rows uses an explicit `data_mode = 'live'` filter; every write sets `data_mode = 'demo'` explicitly; every `DELETE` is filtered `data_mode = 'demo'`. The model global scope (`DataModeScope`) depends on the *active* mode, so the command bypasses it (`withoutGlobalScopes()` / `DB::table`) and never relies on it. No `UPDATE` touches a row whose `data_mode` is `live`.
2. **By verification inside the transaction.** Before any write, compute a fingerprint per table over its LIVE rows (row count + `CRC32` aggregate over all columns). After the clone, recompute. Any difference → throw, roll back, exit non-zero. The comparison covers every cloned table, `users`, `payment_channels` and `settings`.
3. **By test.** A feature test asserts the fingerprints and the live payment-proof files are byte-identical after a run, and after a forced mid-run failure.

Shared tables (`users`, `roles`, `payment_channels`, `settings`, `activity_logs`) are referenced, never written, except one `activity_logs` row recording the clone.

The fingerprint also covers the two tables that a demo wipe can reach through foreign keys without being cloned: `preorder_notifications` (rows of LIVE pre-orders; `ON DELETE CASCADE` from `preorders`) and `pos_drafts` (LIVE rows; `ON DELETE SET NULL` from `events`). Demo `preorder_notifications` disappear with their demo pre-orders; they are not cloned. The fingerprint is taken and compared inside the same transaction, so it proves *this run* wrote nothing to LIVE; a concurrent writer is not detected (stop using the app while it runs).

## 3. Command

`php artisan app:clone-live-to-demo [--force] [--yes]`

- **Default = dry run**: prints per-table "demo rows to delete / live rows to clone", the number and total size of files to copy, and the artist/category code mapping; writes nothing.
- `--force` required to write. A confirmation prompt shows the counts first (skippable with `--yes`; a plain `--no-interaction` run without `--yes` aborts).
- **Guards** (abort before anything is written): LIVE has zero rows to clone (nothing to mirror — would only wipe demo); the safety backup fails. *(Corrected 2026-10-11: an earlier draft refused to run when the DB name contains `test`; that is wrong — the feature tests must run this command on `boothpos_test`. The protection against wiping the wrong database is the dry-run default, `--force`, the confirmation and the backup.)*
- **Error output never contains row values.** A `QueryException` message embeds SQL bindings, which for `customers` are PII, so the command prints only the exception class and SQLSTATE; `CloneException` messages name table/column/id only.
- **Safety backup first** via `BackupService` (same as the restore screen). If the backup fails, abort. The backup id is printed so the pre-clone state is recoverable (`app:restore`).
- Safe to run in the dev `app` container (it is not the test suite, so the `-e DB_DATABASE` rule in CLAUDE.md does not apply). It is **never** run from tests against `boothpos`; tests run on `boothpos_test` only.

## 4. Algorithm

Everything in one DB transaction, except file copies (compensated on failure, see §6).

1. Capture LIVE fingerprints (§2.2).
2. **Wipe DEMO** in reverse FK order, always `WHERE data_mode = 'demo'`: `payment_proofs` (and their demo files), `payments`, `shipments`, `order_items`, `orders`, `preorder_items`, `preorders`, `product_variant_bom_lines`, `purchase_order_items` (via demo POs), `purchase_orders`, `session_opening_cash_entries`, `cashier_sessions`, `artist_settlements`, `stock_movements`, `material_stock_movements`, `vendor_material_prices`, `product_variants`, `products`, `materials`, `vendors`, `customers`, `categories`, `artists`, `events`.
3. **Clone LIVE → DEMO** in FK order, keeping an in-memory map `oldId → newId` per table and rewriting every FK column through it:

   | Tier | Tables |
   |---|---|
   | 1 (no in-scope FK) | `events`, `artists`, `vendors`, `materials`, `customers` |
   | 2 | `categories` (two-pass: `parent_id` after all rows exist), `products`, `vendor_material_prices` |
   | 3 | `product_variants`, `purchase_orders` (+ `purchase_order_items`, which has no `data_mode` column and follows its PO) |
   | 4 | `product_variant_bom_lines`, `cashier_sessions` (+ `session_opening_cash_entries`), `stock_movements`, `material_stock_movements` |
   | 5 | `orders` → `order_items`; `preorders` (two-pass: `source_preorder_id`) → `preorder_items`, `shipments` |
   | 6 | `payments` → `payment_proofs`; `artist_settlements` |

   Self-referencing columns (`categories.parent_id` — `RESTRICT`, `preorders.source_preorder_id`) are inserted as `NULL` and patched after the table is done; the wipe nulls them on demo rows first so a self-FK cannot block the delete. Soft-deleted LIVE rows (`deleted_at` set; 9 tables) are cloned too, so demo mirrors live exactly.
   `users`, `payment_channels`, `roles` FK values are kept as-is (shared). A FK pointing at a row that is not LIVE (should not exist) aborts the run with the table/column named, rather than leaving a dangling or cross-mode reference.
4. Stamp `data_mode = 'demo'` explicitly on every inserted row. Copy `created_at`/`updated_at` as-is. Do not copy `id`.
5. Verify LIVE fingerprints unchanged (§2.2) and run integrity checks (§7). Write one `activity_logs` row (who, when, per-table counts). Commit.

## 5. Unique values (unique across BOTH modes)

A cloned row cannot reuse the live value. **Column widths decide the rule** (checked against the dev schema, 2026-10-11): `artists.code` is `char(3)` and `categories.code` is `char(2)` (both validated `alpha`), `products.code_prefix` is 10 chars (`ART-CA-SEG`), `product_variants.sku` is 14 chars (`ART-CA-SEG-NNN`). A `-DM` suffix does not fit these four, so:

| Table.column | Rule |
|---|---|
| `artists.code` (3 letters) | **Allocated** unused code: try `Z`+first 2 letters of the live code, then `Y`+…, down to `A`+…; the first one not present in `artists.code` (either mode, soft-deleted included, or already allocated this run) wins. Mapping is injective and memoized (live code → demo code). Abort if all 26 are taken |
| `categories.code` (2 letters) | Same, with `Z`+first letter … `A`+first letter |
| `products.code_prefix` | Rebuilt as `<demo artist code>-<demo category code>-<same 3-letter segment>`, looking up each part of the LIVE prefix through the two maps above (so it stays consistent with the prefix's own parts even if the product's artist/category changed after creation — codes are permanent, F19.4). A prefix not shaped `AAA-BB-CCC` aborts with the product id (none exist in the dev DB: verified 0 of the live products) |
| `product_variants.sku` | The live product prefix at the start of the SKU is replaced by the demo prefix, keeping the `-NNN` tail. A SKU that does not start with its product's prefix aborts with the variant id (none exist: verified) |
| `vendors.code`, `materials.code` (20 chars) | `CODE-DM`; overflow aborts with the row id, never truncates |
| `orders.order_number`, `preorders.preorder_number`, `purchase_orders.po_number` (30 chars) | `…-DM`; overflow aborts |
| `orders.local_ref`, `payments.client_ref`, `payment_proofs.proof_token` | New random UUID (idempotency/lookup keys, not display values); `NULL` stays `NULL` except `proof_token`, which is never null |

Unique `(a, b)` pairs (`artist_settlements(event_id, artist_id)`, `vendor_material_prices(vendor_id, material_id)`, `product_variant_bom_lines(variant, po_item)`, `shipments(preorder_id)`) are satisfied automatically because both sides are remapped to new ids.

Remaining check, covered by a test in the plan: the sequence generators (`OrderService::generateOrderNumber()`, `PreorderService::generateNumber()`, `ProductCodeGenerator`), which count across both modes, must still produce non-colliding numbers in both modes after the clone.

Other stored references that are not plain FK columns are remapped too: `stock_movements.reference_id` by (type, reference_type) per CLAUDE.md (`sale`+`order_item` → orders, `return`+`order_item` → order_items, `purchase`/`preorder_handover`+`preorder_item` → preorder_items, `purchase`+`preorder` → preorders); `material_stock_movements.reference_id` (`purchase_order_item`) → purchase_order_items. Any other non-null reference is set to `NULL` and counted in the report — a LIVE id is never copied into a demo row.

## 6. Files: images and payment proofs are copied, never shared

Every cloned row that references a file gets its **own copy** of that file. A demo row sharing a live path would let a later demo delete or replace (`ImageUploadService::delete()`, `PaymentService::deletePayment()`) destroy the LIVE file.

| Column | Disk | Directory kept | Notes |
|---|---|---|---|
| `products.image_path` | `public` | same directory as source | product pictures |
| `product_variants.image_path` | `public` | same directory as source | per-variant pictures |
| `categories.image_path` | `public` | same directory as source | category pictures |
| `payment_proofs.file_path` | `local` (private) | same directory as source | stays private; never moved to `public` |

Not cloned because the owning rows are shared, not cloned (so nothing is duplicated and nothing can be deleted through demo): payment-channel QR codes (`payment_channels`), the store logo (`settings`), user profile photos (`users.photo_path`). Product-import images use the same `products`/`product_variants` columns above, so they are covered.

Rules:
- **New name per copy**: a fresh random UUID filename with the same extension (same convention as `ImageUploadService::store()`), written under the same directory. The cloned row's path column points at the copy.
- **Dedupe within one run**: if several live rows point at the same source file (e.g. a variant inheriting its product's picture by the same path), they share ONE copy per source *within DEMO only*, so demo does not balloon. Demo rows never share with live rows.
- Copies happen while each row is inserted, inside the transaction (before commit). A **missing source file** does not abort: for images the row is cloned with the path set to `NULL`; for a **payment proof** (`file_path` is NOT NULL and a proof without its file is meaningless) the row is **skipped**. Both are counted in the report (`files.missing`, `skipped`). A `NULL`/empty path stays `NULL`.
- **Rollback**: if the transaction fails or the post-clone verification fails, every copy made in this run is deleted. LIVE files are only ever read.
- **Wipe safety (§4.2)**: demo files are deleted only if the path is referenced by demo rows and by **no other row in either mode** (checked immediately before deleting). This protects against a past clone or seeder that left a demo row pointing at a live file. Files are deleted only after the DB transaction commits, so a rollback can never lose a demo file either.
- File sizes/bytes of copies are verified (size equal to source) before commit.
- Disk use: the clone roughly doubles the size of the image and proof folders; the dry run prints the total bytes to be copied.

## 7. Integrity checks (run before commit)

- For each cloned table: `COUNT(demo) == COUNT(live)` (after wipe).
- No FK in a demo row points at a LIVE row, and no live row points at a demo row.
- `SUM(stock_movements.quantity)` per variant equals `current_stock` for demo, matching the live relationship (copied, not recomputed — if live itself is inconsistent, the report says so rather than "fixing" it).
- Money columns are copied verbatim; per-table totals (orders, payments, preorders) match between live and demo.

Any failure → rollback, exit non-zero, report the failed check.

## 8. Out of scope

- `companies`, `invoices`, `pos_drafts`, `activity_logs` history, `license_activations` (installation security data), user accounts (and their photos), payment-channel QR images and the store logo (shared, see §6).
- Masking/anonymization (decided: verbatim).
- A UI button; CLI only. No schema change, no migration, no OpenAPI change (no route is added).
- Syncing continuously: it is a snapshot; re-running replaces demo again.

## 9. Testing (`tests/Feature/CloneLiveToDemoTest.php`, `boothpos_test` only)

- Seed a small LIVE graph (event, artists, category, product+variants, customer, order+items+payment+proof, preorder+items+shipment, PO+items+BOM, stock movements, session, settlement) and some DEMO rows.
- Dry run writes nothing.
- After `--force`: demo mirrors live row counts; old demo rows are gone; FKs remapped and in-mode; unique suffixes applied; demo order number differs from live.
- **LIVE untouched**: fingerprints identical before/after; live proof and image file bytes identical; deleting a demo payment, product, variant or category image does not remove the live file.
- **Images**: product, variant and category `image_path` values in demo are different paths from live, files exist on the `public` disk with identical bytes; payment proofs stay on the private disk; two live rows sharing one file share one demo copy; missing source file → `NULL` path and reported; rollback removes copied files; wipe never deletes a file still referenced by any live row (seed a demo row pointing at a live path to prove it). Use `Storage::fake('public')` / `Storage::fake('local')`.
- Forced failure mid-run (e.g. a fake exception after tier 4) → DB identical to the start (demo and live), copied files cleaned up.
- Guards: refuses to write without `--force`; aborts when LIVE is empty; aborts if the safety backup fails (use `FakeDatabaseDumper`, never the real restore); error output never contains a PII sentinel planted in a customer row.
- Generators after the clone: creating a new order, pre-order, purchase order and product in each mode still works and yields unique numbers/codes.
- Idempotent: running twice yields the same demo counts and no duplicates.

## 10. Rollout

1. Implement and test on `boothpos_test`.
2. Run dry run against `boothpos`, review counts with the product owner.
3. Run with `--force` (the safety backup is taken automatically); spot-check in the UI with `system_mode = demo`.
4. Add a RUNBOOK entry: purpose, flags, the verbatim-PII warning, and recovery via the printed backup id.
