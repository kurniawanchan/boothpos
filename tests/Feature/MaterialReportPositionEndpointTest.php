<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 US1 (T008) — GET /reports/materials/position (contracts §1): posisi & risiko per bahan per outlet, pilihan dan
 * menu yang terancam, ringkasan sebelum saringan status, saringan, validasi, akses, dan penyempitan outlet.
 */
class MaterialReportPositionEndpointTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();
        $this->seedRiskScenario();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function position(array $q = [], ?int $outlet = null)
    {
        // withHeader() menempel untuk permintaan berikutnya di tes yang sama: tanpa outlet = buang header dulu.
        $req = $outlet ? $this->withHeader('X-Outlet-Id', (string) $outlet) : $this->flushHeaders();

        return $req->getJson('/api/v1/reports/materials/position?'.http_build_query($q));
    }

    private function find(array $rows, string $name, int $outletId): ?array
    {
        return collect($rows)->first(fn ($r) => $r['name'] === $name && $r['outlet_id'] === $outletId);
    }

    public function test_rows_carry_risk_options_and_products_and_the_summary_is_complete(): void
    {
        $res = $this->position()->assertOk()->assertJsonStructure(['data', 'summary', 'params' => ['window_days', 'risk_days', 'target_days'], 'computed_at']);
        $soto = $this->find($res->json('data'), 'Indomie Soto', $this->outletA->id);

        $this->assertSame('low', $soto['status']);
        $this->assertSame([['option_id' => $this->opt['soto']->id, 'option' => 'SOTO', 'group' => 'Rasa Kuah', 'portions' => 6]], $soto['at_risk_options']);
        $this->assertSame(['Indomie Kuah'], array_column($soto['at_risk_products'], 'name'));

        // Bahan aman/tidak terpakai tidak membawa daftar terancam.
        $cabai = $this->find($res->json('data'), 'Cabai Bubuk', $this->outletA->id);
        $this->assertSame([], $cabai['at_risk_options']);
        $this->assertNull($cabai['days_left']);

        // Ayam Bawang minus di B → porsi 0; Telur habis di B → menu Topping terdampak (termasuk warisan kategori).
        $telur = $this->find($res->json('data'), 'Telur', $this->outletB->id);
        $this->assertSame(['Indomie Kuah', 'Mie Goreng', 'Nasi Goreng'], array_column($telur['at_risk_products'], 'name'));
        $this->assertSame(0, $this->find($res->json('data'), 'Indomie Ayam Bawang', $this->outletB->id)['at_risk_options'][0]['portions']);

        $summary = $res->json('summary');
        $this->assertSame(1, $summary['negative']);
        $this->assertSame(1, $summary['low']);
        $this->assertSame(1, $summary['out']);
        // Unik lintas baris berisiko: Indomie Kuah (Rasa & Topping), Nasi Goreng, Mie Goreng.
        $this->assertSame(3, $summary['at_risk_products']);
    }

    public function test_the_status_filter_keeps_the_full_summary(): void
    {
        $all = $this->position()->json('summary');
        $res = $this->position(['status' => ['negative']])->assertOk();

        $this->assertSame(['negative'], array_values(array_unique(array_column($res->json('data'), 'status'))));
        $this->assertSame($all, $res->json('summary'));
    }

    public function test_kind_and_search_filters(): void
    {
        $this->assertSame(['Sambal'], array_values(array_unique(array_column($this->position(['kind' => ['semi_finished']])->json('data'), 'name'))));
        $this->assertSame(['Telur'], array_values(array_unique(array_column($this->position(['search' => 'telur'])->json('data'), 'name'))));
    }

    public function test_the_navbar_outlet_narrows_and_all_outlets_are_never_summed(): void
    {
        $onlyB = $this->position([], $this->outletB->id)->json('data');
        $this->assertSame([$this->outletB->id], array_values(array_unique(array_column($onlyB, 'outlet_id'))));

        $all = $this->position()->json('data');
        $soto = collect($all)->where('name', 'Indomie Soto');
        $this->assertSame([$this->outletA->id, $this->outletB->id], $soto->pluck('outlet_id')->sort()->values()->all());
    }

    public function test_parameters_are_validated_and_change_the_result(): void
    {
        $this->position(['window_days' => 0])->assertStatus(422)->assertJsonValidationErrors('window_days');
        $this->position(['risk_days' => 61])->assertStatus(422)->assertJsonValidationErrors('risk_days');
        $this->position(['status' => ['bogus']])->assertStatus(422);

        // N = 1 hari: Soto (1,5 hari) tidak lagi "akan habis".
        $res = $this->position(['risk_days' => 1]);
        $this->assertSame('ok', $this->find($res->json('data'), 'Indomie Soto', $this->outletA->id)['status']);
        $this->assertSame(1, $res->json('params.risk_days'));
    }

    public function test_only_users_with_the_reports_menu_may_open_it(): void
    {
        $this->actingAs($this->cashier, 'sanctum');
        $this->position()->assertForbidden();
    }

    // --- US2 (T017) — saran pembelian: max(0, target × laju − saldo), vendor/harga rujukan ---

    public function test_suggestions_cover_the_target_days_with_the_reference_vendor_price(): void
    {
        $rows = $this->position()->json('data');

        // Soto @A: laju 4, saldo 6, target 7 → 28 − 6 = 22 × Rp 3.000.
        $this->assertSame(['qty' => '22.000', 'vendor_id' => $this->grosir->id, 'vendor' => 'Tukang Grosir Indomie', 'unit_price' => '3000.00', 'cost' => '66000.00'],
            $this->find($rows, 'Indomie Soto', $this->outletA->id)['suggestion']);

        // Ayam Bawang @B: minus −2, laju 2 → 14 + 2 = 16; tanpa vendor → harga/biaya null.
        $ayam = $this->find($rows, 'Indomie Ayam Bawang', $this->outletB->id)['suggestion'];
        $this->assertSame('16.000', $ayam['qty']);
        $this->assertNull($ayam['vendor_id']);
        $this->assertNull($ayam['cost']);

        // Telur @B habis tanpa pemakaian → tidak ada yang bisa diperkirakan → tanpa saran; Cabai tidak terpakai juga.
        $this->assertNull($this->find($rows, 'Telur', $this->outletB->id)['suggestion']);
        $this->assertNull($this->find($rows, 'Cabai Bubuk', $this->outletA->id)['suggestion']);

        $this->assertSame('66000.00', $this->position()->json('summary.suggestion_cost'));
    }

    public function test_the_target_days_change_the_suggestion(): void
    {
        $rows = $this->position(['target_days' => 14])->json('data');
        $this->assertSame('50.000', $this->find($rows, 'Indomie Soto', $this->outletA->id)['suggestion']['qty']);
    }

    // --- Filter outlet halaman (`outlet_id[]`, catatan 2026-11-02; pola 071) ---

    public function test_the_outlet_filter_keeps_only_the_chosen_outlets_without_the_navbar_header(): void
    {
        $onlyA = $this->position(['outlet_id' => [$this->outletA->id]])->assertOk();
        $this->assertSame([$this->outletA->id], array_values(array_unique(array_column($onlyA->json('data'), 'outlet_id'))));

        $both = $this->position(['outlet_id' => [$this->outletA->id, $this->outletB->id]])->json('data');
        $this->assertSame([$this->outletA->id, $this->outletB->id], collect($both)->pluck('outlet_id')->unique()->sort()->values()->all());

        // Ringkasan ikut disaring: angka kartu hanya dari outlet terpilih.
        $this->assertSame(0, $onlyA->json('summary.negative'));
    }

    public function test_the_outlet_filter_never_widens_beyond_the_navbar_header_or_unknown_outlets(): void
    {
        // Header menyempitkan ke A lebih dulu; meminta B lewat filter tidak boleh membuka B → kosong, bukan "semua".
        $this->assertSame([], $this->position(['outlet_id' => [$this->outletB->id]], $this->outletA->id)->json('data'));

        // Outlet yang tak dikenal/tak boleh dilihat → hasil kosong, bukan semua outlet.
        $this->assertSame([], $this->position(['outlet_id' => [999999]])->json('data'));

        // Tanpa nilai terisi = tanpa saringan.
        $this->assertNotEmpty($this->position(['outlet_id' => ['']])->json('data'));
        $this->position(['outlet_id' => ['abc']])->assertStatus(422);
    }

    public function test_the_outlet_filter_applies_to_the_usage_report_too(): void
    {
        $res = $this->flushHeaders()->getJson('/api/v1/reports/materials/usage?'.http_build_query(['outlet_id' => [$this->outletB->id]]))->assertOk();
        $outlets = collect($res->json('data'))->pluck('outlet_id')->unique()->values()->all();
        $this->assertSame([], array_values(array_diff($outlets, [$this->outletB->id])));

        $none = $this->flushHeaders()->getJson('/api/v1/reports/materials/usage?'.http_build_query(['outlet_id' => [999999]]))->assertOk();
        $this->assertSame([], $none->json('data'));
    }
}
