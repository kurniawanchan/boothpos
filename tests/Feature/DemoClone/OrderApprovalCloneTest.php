<?php

namespace Tests\Feature\DemoClone;

use App\Models\Order;
use App\Models\OrderApproval;
use App\Services\DemoClone\CloneRegistry;
use App\Services\DemoClone\LiveToDemoCloner;
use App\Support\ModeGate;
use App\Support\OrderApprovalSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

/**
 * 059 (T009) — persetujuan seller ikut clone LIVE→DEMO, dan TETAP berlaku di DEMO. Kuncinya: `items_hash`
 * bebas-id, karena clone memetakan ulang order_items.id/variant_id/artist_id. Bila hash berbasis id, tes ini
 * gagal — itulah alasan hash v1 tidak memuat id apa pun.
 */
class OrderApprovalCloneTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    public function test_an_approval_survives_the_clone_with_its_file_and_still_reads_as_approved(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $live = $this->buildGraph('live');
        $this->buildGraph('demo');

        $liveOrder = Order::findOrFail($live['order']);
        $seller = OrderApprovalSummary::for($liveOrder)['sellers'][$live['artist']];
        Storage::disk('local')->put('order-approvals/live-signature.png', 'live-signature-bytes');
        $liveApprovalId = DB::table('order_approvals')->insertGetId([
            'order_id' => $live['order'], 'artist_id' => $live['artist'], 'artist_name' => 'Artist live', 'method' => 'draw',
            'image_path' => 'order-approvals/live-signature.png', 'image_mime' => 'image/png',
            'items_hash' => $seller['items_hash'], 'items_snapshot' => json_encode(['lines' => $seller['items']]),
            'approved_by' => $this->cloneUserId(), 'approver_name' => 'Penyetuju', 'on_behalf' => false,
            'approved_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        (new LiveToDemoCloner)->run();

        $demoOrderId = (int) DB::table('orders')->whereRaw(CloneRegistry::scope('orders', 'demo'))->value('id');
        $this->assertNotSame($live['order'], $demoOrderId);

        $demoApproval = OrderApproval::where('order_id', $demoOrderId)->firstOrFail();
        $this->assertNotSame($liveApprovalId, $demoApproval->id);
        $this->assertNotSame('order-approvals/live-signature.png', $demoApproval->image_path, 'berkas disalin, tak pernah dibagi');
        $this->assertSame('live-signature-bytes', Storage::disk('local')->get($demoApproval->image_path));
        $this->assertTrue(Storage::disk('local')->exists('order-approvals/live-signature.png'), 'berkas LIVE tak tersentuh');

        $summary = ModeGate::runAs('demo', fn () => OrderApprovalSummary::for(Order::findOrFail($demoOrderId)));
        $this->assertSame('full', $summary['status'], 'persetujuan hasil clone harus tetap berlaku (hash bebas-id)');
        $this->assertSame(1, $summary['approved']);
    }

    public function test_the_live_approval_is_untouched_by_the_clone(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $live = $this->buildGraph('live');
        $this->buildGraph('demo');
        $seller = OrderApprovalSummary::for(Order::findOrFail($live['order']))['sellers'][$live['artist']];
        DB::table('order_approvals')->insert([
            'order_id' => $live['order'], 'artist_id' => $live['artist'], 'artist_name' => 'Artist live', 'method' => 'stamp',
            'items_hash' => $seller['items_hash'], 'items_snapshot' => '[]', 'approved_by' => $this->cloneUserId(),
            'approver_name' => 'P', 'approved_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        (new LiveToDemoCloner)->run();

        $this->assertSame(1, OrderApproval::where('order_id', $live['order'])->count());
        $this->assertSame(2, OrderApproval::count());
    }
}
