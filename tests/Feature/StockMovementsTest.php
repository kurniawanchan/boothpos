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
}
