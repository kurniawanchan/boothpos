<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 036-bom-variant-stock-ux (US1, FR-002/FR-004) — PUT /variants/{variant}/bom:
 * tombol Simpan menyimpan SEMUA jumlah yang berubah sekaligus, semua-atau-tidak-
 * sama-sekali, lewat VariantBomService (kunci -> tulis -> audit -> sinkron harga
 * modal dalam SATU transaksi).
 */
class BomQuantitiesTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $seller;

    private ProductVariant $variant;

    /** @var array<int, array> baris BOM (id, ...) untuk 3 baris PO: 500, 300, 1000 per unit */
    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->seller = Artist::factory()->create();
        $this->variant = $this->variantFor($this->seller);
        $this->variant->update(['current_stock' => 7]);

        $items = $this->po($this->seller, 'ordered', [
            ['type' => 'material', 'name' => 'Chain', 'price' => 500],
            ['type' => 'material', 'name' => 'Ring', 'price' => 300],
            ['type' => 'service', 'name' => 'Assembly', 'price' => 1000],
        ]);
        $this->rows = $this->addItems($this->variant, collect($items)->map(fn ($i) => ['purchase_order_item_id' => $i->id, 'qty' => 1])->all())
            ->assertCreated()->json('data');
    }

    private function save(array $lines, ?ProductVariant $variant = null): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/v1/variants/'.($variant ?? $this->variant)->id.'/bom', ['lines' => $lines]);
    }

    private function qty(int $rowId): string
    {
        return (string) ProductVariantBomLine::find($rowId)->qty_needed;
    }

    public function test_it_saves_several_changed_rows_in_one_call_and_returns_the_refreshed_totals(): void
    {
        $response = $this->save([
            ['id' => $this->rows[0]['id'], 'qty_needed' => 11],
            ['id' => $this->rows[1]['id'], 'qty_needed' => '2'],
        ])->assertOk();

        $this->assertSame('11.0000', $this->qty($this->rows[0]['id']));
        $this->assertSame('2.0000', $this->qty($this->rows[1]['id']));
        $this->assertSame('1.0000', $this->qty($this->rows[2]['id']));
        // 11*500 + 2*300 + 1*1000 = 7100
        $this->assertSame('7100.00', $response->json('summary.bom_cost'));
        $this->assertCount(3, $response->json('data'));
    }

    public function test_one_invalid_row_rejects_the_whole_batch_and_saves_nothing(): void
    {
        $this->save([
            ['id' => $this->rows[0]['id'], 'qty_needed' => 5],
            ['id' => $this->rows[1]['id'], 'qty_needed' => 1.5],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.1.qty_needed');

        $this->assertSame('1.0000', $this->qty($this->rows[0]['id']));
        $this->assertSame(0, ActivityLog::where('action', 'bom_qty_changed')->count());
    }

    public function test_a_line_of_another_variant_is_a_conflict_and_nothing_is_saved(): void
    {
        $other = $this->variantFor($this->seller);
        [$item] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Foreign', 'price' => 100]]);
        $foreign = $this->addItems($other, [['purchase_order_item_id' => $item->id]])->assertCreated()->json('data.0.id');

        $this->save([
            ['id' => $this->rows[0]['id'], 'qty_needed' => 5],
            ['id' => $foreign, 'qty_needed' => 5],
        ])->assertStatus(409)->assertJsonPath('code', 'bom_line_not_found');

        $this->assertSame('1.0000', $this->qty($this->rows[0]['id']));
        $this->assertSame('1.0000', $this->qty($foreign));
    }

    public function test_unchanged_values_are_skipped_and_each_changed_line_gets_one_audit_row(): void
    {
        $this->save([
            ['id' => $this->rows[0]['id'], 'qty_needed' => 1], // sama dengan yang tersimpan
            ['id' => $this->rows[1]['id'], 'qty_needed' => 4],
            ['id' => $this->rows[2]['id'], 'qty_needed' => 6],
        ])->assertOk();

        $logs = ActivityLog::where('action', 'bom_qty_changed')->get();
        $this->assertCount(2, $logs);
        $this->assertEqualsCanonicalizing([$this->rows[1]['id'], $this->rows[2]['id']], $logs->pluck('entity_id')->all());
        $this->assertSame($this->owner->id, $logs->first()->user_id);
    }

    public function test_a_failure_midway_rolls_back_every_write_and_every_audit_row(): void
    {
        $calls = 0;
        Event::listen('eloquent.updating: '.ProductVariantBomLine::class, function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('simulasi gagal di baris kedua');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->save([
                ['id' => $this->rows[0]['id'], 'qty_needed' => 9],
                ['id' => $this->rows[1]['id'], 'qty_needed' => 9],
            ]);
            $this->fail('seharusnya melempar');
        } catch (\RuntimeException) {
            // diharapkan
        } finally {
            Event::forget('eloquent.updating: '.ProductVariantBomLine::class);
        }

        $this->assertSame('1.0000', $this->qty($this->rows[0]['id']));
        $this->assertSame('1.0000', $this->qty($this->rows[1]['id']));
        $this->assertSame(0, ActivityLog::where('action', 'bom_qty_changed')->count());
    }

    public function test_a_complete_bom_resyncs_the_cost_price_exactly_once(): void
    {
        $this->postJson("/api/v1/variants/{$this->variant->id}/bom/complete")->assertOk();
        $this->assertSame('1800.00', number_format((float) $this->variant->fresh()->cost_price, 2, '.', ''));
        $before = ActivityLog::where('action', 'cost_price_synced')->count();

        $this->save([
            ['id' => $this->rows[0]['id'], 'qty_needed' => 2],
            ['id' => $this->rows[1]['id'], 'qty_needed' => 3],
        ])->assertOk();

        // 2*500 + 3*300 + 1*1000 = 2900
        $this->assertSame('2900.00', number_format((float) $this->variant->fresh()->cost_price, 2, '.', ''));
        $this->assertSame($before + 1, ActivityLog::where('action', 'cost_price_synced')->count());
    }

    public function test_it_validates_the_shape_of_the_request(): void
    {
        $id = $this->rows[0]['id'];

        $this->save([])->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->save([['id' => $id, 'qty_needed' => 2], ['id' => $id, 'qty_needed' => 3]])->assertStatus(422)->assertJsonValidationErrors('lines.0.id');
        $this->save([['id' => $id]])->assertStatus(422)->assertJsonValidationErrors('lines.0.qty_needed');
        $this->save(array_fill(0, 201, ['id' => $id, 'qty_needed' => 2]))->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_it_needs_both_the_products_and_purchase_orders_menus(): void
    {
        $productsOnly = User::factory()->create(['role_id' => Role::factory()->create(['name' => 'Staf Produk', 'menu_keys' => ['products']])->id]);
        $this->actingAs($productsOnly, 'sanctum');

        $this->save([['id' => $this->rows[0]['id'], 'qty_needed' => 2]])->assertForbidden();
        $this->assertSame('1.0000', $this->qty($this->rows[0]['id']));
    }

    public function test_the_payload_shows_the_current_stock_and_a_save_never_changes_it(): void
    {
        $get = $this->getJson("/api/v1/variants/{$this->variant->id}/bom")->assertOk();
        $this->assertSame(7, $get->json('summary.current_stock'));

        $put = $this->save([['id' => $this->rows[0]['id'], 'qty_needed' => 4]])->assertOk();

        $this->assertSame(7, $put->json('summary.current_stock'));
        $this->assertSame(7, $this->variant->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count());
    }
}
