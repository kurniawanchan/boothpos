<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 036-bom-variant-stock-ux — GET /stock/movements dipakai layar Stok DAN riwayat
 * transaksi per varian. Menjaga: gerbang akses sisi server (dulu terbuka untuk
 * semua peran), bentuk baris (user_name, produk, referensi), dan penyelesaian
 * referensi yang TIDAK PERNAH menampilkan nomor yang salah — `reference_id`
 * artinya berbeda per penulis (order vs item vs pre-order), jadi tiap
 * penyelesaian dijaga bahwa order/item itu memang menyangkut varian ini.
 */
class StockMovementsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ProductVariant $variant;

    private ProductVariant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'name' => 'Chan Owner']);
        $this->actingAs($this->owner, 'sanctum');

        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => $category->id, 'name' => 'MCYT', 'is_preorder' => true]);
        $this->variant = $product->variants()->create(['sku' => 'SPFKCMCY0001', 'variant_name' => 'Slippery 5cm', 'sell_price' => 20000, 'cost_price' => 5000, 'current_stock' => 10]);
        $this->other = Product::factory()->create(['artist_id' => $product->artist_id, 'category_id' => $category->id, 'name' => 'Other'])
            ->variants()->create(['sku' => 'SPFKCOTH0001', 'variant_name' => 'Std', 'sell_price' => 10000, 'cost_price' => 1000, 'current_stock' => 10]);
    }

    private function movements(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/stock/movements'.($query ? "?{$query}" : ''));
    }

    private function sell(int $qty = 2): \App\Models\Order
    {
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $this->owner->id, 'status' => 'open']);

        return app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => 20000 * $qty]],
        ], $this->owner);
    }

    private function preorder(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => Customer::factory()->create()->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();
    }

    private function movement(array $attrs): StockMovement
    {
        return StockMovement::create(array_merge([
            'variant_id' => $this->variant->id, 'type' => 'adjustment', 'qty_change' => 1,
            'stock_before' => 0, 'stock_after' => 1, 'created_at' => now(),
        ], $attrs));
    }

    // --- akses -------------------------------------------------------------

    public function test_a_role_without_stock_or_products_menu_is_refused_server_side(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->movements()->assertForbidden();
    }

    public function test_owner_admin_and_inventory_can_read_movements(): void
    {
        foreach (['owner', 'admin', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');

            $this->movements()->assertOk();
        }
    }

    // --- bentuk baris ------------------------------------------------------

    public function test_rows_carry_user_product_and_variant_details(): void
    {
        app(StockService::class)->applyMovement($this->variant, 'adjustment', 5, reason: 'tambah stok', userId: $this->owner->id);

        $row = $this->movements("variant_id={$this->variant->id}")->assertOk()->json('data.0');

        $this->assertSame('SPFKCMCY0001', $row['sku']);
        $this->assertSame('Chan Owner', $row['user_name']);
        $this->assertSame('Slippery 5cm', $row['variant_name']);
        $this->assertSame('MCYT', $row['product_name']);
        $this->assertSame($this->variant->product_id, $row['product_id']);
        $this->assertSame('tambah stok', $row['reason']);
        $this->assertNull($row['reference']);
    }

    public function test_user_name_is_null_when_the_movement_has_no_user(): void
    {
        $this->movement(['user_id' => null]);

        $this->assertNull($this->movements()->json('data.0.user_name'));
    }

    public function test_product_id_is_null_when_the_product_was_deleted(): void
    {
        $this->movement([]);
        $this->variant->product->delete();

        $row = $this->movements("variant_id={$this->variant->id}")->assertOk()->json('data.0');

        $this->assertNull($row['product_id']);
        $this->assertSame('SPFKCMCY0001', $row['sku']);
    }

    // --- referensi (penulis nyata) -----------------------------------------

    public function test_a_sale_resolves_to_its_order_number(): void
    {
        $order = $this->sell();

        $ref = $this->movements('type=sale')->json('data.0.reference');

        $this->assertSame(['type' => 'order', 'id' => $order->id, 'number' => $order->order_number], $ref);
    }

    public function test_a_void_return_resolves_to_the_same_order_number(): void
    {
        $order = $this->sell();
        app(OrderService::class)->void($order, 'salah input', $this->owner);

        $ref = $this->movements('type=return')->json('data.0.reference');

        $this->assertSame('order', $ref['type']);
        $this->assertSame($order->order_number, $ref['number']);
    }

    public function test_preorder_arrival_and_handover_resolve_to_the_preorder_number(): void
    {
        $preorder = $this->preorder();
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", ['method' => 'cash', 'amount' => 20000, 'purpose' => 'full'])->assertCreated();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'settled'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'handed_over'])->assertOk();

        $rows = collect($this->movements("variant_id={$this->variant->id}")->json('data'));

        $this->assertCount(2, $rows); // tiba + serah terima
        foreach ($rows as $row) {
            $this->assertSame(['type' => 'preorder', 'id' => $preorder['id'], 'number' => $preorder['preorder_number']], $row['reference'], $row['type']);
        }
    }

    public function test_the_preorder_edit_delta_resolves_but_an_arrival_row_orphaned_by_the_edit_shows_no_reference(): void
    {
        $preorder = $this->preorder();
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", ['method' => 'cash', 'amount' => 20000, 'purpose' => 'full'])->assertCreated();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();
        // Edit setelah tiba: item pre-order dibangun ulang (id baru) dan selisih qty ditulis sebagai 'purchase'.
        $this->putJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [['id' => $preorder['items'][0]['id'], 'variant_id' => $this->variant->id, 'qty' => 3]],
        ])->assertOk();

        $byRef = collect($this->movements("variant_id={$this->variant->id}")->json('data'))->keyBy(fn ($r) => $r['qty_change']);

        // Selisih edit (+2) memakai referensi 'preorder' baru -> terselesaikan.
        $this->assertSame($preorder['preorder_number'], $byRef[2]['reference']['number']);
        // Baris tiba (+1) menunjuk item yang sudah tidak ada -> null, BUKAN nomor tebakan (keterbatasan yang dicatat).
        $this->assertNull($byRef[1]['reference']);
    }

    // --- penjaga: tidak pernah nomor yang salah ---------------------------------

    public function test_a_reference_to_an_order_of_another_variant_is_dropped(): void
    {
        $order = $this->sell(); // hanya memuat $this->variant
        $this->movement(['variant_id' => $this->other->id, 'type' => 'sale', 'qty_change' => -1, 'reference_type' => 'order_item', 'reference_id' => $order->id]);

        $row = $this->movements("variant_id={$this->other->id}")->json('data.0');

        $this->assertNull($row['reference']);
    }

    public function test_a_legacy_preorder_item_reference_holding_a_preorder_id_never_shows_a_wrong_number(): void
    {
        $preorder = $this->preorder();
        // Baris lama dari jalur edit: id PRE-ORDER di bawah 'preorder_item'; id yang sama tidak menunjuk item varian ini.
        $this->movement(['variant_id' => $this->other->id, 'type' => 'purchase', 'reference_type' => 'preorder_item', 'reference_id' => $preorder['id']]);

        $this->assertNull($this->movements("variant_id={$this->other->id}")->json('data.0.reference'));
    }

    public function test_adjustment_initial_and_import_movements_have_no_reference(): void
    {
        $this->movement(['type' => 'initial']);
        $this->movement(['type' => 'adjustment', 'reference_type' => 'MasterDataImport', 'reference_id' => 7]);
        $this->movement(['type' => 'adjustment']);

        foreach ($this->movements()->json('data') as $row) {
            $this->assertNull($row['reference']);
        }
    }

    // --- filter, urutan, performa ---------------------------------------------

    public function test_filters_and_a_stable_newest_first_order(): void
    {
        $t = now();
        $a = $this->movement(['type' => 'adjustment', 'created_at' => $t, 'reason' => 'a']);
        $b = $this->movement(['type' => 'adjustment', 'created_at' => $t, 'reason' => 'b']); // timestamp sama -> id menentukan
        $this->movement(['type' => 'initial', 'created_at' => $t->copy()->subDays(3), 'reason' => 'old']);
        $this->movement(['variant_id' => $this->other->id, 'reason' => 'other-variant']);

        $ids = collect($this->movements("variant_id={$this->variant->id}")->json('data'))->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], array_slice($ids, 0, 2));

        $this->assertCount(2, $this->movements("variant_id={$this->variant->id}&type=adjustment")->json('data'));
        $this->assertCount(2, $this->movements("variant_id={$this->variant->id}&date_from=".$t->copy()->subDay()->toDateString())->json('data'));
        $this->assertCount(1, $this->movements("variant_id={$this->variant->id}&date_to=".$t->copy()->subDays(2)->toDateString())->json('data'));
    }

    public function test_the_newest_stock_after_equals_the_current_stock(): void
    {
        $service = app(StockService::class);
        $service->applyMovement($this->variant, 'purchase', 5, userId: $this->owner->id);
        $service->applyMovement($this->variant, 'sale', -3, userId: $this->owner->id);

        $newest = $this->movements("variant_id={$this->variant->id}")->json('data.0');

        $this->assertSame($this->variant->fresh()->current_stock, $newest['stock_after']);
    }

    public function test_resolving_references_does_not_add_a_query_per_row(): void
    {
        $order = $this->sell(1);
        $pre = $this->preorder();
        for ($i = 0; $i < 4; $i++) {
            $this->movement(['type' => 'sale', 'qty_change' => -1, 'reference_type' => 'order_item', 'reference_id' => $order->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->movements('per_page=2')->assertOk();
        $small = count(DB::getQueryLog());

        for ($i = 0; $i < 12; $i++) {
            $this->movement(['type' => 'sale', 'qty_change' => -1, 'reference_type' => 'order_item', 'reference_id' => $order->id]);
            $this->movement(['type' => 'purchase', 'reference_type' => 'preorder_item', 'reference_id' => $pre['items'][0]['id']]);
        }
        DB::flushQueryLog();
        $this->movements('per_page=25')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($small, $large, 'jumlah query harus tetap saat baris bertambah');
    }

    // --- 048: pencarian, filter penjual/kategori, kolom baru ---------------------

    /** Produk milik penjual/kategori lain dengan satu varian dan satu pergerakan. */
    private function otherSellerVariant(string $productName, string $variantName, string $sku): ProductVariant
    {
        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create(['name' => 'Penjual B'])->id,
            'category_id' => Category::factory()->create(['name' => 'Stiker'])->id,
            'name' => $productName,
        ]);
        $variant = $product->variants()->create(['sku' => $sku, 'variant_name' => $variantName, 'sell_price' => 5000, 'cost_price' => 100, 'current_stock' => 7]);
        $this->movement(['variant_id' => $variant->id]);

        return $variant;
    }

    private function skus(string $query): array
    {
        return collect($this->movements($query)->assertOk()->json('data'))->pluck('sku')->unique()->sort()->values()->all();
    }

    public function test_search_matches_variant_name_sku_and_product_name_case_insensitively(): void
    {
        $this->movement([]);
        $this->movement(['variant_id' => $this->other->id]);

        $this->assertSame(['SPFKCMCY0001'], $this->skus('search=slippery'));   // nama varian, huruf kecil
        $this->assertSame(['SPFKCMCY0001'], $this->skus('search=kcmcy'));       // potongan SKU
        $this->assertSame(['SPFKCOTH0001'], $this->skus('search=OTHER'));       // nama produk
        $this->assertSame([], $this->skus('search=tidak-ada-ini'));
    }

    public function test_blank_search_is_ignored_and_wildcards_are_literal(): void
    {
        $this->movement([]);
        $this->movement(['variant_id' => $this->other->id]);

        $this->assertCount(2, $this->skus('search=%20%20%20'));  // hanya spasi = tanpa filter
        $this->assertSame([], $this->skus('search=%25'));         // "%" literal, bukan "cocok semua"
        $this->assertSame([], $this->skus('search=_'));           // "_" literal, bukan "satu karakter apa saja"
    }

    public function test_seller_and_category_filters_accept_one_or_many_and_combine_with_search(): void
    {
        $this->movement([]);
        $b = $this->otherSellerVariant('Stiker Lucu', 'Kecil', 'SPFSTLUC0001');

        $sellerA = $this->variant->product->artist_id;
        $sellerB = $b->product->artist_id;
        $catA = $this->variant->product->category_id;
        $catB = $b->product->category_id;

        $this->assertSame(['SPFSTLUC0001'], $this->skus("artist_id[]={$sellerB}"));
        $this->assertSame(['SPFKCMCY0001'], $this->skus("category_id[]={$catA}"));
        $this->assertSame(['SPFKCMCY0001', 'SPFSTLUC0001'], $this->skus("artist_id[]={$sellerA}&artist_id[]={$sellerB}"));
        $this->assertSame(['SPFSTLUC0001'], $this->skus("artist_id[]={$sellerB}&category_id[]={$catB}&search=kecil"));
        $this->assertSame([], $this->skus("artist_id[]={$sellerB}&category_id[]={$catA}")); // AND, bukan OR
        $this->assertSame([], $this->skus('artist_id[]=999999'));                           // id usang = kosong, bukan error
    }

    public function test_new_filters_combine_with_type_dates_and_update_the_paging_total(): void
    {
        $this->movement(['type' => 'adjustment', 'created_at' => '2026-10-01 10:00:00']);
        $this->movement(['type' => 'purchase', 'created_at' => '2026-10-20 10:00:00']);
        $this->otherSellerVariant('Stiker Lucu', 'Kecil', 'SPFSTLUC0001');

        $res = $this->movements('search=slippery&type=purchase&date_from=2026-10-10')->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame(1, $res->json('meta.last_page'));
        $this->assertSame('purchase', $res->json('data.0.type'));
    }

    public function test_movements_of_deleted_variants_and_products_stay_findable_and_keep_the_seller_name(): void
    {
        $this->movement([]);
        $this->movement(['variant_id' => $this->other->id]);
        $this->other->delete();                         // varian dihapus (soft)
        $this->variant->product->artist->delete();      // penjual dihapus (soft)
        $this->variant->product->delete();               // produk dihapus (soft)

        $this->assertSame(['SPFKCOTH0001'], $this->skus('search=std'));
        $this->assertSame(['SPFKCMCY0001'], $this->skus('search=MCYT'));

        $row = collect($this->movements('search=MCYT')->json('data'))->first();
        $this->assertNotNull($row['artist_name']);
        $this->assertNull($row['product_id']); // aturan lama: produk terhapus -> null
    }

    public function test_rows_carry_picture_seller_category_and_live_available_stock(): void
    {
        app(StockService::class)->applyMovement($this->variant, 'adjustment', 5, reason: 'tambah', userId: $this->owner->id); // 10 -> 15
        app(StockService::class)->applyMovement($this->variant, 'adjustment', -3, reason: 'koreksi', userId: $this->owner->id); // 15 -> 12

        $rows = collect($this->movements("variant_id={$this->variant->id}")->assertOk()->json('data'));
        $newest = $rows->first();
        $oldest = $rows->last();

        $this->assertSame(12, $newest['current_stock']);
        $this->assertSame(12, $oldest['current_stock']);   // stok TERKINI, bukan stock_after baris itu
        $this->assertSame(15, $oldest['stock_after']);
        $this->assertSame($this->variant->product->artist_id, $newest['artist_id']);
        $this->assertSame($this->variant->product->artist->name, $newest['artist_name']);
        $this->assertSame($this->variant->product->category_id, $newest['category_id']);
        $this->assertSame($this->variant->product->category->name, $newest['category_name']);
        $this->assertNull($newest['image_url']); // tanpa gambar = null (SPA menampilkan placeholder)
    }

    public function test_image_url_prefers_the_variant_picture_then_the_product_picture(): void
    {
        $this->movement([]);
        $this->movement(['variant_id' => $this->other->id]);
        $this->variant->update(['image_path' => 'variants/own.jpg']);
        $this->other->product->update(['image_path' => 'products/parent.jpg']);

        $own = collect($this->movements('search=slippery')->json('data'))->first();
        $fallback = collect($this->movements('search=OTHER')->json('data'))->first();

        $this->assertStringContainsString('variants/own.jpg', $own['image_url']);
        $this->assertStringContainsString('products/parent.jpg', $fallback['image_url']);
    }

    public function test_rows_never_expose_cost_and_demo_movements_stay_out_of_live(): void
    {
        $this->movement([]);
        \App\Support\ModeGate::runAs('demo', fn () => $this->movement(['qty_change' => 99, 'stock_after' => 99]));

        $res = $this->movements()->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertStringNotContainsString('cost', strtolower($res->getContent()));
    }

    public function test_new_fields_and_filters_keep_a_constant_query_count_across_many_sellers(): void
    {
        $this->movement([]);
        $this->movements()->assertOk(); // pemanasan: cache setelan/lisensi terisi sekali, bukan N+1
        DB::enableQueryLog();
        $this->movements('per_page=25&search=r')->assertOk(); // "r" ada di "Slippery" dan "Varian"
        $small = count(DB::getQueryLog());

        foreach (range(1, 8) as $i) {
            $this->otherSellerVariant("Produk {$i}", "Varian {$i}", "SPFXX{$i}0001");
        }
        DB::flushQueryLog();
        $this->movements('per_page=25&search=r')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($small, $large, 'penjual/kategori/gambar harus dimuat eager, bukan per baris');
    }
}
