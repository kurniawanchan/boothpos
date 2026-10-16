<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\ItemAvailabilityMark;
use App\Models\Order;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\User;
use App\Support\ModeGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T042) — POST/DELETE /products/{id}/sold-out.
 * Jam beku: 2026-10-11 05:30 UTC = 12.30 WIB; hari bisnis berakhir 2026-10-11 17:00 UTC (00.00 WIB).
 */
class ProductSoldOutTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 05:30:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-11 05:30:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function reasonId(string $category = 'sold_out'): int
    {
        return (int) ReasonCode::where('category', $category)->orderBy('sort')->value('id');
    }

    private function product()
    {
        return $this->makeVariant(stock: 10, price: 10000)->product;
    }

    public function test_marking_creates_a_mark_that_expires_at_the_end_of_the_business_day_and_is_logged(): void
    {
        $p = $this->product();

        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId(), 'note' => 'susu kental habis'])
            ->assertCreated()->assertJsonPath('state', 'sold_out')->assertJsonPath('note', 'susu kental habis');

        $mark = ItemAvailabilityMark::firstOrFail();
        $this->assertSame('2026-10-11 17:00:00', $mark->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($this->owner->id, $mark->marked_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'item_sold_out', 'entity_id' => $p->id]);
    }

    public function test_a_reason_is_required_and_must_be_an_active_sold_out_reason(): void
    {
        $p = $this->product();

        $this->postJson("/api/v1/products/{$p->id}/sold-out", [])->assertStatus(422)->assertJsonValidationErrors('reason_code_id');
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId('discontinue')])->assertStatus(422);
        ReasonCode::where('id', $this->reasonId())->update(['is_active' => false]);
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertStatus(422);

        $this->assertDatabaseCount('item_availability_marks', 0);
    }

    public function test_the_permission_is_enforced_on_the_server(): void
    {
        $p = $this->product();
        $role = Role::factory()->create(['menu_keys' => ['products', 'pos']]);   // peran tanpa izin 86
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        // 048 / S3: tanpa menu.availability.set, tanda habis butuh persetujuan (token), bukan sekadar 403.
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId()])
            ->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertDatabaseCount('item_availability_marks', 0);

        $granted = Role::factory()->create(['menu_keys' => ['pos'], 'permissions' => ['menu.availability.set']]);
        $this->actingAs(User::factory()->create(['role_id' => $granted->id]), 'sanctum');
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertCreated();
    }

    public function test_a_second_mark_and_a_mark_on_a_discontinued_item_are_conflicts(): void
    {
        $p = $this->product();
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertCreated();
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertStatus(409);

        $q = $this->product();
        $q->update(['is_active' => false]);
        $this->postJson("/api/v1/products/{$q->id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertStatus(409);
    }

    public function test_undo_clears_the_mark_and_a_second_undo_is_a_conflict(): void
    {
        $p = $this->product();
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertCreated();

        $this->deleteJson("/api/v1/products/{$p->id}/sold-out")->assertOk()->assertJsonPath('state', 'available');
        $this->assertNotNull(ItemAvailabilityMark::first()->cleared_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'item_sold_out_cleared', 'entity_id' => $p->id]);

        $this->deleteJson("/api/v1/products/{$p->id}/sold-out")->assertStatus(409);
    }

    public function test_a_marked_item_cannot_be_ordered_but_an_open_tab_line_is_untouched_and_the_mark_ends_with_the_day(): void
    {
        $session = $this->makeShift($this->owner);
        $variant = $this->makeVariant(stock: 10, price: 10000);
        $tab = $this->apiOpenTab($session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $variant->id, 2)->assertCreated();

        $this->postJson("/api/v1/products/{$variant->product_id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertCreated();

        $this->assertSame(2, (int) Order::findOrFail($tab)->items()->value('qty'));                    // baris lama utuh
        $this->apiAddLine($tab, $variant->id, 1)->assertStatus(409);                                  // penambahan baru ditolak
        $this->postJson('/api/v1/orders', [
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertStatus(409);

        // Hari bisnis berganti (00.00 WIB) → tanda berakhir sendiri.
        CarbonImmutable::setTestNow('2026-10-11 17:00:01');
        \Illuminate\Support\Carbon::setTestNow('2026-10-11 17:00:01');
        $this->apiAddLine($tab, $variant->id, 1)->assertCreated();
    }

    public function test_demo_and_live_marks_never_mix(): void
    {
        $p = $this->product();
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $this->reasonId()])->assertCreated();

        ModeGate::runAs(ModeGate::DEMO, fn () => $this->assertSame(0, ItemAvailabilityMark::count()));
    }
}
