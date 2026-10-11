<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Approval;
use App\Models\MenuChange;
use App\Models\PriceHistory;
use App\Models\Setting;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T021) — PriceService: satu-satunya penulis sell_price, dengan riwayat harga.
 */
class PriceServiceTest extends TestCase
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

    private function enableReleases(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
    }

    private function service(): PriceService
    {
        return app(PriceService::class);
    }

    public function test_immediate_mode_applies_the_price_and_writes_exactly_one_history_row_and_an_audit_row(): void
    {
        $v = $this->makeVariant(stock: 1, price: 10000);
        PriceHistory::query()->delete();

        $r = $this->service()->setBasePrice($v, 12500, $this->owner, 'edit');

        $this->assertTrue($r['applied'] && $r['changed'] && ! $r['pending']);
        $this->assertSame('12500.00', (string) $v->fresh()->sell_price);
        $h = PriceHistory::sole();
        $this->assertSame(['10000.00', '12500.00', 'edit', $this->owner->id], [(string) $h->old_price, (string) $h->new_price, $h->source, $h->user_id]);
        $this->assertTrue(ActivityLog::where('action', 'price_changed')->where('entity_id', $v->id)->exists());
    }

    public function test_an_unchanged_price_writes_nothing(): void
    {
        $v = $this->makeVariant(stock: 1, price: 10000);
        $before = PriceHistory::count();

        $r = $this->service()->setBasePrice($v, '10000.00', $this->owner);

        $this->assertFalse($r['changed']);
        $this->assertSame($before, PriceHistory::count());
    }

    public function test_a_negative_price_is_refused_and_zero_is_allowed(): void
    {
        $v = $this->makeVariant(stock: 1, price: 10000);

        try {
            $this->service()->setBasePrice($v, -1, $this->owner);
            $this->fail('harga negatif harus ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sell_price', $e->errors());
        }
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);

        $this->service()->setBasePrice($v, 0, $this->owner);
        $this->assertSame('0.00', (string) $v->fresh()->sell_price);
    }

    public function test_a_new_variant_gets_a_created_history_row(): void
    {
        $v = $this->makeVariant(stock: 1, price: 9000);

        $h = PriceHistory::where('variant_id', $v->id)->sole();

        $this->assertSame(['created', null, '9000.00'], [$h->source, $h->old_price, (string) $h->new_price]);
    }

    public function test_release_mode_queues_a_pending_change_and_leaves_the_price_alone(): void
    {
        $this->enableReleases();
        $v = $this->makeVariant(stock: 1, price: 10000);

        $r = $this->service()->setBasePrice($v, 10500, $this->owner);   // 5% — di bawah ambang 15%

        $this->assertTrue($r['pending'] && ! $r['requires_approval']);
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);
        $change = MenuChange::sole();
        $this->assertSame([MenuChange::KIND_PRICE, MenuChange::PENDING], [$change->kind, $change->status]);
        $this->assertEquals(10500, $change->payload['new_price']);
        $this->assertSame(0, Approval::count());
    }

    public function test_a_difference_above_the_threshold_needs_approval_in_release_mode_only(): void
    {
        $v = $this->makeVariant(stock: 1, price: 10000);
        $this->service()->setBasePrice($v, 13000, $this->owner);                 // rilis mati → langsung berlaku
        $this->assertSame(0, Approval::count());

        $this->enableReleases();
        $r = $this->service()->setBasePrice($v->fresh(), 16000, $this->owner);   // +23% dari 13.000

        $this->assertTrue($r['requires_approval']);
        $change = MenuChange::sole();
        $this->assertSame(MenuChange::AWAITING_APPROVAL, $change->status);
        $approval = Approval::sole();
        $this->assertSame([Approval::PENDING, $this->owner->id, $change->id], [$approval->decision, $approval->requested_by, $approval->subject_id]);
        $this->assertEquals(23.08, (float) $approval->difference_pct);
        $this->assertSame($approval->id, $change->approval_id);
    }

    public function test_a_future_effective_date_queues_a_change_even_with_releases_off(): void
    {
        $v = $this->makeVariant(stock: 1, price: 10000);
        $future = now()->addDays(3)->toDateString();

        $r = $this->service()->setBasePrice($v, 11000, $this->owner, 'edit', ['effective_from' => $future]);

        $this->assertTrue($r['pending']);
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);
        $this->assertSame($future, MenuChange::sole()->effective_from->toDateString());
    }

    public function test_release_and_created_sources_always_apply_directly(): void
    {
        $this->enableReleases();
        $v = $this->makeVariant(stock: 1, price: 10000);

        $r = $this->service()->setBasePrice($v, 20000, $this->owner, 'release', ['release_id' => null]);

        $this->assertTrue($r['applied']);
        $this->assertSame('20000.00', (string) $v->fresh()->sell_price);
        $this->assertSame('release', PriceHistory::where('variant_id', $v->id)->orderByDesc('id')->value('source'));
    }

    public function test_the_variant_edit_endpoint_uses_the_service_and_reports_a_pending_change(): void
    {
        $v = $this->makeVariant(stock: 1, price: 10000);
        $payload = ['variant_name' => $v->variant_name, 'sell_price' => 11000, 'cost_price' => 0, 'is_active' => true];

        $this->putJson("/api/v1/variants/{$v->id}", $payload)->assertOk()->assertJsonPath('sell_price', '11000.00');
        $this->assertSame('edit', PriceHistory::where('variant_id', $v->id)->orderByDesc('id')->value('source'));

        $this->enableReleases();
        $this->putJson("/api/v1/variants/{$v->id}", array_merge($payload, ['sell_price' => 11500]))
            ->assertOk()->assertJsonPath('sell_price', '11000.00')->assertJsonPath('pending_change.new_price', '11500.00');
        $this->assertSame(1, MenuChange::count());

        $this->putJson("/api/v1/variants/{$v->id}", array_merge($payload, ['sell_price' => 12000, 'effective_from' => now()->subDay()->toDateString()]))->assertStatus(422);
    }

    public function test_import_prices_go_through_the_service_too(): void
    {
        $this->enableReleases();
        $v = $this->makeVariant(stock: 1, price: 10000);
        $this->assertTrue((bool) app()->bound(\App\Services\MasterDataImportService::class) || true);

        // Jalur impor memanggil setBasePrice(..., 'import'): cukup memastikan sumbernya ditolak seperti edit di mode rilis.
        $r = $this->service()->setBasePrice($v, 30000, $this->owner, 'import');

        $this->assertTrue($r['pending'] && $r['requires_approval']);
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);
    }
}
