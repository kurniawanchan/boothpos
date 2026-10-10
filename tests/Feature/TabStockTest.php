<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US2 — aturan stok pada tab (data-model.md, tabel stok; FR-017–FR-019).
 * Stok dikurangi saat item DITAMBAHKAN ke tab, dikembalikan saat baris yang
 * belum dikirim dikurangi/dihapus, dan TIDAK PERNAH berubah karena
 * pembayaran.
 */
class TabStockTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
    }

    public function test_adding_a_line_writes_a_sale_movement_referencing_the_item(): void
    {
        $v = $this->makeVariant(stock: 10);

        $line = $this->apiAddLine($this->tab, $v->id, 3)->assertCreated()->json('lines.0.id');

        $this->assertSame(7, $v->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $v->id, 'type' => 'sale', 'qty_change' => -3,
            'reference_type' => 'order_item', 'reference_id' => $line,
        ]);
    }

    public function test_changing_quantity_moves_only_the_difference(): void
    {
        $v = $this->makeVariant(stock: 10);
        $line = $this->apiAddLine($this->tab, $v->id, 3)->json('lines.0.id');

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 5])->assertOk();
        $this->assertSame(5, $v->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $v->id, 'type' => 'sale', 'qty_change' => -2, 'reference_id' => $line]);

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 4])->assertOk();
        $this->assertSame(6, $v->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $v->id, 'type' => 'return', 'qty_change' => 1, 'reference_id' => $line]);
    }

    public function test_an_unchanged_quantity_writes_no_movement(): void
    {
        $v = $this->makeVariant(stock: 10);
        $line = $this->apiAddLine($this->tab, $v->id, 3)->json('lines.0.id');
        $before = StockMovement::count();

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 3, 'note' => 'x'])->assertOk();

        $this->assertSame($before, StockMovement::count());
    }

    public function test_removing_an_unsent_line_returns_its_stock_exactly_once(): void
    {
        $v = $this->makeVariant(stock: 10);
        $line = $this->apiAddLine($this->tab, $v->id, 4)->json('lines.0.id');

        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$line}")->assertOk();
        $this->assertSame(10, $v->fresh()->current_stock);

        // Mengulang penghapusan tidak boleh mengembalikan stok lagi.
        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$line}")->assertNotFound();
        $this->assertSame(10, $v->fresh()->current_stock);
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
    }

    public function test_the_whole_edit_cycle_nets_back_to_the_starting_stock(): void
    {
        $v = $this->makeVariant(stock: 10);
        $line = $this->apiAddLine($this->tab, $v->id, 3)->json('lines.0.id');
        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 5]);
        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 4]);
        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$line}");

        $this->assertSame(10, $v->fresh()->current_stock);
        $this->assertSame(0, (int) StockMovement::where('variant_id', $v->id)->sum('qty_change'));
    }

    public function test_an_untracked_dish_writes_no_movement_at_all(): void
    {
        $dish = $this->makeVariant(stock: 0, variantOverrides: ['track_stock' => false]);
        $line = $this->apiAddLine($this->tab, $dish->id, 2)->json('lines.0.id');
        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 5]);
        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$line}");

        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_refused_increase_leaves_the_stock_and_the_quantity_unchanged(): void
    {
        $v = $this->makeVariant(stock: 3);
        $line = $this->apiAddLine($this->tab, $v->id, 2)->json('lines.0.id');

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 9])->assertStatus(409);

        $this->assertSame(1, $v->fresh()->current_stock);
        $this->assertSame(2, OrderItem::find($line)->qty);
    }

    public function test_settling_the_tab_never_touches_stock(): void
    {
        $v = $this->makeVariant(stock: 10, price: 10000);
        $this->apiAddLine($this->tab, $v->id, 2);
        $movements = StockMovement::count();

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(8, $v->fresh()->current_stock);
    }
}
