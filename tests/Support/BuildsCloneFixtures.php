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
