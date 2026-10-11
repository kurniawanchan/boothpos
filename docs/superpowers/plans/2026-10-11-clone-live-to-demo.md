**CONFIDENTIAL** — describes the structure of real customer data. Contains no customer values (fixtures use synthetic `@example.test` data).

# Clone LIVE → DEMO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `php artisan app:clone-live-to-demo` — wipe all `data_mode='demo'` rows and rebuild demo as an exact mirror of live (rows, soft-deleted rows, images, payment proofs), without ever modifying a LIVE row or file.

**Architecture:** A table-driven cloner (`App\Services\DemoClone\*`) reads a single registry of 24 tables in FK order, copies rows with raw `DB::table()` (bypassing the `DataModeScope`), keeps an `oldId → newId` map per table to rewrite FKs, and runs inside one transaction. A LIVE fingerprint taken before and after (inside the transaction) proves nothing live changed; any difference rolls back. Files are copied to new random names, never shared. The artisan command wraps it with dry-run default, `--force`, confirmation and a safety backup.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL 8 (CRC32/CONCAT_WS in fingerprints — MySQL only, same as the rest of the app), PHPUnit on `boothpos_test`.

**Spec:** `docs/superpowers/specs/2026-10-11-clone-live-to-demo-design.md` (read it first; this plan implements it, including the 2026-10-11 corrections to §3 and §5).

## Global Constraints

- **LIVE rows and files are read-only for this feature.** Every read of source rows uses `data_mode = 'live'`; every write sets `data_mode = 'demo'`; every DELETE/UPDATE is filtered `data_mode = 'demo'`. Never rely on the `DataModeScope` (it follows the *active* mode) — use `DB::table()` + `CloneRegistry::scope()`.
- **Tests run ONLY on `boothpos_test`.** Run them with: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<X>`. Never run `php artisan test` inside the container without those `-e` flags (it wiped the dev DB twice — see CLAUDE.md). Never call a real `mysqldump`/restore from a test: bind `Tests\Support\FakeDatabaseDumper`.
- **No PII in output or logs.** Error text may contain table/column/id only. Fixtures use synthetic values (`*@example.test`).
- Unique-value rules (spec §5): artist code (3 letters) and category code (2 letters) are **allocated**, product prefix/SKU are **rebuilt from them**, vendor/material `code` and the three document numbers get `-DM`, `local_ref`/`client_ref`/`proof_token` get a new UUID.
- Code comments, commit messages in **Indonesian** (CLAUDE.md convention); comments explain *why*. Commits end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- No schema change, no migration, no route, so no OpenAPI change. Do NOT touch `companies`, `invoices`, `pos_drafts`, `users`, `payment_channels`, `settings`.
- Work on a feature branch: `git checkout -b 041-clone-live-to-demo develop`.

## Review Focus

Inputs/conditions the spec implies but the obvious tests would miss, most likely first. Each has a test in the task that owns the code:

1. **Active mode is `demo` while the command runs** (`system_mode=demo`): the global scope would invert and the wipe could target live rows if any query leaned on it. → Task 6 `test_result_is_identical_when_the_active_mode_is_demo`.
2. **Soft-deleted LIVE rows** (9 tables have `deleted_at`): must be mirrored, and their codes still block allocation. → Task 5 `test_soft_deleted_live_rows_are_cloned_as_soft_deleted`, Task 3 `test_soft_deleted_codes_count_as_taken`.
3. **Two live artists whose first two letters match** (`VLC`, `VLD`): naive "Z + first two letters" collides. → Task 3 `test_two_live_artists_sharing_two_letters_get_different_codes`.
4. **Missing source file / file shared by several rows / demo row already pointing at a live file**: must not abort, must not duplicate, and the wipe must never delete a live-referenced file. → Task 4 and Task 6 file tests.
5. **A failed run and a second run**: failure after files were copied must leave demo and files exactly as before; running twice must not duplicate. → Task 6 `test_failure_after_copy_restores_demo_and_removes_copied_files`, `test_running_twice_gives_the_same_result`; PII never reaches the console → Task 7 `SafeErrorTest`.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Services/DemoClone/CloneException.php` | Business failure. Message may name table/column/id, never row values |
| `app/Services/DemoClone/CloneRegistry.php` | The one list of 24 tables: order, FKs, self-FKs, unique rules, file columns, LIVE/DEMO row scope SQL |
| `app/Services/DemoClone/LiveFingerprint.php` | `count + SUM(CRC32(row))` per table over LIVE rows (+ shared tables, + tables a wipe can reach); `diff()` |
| `app/Services/DemoClone/CodeAllocator.php` | Artist/category code allocation, product prefix + SKU rebuild, `-DM` suffix |
| `app/Services/DemoClone/FileCopier.php` | Copy a stored file to a fresh random name (dedupe per run, count, rollback cleanup), `measure()` for the dry run |
| `app/Services/DemoClone/LiveToDemoCloner.php` | `plan()` (dry run numbers) and `run()` (transaction: fingerprint → wipe → clone → verify → log) |
| `app/Services/DemoClone/SafeError.php` | Turns any `Throwable` into a PII-free one-line message |
| `app/Console/Commands/CloneLiveToDemo.php` | CLI: dry run, `--force`, `--yes`, guards, safety backup, report |
| `tests/Support/BuildsCloneFixtures.php` | Builds a complete synthetic data graph (all 24 tables + files) in a given mode |
| `tests/Feature/DemoClone/*Test.php` | One test class per unit above |
| `docs/RUNBOOK.md`, `CLAUDE.md` | Operator docs and the one-paragraph project note |

---

### Task 1: Test fixture — a complete synthetic data graph

**Files:**
- Create: `tests/Support/BuildsCloneFixtures.php`
- Create: `tests/Feature/DemoClone/CloneFixturesTest.php`

**Interfaces:**
- Produces: trait `Tests\Support\BuildsCloneFixtures` with `buildGraph(string $mode): array` (mode `'live'|'demo'`; returns ids keyed `user, channel, event, artist, category, parentCategory, vendor, material, customer, product, variant1, variant2, vmp, po, poItem, bom, session, cash, order, orderItem, preorderA, preorderB, preorderItem, shipment, cashPayment, bankPayment, poPayment, proof, stockSale, stockPurchase, stockAdjust, stockReturn, matStock, settlement`), and private `row(string $table, array $attrs): int` (insertGetId, adds `created_at`/`updated_at` when the table has them). `buildGraph` also writes the image/proof files to the fake disks — the test class must call `Storage::fake('public')` and `Storage::fake('local')` in `setUp()` first.

The graph per mode (codes differ per mode because `artists.code`/`categories.code`/`vendors.code`/… are unique across modes): live artist `LIV`, categories `LC` (child) / `LP` (parent), segment `ABC`; demo artist `DMO`, `DC` / `DP`, `XYZ`.

- [ ] **Step 1: Create the fixture trait**

```php
<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Membangun satu graf data sintetis LENGKAP (24 tabel + berkas) pada mode
 * tertentu lewat DB::table() — sengaja BUKAN lewat model/service supaya
 * data_mode, created_at, dan nilai unik bisa ditentukan tepat, dan
 * supaya test tidak bergantung pada aturan bisnis yang bukan urusan
 * fitur salin LIVE→DEMO. Semua nilai sintetis (tanpa PII asli).
 *
 * Pemanggil WAJIB memanggil Storage::fake('public') dan
 * Storage::fake('local') di setUp() sebelum buildGraph().
 */
trait BuildsCloneFixtures
{
    private ?int $cloneUserId = null;

    private ?int $cloneChannelId = null;

    private function row(string $table, array $attrs): int
    {
        foreach (['created_at', 'updated_at'] as $col) {
            if (! array_key_exists($col, $attrs) && Schema::hasColumn($table, $col)) {
                $attrs[$col] = now();
            }
        }

        return DB::table($table)->insertGetId($attrs);
    }

    private function cloneUserId(): int
    {
        return $this->cloneUserId ??= User::factory()->create()->id;
    }

    private function cloneChannelId(): int
    {
        return $this->cloneChannelId ??= $this->row('payment_channels', [
            'type' => 'bank_transfer',
            'provider' => 'TESTBANK',
            'account_name' => 'Test Account',
        ]);
    }

    /** @return array<string,int> */
    private function buildGraph(string $mode): array
    {
        $tag = strtoupper($mode);
        $c = $mode === 'live'
            ? ['artist' => 'LIV', 'cat' => 'LC', 'parent' => 'LP', 'seg' => 'ABC']
            : ['artist' => 'DMO', 'cat' => 'DC', 'parent' => 'DP', 'seg' => 'XYZ'];
        $m = ['data_mode' => $mode];
        $g = [];

        $disk = Storage::disk('public');
        $disk->put("products/{$mode}-product.jpg", "{$mode}-product-bytes");
        $disk->put("products/{$mode}-variant.jpg", "{$mode}-variant-bytes");
        $disk->put("categories/{$mode}-category.jpg", "{$mode}-category-bytes");
        Storage::disk('local')->put("payment-proofs/{$mode}-proof.jpg", "{$mode}-proof-bytes");

        $g['user'] = $this->cloneUserId();
        $g['channel'] = $this->cloneChannelId();

        $g['event'] = $this->row('events', $m + ['name' => "Event {$tag}", 'start_date' => '2026-10-01', 'end_date' => '2026-10-02']);
        $g['artist'] = $this->row('artists', $m + ['code' => $c['artist'], 'name' => "Artist {$tag}"]);

        // Anak dibuat SEBELUM induk lalu ditautkan, supaya id anak < id induk
        // dan penambalan self-FK (parent_id) benar-benar teruji.
        $g['category'] = $this->row('categories', $m + ['code' => $c['cat'], 'name' => "Category {$tag}", 'image_path' => "categories/{$mode}-category.jpg"]);
        $g['parentCategory'] = $this->row('categories', $m + ['code' => $c['parent'], 'name' => "Parent {$tag}"]);
        DB::table('categories')->where('id', $g['category'])->update(['parent_id' => $g['parentCategory']]);

        $g['vendor'] = $this->row('vendors', $m + ['code' => "VND-{$tag}", 'name' => "Vendor {$tag}"]);
        $g['material'] = $this->row('materials', $m + ['code' => "MAT-{$tag}", 'name' => "Material {$tag}", 'unit' => 'pcs']);
        $g['customer'] = $this->row('customers', $m + [
            'name' => "Customer {$tag}",
            'phone' => $mode === 'live' ? '+62-000-LIVE-0001' : '+62-000-DEMO-0001',
            'email' => "{$mode}.customer@example.test",
        ]);

        $g['product'] = $this->row('products', $m + [
            'artist_id' => $g['artist'], 'category_id' => $g['category'],
            'code_prefix' => "{$c['artist']}-{$c['cat']}-{$c['seg']}", 'product_segment' => $c['seg'],
            'name' => "Product {$tag}", 'image_path' => "products/{$mode}-product.jpg",
        ]);
        $g['variant1'] = $this->row('product_variants', $m + [
            'product_id' => $g['product'], 'sku' => "{$c['artist']}-{$c['cat']}-{$c['seg']}-001",
            'sell_price' => '10000.00', 'current_stock' => 4, 'image_path' => "products/{$mode}-variant.jpg",
        ]);
        $g['variant2'] = $this->row('product_variants', $m + [
            'product_id' => $g['product'], 'sku' => "{$c['artist']}-{$c['cat']}-{$c['seg']}-002",
            'sell_price' => '12000.00', 'current_stock' => 0,
        ]);

        $g['vmp'] = $this->row('vendor_material_prices', $m + ['vendor_id' => $g['vendor'], 'material_id' => $g['material'], 'price' => '1500.00']);
        $g['po'] = $this->row('purchase_orders', $m + [
            'po_number' => "PO-{$tag}-0001", 'vendor_id' => $g['vendor'], 'created_by' => $g['user'],
            'artist_id' => $g['artist'], 'status' => 'ordered',
        ]);
        // purchase_order_items tidak punya data_mode: ikut PO induknya.
        $g['poItem'] = $this->row('purchase_order_items', [
            'purchase_order_id' => $g['po'], 'material_id' => $g['material'], 'line_type' => 'material',
            'qty' => '2.00', 'unit_price' => '1500.00', 'line_total' => '3000.00',
        ]);
        $g['bom'] = $this->row('product_variant_bom_lines', $m + [
            'product_variant_id' => $g['variant1'], 'material_id' => $g['material'], 'vendor_id' => $g['vendor'],
            'purchase_order_item_id' => $g['poItem'], 'line_type' => 'material', 'qty_needed' => '2.0000',
        ]);

        $g['session'] = $this->row('cashier_sessions', $m + ['event_id' => $g['event'], 'user_id' => $g['user'], 'opened_at' => now()]);
        $g['cash'] = $this->row('session_opening_cash_entries', $m + ['session_id' => $g['session'], 'artist_id' => $g['artist'], 'amount' => '50000.00']);

        $g['order'] = $this->row('orders', $m + [
            'order_number' => "TRX-{$tag}-0001", 'event_id' => $g['event'], 'session_id' => $g['session'],
            'user_id' => $g['user'], 'customer_id' => $g['customer'], 'local_ref' => (string) Str::uuid(),
        ]);
        $g['orderItem'] = $this->row('order_items', $m + [
            'order_id' => $g['order'], 'variant_id' => $g['variant1'], 'artist_id' => $g['artist'],
            'sku_snapshot' => "{$c['artist']}-{$c['cat']}-{$c['seg']}-001", 'name_snapshot' => "Product {$tag}",
            'qty' => 1, 'cost_price' => '5000.00', 'sell_price' => '10000.00', 'line_total' => '10000.00',
        ]);

        // preorderB dibuat dulu (id lebih kecil) lalu menunjuk preorderA: uji self-FK.
        $g['preorderB'] = $this->row('preorders', $m + [
            'preorder_number' => "PRE-{$tag}-0002", 'customer_id' => $g['customer'], 'user_id' => $g['user'], 'event_id' => $g['event'],
        ]);
        $g['preorderA'] = $this->row('preorders', $m + [
            'preorder_number' => "PRE-{$tag}-0001", 'customer_id' => $g['customer'], 'user_id' => $g['user'], 'event_id' => $g['event'],
        ]);
        DB::table('preorders')->where('id', $g['preorderB'])->update([
            'source_preorder_id' => $g['preorderA'], 'source_type' => 'split', 'source_preorder_number' => "PRE-{$tag}-0001",
        ]);
        $g['preorderItem'] = $this->row('preorder_items', $m + [
            'preorder_id' => $g['preorderA'], 'variant_id' => $g['variant2'], 'artist_id' => $g['artist'],
            'sku_snapshot' => "{$c['artist']}-{$c['cat']}-{$c['seg']}-002", 'name_snapshot' => "Product {$tag}",
            'qty' => 2, 'cost_price' => '6000.00', 'sell_price' => '12000.00', 'line_total' => '24000.00',
        ]);
        $g['shipment'] = $this->row('shipments', $m + [
            'preorder_id' => $g['preorderA'], 'courier_name' => 'JNE', 'recipient_name' => "Recipient {$tag}",
            'recipient_phone' => '+62-000-RCPT-0001', 'address_line' => 'Jl. Contoh No. 1',
        ]);

        // CHECK constraints payments: tunai tanpa channel; non-tunai wajib channel; tepat satu target.
        $g['cashPayment'] = $this->row('payments', $m + [
            'order_id' => $g['order'], 'session_id' => $g['session'], 'method' => 'cash', 'amount' => '10000.00',
            'paid_at' => now(), 'client_ref' => (string) Str::uuid(),
        ]);
        $g['bankPayment'] = $this->row('payments', $m + [
            'preorder_id' => $g['preorderA'], 'method' => 'bank_transfer', 'channel_id' => $g['channel'], 'amount' => '8000.00',
            'paid_at' => now(), 'client_ref' => (string) Str::uuid(),
        ]);
        $g['poPayment'] = $this->row('payments', $m + [
            'purchase_order_id' => $g['po'], 'method' => 'bank_transfer', 'channel_id' => $g['channel'], 'amount' => '3000.00',
            'paid_at' => now(), 'client_ref' => (string) Str::uuid(),
        ]);
        $g['proof'] = $this->row('payment_proofs', $m + [
            'payment_id' => $g['bankPayment'], 'proof_token' => (string) Str::uuid(), 'file_path' => "payment-proofs/{$mode}-proof.jpg",
            'mime_type' => 'image/jpeg', 'file_size' => 16, 'captured_via' => 'upload',
        ]);

        // reference_id berarti hal berbeda per jenis (lihat CLAUDE.md, fitur 036).
        $g['stockSale'] = $this->row('stock_movements', $m + [
            'variant_id' => $g['variant1'], 'type' => 'sale', 'qty_change' => -1, 'stock_before' => 5, 'stock_after' => 4,
            'reference_type' => 'order_item', 'reference_id' => $g['order'],
        ]);
        $g['stockPurchase'] = $this->row('stock_movements', $m + [
            'variant_id' => $g['variant2'], 'type' => 'purchase', 'qty_change' => 2, 'stock_before' => 0, 'stock_after' => 2,
            'reference_type' => 'preorder_item', 'reference_id' => $g['preorderItem'],
        ]);
        $g['stockAdjust'] = $this->row('stock_movements', $m + [
            'variant_id' => $g['variant1'], 'type' => 'adjustment', 'qty_change' => 5, 'stock_before' => 0, 'stock_after' => 5,
            'reference_type' => 'MasterDataImport', 'reference_id' => null,
        ]);
        $g['stockReturn'] = $this->row('stock_movements', $m + [
            'variant_id' => $g['variant1'], 'type' => 'return', 'qty_change' => 0, 'stock_before' => 4, 'stock_after' => 4,
            'reference_type' => 'order_item', 'reference_id' => $g['orderItem'],
        ]);
        $g['matStock'] = $this->row('material_stock_movements', $m + [
            'material_id' => $g['material'], 'type' => 'purchase', 'qty_change' => '2.00', 'stock_before' => '0.00', 'stock_after' => '2.00',
            'reference_type' => 'purchase_order_item', 'reference_id' => $g['poItem'],
        ]);
        $g['settlement'] = $this->row('artist_settlements', $m + ['event_id' => $g['event'], 'artist_id' => $g['artist']]);

        return $g;
    }
}
```

