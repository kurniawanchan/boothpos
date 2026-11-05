<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 059 US5 — riwayat penuh untuk owner/admin, catatan tak bisa diubah/dihapus, gambar hanya untuk yang berhak. */
class AuditAndProtectionTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function approveWithImage(array $w, $order, $artist, $as): int
    {
        $this->actingAs($as, 'sanctum');
        $this->post("/api/v1/orders/{$order->id}/approvals", ['method' => 'draw', 'image' => UploadedFile::fake()->image('s.png')] + $this->stampPayload($artist->id, $this->currentHash($order->id, $artist->id)), ['Accept' => 'application/json'])->assertCreated();

        return (int) OrderApproval::where('order_id', $order->id)->where('artist_id', $artist->id)->orderByDesc('id')->value('id');
    }

    public function test_history_lists_every_cycle_for_owner_and_admin_only(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->approveWithImage($w, $w['orderAB'], $w['b'], $w['owner']);
        OrderItem::where('order_id', $w['orderAB']->id)->where('artist_id', $w['b']->id)->update(['qty' => 3]);
        $this->approveWithImage($w, $w['orderAB'], $w['b'], $w['owner']);

        foreach (['owner', 'admin'] as $who) {
            $this->actingAs($w[$who], 'sanctum');
            $history = $this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->assertOk()->json('data.history');
            $this->assertCount(2, $history, $who);
            $this->assertNotNull($history[1]['invalidated_at'], 'siklus pertama tercatat batal');
            $this->assertSame('items_changed', $history[1]['invalidation_reason']);
            $this->assertNull($history[0]['invalidated_at']);
            $this->assertSame($w['b']->id, $history[0]['artist_id']);
            $this->assertSame($w['owner']->name, $history[0]['approver_name']);
            $this->assertSame('draw', $history[0]['method']);
        }

        // Pengguna lain: kunci `history` TIDAK ada (bukan kosong) — SPA tak memutuskan dari peran.
        $this->actingAs($w['userA'], 'sanctum');
        $this->assertArrayNotHasKey('history', $this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->json('data'));
    }

    public function test_no_route_can_edit_or_delete_an_approval(): void
    {
        $offending = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'approvals')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
                    $offending[] = "{$method} {$route->uri()}";
                }
            }
        }
        $this->assertSame([], $offending);

        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $base = "/api/v1/orders/{$w['orderA']->id}/approvals";
        $this->deleteJson($base)->assertStatus(405);
        $this->putJson($base, [])->assertStatus(405);
        $this->patchJson($base, [])->assertStatus(405);
        $this->deleteJson("{$base}/1/image")->assertStatus(405);
        $this->postJson("{$base}/1/image", [])->assertStatus(405);
    }

    public function test_only_the_permitted_can_open_the_image(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $second = User::factory()->create(['role' => 'cashier', 'artist_id' => $w['a']->id]); // seller sama, pengguna lain
        $id = $this->approveWithImage($w, $w['orderAB'], $w['a'], $w['userA']); // penyetuju = userA
        $url = "/api/v1/orders/{$w['orderAB']->id}/approvals/{$id}/image";

        foreach (['owner', 'admin', 'userA'] as $who) {
            $this->actingAs($w[$who], 'sanctum');
            $r = $this->get($url)->assertOk();
            $this->assertStringContainsString('image/png', $r->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $r->headers->get('Cache-Control'));
        }
        $this->actingAs($second, 'sanctum');
        $this->get($url)->assertOk(); // terikat ke seller yang sama

        // Seller B ikut melihat penjualannya, tetapi BUKAN gambar milik seller A.
        $this->actingAs($w['userB'], 'sanctum');
        $this->getJson($url)->assertStatus(403)->assertJsonPath('code', 'image_forbidden');
        $this->actingAs($w['cashier'], 'sanctum');
        $this->getJson($url)->assertStatus(403);
    }

    public function test_the_payload_flags_image_visibility_per_viewer(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->approveWithImage($w, $w['orderAB'], $w['a'], $w['userA']);

        $this->actingAs($w['userB'], 'sanctum');
        $a = collect($this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->json('data.sellers'))->firstWhere('artist_id', $w['a']->id);
        $this->assertTrue($a['approval']['has_image']);
        $this->assertFalse($a['approval']['can_view_image']);
        // Fakta status/penyetuju/metode/waktu tetap terlihat bersama penjualan.
        $this->assertSame($w['userA']->name, $a['approval']['approver_name']);

        $this->actingAs($w['userA'], 'sanctum');
        $a = collect($this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->json('data.sellers'))->firstWhere('artist_id', $w['a']->id);
        $this->assertTrue($a['approval']['can_view_image']);
    }

    public function test_a_stamp_has_no_image_to_open(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $this->post("/api/v1/orders/{$w['orderA']->id}/approvals", $this->stampPayload($w['a']->id, $this->currentHash($w['orderA']->id, $w['a']->id)), ['Accept' => 'application/json'])->assertCreated();
        $id = OrderApproval::firstOrFail()->id;

        $this->getJson("/api/v1/orders/{$w['orderA']->id}/approvals/{$id}/image")->assertStatus(404);
    }

    public function test_a_missing_file_is_a_404_not_a_500(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $id = $this->approveWithImage($w, $w['orderA'], $w['a'], $w['owner']);
        Storage::disk('local')->delete(OrderApproval::findOrFail($id)->image_path);

        $this->getJson("/api/v1/orders/{$w['orderA']->id}/approvals/{$id}/image")->assertStatus(404);
    }

    public function test_approval_and_invalidation_appear_on_the_activity_log(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $this->post("/api/v1/orders/{$w['orderAB']->id}/approvals", $this->stampPayload($w['b']->id, $this->currentHash($w['orderAB']->id, $w['b']->id)), ['Accept' => 'application/json'])->assertCreated();
        OrderItem::where('order_id', $w['orderAB']->id)->where('artist_id', $w['b']->id)->update(['qty' => 2]);
        $this->post("/api/v1/orders/{$w['orderAB']->id}/approvals", $this->stampPayload($w['b']->id, $this->currentHash($w['orderAB']->id, $w['b']->id)), ['Accept' => 'application/json'])->assertCreated();

        $rows = $this->getJson("/api/v1/activity-logs?entity_type=Order&entity_id={$w['orderAB']->id}")->assertOk()->json('data');
        $actions = collect($rows)->pluck('action')->all();

        $this->assertSame(2, collect($actions)->filter(fn ($a) => $a === 'order_approved')->count());
        $this->assertSame(1, collect($actions)->filter(fn ($a) => $a === 'order_approval_invalidated')->count());
        $this->assertSame($w['owner']->id, collect($rows)->firstWhere('action', 'order_approved')['user_id']);
    }
}
