<?php

namespace Tests\Feature\SellerScope;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 058 US2 — draf POS dan ekspor pre-order mengikuti scope seller (T030, T031). */
class DraftsAndExportsTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    public function test_a_draft_line_for_another_sellers_variant_is_dropped_with_a_warning(): void
    {
        $w = $this->buildSellerWorld();

        // Draf dibuat saat pengguna belum dibatasi (mis. sebelum diikat ke seller), memuat varian A dan B.
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $id = $this->postJson('/api/v1/pos-drafts', ['items' => [
            ['variant_id' => $w['variantA']->id, 'qty' => 1, 'sell_price' => 10000],
            ['variant_id' => $w['variantB']->id, 'qty' => 1, 'sell_price' => 10000],
        ]])->assertCreated()->json('id');

        // Kini pengguna yang sama diikat ke seller A.
        $cashier->forceFill(['artist_id' => $w['a']->id])->save();
        $this->actingAs($cashier->fresh(), 'sanctum');

        $resume = $this->getJson("/api/v1/pos-drafts/{$id}")->assertOk();
        $this->assertSame([$w['variantA']->id], collect($resume->json('items'))->pluck('variant_id')->all());
        $this->assertNotEmpty($resume->json('warnings'));
    }

    public function test_preorder_export_contains_only_visible_preorders(): void
    {
        $w = $this->buildSellerWorld();
        $p = $this->buildPreorders($w);
        $this->assertSame(3, \count($p));
        $this->actingAsSellerUser($w['a'], 'admin');

        $res = $this->get('/api/v1/preorders/export')->assertOk();
        $content = (string) $res->streamedContent();
        // Berkas xlsx berformat impor (satu baris per pre-order, tanpa nomor): hitung baris data.
        $tmp = tempnam(sys_get_temp_dir(), 'pe').'.xlsx';
        file_put_contents($tmp, $content);
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet()->toArray();
        @unlink($tmp);

        $dataRows = array_values(array_filter(array_slice($rows, 1), fn ($r) => array_filter($r) !== []));
        $this->assertCount(2, $dataRows); // pre-order hanya-A dan campuran; hanya-B tidak ikut
        $this->assertSame(3, \App\Models\Preorder::withoutGlobalScopes()->count());
    }
}
