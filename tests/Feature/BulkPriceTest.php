<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuChange;
use App\Models\PriceChangeBatch;
use App\Models\PriceHistory;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T054) — ubah harga massal: pratinjau, validasi, pembulatan, semua-atau-tidak,
 * riwayat, mode rilis/persetujuan, dan harga order lama yang tidak berubah.
 */
class BulkPriceTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function item(string $name, float $price, ?Category $cat = null)
    {
        $v = $this->makeVariant(stock: 5, price: $price);
        $v->product->update(['name' => $name] + ($cat ? ['category_id' => $cat->id] : []));

        return $v->fresh();
    }
    /**
     * Tanggal bisnis TOKO (zona waktu toko, bukan UTC): validasi tanggal berlaku memakai BusinessDay,
     * jadi `now()` (UTC) membuat tes gagal antara 00:00-07:00 WIB — dikunci di sini.
     */
    private function businessToday(int $addDays = 0): string
    {
        return \Carbon\CarbonImmutable::parse(\App\Support\BusinessDay::today())->addDays($addDays)->toDateString();
    }


    private function body(array $over = []): array
    {
        return array_merge(['category_ids' => [], 'type' => 'percent', 'value' => 5, 'rounding' => '500', 'effective_from' => $this->businessToday()], $over);
    }

    public function test_preview_is_server_computed_with_percent_amount_and_rounding(): void
    {
        $cat = Category::factory()->create();
        $a = $this->item('Kopi', 9900, $cat);
        $b = $this->item('Teh', 12000, $cat);
        $this->item('Lain', 5000);                                                          // kategori lain → tidak ikut

        $p = $this->postJson('/api/v1/price-changes/preview', $this->body(['category_ids' => [$cat->category_id ?? $cat->id]]))->assertOk()->json();
        $rows = collect($p['items'])->keyBy('variant_id');

        $this->assertSame(2, $p['count']);
        $this->assertSame('10500.00', $rows[$a->id]['new_price']);                          // 9.900×1,05 = 10.395 → 10.500
        $this->assertSame('12500.00', $rows[$b->id]['new_price']);                          // 12.600 → 12.500
        $this->assertSame('9900.00', $rows[$a->id]['old_price']);

        $amount = $this->postJson('/api/v1/price-changes/preview', $this->body(['category_ids' => [$cat->id], 'type' => 'amount', 'value' => -1000, 'rounding' => 'none']))->json('items');
        $this->assertSame(['8900.00', '11000.00'], array_column($amount, 'new_price'));
    }

    public function test_child_categories_are_included_and_discontinued_items_are_not(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $this->item('Anak', 10000, $child);
        $gone = $this->item('Gone', 10000, $parent);
        $gone->product->update(['is_active' => false]);

        $names = array_column($this->postJson('/api/v1/price-changes/preview', $this->body(['category_ids' => [$parent->id]]))->json('items'), 'product');

        $this->assertSame(['Anak'], $names);
    }

    public function test_invalid_input_is_refused_with_the_field_named_and_nothing_changes(): void
    {
        $cat = Category::factory()->create();
        $v = $this->item('Kopi', 10000, $cat);

        $cases = [
            'category_ids' => $this->body(['category_ids' => []]),
            'value' => $this->body(['category_ids' => [$cat->id], 'value' => null]),
            'value ' => $this->body(['category_ids' => [$cat->id], 'value' => 0]),
            'type' => $this->body(['category_ids' => [$cat->id], 'type' => 'kali']),
            'rounding' => $this->body(['category_ids' => [$cat->id], 'rounding' => '1000']),
            'effective_from' => $this->body(['category_ids' => [$cat->id], 'effective_from' => $this->businessToday(-1)]),
        ];
        foreach ($cases as $field => $body) {
            $this->postJson('/api/v1/price-changes', $body)->assertStatus(422)->assertJsonValidationErrors(trim($field));
        }

        // Hasil nol/negatif → galat per varian.
        $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$cat->id], 'type' => 'amount', 'value' => -10000, 'rounding' => 'none']))
            ->assertStatus(422)->assertJsonValidationErrors('value');

        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);
        $this->assertSame(0, PriceChangeBatch::count());
    }

    public function test_a_scope_that_matches_nothing_is_refused(): void
    {
        $empty = Category::factory()->create();

        $this->postJson('/api/v1/price-changes/preview', $this->body(['category_ids' => [$empty->id]]))->assertOk()->assertJsonPath('count', 0);
        $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$empty->id]]))->assertStatus(422)->assertJsonValidationErrors('category_ids');
    }

    public function test_apply_changes_prices_writes_one_history_row_per_variant_and_never_touches_recorded_orders(): void
    {
        $cat = Category::factory()->create();
        $v = $this->item('Kopi', 10000, $cat);
        $session = $this->makeShift($this->owner);
        $this->postJson('/api/v1/orders', ['session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $v->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]]])->assertCreated();
        PriceHistory::where('variant_id', $v->id)->where('source', 'bulk')->delete();

        $r = $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$cat->id], 'value' => 10]))->assertCreated()->json();

        $this->assertSame([1, 0, 0], [$r['applied'], $r['pending'], $r['awaiting_approval']]);
        $this->assertSame('11000.00', (string) $v->fresh()->sell_price);
        $this->assertSame(1, PriceHistory::where('variant_id', $v->id)->where('source', 'bulk')->count());
        $this->assertSame('10000.00', (string) \App\Models\OrderItem::first()->sell_price);       // snapshot order lama utuh
        $this->assertDatabaseHas('price_change_batches', ['id' => $r['batch_id'], 'type' => 'percent']);
    }

    public function test_apply_is_all_or_nothing_when_one_variant_fails(): void
    {
        $cat = Category::factory()->create();
        $this->item('A', 10000, $cat);
        $this->item('B', 10000, $cat);
        $calls = 0;
        $this->mock(PriceService::class, function ($m) use (&$calls) {
            $m->shouldReceive('setBasePrice')->andReturnUsing(function () use (&$calls) {
                if (++$calls === 2) {
                    throw new \RuntimeException('gagal di varian kedua');
                }

                return ['changed' => true, 'applied' => true, 'pending' => false, 'requires_approval' => false, 'change' => null, 'history' => null];
            });
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$cat->id]]));
            $this->fail('harus gagal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('varian kedua', $e->getMessage());
        }

        $this->assertSame(0, PriceChangeBatch::count());                                            // transaksi digulung
    }

    public function test_release_mode_creates_pending_changes_and_over_threshold_ones_await_approval(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $cat = Category::factory()->create();
        $v = $this->item('Kopi', 10000, $cat);

        $r = $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$cat->id], 'value' => 5]))->assertCreated()->json();
        $this->assertSame([0, 1, 0], [$r['applied'], $r['pending'], $r['awaiting_approval']]);
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);

        MenuChange::query()->delete();
        $r = $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$cat->id], 'value' => 30]))->assertCreated()->json();
        $this->assertSame([0, 0, 1], [$r['applied'], $r['pending'], $r['awaiting_approval']]);
        $this->assertSame($r['batch_id'], MenuChange::sole()->batch_id);
    }

    public function test_a_future_date_applies_later_and_a_role_without_the_permission_is_refused(): void
    {
        $cat = Category::factory()->create();
        $v = $this->item('Kopi', 10000, $cat);

        $r = $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$cat->id], 'effective_from' => $this->businessToday(2)]))->assertCreated()->json();
        $this->assertSame(1, $r['pending']);
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);

        $role = Role::factory()->create(['menu_keys' => ['products'], 'permissions' => []]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');
        $this->postJson('/api/v1/price-changes/preview', $this->body(['category_ids' => [$cat->id]]))->assertForbidden();
        $this->postJson('/api/v1/price-changes', $this->body(['category_ids' => [$cat->id]]))->assertForbidden();
    }
}
