<?php

namespace Tests\Feature\PreorderBulk;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PreorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 065 US1/US4 + lintas aksi — izin, scope, batas, bentuk hasil, isolasi galat, jumlah query. */
class BulkGuardsTest extends TestCase
{
    use PreorderBulkFixture, RefreshDatabase;

    public function test_every_route_refuses_a_role_without_the_individual_ability_and_changes_nothing(): void
    {
        $w = $this->buildBulkWorld();
        $po = $this->bulkPreorder($w, 'dp_paid');
        // Hanya `export` — tak satu pun dari status/dispatch.
        $this->actingAs($this->bulkUserWith(['export']), 'sanctum');

        foreach ([
            'status' => ['status' => 'arrived'],
            'dispatch' => ['dispatch_status' => 'invoice_sent'],
        ] as $action => $params) {
            foreach (['preview', 'apply'] as $phase) {
                $this->bulkCall($action, $phase, [$po], $params)
                    ->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
            }
        }

        $this->assertSame('dp_paid', $po->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', 'like', 'preorder_bulk_%')->count());
    }

    public function test_a_role_with_only_one_ability_can_use_only_that_action(): void
    {
        $w = $this->buildBulkWorld();
        $po = $this->bulkPreorder($w, 'dp_paid');
        $this->actingAs($this->bulkUserWith(['status']), 'sanctum');

        $this->bulkCall('status', 'apply', [$po], ['status' => 'arrived'])->assertOk()->assertJsonPath('data.succeeded', 1);
        $this->bulkCall('dispatch', 'apply', [$po], ['dispatch_status' => 'invoice_sent'])->assertStatus(403);
    }

    public function test_other_mode_and_other_seller_pre_orders_look_like_missing_ids(): void
    {
        $w = $this->buildBulkWorld();
        $mine = $this->bulkPreorder($w, 'dp_paid');
        $otherMode = $this->bulkPreorder($w, 'dp_paid');
        Preorder::withoutGlobalScopes()->whereKey($otherMode->id)->update(['data_mode' => 'demo']);

        $other = Artist::factory()->create();
        $foreign = Product::factory()->create(['artist_id' => $other->id, 'category_id' => Category::factory()->create()->id]);
        $fv = ProductVariant::factory()->create(['product_id' => $foreign->id, 'sell_price' => 100000]);
        $foreignOrder = app(\App\Services\PreorderService::class)->create([
            'customer_id' => $w['customer']->id, 'fulfillment' => 'pickup', 'items' => [['variant_id' => $fv->id, 'qty' => 1]],
        ], $w['owner']);
        app(\App\Services\PaymentService::class)->addPayment($foreignOrder, ['method' => 'cash', 'purpose' => 'down_payment', 'amount' => 1000], $w['owner']);

        $scoped = $this->bulkUserWith(['status'], $w['artist']->id);
        $this->actingAs($scoped, 'sanctum');

        $res = $this->bulkCall('status', 'apply', [$mine, $otherMode, $foreignOrder], ['status' => 'arrived'])->assertOk()->json('data');
        $byId = collect($res['results'])->keyBy('preorder_id');

        $this->assertSame('succeeded', $byId[$mine->id]['status']);
        $this->assertSame('not_found', $byId[$otherMode->id]['reason']);
        $this->assertSame('not_found', $byId[$foreignOrder->id]['reason']);
        $this->assertNull($byId[$foreignOrder->id]['preorder_number']);   // tak membocorkan apa pun
        $this->assertSame('dp_paid', $foreignOrder->fresh()->status);
        $this->assertSame('dp_paid', Preorder::withoutGlobalScopes()->find($otherMode->id)->status);
    }

    public function test_limit_empty_and_malformed_selections_are_refused_before_anything_is_written(): void
    {
        $w = $this->buildBulkWorld();
        $po = $this->bulkPreorder($w, 'dp_paid');
        $this->actingAs($w['owner'], 'sanctum');
        config(['preorders.bulk_max' => 2]);

        $this->bulkCall('status', 'apply', [$po, $po->id + 1, $po->id + 2], ['status' => 'arrived'])
            ->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->bulkCall('status', 'preview', [$po, $po->id + 1, $po->id + 2])->assertStatus(422);
        $this->bulkCall('status', 'apply', [], ['status' => 'arrived'])->assertStatus(422);
        $this->postJson('/api/v1/preorders/bulk/status/apply', ['order_ids' => ['x'], 'params' => ['status' => 'arrived']])->assertStatus(422);
        $this->postJson('/api/v1/preorders/bulk/status/apply', ['order_ids' => [$po->id, $po->id], 'params' => ['status' => 'arrived']])->assertStatus(422);

        $this->assertSame('dp_paid', $po->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', 'like', 'preorder_bulk_%')->count());
    }

    public function test_an_unexpected_failure_on_one_pre_order_is_failed_generically_and_isolated(): void
    {
        $w = $this->buildBulkWorld();
        $good = $this->bulkPreorder($w, 'ordered', 'courier');
        $bad = $this->bulkPreorder($w, 'ordered', 'courier');
        $good2 = $this->bulkPreorder($w, 'ordered', 'courier');
        $this->actingAs($w['owner'], 'sanctum');

        $badId = $bad->id;
        $this->app->bind(PreorderService::class, fn () => new class($badId) extends PreorderService {
            public function __construct(private int $badId)
            {
                parent::__construct(app(\App\Services\StockService::class), app(\App\Services\PaymentRecorder::class), app(\App\Services\ActivityLogger::class));
            }

            public function setDispatchStatus(Preorder $preorder, string $target): Preorder
            {
                $result = parent::setDispatchStatus($preorder, $target);   // ditulis dulu …
                if ($preorder->id === $this->badId) {
                    throw new \RuntimeException('SQLSTATE[HY000]: rahasia /var/www/path');   // … lalu meledak
                }

                return $result;
            }
        });

        $res = $this->bulkCall('dispatch', 'apply', [$good, $bad, $good2], ['dispatch_status' => 'invoice_sent'])->assertOk()->json('data');

        $this->assertSame([2, 0, 1], [$res['succeeded'], $res['skipped'], $res['failed']]);
        $this->assertSame($res['selected'], $res['succeeded'] + $res['skipped'] + $res['failed']);
        $r = collect($res['results'])->keyBy('preorder_id');
        $this->assertSame('failed', $r[$bad->id]['status']);
        $this->assertStringNotContainsString('SQLSTATE', $r[$bad->id]['message']);
        $this->assertStringNotContainsString('/var/www', $r[$bad->id]['message']);
        $this->assertSame('pending', $bad->fresh()->dispatch_status);   // tak setengah jadi (rollback)
        $this->assertSame(0, ActivityLog::where('action', 'preorder_bulk_dispatch')->where('entity_id', $bad->id)->count());
        $this->assertSame('invoice_sent', $good->fresh()->dispatch_status);
        $this->assertSame('invoice_sent', $good2->fresh()->dispatch_status);
    }

    public function test_matching_ids_follow_the_list_filters_cap_at_the_limit_and_respect_visibility(): void
    {
        $w = $this->buildBulkWorld();
        $dp = [$this->bulkPreorder($w, 'dp_paid'), $this->bulkPreorder($w, 'dp_paid'), $this->bulkPreorder($w, 'dp_paid')];
        $this->bulkPreorder($w, 'ordered');
        $hidden = $this->bulkPreorder($w, 'dp_paid');
        Preorder::withoutGlobalScopes()->whereKey($hidden->id)->update(['data_mode' => 'demo']);
        $this->actingAs($w['owner'], 'sanctum');

        $all = $this->getJson('/api/v1/preorders/matching-ids?status[]=dp_paid')->assertOk()->json();
        $this->assertSame(3, $all['total']);
        $this->assertFalse($all['capped']);
        $this->assertEqualsCanonicalizing($this->bulkIds($dp), $all['ids']);

        config(['preorders.bulk_max' => 2]);
        $capped = $this->getJson('/api/v1/preorders/matching-ids?status[]=dp_paid&page=3&per_page=1')->assertOk()->json();
        $this->assertCount(2, $capped['ids']);
        $this->assertSame(3, $capped['total']);
        $this->assertTrue($capped['capped']);

        $this->actingAs($this->bulkUserWith(['status']), 'sanctum');
        $this->getJson('/api/v1/preorders/matching-ids')->assertOk();   // menu preorders cukup
    }

    public function test_preview_and_resolution_do_not_grow_with_the_number_of_pre_orders(): void
    {
        $w = $this->buildBulkWorld();
        $few = $this->bulkPreorders($w, 3, 'dp_paid');
        $many = $this->bulkPreorders($w, 9, 'dp_paid');
        $this->actingAs($w['owner'], 'sanctum');

        $count = function (array $po) {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            foreach ([['status', ['status' => 'arrived']], ['dispatch', ['dispatch_status' => 'invoice_sent']]] as [$action, $params]) {
                $this->bulkCall($action, 'preview', $po, $params)->assertOk();
            }

            return $n;
        };

        $count($few);                       // pemanasan (cache pengaturan/lisensi terisi di request pertama)
        $small = $count($few);
        $large = $count($many);

        $this->assertSame($small, $large, 'jumlah query pratinjau tumbuh seiring jumlah pre-order (N+1)');
    }
}
