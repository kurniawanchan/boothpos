<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\KitchenTicketItem;
use App\Models\MaterialStockMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 087 US3 (T036) — batal "buang" di tab terbuka mencatat bahan terbuang (FR-018, disetujui pemilik 2026-10-31).
 */
class MaterialWasteTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private int $tab;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->indomie->product->forceFill(['station_id' => $this->makeStation('Dapur')->id])->save();
        $this->tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
    }

    /** Baris dikirim ke dapur dengan status item dapur tertentu. */
    private function sentLine(int $qty, array $opts, string $kitchen = 'preparing'): int
    {
        $line = max($this->apiAddLine($this->tab, $this->indomie->id, $qty, ['modifier_option_ids' => $opts])->assertCreated()->json('lines.*.id'));
        $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();
        KitchenTicketItem::where('order_item_id', $line)->update(['status' => $kitchen]);

        return $line;
    }

    private function cancel(int $line, string $kitchen, string $stock)
    {
        $this->actingAs($this->owner, 'sanctum'); // pemilik memegang order.void: tanpa token persetujuan

        return $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['reason_code' => 'wrong_item', 'kitchen_status' => $kitchen, 'stock_decision' => $stock, 'reason' => 'salah pesan']);
    }

    public function test_cancelling_a_cooked_line_as_waste_records_wasted_materials_before_the_line_is_deleted(): void
    {
        $line = $this->sentLine(2, $this->opts('soto', 'telur'));

        $this->cancel($line, 'started', 'waste')->assertOk();

        $waste = MaterialStockMovement::where('type', 'waste')->get();
        $this->assertCount(2, $waste);
        $this->assertTrue($waste->every(fn ($m) => $m->reference_type === 'order_item' && (int) $m->reference_id === $line));
        $this->assertSame(48.0, $this->balance('soto'));
        $this->assertSame(98.0, $this->balance('telur'));
        $this->assertDatabaseMissing('order_items', ['id' => $line]);
        $this->assertTrue(ActivityLog::where('entity_id', $this->tab)->exists());
    }

    public function test_a_return_decision_moves_no_material(): void
    {
        $line = $this->sentLine(1, $this->opts('soto'), 'new');

        $this->cancel($line, 'not_started', 'return')->assertOk();

        $this->assertCount(0, $this->consumptionMovements());
    }

    public function test_a_refused_approval_records_no_waste(): void
    {
        $line = $this->sentLine(1, $this->opts('soto'));

        // Kasir tanpa token persetujuan: 409 override_required, tidak ada bahan terbuang.
        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['reason_code' => 'wrong_item', 'kitchen_status' => 'started', 'stock_decision' => 'waste', 'reason' => 'x'])
            ->assertStatus(409);

        $this->assertCount(0, $this->consumptionMovements());
    }

    public function test_a_wasted_line_is_not_deducted_again_when_the_tab_is_paid(): void
    {
        $line = $this->sentLine(1, $this->opts('soto', 'telur'));
        $this->apiAddLine($this->tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('ayam')])->assertCreated();
        $this->cancel($line, 'started', 'waste')->assertOk();

        $this->actingAs($this->cashier, 'sanctum');
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => (float) Order::find($this->tab)->total_amount]])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame(49.0, $this->balance('soto'));
        $this->assertSame(49.0, $this->balance('ayam'));
        $this->assertCount(1, $this->consumptionMovements('sale'));
    }

    public function test_voiding_an_open_tab_wastes_only_the_cooked_lines(): void
    {
        $this->sentLine(1, $this->opts('soto'));                      // dimasak → terbuang
        $this->apiAddLine($this->tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('ayam')]); // belum dikirim → tidak

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/tabs/{$this->tab}/void", ['reason' => 'tamu pergi'])->assertOk();

        $this->assertSame(49.0, $this->balance('soto'));
        $this->assertSame(50.0, $this->balance('ayam'));
        $this->assertCount(1, $this->consumptionMovements('waste'));
    }

    public function test_waste_may_drive_the_balance_negative(): void
    {
        $this->setStock('telur', $this->outletA, 1);
        $line = $this->sentLine(2, $this->opts('soto', 'double')); // Rasa Kuah wajib

        $this->cancel($line, 'started', 'waste')->assertOk();

        $this->assertSame(-3.0, $this->balance('telur')); // 1 − 2×2
    }
}