- [ ] **Step 2: Write the smoke test**

```php
<?php

namespace Tests\Feature\DemoClone;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

/**
 * Memastikan fixture itu sendiri valid terhadap skema MySQL sungguhan
 * (CHECK constraint payments, kolom NOT NULL, FK) — kalau ini gagal,
 * test fitur salin tidak bermakna.
 */
class CloneFixturesTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_live_and_demo_graphs_can_be_built_side_by_side(): void
    {
        $live = $this->buildGraph('live');
        $demo = $this->buildGraph('demo');

        $this->assertNotSame($live['order'], $demo['order']);
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'live')->count());
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'demo')->count());
        $this->assertSame(2, DB::table('preorders')->where('data_mode', 'live')->count());
        $this->assertSame(2, DB::table('categories')->where('data_mode', 'live')->count());
        $this->assertSame(3, DB::table('payments')->where('data_mode', 'live')->count());
        $this->assertSame(1, DB::table('purchase_order_items')->where('purchase_order_id', $live['po'])->count());
        Storage::disk('public')->assertExists('products/live-product.jpg');
        Storage::disk('local')->assertExists('payment-proofs/demo-proof.jpg');
    }
}
```

- [ ] **Step 3: Run it**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=CloneFixturesTest`
Expected: PASS. If a `QueryException` names a NOT NULL column or a CHECK constraint, fix **the fixture row** (add the column named in the error) — do not weaken the schema. Re-run until green.

- [ ] **Step 4: Commit**

```bash
git add tests/Support/BuildsCloneFixtures.php tests/Feature/DemoClone/CloneFixturesTest.php
git commit -m "test(demo-clone): fixture graf data sintetis lengkap untuk uji salin LIVE→DEMO" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `CloneException`, `CloneRegistry`, `LiveFingerprint`

**Files:**
- Create: `app/Services/DemoClone/CloneException.php`
- Create: `app/Services/DemoClone/CloneRegistry.php`
- Create: `app/Services/DemoClone/LiveFingerprint.php`
- Test: `tests/Feature/DemoClone/LiveFingerprintTest.php`

**Interfaces:**
- Produces:
  - `CloneException extends \RuntimeException`
  - `CloneRegistry::tables(): array<string,array>` — ordered `table => spec`, every spec has all keys `fks, deferred, suffix, uuid, files, required_files, via`
  - `CloneRegistry::spec(string $table): array`
  - `CloneRegistry::scope(string $table, string $mode): string` — SQL fragment (`'live'|'demo'` only, else `InvalidArgumentException`)
  - `CloneRegistry::references(string $table): array<string,string>` — `fks + deferred` (column ⇒ parent table)
  - `LiveFingerprint::capture(): array<string,array{count:int,crc:string}>`
  - `LiveFingerprint::diff(array $before, array $after): list<string>` — table names that differ

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneRegistry;
use App\Services\DemoClone\LiveFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class LiveFingerprintTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_capture_is_stable_when_nothing_changes(): void
    {
        $this->buildGraph('live');

        $this->assertSame(LiveFingerprint::capture(), LiveFingerprint::capture());
    }

    public function test_changing_a_live_row_is_detected(): void
    {
        $g = $this->buildGraph('live');
        $before = LiveFingerprint::capture();

        DB::table('customers')->where('id', $g['customer'])->update(['name' => 'Changed']);

        $this->assertSame(['customers'], LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }

    public function test_changing_demo_rows_is_not_detected(): void
    {
        $this->buildGraph('live');
        $demo = $this->buildGraph('demo');
        $before = LiveFingerprint::capture();

        DB::table('customers')->where('id', $demo['customer'])->update(['name' => 'Changed']);
        DB::table('artists')->where('id', $demo['artist'])->update(['name' => 'Renamed']);

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }

    public function test_a_table_without_data_mode_is_covered_through_its_purchase_order(): void
    {
        $g = $this->buildGraph('live');
        $before = LiveFingerprint::capture();

        DB::table('purchase_order_items')->where('id', $g['poItem'])->update(['qty' => '9.00']);

        $this->assertContains('purchase_order_items', LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }

    public function test_shared_tables_and_cascade_targets_are_covered(): void
    {
        $g = $this->buildGraph('live');
        $before = LiveFingerprint::capture();

        DB::table('users')->where('id', $g['user'])->update(['updated_at' => now()->addDay()]);

        $this->assertContains('users', LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertArrayHasKey('preorder_notifications', $before);
        $this->assertArrayHasKey('pos_drafts', $before);
    }

    public function test_registry_is_in_fk_order_and_scopes_are_mode_safe(): void
    {
        $tables = array_keys(CloneRegistry::tables());

        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach (CloneRegistry::references($table) as $col => $parent) {
                $this->assertLessThanOrEqual(
                    array_search($table, $tables, true),
                    array_search($parent, $tables, true),
                    "{$table}.{$col} -> {$parent} harus induk lebih dulu (atau self-FK)",
                );
            }
        }

        $this->assertSame("data_mode = 'live'", CloneRegistry::scope('orders', 'live'));
        $this->assertStringContainsString("purchase_orders WHERE data_mode = 'demo'", CloneRegistry::scope('purchase_order_items', 'demo'));
        $this->expectException(\InvalidArgumentException::class);
        CloneRegistry::scope('orders', "live' OR 1=1 --");
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=LiveFingerprintTest`
Expected: FAIL — `Class "App\Services\DemoClone\LiveFingerprint" not found`.

- [ ] **Step 3: Implement the three classes**

`app/Services/DemoClone/CloneException.php`:

```php
<?php

namespace App\Services\DemoClone;

use RuntimeException;

/**
 * Kegagalan bisnis penyalinan LIVE→DEMO. Pesan HANYA boleh memuat nama
 * tabel/kolom/id — tidak pernah nilai baris (bisa berisi PII pelanggan).
 */
class CloneException extends RuntimeException {}
```

`app/Services/DemoClone/CloneRegistry.php`:

```php
<?php

namespace App\Services\DemoClone;

use InvalidArgumentException;

/**
 * Daftar tabel yang disalin LIVE → DEMO, urut FK (induk dulu). Satu-satunya
 * tempat yang tahu bentuk graf data untuk fitur ini: cloner, fingerprint,
 * dan pemeriksaan integritas semuanya membaca dari sini.
 *
 * Kunci spec:
 *  fks             kolom => tabel induk; nilai dipetakan ke id baru. Kolom ke
 *                  users/payment_channels TIDAK di sini (dibagi, nilai tetap).
 *  deferred        kolom self-FK: di-insert NULL lalu ditambal setelah tabel selesai.
 *  suffix          kolom => lebar maks; nilai diberi akhiran "-DM".
 *  uuid            kolom yang diganti UUID baru (bila tidak NULL).
 *  files           kolom => disk; berkasnya DISALIN, tidak pernah dibagi.
 *  required_files  kolom berkas NOT NULL: baris dilewati bila berkas sumber hilang.
 *  via             "induk.kolom" untuk tabel tanpa kolom data_mode (ikut induknya).
 */
final class CloneRegistry
{
    private const DEFAULTS = [
        'fks' => [],
        'deferred' => [],
        'suffix' => [],
        'uuid' => [],
        'files' => [],
        'required_files' => [],
        'via' => null,
    ];

    /** @return array<string,array> */
    public static function tables(): array
    {
        $raw = [
            'events' => [],
            'artists' => [],
            'categories' => ['deferred' => ['parent_id' => 'categories'], 'files' => ['image_path' => 'public']],
            'vendors' => ['suffix' => ['code' => 20]],
            'materials' => ['suffix' => ['code' => 20]],
            'customers' => [],
            'products' => [
                'fks' => ['artist_id' => 'artists', 'category_id' => 'categories'],
                'files' => ['image_path' => 'public'],
            ],
            'product_variants' => ['fks' => ['product_id' => 'products'], 'files' => ['image_path' => 'public']],
            'vendor_material_prices' => ['fks' => ['vendor_id' => 'vendors', 'material_id' => 'materials']],
            'purchase_orders' => [
                'fks' => ['vendor_id' => 'vendors', 'artist_id' => 'artists'],
                'suffix' => ['po_number' => 30],
            ],
            'purchase_order_items' => [
                'fks' => ['purchase_order_id' => 'purchase_orders', 'material_id' => 'materials', 'product_id' => 'products'],
                'via' => 'purchase_orders.purchase_order_id',
            ],
            'product_variant_bom_lines' => [
                'fks' => [
                    'product_variant_id' => 'product_variants', 'material_id' => 'materials',
                    'vendor_id' => 'vendors', 'purchase_order_item_id' => 'purchase_order_items',
                ],
            ],
            'cashier_sessions' => ['fks' => ['event_id' => 'events']],
            'session_opening_cash_entries' => ['fks' => ['session_id' => 'cashier_sessions', 'artist_id' => 'artists']],
            'orders' => [
                'fks' => ['event_id' => 'events', 'session_id' => 'cashier_sessions', 'customer_id' => 'customers'],
                'suffix' => ['order_number' => 30],
                'uuid' => ['local_ref'],
            ],
            'order_items' => ['fks' => ['order_id' => 'orders', 'variant_id' => 'product_variants', 'artist_id' => 'artists']],
            'preorders' => [
                'fks' => ['customer_id' => 'customers', 'event_id' => 'events'],
                'deferred' => ['source_preorder_id' => 'preorders'],
                'suffix' => ['preorder_number' => 30],
            ],
            'preorder_items' => ['fks' => ['preorder_id' => 'preorders', 'variant_id' => 'product_variants', 'artist_id' => 'artists']],
            'shipments' => ['fks' => ['preorder_id' => 'preorders']],
            'payments' => [
                'fks' => [
                    'order_id' => 'orders', 'preorder_id' => 'preorders',
                    'purchase_order_id' => 'purchase_orders', 'session_id' => 'cashier_sessions',
                ],
                'uuid' => ['client_ref'],
            ],
            'payment_proofs' => [
                'fks' => ['payment_id' => 'payments'],
                'uuid' => ['proof_token'],
                'files' => ['file_path' => 'local'],
                'required_files' => ['file_path'],
            ],
            'stock_movements' => ['fks' => ['variant_id' => 'product_variants']],
            'material_stock_movements' => ['fks' => ['material_id' => 'materials']],
            'artist_settlements' => ['fks' => ['event_id' => 'events', 'artist_id' => 'artists']],
        ];

        return array_map(fn (array $spec) => $spec + self::DEFAULTS, $raw);
    }

    public static function spec(string $table): array
    {
        return self::tables()[$table] ?? throw new InvalidArgumentException("Tabel {$table} tidak ada di registry.");
    }

    /**
     * Fragmen WHERE yang memilih baris satu mode. $mode hanya boleh konstanta
     * 'live'/'demo' — string ini disisipkan ke SQL, jadi dikunci.
     */
    public static function scope(string $table, string $mode): string
    {
        if (! in_array($mode, ['live', 'demo'], true)) {
            throw new InvalidArgumentException('Mode harus live atau demo.');
        }

        $via = self::spec($table)['via'];

        if ($via === null) {
            return "data_mode = '{$mode}'";
        }

        [$parent, $column] = explode('.', $via);

        return "{$column} IN (SELECT id FROM {$parent} WHERE data_mode = '{$mode}')";
    }

    /** @return array<string,string> kolom => tabel induk (FK biasa + self-FK) */
    public static function references(string $table): array
    {
        $spec = self::spec($table);

        return $spec['fks'] + $spec['deferred'];
    }
}
```

`app/Services/DemoClone/LiveFingerprint.php`:

```php
<?php

namespace App\Services\DemoClone;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sidik jari data LIVE: jumlah baris + SUM(CRC32(seluruh kolom)) per tabel.
 * Dibaca SEBELUM dan SESUDAH penyalinan di dalam transaksi yang sama —
 * selisih berarti perintah ini menyentuh LIVE, dan seluruhnya dibatalkan.
 *
 * Selain tabel yang disalin, ikut dijaga: tabel bersama (users, roles,
 * payment_channels, settings) serta dua tabel yang bisa tersentuh oleh
 * penghapusan DEMO lewat FK tanpa ikut disalin — preorder_notifications
 * (CASCADE dari preorders) dan pos_drafts (SET NULL dari events).
 */
final class LiveFingerprint
{
    private const SHARED = ['users', 'roles', 'payment_channels', 'settings'];

    /** @return array<string,array{count:int,crc:string}> */
    public static function capture(): array
    {
        $out = [];

        foreach (array_keys(CloneRegistry::tables()) as $table) {
            $out[$table] = self::of($table, CloneRegistry::scope($table, 'live'));
        }

        $out['preorder_notifications'] = self::of(
            'preorder_notifications',
            "preorder_id IN (SELECT id FROM preorders WHERE data_mode = 'live')",
        );
        $out['pos_drafts'] = self::of('pos_drafts', "data_mode = 'live'");

        foreach (self::SHARED as $table) {
            $out[$table] = self::of($table, '1 = 1');
        }

        return $out;
    }

    /** @return list<string> nama tabel yang berbeda */
    public static function diff(array $before, array $after): array
    {
        return array_values(array_keys(array_filter(
            $before,
            fn (array $fingerprint, string $table) => $fingerprint !== ($after[$table] ?? null),
            ARRAY_FILTER_USE_BOTH,
        )));
    }

    /** @return array{count:int,crc:string} */
    private static function of(string $table, string $where): array
    {
        $columns = array_map(
            fn (string $column) => "IFNULL(CAST(`{$column}` AS CHAR), '<NULL>')",
            Schema::getColumnListing($table),
        );

        $row = DB::selectOne(
            "SELECT COUNT(*) AS c, IFNULL(SUM(CRC32(CONCAT_WS('|', ".implode(', ', $columns)."))), 0) AS s FROM `{$table}` WHERE {$where}"
        );

        return ['count' => (int) $row->c, 'crc' => (string) $row->s];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=LiveFingerprintTest`
Expected: PASS (6 tests). If `test_registry_is_in_fk_order…` fails, fix the **registry order**, not the test.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DemoClone tests/Feature/DemoClone/LiveFingerprintTest.php
git commit -m "feat(demo-clone): registry tabel, sidik jari data LIVE, dan CloneException" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `CodeAllocator` — artist/category codes, product prefix, SKU, `-DM`

**Files:**
- Create: `app/Services/DemoClone/CodeAllocator.php`
- Test: `tests/Feature/DemoClone/CodeAllocatorTest.php`

**Interfaces:**
- Consumes: `CloneException`.
- Produces: `new CodeAllocator()` (reads every existing `artists.code` / `categories.code` of BOTH modes incl. soft-deleted — so construct it AFTER the demo wipe), with `artist(string $liveCode): string`, `category(string $liveCode): string`, `productPrefix(string $livePrefix, int $productId): string`, `sku(string $liveSku, string $livePrefix, string $demoPrefix, int $variantId): string`, `suffixed(string $value, int $max, string $where): string`, `mapping(): array{artists: array<string,string>, categories: array<string,string>}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\CodeAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class CodeAllocatorTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    public function test_artist_code_is_z_plus_the_first_two_letters(): void
    {
        $this->assertSame('ZVL', (new CodeAllocator)->artist('VLC'));
    }

    public function test_taken_candidates_are_skipped(): void
    {
        $this->row('artists', ['code' => 'ZVL', 'name' => 'x', 'data_mode' => 'live']);
        $this->row('artists', ['code' => 'YVL', 'name' => 'x', 'data_mode' => 'demo']);

        $this->assertSame('XVL', (new CodeAllocator)->artist('VLC'));
    }

    public function test_soft_deleted_codes_count_as_taken(): void
    {
        $this->row('artists', ['code' => 'ZVL', 'name' => 'x', 'data_mode' => 'live', 'deleted_at' => now()]);

        $this->assertSame('YVL', (new CodeAllocator)->artist('VLC'));
    }

    public function test_two_live_artists_sharing_two_letters_get_different_codes(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('ZVL', $allocator->artist('VLC'));
        $this->assertSame('YVL', $allocator->artist('VLD'));
    }

    public function test_mapping_is_memoized_and_case_insensitive(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('ZVL', $allocator->artist('VLC'));
        $this->assertSame('ZVL', $allocator->artist('vlc'));
        $this->assertSame(['VLC' => 'ZVL'], $allocator->mapping()['artists']);
    }

    public function test_category_code_is_z_plus_the_first_letter(): void
    {
        $this->assertSame('ZK', (new CodeAllocator)->category('KC'));
    }

    public function test_product_prefix_is_rebuilt_from_the_two_maps(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('ZVL-ZK-LOV', $allocator->productPrefix('VLC-KC-LOV', 7));
        $this->assertSame('ZVL', $allocator->artist('VLC'));
        $this->assertSame('ZK', $allocator->category('KC'));
    }

    public function test_a_prefix_with_the_wrong_shape_aborts_with_the_product_id(): void
    {
        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('products id 9');

        (new CodeAllocator)->productPrefix('VLCKCLOV', 9);
    }

    public function test_sku_keeps_its_numeric_tail_under_the_new_prefix(): void
    {
        $this->assertSame('ZVL-ZK-LOV-001', (new CodeAllocator)->sku('VLC-KC-LOV-001', 'VLC-KC-LOV', 'ZVL-ZK-LOV', 3));
    }

    public function test_a_sku_that_does_not_start_with_its_product_prefix_aborts(): void
    {
        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('product_variants id 3');

        (new CodeAllocator)->sku('OTHER-SKU-001', 'VLC-KC-LOV', 'ZVL-ZK-LOV', 3);
    }

    public function test_suffix_is_appended_and_overflow_aborts_instead_of_truncating(): void
    {
        $allocator = new CodeAllocator;

        $this->assertSame('BC-001-DM', $allocator->suffixed('BC-001', 20, 'materials.code id 1'));

        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('materials.code id 2');
        $allocator->suffixed('ABCDEFGHIJKLMNOPQR', 20, 'materials.code id 2');
    }

    public function test_it_aborts_when_all_26_candidates_are_taken(): void
    {
        foreach (range('A', 'Z') as $letter) {
            $this->row('artists', ['code' => $letter.'VL', 'name' => 'x', 'data_mode' => 'live']);
        }

        $this->expectException(CloneException::class);
        (new CodeAllocator)->artist('VLC');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=CodeAllocatorTest`
Expected: FAIL — `Class "App\Services\DemoClone\CodeAllocator" not found`.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\DemoClone;

use Illuminate\Support\Facades\DB;

/**
 * Kode unik untuk baris DEMO hasil salinan. Lebar kolom menentukan aturan:
 * artists.code = char(3) dan categories.code = char(2), jadi akhiran "-DM"
 * tidak muat. Keduanya DIALOKASIKAN dari kode yang belum dipakai (lintas
 * mode, termasuk soft-deleted — UNIQUE DB tidak peduli deleted_at), lalu
 * code_prefix produk ("AAA-BB-CCC") dan SKU ("AAA-BB-CCC-NNN") disusun
 * ulang dari pemetaan itu supaya bentuknya tetap valid.
 *
 * Bangun SESUDAH data DEMO lama dihapus: kode milik DEMO lama sudah bebas.
 */
final class CodeAllocator
{
    private const LETTERS = ['Z', 'Y', 'X', 'W', 'V', 'U', 'T', 'S', 'R', 'Q', 'P', 'O', 'N',
        'M', 'L', 'K', 'J', 'I', 'H', 'G', 'F', 'E', 'D', 'C', 'B', 'A'];

    /** @var array<string,array<string,string>> */
    private array $map = ['artists' => [], 'categories' => []];

    /** @var array<string,array<string,true>> */
    private array $used = ['artists' => [], 'categories' => []];

    public function __construct()
    {
        foreach (['artists', 'categories'] as $table) {
            foreach (DB::table($table)->pluck('code') as $code) {
                $this->used[$table][strtoupper((string) $code)] = true;
            }
        }
    }

    public function artist(string $liveCode): string
    {
        return $this->allocate('artists', $liveCode, 3);
    }

    public function category(string $liveCode): string
    {
        return $this->allocate('categories', $liveCode, 2);
    }

    public function productPrefix(string $livePrefix, int $productId): string
    {
        if (! preg_match('/^([A-Za-z]{3})-([A-Za-z]{2})-([A-Za-z]{3})$/', $livePrefix, $m)) {
            throw new CloneException("products id {$productId}: code_prefix tidak berbentuk AAA-BB-CCC.");
        }

        // Tiap bagian prefix dicari lewat peta kode LIVE-nya sendiri (bukan lewat
        // artist/kategori produk sekarang): kode bersifat permanen (F19.4), jadi
        // artist/kategori produk bisa saja sudah berganti setelah prefix dibuat.
        return $this->artist($m[1]).'-'.$this->category($m[2]).'-'.strtoupper($m[3]);
    }

    public function sku(string $liveSku, string $livePrefix, string $demoPrefix, int $variantId): string
    {
        if (! str_starts_with($liveSku, $livePrefix.'-')) {
            throw new CloneException("product_variants id {$variantId}: sku tidak diawali code_prefix produknya.");
        }

        return $demoPrefix.substr($liveSku, strlen($livePrefix));
    }

    public function suffixed(string $value, int $max, string $where): string
    {
        $out = $value.'-DM';

        if (strlen($out) > $max) {
            throw new CloneException("{$where}: terlalu panjang untuk akhiran -DM (maks {$max}).");
        }

        return $out;
    }

    /** @return array{artists:array<string,string>,categories:array<string,string>} */
    public function mapping(): array
    {
        return $this->map;
    }

    private function allocate(string $table, string $liveCode, int $width): string
    {
        $key = strtoupper($liveCode);

        if (isset($this->map[$table][$key])) {
            return $this->map[$table][$key];
        }

        $tail = substr($key, 0, $width - 1);

        foreach (self::LETTERS as $letter) {
            $candidate = $letter.$tail;

            if (! isset($this->used[$table][$candidate])) {
                $this->used[$table][$candidate] = true;

                return $this->map[$table][$key] = $candidate;
            }
        }

        throw new CloneException("Tidak ada kode {$table} kosong untuk menggantikan {$key}.");
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=CodeAllocatorTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DemoClone/CodeAllocator.php tests/Feature/DemoClone/CodeAllocatorTest.php
git commit -m "feat(demo-clone): alokasi kode artist/kategori dan susun ulang prefix produk + SKU" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `FileCopier` — copy files, never share them

**Files:**
- Create: `app/Services/DemoClone/FileCopier.php`
- Test: `tests/Feature/DemoClone/FileCopierTest.php`

**Interfaces:**
- Consumes: `CloneException`.
- Produces: `new FileCopier()` with `copy(string $disk, ?string $path): ?string` (new path, or `null` for null/''/missing source), `discardCopies(): void`, public counters `int $copied, int $bytes, int $missing`; `FileCopier::measure(string $disk, array $paths): array{files:int,bytes:int,missing:int}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\FileCopier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileCopierTest extends TestCase
{
    // TestCase::setUp() menulis baris aktivasi lisensi, jadi DB harus termigrasi
    // dan dibersihkan per test walau test ini sendiri tidak memakai DB.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_it_copies_to_a_new_random_name_in_the_same_directory(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        $copier = new FileCopier;

        $new = $copier->copy('public', 'products/a.jpg');

        $this->assertNotSame('products/a.jpg', $new);
        $this->assertStringStartsWith('products/', $new);
        $this->assertStringEndsWith('.jpg', $new);
        $this->assertSame('AAAA', Storage::disk('public')->get($new));
        $this->assertSame('AAAA', Storage::disk('public')->get('products/a.jpg'));
        $this->assertSame(1, $copier->copied);
        $this->assertSame(4, $copier->bytes);
    }

    public function test_rows_pointing_at_the_same_source_share_one_copy(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        $copier = new FileCopier;

        $first = $copier->copy('public', 'products/a.jpg');
        $second = $copier->copy('public', 'products/a.jpg');

        $this->assertSame($first, $second);
        $this->assertSame(1, $copier->copied);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_the_same_path_on_another_disk_is_a_different_file(): void
    {
        Storage::disk('public')->put('x/a.jpg', 'PUB');
        Storage::disk('local')->put('x/a.jpg', 'PRIV');
        $copier = new FileCopier;

        $this->assertSame('PUB', Storage::disk('public')->get($copier->copy('public', 'x/a.jpg')));
        $this->assertSame('PRIV', Storage::disk('local')->get($copier->copy('local', 'x/a.jpg')));
    }

    public function test_null_empty_and_missing_sources_yield_null_without_failing(): void
    {
        $copier = new FileCopier;

        $this->assertNull($copier->copy('public', null));
        $this->assertNull($copier->copy('public', ''));
        $this->assertNull($copier->copy('public', 'products/gone.jpg'));
        $this->assertNull($copier->copy('public', 'products/gone.jpg'));
        $this->assertSame(0, $copier->copied);
        $this->assertSame(1, $copier->missing);
    }

    public function test_discard_removes_only_the_copies(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        $copier = new FileCopier;
        $new = $copier->copy('public', 'products/a.jpg');

        $copier->discardCopies();

        Storage::disk('public')->assertMissing($new);
        Storage::disk('public')->assertExists('products/a.jpg');
    }

    public function test_measure_counts_distinct_existing_files_and_missing_ones(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'AAAA');
        Storage::disk('public')->put('products/b.jpg', 'BB');

        $this->assertSame(
            ['files' => 2, 'bytes' => 6, 'missing' => 1],
            FileCopier::measure('public', ['products/a.jpg', 'products/b.jpg', 'products/gone.jpg']),
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=FileCopierTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\DemoClone;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyalin berkas tersimpan ke NAMA ACAK BARU (konvensi ImageUploadService).
 * Baris DEMO tidak pernah berbagi path dengan LIVE: kalau berbagi, menghapus/
 * mengganti gambar atau bukti bayar di DEMO akan menghapus berkas LIVE
 * (ImageUploadService::delete, PaymentService::deletePayment).
 *
 * Beberapa baris yang menunjuk SATU berkas sumber berbagi SATU salinan
 * (hanya di antara baris DEMO). Berkas sumber hanya dibaca. Bila transaksi
 * gagal, discardCopies() menghapus semua salinan yang dibuat run ini.
 */
final class FileCopier
{
    public int $copied = 0;

    public int $bytes = 0;

    public int $missing = 0;

    /** @var array<string,?string> "disk|sumber" => path salinan (null = sumber hilang) */
    private array $map = [];

    /** @var list<array{0:string,1:string}> */
    private array $created = [];

    public function copy(string $disk, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $key = "{$disk}|{$path}";

        if (array_key_exists($key, $this->map)) {
            return $this->map[$key];
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            $this->missing++;

            return $this->map[$key] = null;
        }

        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = Str::uuid()->toString().($extension !== '' ? ".{$extension}" : '');
        $destination = ($directory === '.' || $directory === '') ? $name : "{$directory}/{$name}";

        $storage->copy($path, $destination);
        $this->created[] = [$disk, $destination];

        if ($storage->size($destination) !== $storage->size($path)) {
            throw new CloneException("Salinan berkas di disk {$disk} tidak sama ukurannya dengan sumber.");
        }

        $this->copied++;
        $this->bytes += $storage->size($destination);

        return $this->map[$key] = $destination;
    }

    public function discardCopies(): void
    {
        foreach ($this->created as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        $this->created = [];
        $this->map = [];
    }

    /**
     * Untuk simulasi (dry run): berapa berkas/byte yang akan disalin.
     *
     * @param  list<string>  $paths  path sumber yang sudah unik
     * @return array{files:int,bytes:int,missing:int}
     */
    public static function measure(string $disk, array $paths): array
    {
        $storage = Storage::disk($disk);
        $out = ['files' => 0, 'bytes' => 0, 'missing' => 0];

        foreach ($paths as $path) {
            if ($storage->exists($path)) {
                $out['files']++;
                $out['bytes'] += $storage->size($path);
            } else {
                $out['missing']++;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=FileCopierTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DemoClone/FileCopier.php tests/Feature/DemoClone/FileCopierTest.php
git commit -m "feat(demo-clone): FileCopier menyalin berkas ke nama acak, tidak pernah berbagi path dengan LIVE" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `LiveToDemoCloner` core — plan, wipe, clone, remap

**Files:**
- Create: `app/Services/DemoClone/LiveToDemoCloner.php`
- Test: `tests/Feature/DemoClone/LiveToDemoClonerTest.php`

**Interfaces:**
- Consumes: `CloneRegistry`, `CodeAllocator`, `FileCopier`, `CloneException` (Tasks 2–4).
- Produces: `class LiveToDemoCloner` (non-final) with `plan(): array{tables: array<string,array{demo_delete:int,live_clone:int}>, files: array{files:int,bytes:int,missing:int}}`, `run(): array{deleted: array<string,int>, cloned: array<string,int>, skipped: array<string,int>, files: array{copied:int,bytes:int,missing:int}, references_dropped:int, codes: array}`, and `protected function afterClone(): void {}` (test seam, runs inside the transaction after the copy). In this task `run()` = transaction { wipe demo → clone } with copy cleanup on failure; Task 6 adds verification, fingerprint and file cleanup.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneRegistry;
use App\Services\DemoClone\LiveToDemoCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class LiveToDemoClonerTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    private array $live;

    private array $oldDemo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->live = $this->buildGraph('live');
        $this->oldDemo = $this->buildGraph('demo');
    }

    private function mode(string $table, string $mode)
    {
        return DB::table($table)->whereRaw(CloneRegistry::scope($table, $mode));
    }

    private function demo(string $table): object
    {
        return $this->mode($table, 'demo')->orderBy('id')->first();
    }

    public function test_plan_reports_counts_and_files_without_writing(): void
    {
        $plan = (new LiveToDemoCloner)->plan();

        $this->assertSame(['demo_delete' => 1, 'live_clone' => 1], $plan['tables']['orders']);
        $this->assertSame(['demo_delete' => 2, 'live_clone' => 2], $plan['tables']['preorders']);
        $this->assertSame(4, $plan['files']['files']); // 3 gambar + 1 bukti bayar LIVE
        $this->assertGreaterThan(0, $plan['files']['bytes']);
        $this->assertSame(1, $this->mode('orders', 'demo')->count());
        $this->assertSame($this->oldDemo['order'], $this->demo('orders')->id);
    }

    public function test_old_demo_rows_are_replaced_and_every_table_mirrors_live(): void
    {
        $report = (new LiveToDemoCloner)->run();

        $this->assertFalse(DB::table('artists')->where('code', 'DMO')->exists());
        $this->assertFalse(DB::table('orders')->where('id', $this->oldDemo['order'])->exists());

        foreach (array_keys(CloneRegistry::tables()) as $table) {
            $this->assertSame(
                $this->mode($table, 'live')->count(),
                $this->mode($table, 'demo')->count(),
                "{$table}: jumlah DEMO harus sama dengan LIVE",
            );
        }

        $this->assertSame(1, $report['cloned']['orders']);
        $this->assertSame(1, $report['deleted']['orders']);
    }

    public function test_foreign_keys_point_at_demo_rows_and_self_references_are_patched(): void
    {
        (new LiveToDemoCloner)->run();

        $order = $this->demo('orders');
        $this->assertSame('demo', DB::table('customers')->where('id', $order->customer_id)->value('data_mode'));
        $this->assertSame('demo', DB::table('cashier_sessions')->where('id', $order->session_id)->value('data_mode'));
        $this->assertSame('demo', DB::table('events')->where('id', $order->event_id)->value('data_mode'));
        // user dibagi: tetap user yang sama
        $this->assertSame($this->live['user'], (int) $order->user_id);

        $child = DB::table('categories')->where('data_mode', 'demo')->whereNotNull('parent_id')->first();
        $this->assertSame('demo', DB::table('categories')->where('id', $child->parent_id)->value('data_mode'));

        $split = DB::table('preorders')->where('data_mode', 'demo')->whereNotNull('source_preorder_id')->first();
        $this->assertSame('demo', DB::table('preorders')->where('id', $split->source_preorder_id)->value('data_mode'));

        $bom = $this->demo('product_variant_bom_lines');
        $this->assertSame('demo', DB::table('product_variants')->where('id', $bom->product_variant_id)->value('data_mode'));
        $poItem = DB::table('purchase_order_items')->where('id', $bom->purchase_order_item_id)->first();
        $this->assertSame('demo', DB::table('purchase_orders')->where('id', $poItem->purchase_order_id)->value('data_mode'));
    }

    public function test_unique_values_are_rewritten_by_their_rule(): void
    {
        (new LiveToDemoCloner)->run();

        $this->assertSame('ZLI', $this->demo('artists')->code);
        $this->assertSame('ZL', DB::table('categories')->where('data_mode', 'demo')->whereNotNull('parent_id')->value('code'));
        $this->assertSame('VND-LIVE-DM', $this->demo('vendors')->code);
        $this->assertSame('MAT-LIVE-DM', $this->demo('materials')->code);
        $this->assertSame('TRX-LIVE-0001-DM', $this->demo('orders')->order_number);
        $this->assertSame('PO-LIVE-0001-DM', $this->demo('purchase_orders')->po_number);
        $this->assertEqualsCanonicalizing(
            ['PRE-LIVE-0001-DM', 'PRE-LIVE-0002-DM'],
            $this->mode('preorders', 'demo')->pluck('preorder_number')->all(),
        );
        $this->assertSame('ZLI-ZL-ABC', $this->demo('products')->code_prefix);
        $this->assertEqualsCanonicalizing(
            ['ZLI-ZL-ABC-001', 'ZLI-ZL-ABC-002'],
            $this->mode('product_variants', 'demo')->pluck('sku')->all(),
        );
        $this->assertNotSame(
            DB::table('orders')->where('data_mode', 'live')->value('local_ref'),
            $this->demo('orders')->local_ref,
        );
        $this->assertSame(36, strlen($this->demo('orders')->local_ref));
        $this->assertSame(3, $this->mode('payments', 'demo')->whereNotNull('client_ref')->distinct()->count('client_ref'));
        $this->assertNotSame(
            DB::table('payment_proofs')->where('data_mode', 'live')->value('proof_token'),
            $this->demo('payment_proofs')->proof_token,
        );
    }

    public function test_stock_movement_references_are_remapped_by_type(): void
    {
        (new LiveToDemoCloner)->run();

        $order = $this->demo('orders');
        $orderItem = $this->demo('order_items');
        $preorderItem = $this->demo('preorder_items');

        $sale = $this->mode('stock_movements', 'demo')->where('type', 'sale')->first();
        $return = $this->mode('stock_movements', 'demo')->where('type', 'return')->first();
        $purchase = $this->mode('stock_movements', 'demo')->where('type', 'purchase')->first();
        $adjust = $this->mode('stock_movements', 'demo')->where('type', 'adjustment')->first();

        $this->assertSame((int) $order->id, (int) $sale->reference_id);
        $this->assertSame((int) $orderItem->id, (int) $return->reference_id);
        $this->assertSame((int) $preorderItem->id, (int) $purchase->reference_id);
        $this->assertNull($adjust->reference_id);
        $this->assertSame('MasterDataImport', $adjust->reference_type);

        $poItem = DB::table('purchase_order_items')
            ->whereIn('purchase_order_id', $this->mode('purchase_orders', 'demo')->pluck('id'))->first();
        $this->assertSame((int) $poItem->id, (int) $this->demo('material_stock_movements')->reference_id);
    }

    public function test_unknown_non_null_references_are_dropped_and_counted_never_copied(): void
    {
        $this->row('stock_movements', [
            'data_mode' => 'live', 'variant_id' => $this->live['variant1'], 'type' => 'adjustment',
            'qty_change' => 0, 'stock_before' => 0, 'stock_after' => 0,
            'reference_type' => 'Mystery', 'reference_id' => $this->live['order'],
        ]);

        $report = (new LiveToDemoCloner)->run();

        $this->assertSame(1, $report['references_dropped']);
        $this->assertSame(0, $this->mode('stock_movements', 'demo')->where('reference_id', $this->live['order'])->count());
    }

    public function test_values_timestamps_and_pii_are_copied_verbatim(): void
    {
        $liveOrder = DB::table('orders')->where('id', $this->live['order'])->first();
        (new LiveToDemoCloner)->run();

        $this->assertSame($liveOrder->created_at, $this->demo('orders')->created_at);
        $customer = $this->demo('customers');
        $this->assertSame('live.customer@example.test', $customer->email);
        $this->assertSame('+62-000-LIVE-0001', $customer->phone);
        $this->assertSame('demo', $customer->data_mode);
    }

    public function test_soft_deleted_live_rows_are_cloned_as_soft_deleted(): void
    {
        $stamp = now()->subDay()->format('Y-m-d H:i:s');
        $deletedVendor = $this->row('vendors', ['data_mode' => 'live', 'code' => 'GONE', 'name' => 'Deleted vendor', 'deleted_at' => $stamp]);

        (new LiveToDemoCloner)->run();

        $clone = DB::table('vendors')->where('data_mode', 'demo')->where('code', 'GONE-DM')->first();
        $this->assertNotNull($clone);
        $this->assertSame($stamp, $clone->deleted_at);
        $this->assertNotSame($deletedVendor, $clone->id);
    }

    public function test_live_rows_are_untouched_by_the_core_run(): void
    {
        $before = $this->mode('orders', 'live')->first();

        (new LiveToDemoCloner)->run();

        $this->assertEquals($before, $this->mode('orders', 'live')->first());
        $this->assertSame(1, $this->mode('artists', 'live')->count());
        $this->assertSame('LIV', $this->mode('artists', 'live')->value('code'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=LiveToDemoClonerTest`
Expected: FAIL — `Class "App\Services\DemoClone\LiveToDemoCloner" not found`.

- [ ] **Step 3: Implement the cloner core**

```php
<?php

namespace App\Services\DemoClone;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mengganti seluruh data DEMO dengan salinan persis data LIVE.
 *
 * Aturan yang tidak boleh dilanggar: baris LIVE hanya DIBACA. Setiap SELECT
 * sumber memakai scope 'live', setiap tulisan memberi data_mode 'demo',
 * setiap DELETE/UPDATE difilter 'demo'. Query memakai DB::table() +
 * CloneRegistry::scope(), BUKAN model — DataModeScope mengikuti mode AKTIF
 * dan akan terbalik bila system_mode = demo.
 */
class LiveToDemoCloner
{
    /** @var array<string,array<int,int>> tabel => [id live => id demo] */
    private array $maps = [];

    /** @var array<int,array{0:string,1:string}> id produk live => [prefix live, prefix demo] */
    private array $prefixes = [];

    /** @var array<string,int> baris yang dilewati per tabel (mis. bukti bayar tanpa berkas) */
    private array $skipped = [];

    private int $referencesDropped = 0;

    /**
     * Angka untuk simulasi (dry run). Tidak menulis apa pun.
     */
    public function plan(): array
    {
        $tables = [];
        $paths = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            $tables[$table] = [
                'demo_delete' => DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))->count(),
                'live_clone' => DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->count(),
            ];

            foreach ($spec['files'] as $column => $disk) {
                $found = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))
                    ->whereNotNull($column)->where($column, '!=', '')->pluck($column);

                foreach ($found as $path) {
                    $paths[$disk][(string) $path] = true;
                }
            }
        }

        $files = ['files' => 0, 'bytes' => 0, 'missing' => 0];

        foreach ($paths as $disk => $set) {
            foreach (FileCopier::measure($disk, array_map('strval', array_keys($set))) as $key => $value) {
                $files[$key] += $value;
            }
        }

        return ['tables' => $tables, 'files' => $files];
    }

    /**
     * Seluruhnya dalam SATU transaksi; salinan berkas yang sudah dibuat
     * dihapus bila transaksi gagal.
     */
    public function run(): array
    {
        $copier = new FileCopier;

        try {
            return DB::transaction(function () use ($copier) {
                $deleted = $this->wipeDemo();
                $codes = new CodeAllocator;
                $cloned = $this->cloneAll($copier, $codes);

                $this->afterClone();

                return [
                    'deleted' => $deleted,
                    'cloned' => $cloned,
                    'skipped' => $this->skipped,
                    'files' => ['copied' => $copier->copied, 'bytes' => $copier->bytes, 'missing' => $copier->missing],
                    'references_dropped' => $this->referencesDropped,
                    'codes' => $codes->mapping(),
                ];
            });
        } catch (\Throwable $e) {
            $copier->discardCopies();

            throw $e;
        }
    }

    /**
     * Titik sambung untuk test (dipanggil di dalam transaksi, sesudah
     * penyalinan). Produksi tidak mengisinya.
     */
    protected function afterClone(): void {}

    /** @return array<string,int> */
    private function wipeDemo(): array
    {
        $deleted = [];

        foreach (array_reverse(array_keys(CloneRegistry::tables())) as $table) {
            $where = CloneRegistry::scope($table, 'demo');

            // Self-FK (categories.parent_id = RESTRICT) bisa menahan DELETE
            // massal: lepas dulu — hanya pada baris DEMO.
            foreach (CloneRegistry::spec($table)['deferred'] as $column => $_) {
                DB::table($table)->whereRaw($where)->update([$column => null]);
            }

            $deleted[$table] = DB::table($table)->whereRaw($where)->delete();
        }

        return $deleted;
    }

    /** @return array<string,int> */
    private function cloneAll(FileCopier $copier, CodeAllocator $codes): array
    {
        $this->maps = [];
        $this->prefixes = [];
        $this->skipped = [];
        $this->referencesDropped = 0;
        $counts = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            $this->maps[$table] = [];
            $counts[$table] = 0;
            $deferred = [];

            $rows = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->orderBy('id')->get();

            foreach ($rows as $row) {
                $oldId = (int) $row->id;
                $data = (array) $row;
                unset($data['id']);

                foreach ($spec['fks'] as $column => $parent) {
                    $data[$column] = $this->mapForeignKey($table, $column, $parent, $row->{$column}, $oldId);
                }

                foreach ($spec['deferred'] as $column => $parent) {
                    $deferred[$oldId][$column] = $row->{$column};
                    $data[$column] = null;
                }

                foreach ($spec['suffix'] as $column => $max) {
                    if ($data[$column] !== null) {
                        $data[$column] = $codes->suffixed((string) $data[$column], $max, "{$table}.{$column} id {$oldId}");
                    }
                }

                foreach ($spec['uuid'] as $column) {
                    if ($data[$column] !== null) {
                        $data[$column] = (string) Str::uuid();
                    }
                }

                $skip = false;

                foreach ($spec['files'] as $column => $disk) {
                    $data[$column] = $copier->copy($disk, $data[$column]);

                    if ($data[$column] === null && in_array($column, $spec['required_files'], true)) {
                        $skip = true;
                    }
                }

                if ($skip) {
                    $this->skipped[$table] = ($this->skipped[$table] ?? 0) + 1;

                    continue;
                }

                $data = $this->transformSpecial($table, $row, $data, $codes, $oldId);

                if (array_key_exists('data_mode', $data)) {
                    $data['data_mode'] = 'demo';
                }

                $this->maps[$table][$oldId] = DB::table($table)->insertGetId($data);
                $counts[$table]++;
            }

            foreach ($spec['deferred'] as $column => $parent) {
                foreach ($deferred as $oldId => $values) {
                    if ($values[$column] === null) {
                        continue;
                    }

                    $newParent = $this->maps[$parent][(int) $values[$column]] ?? null;

                    if ($newParent === null) {
                        throw new CloneException("{$table}.{$column} (id {$oldId}): induk {$parent} id {$values[$column]} bukan baris LIVE yang ikut disalin.");
                    }

                    // id baru = baris DEMO yang baru saja dibuat; LIVE tidak tersentuh.
                    DB::table($table)->where('id', $this->maps[$table][$oldId])->update([$column => $newParent]);
                }
            }
        }

        return $counts;
    }

    private function mapForeignKey(string $table, string $column, string $parent, mixed $value, int $oldId): ?int
    {
        if ($value === null) {
            return null;
        }

        $new = $this->maps[$parent][(int) $value] ?? null;

        if ($new === null) {
            throw new CloneException("{$table}.{$column} (id {$oldId}): induk {$parent} id {$value} bukan baris LIVE yang ikut disalin.");
        }

        return $new;
    }

    /** Aturan per tabel yang tidak bisa dinyatakan secara deklaratif di registry. */
    private function transformSpecial(string $table, object $row, array $data, CodeAllocator $codes, int $oldId): array
    {
        switch ($table) {
            case 'artists':
                $data['code'] = $codes->artist((string) $row->code);
                break;

            case 'categories':
                $data['code'] = $codes->category((string) $row->code);
                break;

            case 'products':
                $demoPrefix = $codes->productPrefix((string) $row->code_prefix, $oldId);
                $this->prefixes[$oldId] = [(string) $row->code_prefix, $demoPrefix];
                $data['code_prefix'] = $demoPrefix;
                break;

            case 'product_variants':
                [$livePrefix, $demoPrefix] = $this->prefixes[(int) $row->product_id]
                    ?? throw new CloneException("product_variants id {$oldId}: produk induk tidak ikut disalin.");
                $data['sku'] = $codes->sku((string) $row->sku, $livePrefix, $demoPrefix, $oldId);
                break;

            case 'stock_movements':
                $data['reference_id'] = $this->remapReference($row->reference_id, $this->stockReferenceParent($row));
                break;

            case 'material_stock_movements':
                $data['reference_id'] = $this->remapReference(
                    $row->reference_id,
                    $row->reference_type === 'purchase_order_item' ? 'purchase_order_items' : null,
                );
                break;
        }

        return $data;
    }

    /**
     * reference_id berarti hal berbeda per penulis (CLAUDE.md, fitur 036).
     * Kombinasi di luar daftar ini TIDAK disalin: id LIVE tidak boleh bocor
     * ke baris DEMO, jadi dijadikan NULL dan dihitung di laporan.
     */
    private function stockReferenceParent(object $row): ?string
    {
        return match (true) {
            $row->type === 'sale' && $row->reference_type === 'order_item' => 'orders',
            $row->type === 'return' && $row->reference_type === 'order_item' => 'order_items',
            in_array($row->type, ['purchase', 'preorder_handover'], true) && $row->reference_type === 'preorder_item' => 'preorder_items',
            $row->type === 'purchase' && $row->reference_type === 'preorder' => 'preorders',
            default => null,
        };
    }

    private function remapReference(mixed $referenceId, ?string $parent): ?int
    {
        if ($referenceId === null) {
            return null;
        }

        $new = $parent === null ? null : ($this->maps[$parent][(int) $referenceId] ?? null);

        if ($new === null) {
            $this->referencesDropped++;
        }

        return $new;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=LiveToDemoClonerTest`
Expected: PASS (8 tests). Likely first-run snags, fix in the cloner (not by loosening tests): a `Duplicate entry` on a unique key you did not rewrite (add the column to the registry's `suffix`/`uuid`), or a NOT NULL column mapped to `null`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DemoClone/LiveToDemoCloner.php tests/Feature/DemoClone/LiveToDemoClonerTest.php
git commit -m "feat(demo-clone): cloner inti — hapus DEMO, salin LIVE berurutan FK dengan pemetaan id baru" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Safety net — LIVE fingerprint guard, integrity checks, files, rollback

**Files:**
- Modify: `app/Services/DemoClone/LiveToDemoCloner.php` (`run()`, new private methods)
- Test: `tests/Feature/DemoClone/LiveToDemoClonerSafetyTest.php`

**Interfaces:**
- Consumes: Task 5's cloner and `afterClone()` seam, Task 2's `LiveFingerprint`, `App\Services\ActivityLogger::log(?int $userId, string $action, string $entityType, ?int $entityId, ?string $description, ?array $old, ?array $new)`.
- Produces: `run()` now also (inside the transaction) captures the LIVE fingerprint first, verifies integrity + files after the copy, re-captures and aborts on any LIVE difference, writes one `activity_logs` row (`action='cloned_live_to_demo'`), and AFTER commit deletes wiped demo files that no row in either mode references.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\LiveFingerprint;
use App\Services\DemoClone\LiveToDemoCloner;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class LiveToDemoClonerSafetyTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    private array $live;

    private array $oldDemo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->live = $this->buildGraph('live');
        $this->oldDemo = $this->buildGraph('demo');
    }

    /** Cloner yang menjalankan $hook di dalam transaksi, setelah penyalinan. */
    private function clonerWithHook(\Closure $hook): LiveToDemoCloner
    {
        return new class($hook) extends LiveToDemoCloner
        {
            public function __construct(private \Closure $hook) {}

            protected function afterClone(): void
            {
                ($this->hook)();
            }
        };
    }

    private function files(): array
    {
        return [Storage::disk('public')->allFiles(), Storage::disk('local')->allFiles()];
    }

    public function test_live_rows_and_files_are_identical_after_a_run(): void
    {
        $before = LiveFingerprint::capture();
        $liveBytes = Storage::disk('public')->get('products/live-product.jpg');

        (new LiveToDemoCloner)->run();

        // Hanya sidik jari yang boleh berubah di luar LIVE: users/settings/dll. tidak ditulis.
        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertSame($liveBytes, Storage::disk('public')->get('products/live-product.jpg'));
        Storage::disk('public')->assertExists('categories/live-category.jpg');
        Storage::disk('local')->assertExists('payment-proofs/live-proof.jpg');
    }

    public function test_a_change_to_live_during_the_run_aborts_and_rolls_everything_back(): void
    {
        $before = LiveFingerprint::capture();
        $cloner = $this->clonerWithHook(
            fn () => DB::table('customers')->where('id', $this->live['customer'])->update(['name' => 'Tampered']),
        );

        try {
            $cloner->run();
            $this->fail('Seharusnya CloneException');
        } catch (CloneException $e) {
            $this->assertStringContainsString('LIVE berubah', $e->getMessage());
            $this->assertStringContainsString('customers', $e->getMessage());
        }

        $this->assertSame('Customer LIVE', DB::table('customers')->where('id', $this->live['customer'])->value('name'));
        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertTrue(DB::table('artists')->where('code', 'DMO')->exists(), 'DEMO lama harus kembali');
    }

    public function test_a_row_count_mismatch_aborts(): void
    {
        $cloner = $this->clonerWithHook(
            fn () => DB::table('artist_settlements')->where('data_mode', 'demo')->delete(),
        );

        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('artist_settlements');

        $cloner->run();
    }

    public function test_a_cross_mode_reference_aborts(): void
    {
        $cloner = $this->clonerWithHook(function () {
            $demoCustomer = DB::table('customers')->where('data_mode', 'demo')->value('id');
            DB::table('orders')->where('id', $this->live['order'])->update(['customer_id' => $demoCustomer]);
        });

        $this->expectException(CloneException::class);

        $cloner->run();
    }

    public function test_failure_after_copy_restores_demo_and_removes_copied_files(): void
    {
        $filesBefore = $this->files();
        $demoArtistBefore = DB::table('artists')->where('data_mode', 'demo')->first();
        $cloner = $this->clonerWithHook(fn () => throw new \RuntimeException('boom'));

        try {
            $cloner->run();
            $this->fail('Seharusnya melempar');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertEquals($demoArtistBefore, DB::table('artists')->where('data_mode', 'demo')->first());
        $this->assertSame($filesBefore, $this->files(), 'salinan berkas harus dihapus saat gagal');
        Storage::disk('public')->assertExists('products/demo-product.jpg');
    }

    public function test_copied_images_and_proofs_are_independent_files(): void
    {
        (new LiveToDemoCloner)->run();

        $product = DB::table('products')->where('data_mode', 'demo')->first();
        $variant = DB::table('product_variants')->where('data_mode', 'demo')->whereNotNull('image_path')->first();
        $category = DB::table('categories')->where('data_mode', 'demo')->whereNotNull('image_path')->first();
        $proof = DB::table('payment_proofs')->where('data_mode', 'demo')->first();

        $this->assertNotSame('products/live-product.jpg', $product->image_path);
        $this->assertSame('live-product-bytes', Storage::disk('public')->get($product->image_path));
        $this->assertSame('live-variant-bytes', Storage::disk('public')->get($variant->image_path));
        $this->assertSame('live-category-bytes', Storage::disk('public')->get($category->image_path));
        // bukti bayar tetap di disk PRIVAT
        $this->assertSame('live-proof-bytes', Storage::disk('local')->get($proof->file_path));
        $this->assertStringStartsWith('payment-proofs/', $proof->file_path);
        $this->assertNotSame('payment-proofs/live-proof.jpg', $proof->file_path);
        Storage::disk('public')->assertMissing($proof->file_path);
    }

    public function test_a_missing_live_image_becomes_null_and_a_missing_proof_row_is_skipped(): void
    {
        Storage::disk('public')->delete('products/live-product.jpg');
        Storage::disk('local')->delete('payment-proofs/live-proof.jpg');

        $report = (new LiveToDemoCloner)->run();

        $this->assertNull(DB::table('products')->where('data_mode', 'demo')->value('image_path'));
        $this->assertSame(0, DB::table('payment_proofs')->where('data_mode', 'demo')->count());
        $this->assertSame(['payment_proofs' => 1], $report['skipped']);
        $this->assertSame(2, $report['files']['missing']);
    }

    public function test_rows_sharing_one_live_file_share_one_demo_copy_but_never_with_live(): void
    {
        DB::table('product_variants')->where('id', $this->live['variant1'])->update(['image_path' => 'products/live-product.jpg']);

        (new LiveToDemoCloner)->run();

        $productPath = DB::table('products')->where('data_mode', 'demo')->value('image_path');
        $variantPath = DB::table('product_variants')->where('data_mode', 'demo')->whereNotNull('image_path')->value('image_path');
        $this->assertSame($productPath, $variantPath);
        $this->assertNotSame('products/live-product.jpg', $productPath);
    }

    public function test_wiping_demo_deletes_its_own_files_but_never_a_file_a_live_row_still_uses(): void
    {
        // Sisa kloning lama: baris DEMO menunjuk berkas milik LIVE.
        DB::table('products')->where('id', $this->oldDemo['product'])->update(['image_path' => 'products/live-product.jpg']);

        (new LiveToDemoCloner)->run();

        Storage::disk('public')->assertExists('products/live-product.jpg');
        // berkas DEMO lama yang tak dipakai siapa pun ikut dibersihkan
        Storage::disk('public')->assertMissing('products/demo-variant.jpg');
        Storage::disk('public')->assertMissing('categories/demo-category.jpg');
        Storage::disk('local')->assertMissing('payment-proofs/demo-proof.jpg');
    }

    public function test_result_is_identical_when_the_active_mode_is_demo(): void
    {
        $before = LiveFingerprint::capture();

        ModeGate::runAs(ModeGate::DEMO, fn () => (new LiveToDemoCloner)->run());

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertSame(1, DB::table('artists')->where('data_mode', 'live')->count());
        $this->assertSame('ZLI', DB::table('artists')->where('data_mode', 'demo')->value('code'));
    }

    public function test_running_twice_gives_the_same_result(): void
    {
        $first = (new LiveToDemoCloner)->run();
        $second = (new LiveToDemoCloner)->run();

        $this->assertSame($first['cloned'], $second['cloned']);
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'demo')->count());
        $this->assertSame(1, DB::table('artists')->where('data_mode', 'demo')->count());
        // dua run berturut-turut tidak menumpuk berkas: salinan run-1 dibersihkan di run-2
        $this->assertCount(6, Storage::disk('public')->allFiles()); // 3 LIVE + 3 salinan DEMO terkini
    }

    public function test_a_log_entry_records_the_clone_without_row_values(): void
    {
        (new LiveToDemoCloner)->run();

        $log = DB::table('activity_logs')->where('action', 'cloned_live_to_demo')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('example.test', (string) $log->new_values);
    }
}
```

Why 6 in `test_running_twice_gives_the_same_result`: LIVE has 3 public files; each run creates 3 demo copies, and the next run deletes the previous 3 (the fixture's old demo files are wiped in run 1). After two runs the public disk holds 3 (LIVE) + 3 (current demo) = 6.

- [ ] **Step 2: Run to verify the new tests fail**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=LiveToDemoClonerSafetyTest`
Expected: FAIL — the guard/verification/log/stale-file tests fail (no checks exist yet); `test_live_rows_and_files_are_identical_after_a_run` and `test_copied_images_and_proofs_are_independent_files` may already pass.

- [ ] **Step 3: Implement — replace `run()` and add the verification methods**

In `app/Services/DemoClone/LiveToDemoCloner.php` add imports `use App\Services\ActivityLogger; use Illuminate\Support\Facades\Schema; use Illuminate\Support\Facades\Storage;` and replace `run()` with:

```php
    /**
     * Seluruhnya dalam SATU transaksi: sidik jari LIVE → hapus DEMO → salin →
     * verifikasi → sidik jari LIVE lagi (harus sama) → catat log. Salinan
     * berkas dihapus bila gagal; berkas DEMO lama yang tak dipakai siapa pun
     * dihapus HANYA sesudah commit (rollback tak boleh menghilangkan berkas).
     */
    public function run(): array
    {
        $copier = new FileCopier;
        $stale = [];

        try {
            $report = DB::transaction(function () use ($copier, &$stale) {
                $before = LiveFingerprint::capture();

                $stale = $this->demoFiles();
                $deleted = $this->wipeDemo();
                $codes = new CodeAllocator;
                $cloned = $this->cloneAll($copier, $codes);

                $this->afterClone();

                $this->verifyIntegrity();
                $this->verifyFiles();

                $changed = LiveFingerprint::diff($before, LiveFingerprint::capture());

                if ($changed !== []) {
                    throw new CloneException('Data LIVE berubah selama proses (tabel: '.implode(', ', $changed).'); dibatalkan.');
                }

                app(ActivityLogger::class)->log(
                    null,
                    'cloned_live_to_demo',
                    'system',
                    null,
                    'Data DEMO diganti dengan salinan data LIVE.',
                    null,
                    ['deleted' => $deleted, 'cloned' => $cloned],
                );

                return [
                    'deleted' => $deleted,
                    'cloned' => $cloned,
                    'skipped' => $this->skipped,
                    'files' => ['copied' => $copier->copied, 'bytes' => $copier->bytes, 'missing' => $copier->missing],
                    'references_dropped' => $this->referencesDropped,
                    'codes' => $codes->mapping(),
                ];
            });
        } catch (\Throwable $e) {
            $copier->discardCopies();

            throw $e;
        }

        $this->deleteUnreferencedFiles($stale);

        return $report;
    }
```

and add these private methods:

```php
    /** @return array<string,array{0:string,1:string}> "disk|path" => [disk, path] milik baris DEMO (sebelum dihapus) */
    private function demoFiles(): array
    {
        $out = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach ($spec['files'] as $column => $disk) {
                $paths = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))
                    ->whereNotNull($column)->where($column, '!=', '')->pluck($column);

                foreach ($paths as $path) {
                    $out["{$disk}|{$path}"] = [$disk, (string) $path];
                }
            }
        }

        return $out;
    }

    /**
     * Berkas DEMO lama dihapus hanya bila TIDAK ada baris (mode apa pun) yang
     * masih menunjuknya — melindungi berkas LIVE kalau sisa kloning lama atau
     * seeder pernah membuat baris DEMO menunjuk path LIVE.
     */
    private function deleteUnreferencedFiles(array $stale): void
    {
        foreach ($stale as [$disk, $path]) {
            if (! $this->isReferenced($disk, $path)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    private function isReferenced(string $disk, string $path): bool
    {
        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach ($spec['files'] as $column => $fileDisk) {
                if ($fileDisk === $disk && DB::table($table)->where($column, $path)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function verifyIntegrity(): void
    {
        foreach (CloneRegistry::tables() as $table => $spec) {
            $live = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->count();
            $demo = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))->count();
            $skipped = $this->skipped[$table] ?? 0;

            if ($demo !== $live - $skipped) {
                throw new CloneException("{$table}: jumlah baris DEMO ({$demo}) tidak sama dengan LIVE yang disalin (".($live - $skipped).').');
            }

            foreach (CloneRegistry::references($table) as $column => $parent) {
                foreach ([['demo', 'live'], ['live', 'demo']] as [$childMode, $parentMode]) {
                    $bad = DB::table($table)
                        ->whereRaw(CloneRegistry::scope($table, $childMode))
                        ->whereRaw("`{$column}` IN (SELECT id FROM `{$parent}` WHERE ".CloneRegistry::scope($parent, $parentMode).')')
                        ->count();

                    if ($bad > 0) {
                        throw new CloneException("{$table}.{$column}: {$bad} baris {$childMode} menunjuk induk {$parentMode} (lintas mode).");
                    }
                }
            }

            if ($skipped === 0) {
                foreach ($this->decimalColumns($table) as $column) {
                    $liveSum = (string) DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->selectRaw("SUM(`{$column}`) AS s")->value('s');
                    $demoSum = (string) DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))->selectRaw("SUM(`{$column}`) AS s")->value('s');

                    if ($liveSum !== $demoSum) {
                        throw new CloneException("{$table}.{$column}: total DEMO ({$demoSum}) tidak sama dengan LIVE ({$liveSum}).");
                    }
                }
            }
        }

        // Stok: salinan tidak boleh mengubah hubungan current_stock ↔ riwayat
        // (kalau LIVE sendiri tidak konsisten, DEMO harus sama persis, bukan "diperbaiki").
        $mismatch = fn (string $mode) => (int) DB::scalar(
            "SELECT COUNT(*) FROM product_variants v WHERE v.data_mode = '{$mode}' AND v.current_stock <> COALESCE((SELECT SUM(m.qty_change) FROM stock_movements m WHERE m.variant_id = v.id), 0)"
        );

        if ($mismatch('live') !== $mismatch('demo')) {
            throw new CloneException('Ketidakcocokan stok DEMO berbeda dari LIVE.');
        }
    }

    /** Setiap path berkas DEMO ada di disknya dan tidak sama dengan path LIVE mana pun. */
    private function verifyFiles(): void
    {
        $live = $this->filePaths('live');

        foreach ($this->filePaths('demo') as $disk => $set) {
            foreach (array_keys($set) as $path) {
                $path = (string) $path;

                if (isset($live[$disk][$path])) {
                    throw new CloneException("Berkas di disk {$disk} dipakai bersama oleh baris LIVE dan DEMO.");
                }

                if (! Storage::disk($disk)->exists($path)) {
                    throw new CloneException("Berkas DEMO di disk {$disk} tidak ditemukan sesudah disalin.");
                }
            }
        }
    }

    /** @return array<string,array<string,true>> disk => [path => true] untuk satu mode */
    private function filePaths(string $mode): array
    {
        $out = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach ($spec['files'] as $column => $disk) {
                $paths = DB::table($table)->whereRaw(CloneRegistry::scope($table, $mode))
                    ->whereNotNull($column)->where($column, '!=', '')->pluck($column);

                foreach ($paths as $path) {
                    $out[$disk][(string) $path] = true;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function decimalColumns(string $table): array
    {
        return collect(Schema::getColumns($table))->where('type_name', 'decimal')->pluck('name')->all();
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=DemoClone`
Expected: PASS for every `DemoClone` class built so far (fixtures, fingerprint, allocator, copier, cloner, safety).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DemoClone/LiveToDemoCloner.php tests/Feature/DemoClone/LiveToDemoClonerSafetyTest.php
git commit -m "feat(demo-clone): jaring pengaman — sidik jari LIVE, cek integritas, verifikasi berkas, rollback, log" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `SafeError` and the `app:clone-live-to-demo` command

**Files:**
- Create: `app/Services/DemoClone/SafeError.php`
- Create: `app/Console/Commands/CloneLiveToDemo.php`
- Test: `tests/Feature/DemoClone/SafeErrorTest.php`
- Test: `tests/Feature/DemoClone/CloneLiveToDemoCommandTest.php`

**Interfaces:**
- Consumes: `LiveToDemoCloner::plan()/run()`, `App\Services\BackupService::create(): array{id:string,...}` (throws `App\Services\Backup\BackupException`), `Tests\Support\FakeDatabaseDumper` (public `array $calls`, `bool $failDump`).
- Produces: `SafeError::describe(\Throwable $e): string`; command `app:clone-live-to-demo {--force} {--yes}`; `CloneLiveToDemo::CONFIRM_QUESTION` (public const string).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/DemoClone/SafeErrorTest.php`:

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\SafeError;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

class SafeErrorTest extends TestCase
{
    // TestCase::setUp() menulis baris aktivasi lisensi (lihat FileCopierTest).
    use RefreshDatabase;

    public function test_a_query_exception_never_exposes_bindings_or_the_driver_message(): void
    {
        $pdo = new PDOException("SQLSTATE[23000]: Duplicate entry 'SENTINEL-PHONE-123' for key 'customers.x'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'SENTINEL-PHONE-123'"];
        $e = new QueryException('mysql', 'insert into `customers` (`phone`) values (?)', ['SENTINEL-PHONE-123'], $pdo);

        $text = SafeError::describe($e);

        $this->assertStringNotContainsString('SENTINEL-PHONE-123', $text);
        $this->assertStringContainsString('23000', $text);
        $this->assertStringContainsString('customers', $text);
    }

    public function test_a_clone_exception_message_is_passed_through(): void
    {
        $this->assertSame('orders.customer_id (id 4): induk x', SafeError::describe(new CloneException('orders.customer_id (id 4): induk x')));
    }

    public function test_any_other_exception_is_reduced_to_its_class(): void
    {
        $text = SafeError::describe(new \RuntimeException('contains SENTINEL-EMAIL@example.test'));

        $this->assertStringNotContainsString('SENTINEL-EMAIL', $text);
        $this->assertStringContainsString('RuntimeException', $text);
    }
}
```

`tests/Feature/DemoClone/CloneLiveToDemoCommandTest.php`:

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Console\Commands\CloneLiveToDemo;
use App\Services\Backup\DatabaseDumper;
use App\Services\DemoClone\CloneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\Support\FakeDatabaseDumper;
use Tests\TestCase;

class CloneLiveToDemoCommandTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    private FakeDatabaseDumper $dumper;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir().'/boothpos-clone-test-'.uniqid();
        config(['backup.path' => $this->backupDir, 'backup.external_path' => null]);
        Storage::fake('public');
        Storage::fake('local');
        $this->dumper = new FakeDatabaseDumper;
        $this->app->instance(DatabaseDumper::class, $this->dumper);
        $this->buildGraph('live');
        $this->buildGraph('demo');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        parent::tearDown();
    }

    private function demoUntouched(): bool
    {
        return DB::table('artists')->where('code', 'DMO')->exists();
    }

    public function test_default_is_a_dry_run_that_writes_nothing(): void
    {
        $this->artisan('app:clone-live-to-demo')
            ->expectsOutputToContain('Simulasi')
            ->assertSuccessful();

        $this->assertTrue($this->demoUntouched());
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_declining_the_confirmation_aborts_without_a_backup(): void
    {
        $this->artisan('app:clone-live-to-demo --force')
            ->expectsConfirmation(CloneLiveToDemo::CONFIRM_QUESTION, 'no')
            ->assertFailed();

        $this->assertTrue($this->demoUntouched());
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_a_non_interactive_force_without_yes_aborts(): void
    {
        $this->artisan('app:clone-live-to-demo --force --no-interaction')->assertFailed();

        $this->assertTrue($this->demoUntouched());
    }

    public function test_force_and_yes_take_a_backup_first_then_clone(): void
    {
        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('Cadangan pengaman')
            ->assertSuccessful();

        $this->assertSame('dump', $this->dumper->calls[0][0]);
        $this->assertFalse($this->demoUntouched());
        $this->assertSame('ZLI', DB::table('artists')->where('data_mode', 'demo')->value('code'));
        $this->assertSame('LIV', DB::table('artists')->where('data_mode', 'live')->value('code'));
    }

    public function test_a_failed_backup_aborts_before_anything_is_written(): void
    {
        $this->dumper->failDump = true;

        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('Cadangan pengaman gagal')
            ->assertFailed();

        $this->assertTrue($this->demoUntouched());
    }

    /** Kosongkan data LIVE: lepas self-FK, lalu hapus baris LIVE dari anak ke induk. */
    private function emptyLive(): void
    {
        $tables = array_reverse(array_keys(CloneRegistry::tables()));

        foreach ($tables as $table) {
            foreach (CloneRegistry::spec($table)['deferred'] as $column => $_) {
                DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->update([$column => null]);
            }
        }

        foreach ($tables as $table) {
            DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->delete();
        }
    }

    public function test_it_refuses_when_live_has_nothing_to_clone(): void
    {
        $this->emptyLive();

        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('Tidak ada data LIVE')
            ->assertFailed();

        $this->assertTrue($this->demoUntouched());
        $this->assertSame([], $this->dumper->calls);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter="SafeErrorTest|CloneLiveToDemoCommandTest"`
Expected: FAIL — `SafeError` not found / command `app:clone-live-to-demo` not defined.

- [ ] **Step 3: Implement**

`app/Services/DemoClone/SafeError.php`:

```php
<?php

namespace App\Services\DemoClone;

use Illuminate\Database\QueryException;

/**
 * Pesan kegagalan yang AMAN dicetak: tanpa nilai baris. Pesan QueryException
 * dan PDOException memuat nilai binding / "Duplicate entry '<nilai>'" —
 * untuk tabel customers itu PII (telepon, email). Yang boleh keluar hanya:
 * pesan CloneException (hanya nama tabel/kolom/id), SQLSTATE + kode driver +
 * SQL bertanda tanya (tanpa nilai), atau nama kelas.
 */
final class SafeError
{
    public static function describe(\Throwable $e): string
    {
        if ($e instanceof CloneException) {
            return $e->getMessage();
        }

        if ($e instanceof QueryException) {
            $state = $e->errorInfo[0] ?? 'tidak diketahui';
            $driver = $e->errorInfo[1] ?? '?';

            return "Kesalahan database (SQLSTATE {$state}, kode {$driver}) pada: ".$e->getSql();
        }

        return get_class($e).' (pesan disembunyikan karena dapat memuat data).';
    }
}
```

`app/Console/Commands/CloneLiveToDemo.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\BackupService;
use App\Services\DemoClone\LiveToDemoCloner;
use App\Services\DemoClone\SafeError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan app:clone-live-to-demo [--force] [--yes]
 *
 * Mengganti SELURUH data DEMO dengan salinan persis data LIVE (baris,
 * baris soft-deleted, gambar, bukti bayar). Data LIVE hanya dibaca.
 * Default = simulasi; --force menulis; cadangan pengaman selalu dibuat
 * lebih dulu. Pesan kesalahan tidak pernah memuat nilai baris (PII).
 */
class CloneLiveToDemo extends Command
{
    public const CONFIRM_QUESTION = 'Semua data DEMO akan DIHAPUS lalu diganti salinan data LIVE (termasuk data pelanggan apa adanya). Lanjutkan?';

    protected $signature = 'app:clone-live-to-demo
                            {--force : Tulis ke database (tanpa ini hanya simulasi)}
                            {--yes : Lewati pertanyaan konfirmasi}';

    protected $description = 'Ganti data DEMO dengan salinan persis data LIVE (data LIVE tidak diubah)';

    public function handle(LiveToDemoCloner $cloner, BackupService $backups): int
    {
        $plan = $cloner->plan();
        $this->renderPlan($plan);

        if (! $this->option('force')) {
            $this->warn('Simulasi — tidak ada yang ditulis. Jalankan ulang dengan --force untuk melakukannya.');

            return self::SUCCESS;
        }

        if (array_sum(array_column($plan['tables'], 'live_clone')) === 0) {
            $this->error('Tidak ada data LIVE untuk disalin; dibatalkan (data DEMO tidak dihapus).');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm(self::CONFIRM_QUESTION, false)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        try {
            $backup = $backups->create();
        } catch (BackupException $e) {
            $this->error('Cadangan pengaman gagal, dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Cadangan pengaman: '.$backup['id'].' (pulihkan dengan app:restore bila perlu).');

        try {
            $report = $cloner->run();
        } catch (\Throwable $e) {
            $message = SafeError::describe($e);
            Log::error('app:clone-live-to-demo gagal: '.$message);
            $this->error('Gagal; semua perubahan dibatalkan. '.$message);

            return self::FAILURE;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    private function renderPlan(array $plan): void
    {
        $rows = [];

        foreach ($plan['tables'] as $table => $counts) {
            $rows[] = [$table, $counts['demo_delete'], $counts['live_clone']];
        }

        $this->table(['Tabel', 'DEMO dihapus', 'LIVE disalin'], $rows);
        $this->line(sprintf(
            'Berkas yang akan disalin: %d (%s); sumber hilang: %d',
            $plan['files']['files'],
            $this->megabytes($plan['files']['bytes']),
            $plan['files']['missing'],
        ));
    }

    private function renderReport(array $report): void
    {
        $this->info('Selesai. DEMO kini mencerminkan LIVE.');
        $this->line(sprintf(
            'Berkas disalin: %d (%s); sumber hilang: %d; referensi stok dibuang: %d',
            $report['files']['copied'],
            $this->megabytes($report['files']['bytes']),
            $report['files']['missing'],
            $report['references_dropped'],
        ));

        foreach ($report['skipped'] as $table => $count) {
            $this->warn("{$table}: {$count} baris dilewati karena berkas sumber hilang.");
        }
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 2).' MB';
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter="SafeErrorTest|CloneLiveToDemoCommandTest"`
Expected: PASS. (Laravel auto-registers commands in `app/Console/Commands`.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/DemoClone/SafeError.php app/Console/Commands/CloneLiveToDemo.php tests/Feature/DemoClone/SafeErrorTest.php tests/Feature/DemoClone/CloneLiveToDemoCommandTest.php
git commit -m "feat(demo-clone): perintah app:clone-live-to-demo (simulasi default, --force, cadangan pengaman, galat bebas PII)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Number generators after the clone, docs, full suite

**Files:**
- Test: `tests/Feature/DemoClone/GeneratorsAfterCloneTest.php`
- Modify: `docs/RUNBOOK.md` (append a section), `CLAUDE.md` (one short section)

**Interfaces:**
- Consumes: `App\Services\OrderService` (private `generateOrderNumber(): string`), `App\Services\PreorderService::generateNumber(): string` (public), `App\Services\PurchaseOrderService` (private `generatePoNumber(): string`), `App\Services\ProductCodeGenerator::buildCodePrefix(string $artistCode, string $categoryCode, string $productSegment): string`. All count rows created today across BOTH modes.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\LiveToDemoCloner;
use App\Services\OrderService;
use App\Services\PreorderService;
use App\Services\ProductCodeGenerator;
use App\Services\PurchaseOrderService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

/**
 * Pembangkit nomor menghitung baris HARI INI lintas kedua mode (+1). Salinan
 * menggandakan hitungan itu, jadi nomor berikutnya hanya melompat — tidak
 * boleh bertabrakan dengan nomor yang sudah ada, di mode mana pun.
 */
class GeneratorsAfterCloneTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->buildGraph('live');
        (new LiveToDemoCloner)->run();
    }

    private function invokePrivate(object $object, string $method): string
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    public function test_new_order_preorder_and_po_numbers_do_not_collide_in_either_mode(): void
    {
        foreach ([ModeGate::LIVE, ModeGate::DEMO] as $mode) {
            ModeGate::runAs($mode, function () {
                $order = $this->invokePrivate(app(OrderService::class), 'generateOrderNumber');
                $preorder = app(PreorderService::class)->generateNumber();
                $po = $this->invokePrivate(app(PurchaseOrderService::class), 'generatePoNumber');

                $this->assertFalse(DB::table('orders')->where('order_number', $order)->exists());
                $this->assertFalse(DB::table('preorders')->where('preorder_number', $preorder)->exists());
                $this->assertFalse(DB::table('purchase_orders')->where('po_number', $po)->exists());
            });
        }
    }

    public function test_a_new_product_prefix_can_still_be_built_for_the_demo_artist_and_category(): void
    {
        $artist = DB::table('artists')->where('data_mode', 'demo')->value('code');
        $category = DB::table('categories')->where('data_mode', 'demo')->whereNotNull('parent_id')->value('code');

        $prefix = ModeGate::runAs(ModeGate::DEMO, fn () => app(ProductCodeGenerator::class)->buildCodePrefix($artist, $category, 'NEW'));

        $this->assertSame("{$artist}-{$category}-NEW", $prefix);
    }
}
```

- [ ] **Step 2: Run it**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=GeneratorsAfterCloneTest`
Expected: PASS. A failure here is a real finding about the generators vs. cloned rows — fix the cloner or report it, don't weaken the test.

- [ ] **Step 3: Append the operator docs**

Append to `docs/RUNBOOK.md` (Indonesian, new top-level section):

```markdown
## Salin data LIVE ke DEMO (`app:clone-live-to-demo`)

Mengganti SELURUH data mode DEMO dengan salinan persis data mode LIVE: baris
(termasuk yang soft-deleted), gambar produk/varian/kategori, dan bukti bayar.
Data LIVE hanya dibaca — sidik jari tabel LIVE dicek sebelum dan sesudah, dan
proses dibatalkan bila ada selisih.

    php artisan app:clone-live-to-demo           # simulasi: tampilkan jumlah, tidak menulis
    php artisan app:clone-live-to-demo --force   # tulis (minta konfirmasi)
    php artisan app:clone-live-to-demo --force --yes   # tanpa pertanyaan

Di Docker dev: `docker compose exec app php artisan app:clone-live-to-demo ...`
(ini BUKAN suite test, jadi aturan `-e DB_DATABASE=boothpos_test` tidak berlaku).

- **Peringatan PII:** data pelanggan disalin APA ADANYA (telepon, email, akun
  sosial, alamat). Jangan bagikan DB, screenshot, atau cadangan yang berisi DEMO.
- Cadangan pengaman dibuat otomatis lebih dulu (id dicetak); pulihkan dengan
  `php artisan app:restore <path/database.sql>`. Gambar produk di disk publik
  TIDAK ikut dicadangkan oleh `app:backup` — gambar DEMO lama yang tidak dipakai
  siapa pun memang dihapus (DEMO dibangun ulang), gambar LIVE tidak pernah disentuh.
- Kode artist/kategori DEMO berbeda dari LIVE (kolomnya UNIQUE lintas mode dan
  hanya 3/2 huruf): mis. `VLC` → `ZVL`; `code_prefix`/SKU DEMO disusun ulang dari
  kode itu. Nomor dokumen DEMO berakhiran `-DM`.
- Tidak disalin: `companies`, `invoices`, `pos_drafts`, riwayat `activity_logs`,
  `preorder_notifications`, `users`, `payment_channels`, `settings`.
- Hentikan pemakaian aplikasi saat menjalankan: bila data LIVE berubah di tengah
  proses, perintah membatalkan dirinya.
```

Append to `CLAUDE.md` (English, before "## Conventions"):

```markdown
## Clone LIVE → DEMO (added 2026-10-11)

`php artisan app:clone-live-to-demo [--force] [--yes]` replaces ALL `data_mode='demo'` rows with an exact mirror of LIVE (`App\Services\DemoClone\*`, spec in `docs/superpowers/specs/2026-10-11-clone-live-to-demo-design.md`). Non-obvious rules:

- **LIVE is read-only.** All queries use `DB::table()` + `CloneRegistry::scope()`, never the `DataModeScope` (it follows the active mode). A LIVE fingerprint (`LiveFingerprint`) is taken before and after inside the transaction; any difference rolls back. **Add a new `HasDataMode` table to `CloneRegistry::tables()` in FK order**, or it is silently not mirrored (the parity check only covers registered tables).
- **`artists.code` is `char(3)`, `categories.code` is `char(2)`**, both UNIQUE across modes: demo codes are *allocated* (`CodeAllocator`: `Z`+first letters, then `Y`…), and `products.code_prefix` / `product_variants.sku` are rebuilt from them. Document numbers and vendor/material codes get `-DM`; `local_ref`/`client_ref`/`proof_token` get new UUIDs.
- **Files are copied, never shared** (`FileCopier`): sharing a path would let a demo delete remove the LIVE file. A demo wipe deletes an old file only if no row in either mode still references it.
- **Customer PII is copied verbatim** (product-owner decision) — keep DEMO data local. Error output never contains row values (`SafeError`).
- Stock-movement `reference_id` is remapped by (type, reference_type); unknown non-null references become `NULL` (counted), a LIVE id is never copied.
```

- [ ] **Step 4: Run the whole suite**

Run: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`
Expected: all green (the suite had ~600 backend tests before; the new `DemoClone` classes add ~60). If any pre-existing test fails, check `git stash` to confirm it failed before this branch before touching it.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/DemoClone/GeneratorsAfterCloneTest.php docs/RUNBOOK.md CLAUDE.md
git commit -m "docs(demo-clone): RUNBOOK + CLAUDE.md, dan uji pembangkit nomor sesudah salin" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Manual rollout on the real dev database — needs the user's go-ahead

**This task is NOT automated. Stop and ask the user before each step that writes.** Nothing in Tasks 1–8 touches `boothpos`.

- [ ] **Step 1: Dry run (read-only)**

Run: `docker compose exec app php artisan app:clone-live-to-demo`
Expected: a table of per-table counts matching the earlier read-only survey (e.g. live: 26 products, 132 variants, 40 customers, 44 pre-orders, 155 pre-order items, 18 payment proofs…) and "Simulasi — tidak ada yang ditulis". Show the output to the user and wait for approval.

- [ ] **Step 2: Real run (after explicit approval)**

Run: `docker compose exec app php artisan app:clone-live-to-demo --force`
Expected: confirmation prompt, then "Cadangan pengaman: <id>", then "Selesai. DEMO kini mencerminkan LIVE." Note the backup id for the user.

- [ ] **Step 3: Verify live is untouched**

Re-run the read-only count query from the survey; every `live=` count must equal the pre-run number, `demo=` must now equal `live=` per table (except tables with skipped rows, which the report lists). Spot-check in the SPA with `system_mode = demo` (products with images, a pre-order, a payment proof).

---

## Self-Review

**Spec coverage** (spec § → task):
- §1 goal/decisions (replace, verbatim PII, exclusions) → Tasks 5, 7, 8 (docs warn about PII); exclusions are simply absent from `CloneRegistry`.
- §2 invariant (by construction / fingerprint / test) → Global Constraints; Task 2 (`LiveFingerprint`), Task 5 (`DB::table` + `scope`), Task 6 (fingerprint guard + tests), `test_result_is_identical_when_the_active_mode_is_demo`.
- §2 extra fingerprint tables (`preorder_notifications`, `pos_drafts`) → Task 2 test `test_shared_tables_and_cascade_targets_are_covered`.
- §3 command (dry run, `--force`, `--yes`, guards, backup, safe output) → Task 7.
- §4 algorithm (fingerprint → wipe → clone tiers → verify → log; self-FK patching; soft-deleted rows) → Tasks 5–6.
- §5 unique rules (allocated codes, rebuilt prefix/SKU, `-DM`, UUIDs, reference remaps, generators check) → Tasks 3, 5, 8.
- §6 files (copy, dedupe, missing source, rollback, wipe safety, size verification, dry-run bytes) → Tasks 4, 5 (`plan`), 6.
- §7 integrity checks (parity, cross-mode refs, decimal sums, stock parity, file checks) → Task 6 `verifyIntegrity`/`verifyFiles` + tests.
- §8 out of scope → nothing built for them.
- §9 testing list → Tasks 1–8 (each bullet has a named test).
- §10 rollout → Task 9; RUNBOOK → Task 8.
- **Spec addition needed:** spec §6 says "a missing source file → row cloned with NULL path"; the plan skips a `payment_proofs` row instead (its `file_path` is NOT NULL and a proof row without a file is meaningless). Update spec §6 with that sentence when approving this plan.

**Placeholder scan:** no TBD/TODO and no "fix this later" notes. Two steps legitimately depend on the first run against the real MySQL schema, because I could not execute them while planning: Task 1 Step 3 (if a fixture row is rejected for a NOT NULL column or CHECK, add the column named in the error to the fixture) and Task 5 Step 4 (if a `Duplicate entry` appears on a unique key I did not rewrite, add that column to the registry's `suffix`/`uuid`). The required-column and CHECK lists in the fixture came from `information_schema` on the dev DB, so surprises should be rare.

**Not yet executed:** none of this code has been run. Every snippet was written against the dev schema as inspected on 2026-10-11 (column names, widths, FKs, CHECK constraints, unique indexes); Task 1's smoke test is the first real check.

**Type consistency:** `CloneRegistry::scope/references/spec/tables`, `LiveFingerprint::capture/diff`, `CodeAllocator::artist/category/productPrefix/sku/suffixed/mapping`, `FileCopier::copy/discardCopies/measure` and counters `copied/bytes/missing`, `LiveToDemoCloner::plan/run/afterClone`, `SafeError::describe`, `CloneLiveToDemo::CONFIRM_QUESTION` are used with identical names/signatures across tasks. Report keys (`deleted, cloned, skipped, files{copied,bytes,missing}, references_dropped, codes`) match between Task 5, Task 6 and Task 7's `renderReport`.

**Known limits stated, not hidden:** concurrent writers during the run abort it (spec §2); no test forces a `CloneException` from `mapForeignKey` because a LIVE row pointing at a DEMO parent is blocked earlier by the wipe's FK restriction (covered instead by the cross-mode integrity check test); `FileCopier` dedupes proofs too (identical to live behavior).
