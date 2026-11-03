<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 089 US4 — jumlah per pilihan modifier: batas min/maks, harga × jumlah, porsi per satuan, kompatibilitas klien lama.
 * Dunia uji = fixtures 087 (Indomie Kuah: Rasa Kuah wajib/tunggal, Level Pedas tunggal, Topping jamak; Nasi Goreng: Topping).
 * Telur: Rp3.000 per pilihan; batasnya diatur per tes.
 */
class ModifierQuantityTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    /** @param array<int, array{option_id:int, qty:int}> $selections */
    private function order(array $selections, int $qty = 1, array $extraItem = []): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->nasiGoreng->id, 'qty' => $qty] + $extraItem + ['modifier_selections' => $selections]],
            'payments' => [['method' => 'cash', 'amount' => 500000]],
        ]);
    }

    private function sel(string $key, int $qty): array
    {
        return ['option_id' => $this->opt[$key]->id, 'qty' => $qty];
    }

    public function test_a_quantity_within_the_limits_is_priced_per_unit_and_snapshotted(): void
    {
        $this->opt['telur']->update(['min_qty' => 1, 'max_qty' => 3]);

        $r = $this->order([$this->sel('telur', 2)])->assertCreated();

        $line = OrderItem::find($r->json('items.0.id'));
        $this->assertEquals(20000 + 2 * 3000, (float) $line->sell_price);
        $this->assertSame(2, $line->modifiers[0]['qty']);
        $this->assertSame('6000.00', $line->modifiers[0]['price_delta'], 'kontribusi TOTAL pilihan (sudah × jumlah)');
        $this->assertSame('Telur ×2', $r->json('items.0.modifiers.0.label'));
    }

    public function test_one_option_at_its_maximum_quantity_satisfies_a_group_that_requires_that_many(): void
    {
        // 2026-11-02 — MINIMUM grup dihitung dalam satuan jumlah, MAKSIMUM dalam pilihan berbeda.
        $group = $this->opt['telur']->group;
        $group->update(['selection_type' => 'multiple', 'is_required' => true, 'min_select' => 2, 'max_select' => 2]);
        $this->opt['telur']->update(['min_qty' => 1, 'max_qty' => 2]);

        $this->order([$this->sel('telur', 1)])->assertStatus(422);
        $this->order([$this->sel('telur', 2)])->assertCreated();
    }

    public function test_a_quantity_above_the_maximum_is_refused_naming_group_and_option(): void
    {
        $this->opt['telur']->update(['min_qty' => 1, 'max_qty' => 3]);

        $res = $this->order([$this->sel('telur', 4)])->assertStatus(422)->assertJsonValidationErrors(['modifier_selections']);

        $this->assertStringContainsString('Topping', $res->json('errors.modifier_selections.0'));
        $this->assertStringContainsString('Telur', $res->json('errors.modifier_selections.0'));
        $this->assertSame(0, OrderItem::count(), 'penolakan tidak meninggalkan baris');
    }

    public function test_a_quantity_below_the_minimum_is_refused_including_through_the_old_id_format(): void
    {
        $this->opt['telur']->update(['min_qty' => 2, 'max_qty' => 5]);

        $this->order([$this->sel('telur', 1)])->assertStatus(422)->assertJsonValidationErrors(['modifier_selections']);
        $this->order([$this->sel('telur', 2)])->assertCreated();
        // Klien lama hanya bisa mengirim id (jumlah 1): minimum 2 membuatnya ditolak (risiko yang dicatat di plan).
        $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->nasiGoreng->id, 'qty' => 1, 'modifier_option_ids' => [$this->opt['telur']->id]]],
            'payments' => [['method' => 'cash', 'amount' => 500000]],
        ])->assertStatus(422);
    }

    public function test_an_empty_maximum_means_no_upper_limit_up_to_the_hard_cap(): void
    {
        $this->opt['telur']->update(['min_qty' => 1, 'max_qty' => null]);

        $this->order([$this->sel('telur', 50)])->assertCreated();
        $this->order([$this->sel('telur', 51)])->assertStatus(422);   // batas keras request: 50
    }

    public function test_the_old_id_format_is_still_accepted_as_quantity_one(): void
    {
        $r = $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->nasiGoreng->id, 'qty' => 1, 'modifier_option_ids' => [$this->opt['telur']->id]]],
            'payments' => [['method' => 'cash', 'amount' => 500000]],
        ])->assertCreated();

        $line = OrderItem::find($r->json('items.0.id'));
        $this->assertSame(1, $line->modifiers[0]['qty']);
        $this->assertEquals(23000, (float) $line->sell_price);
    }

    public function test_a_snapshot_without_qty_from_before_this_feature_is_read_as_one(): void
    {
        $r = $this->order([$this->sel('telur', 1)])->assertCreated();
        $line = OrderItem::find($r->json('items.0.id'));
        $legacy = collect($line->modifiers)->map(fn ($m) => collect($m)->except('qty')->all())->all();
        $line->forceFill(['modifiers' => $legacy])->save();

        $display = \App\Support\ModifierSnapshot::forDisplay($line->fresh()->modifiers);

        $this->assertSame(1, $display[0]['qty']);
        $this->assertSame('Telur', $display[0]['label']);
    }

    public function test_both_formats_together_and_duplicate_options_are_rejected(): void
    {
        $this->order([$this->sel('telur', 1)], extraItem: ['modifier_option_ids' => [$this->opt['telur']->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['modifier_selections']);
        $this->order([$this->sel('telur', 1), $this->sel('telur', 1)])->assertStatus(422)->assertJsonValidationErrors(['modifier_selections']);
        $this->order([['option_id' => $this->opt['telur']->id, 'qty' => 0]])->assertStatus(422);
    }

    public function test_an_option_from_another_group_or_item_is_still_refused(): void
    {
        $this->order([$this->sel('soto', 1)])->assertStatus(422);   // Rasa Kuah hanya milik Indomie, bukan Nasi Goreng
    }

    /** Grup "tiap pilihan = 1 porsi": porsi dihitung per SATUAN jumlah (SOTO ×2 = dua bungkus = dua porsi). */
    public function test_portion_per_option_counts_every_unit_of_quantity(): void
    {
        $group = ModifierGroup::factory()->create(['name' => 'Rasa Ganda', 'selection_type' => 'multiple', 'is_required' => true, 'min_select' => 1, 'max_select' => null, 'portion_per_option' => true]);
        $soto = $group->options()->create(['name' => 'SOTO', 'price_delta' => 0, 'min_qty' => 1, 'max_qty' => 3, 'display_order' => 0]);
        $ayam = $group->options()->create(['name' => 'AYAM', 'price_delta' => 500, 'min_qty' => 1, 'max_qty' => 3, 'display_order' => 1]);
        $group->products()->attach($this->nasiGoreng->product_id);

        // Nasi Goreng Rp20.000: SOTO ×2 (pertama: 1 porsi tertutup dasar, 1 porsi ekstra = +20.000) + AYAM ×1 (+500 + 1 porsi ekstra = +20.500).
        $r = $this->order([['option_id' => $soto->id, 'qty' => 2], ['option_id' => $ayam->id, 'qty' => 1]])->assertCreated();

        $line = OrderItem::find($r->json('items.0.id'));
        $this->assertSame('20000.00', $line->modifiers[0]['price_delta']);
        $this->assertSame('20500.00', $line->modifiers[1]['price_delta']);
        $this->assertEquals(20000 + 20000 + 20500, (float) $line->sell_price, 'tiga porsi: 3 × 20.000 + 500');
        $this->assertEquals(3 * 20000 + 500, (float) $line->sell_price);
    }

    public function test_group_rules_still_count_distinct_options_not_units(): void
    {
        // Topping bebas (min 0, tanpa maks); Rasa tunggal wajib: dua pilihan berbeda di grup tunggal tetap ditolak.
        $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->indomie->id, 'qty' => 1, 'modifier_selections' => [$this->sel('soto', 1), $this->sel('ayam', 1)]]],
            'payments' => [['method' => 'cash', 'amount' => 500000]],
        ])->assertStatus(422);
    }

    // --- tab ----------------------------------------------------------------------------------------

    private function openTab(): int
    {
        return $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
    }

    public function test_tab_lines_with_different_quantities_do_not_merge_and_identical_ones_do(): void
    {
        $this->opt['telur']->update(['max_qty' => 3]);
        $tab = $this->openTab();

        $this->apiAddLine($tab, $this->nasiGoreng->id, 1, ['modifier_selections' => [$this->sel('telur', 1)]])->assertCreated();
        $this->apiAddLine($tab, $this->nasiGoreng->id, 1, ['modifier_selections' => [$this->sel('telur', 2)]])->assertCreated();
        $res = $this->apiAddLine($tab, $this->nasiGoreng->id, 1, ['modifier_selections' => [$this->sel('telur', 2)]])->assertCreated();

        $lines = collect($res->json('lines'));
        $this->assertCount(2, $lines, '×1 dan ×2 terpisah; dua ×2 bergabung');
        $this->assertEqualsCanonicalizing([1, 2], $lines->map(fn ($l) => $l['qty'])->all());
    }

    public function test_changing_the_selection_of_an_unsent_line_reprices_from_the_original_base(): void
    {
        $this->opt['telur']->update(['max_qty' => 3]);
        $tab = $this->openTab();
        $lineId = max($this->apiAddLine($tab, $this->nasiGoreng->id, 1, ['modifier_selections' => [$this->sel('telur', 2)]])->assertCreated()->json('lines.*.id'));
        $this->nasiGoreng->update(['sell_price' => 99999]);   // harga menu berubah di tengah tab: dasar tetap yang lama

        $this->patchJson("/api/v1/tabs/{$tab}/items/{$lineId}", ['modifier_selections' => [$this->sel('telur', 3)]])->assertOk();

        $line = OrderItem::find($lineId);
        $this->assertEquals(20000 + 3 * 3000, (float) $line->sell_price);
        $this->assertSame(3, $line->modifiers[0]['qty']);
        $this->patchJson("/api/v1/tabs/{$tab}/items/{$lineId}", ['modifier_selections' => [$this->sel('telur', 4)]])->assertStatus(422);
    }

    // --- pengaturan grup ----------------------------------------------------------------------------

    public function test_group_options_save_default_and_custom_limits_and_log_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $group = $this->postJson('/api/v1/modifier-groups', ['name' => 'Tambahan', 'selection_type' => 'multiple', 'options' => [
            ['name' => 'Keju', 'price_delta' => 2000],
            ['name' => 'Telur', 'price_delta' => 3000, 'min_qty' => 1, 'max_qty' => 3],
            ['name' => 'Kerupuk', 'price_delta' => 1000, 'min_qty' => 2, 'max_qty' => null],
        ]])->assertCreated()->json();

        $limits = array_map(fn ($o) => [$o['min_qty'], $o['max_qty']], $group['options']);
        $this->assertSame([[1, 1], [1, 3], [2, null]], $limits);

        $this->putJson("/api/v1/modifier-groups/{$group['id']}", ['options' => [
            ['id' => $group['options'][0]['id'], 'name' => 'Keju', 'price_delta' => 2000, 'max_qty' => 2],
            ['id' => $group['options'][1]['id'], 'name' => 'Telur', 'price_delta' => 3000],
            ['id' => $group['options'][2]['id'], 'name' => 'Kerupuk', 'price_delta' => 1000],
        ]])->assertOk();

        $listed = collect($this->getJson('/api/v1/modifier-groups')->json('data'))->firstWhere('id', $group['id']);
        $this->assertSame([[1, 2], [1, 3], [2, null]], array_map(fn ($o) => [$o['min_qty'], $o['max_qty']], $listed['options']), 'tanpa kunci = tidak berubah');
        $log = ActivityLog::where('action', 'modifier_qty_changed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Keju', $log->description);
    }

    public function test_a_minimum_above_the_maximum_is_rejected_even_when_only_one_side_is_sent(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $this->postJson('/api/v1/modifier-groups', ['name' => 'X', 'selection_type' => 'multiple', 'options' => [
            ['name' => 'A', 'price_delta' => 0, 'min_qty' => 3, 'max_qty' => 2],
        ]])->assertStatus(422)->assertJsonValidationErrors(['options.0.max_qty']);

        $group = $this->postJson('/api/v1/modifier-groups', ['name' => 'Y', 'selection_type' => 'multiple', 'options' => [
            ['name' => 'A', 'price_delta' => 0, 'min_qty' => 2, 'max_qty' => 3],
        ]])->assertCreated()->json();

        $this->putJson("/api/v1/modifier-groups/{$group['id']}", ['options' => [
            ['id' => $group['options'][0]['id'], 'name' => 'A', 'price_delta' => 0, 'max_qty' => 1],   // min tersimpan 2 > 1
        ]])->assertStatus(422)->assertJsonValidationErrors(['options.0.max_qty']);
    }

    public function test_the_pos_payload_carries_the_limits_of_each_option(): void
    {
        $this->opt['telur']->update(['min_qty' => 1, 'max_qty' => 3]);

        $groups = $this->getJson("/api/v1/variants/lookup?with_modifiers=1&sku={$this->nasiGoreng->sku}")->assertOk()->json();
        $flat = json_encode($groups);

        $this->assertStringContainsString('"min_qty":1', $flat);
        $this->assertStringContainsString('"max_qty":3', $flat);
    }

    public function test_the_receipt_and_the_order_detail_show_the_quantity_of_a_choice(): void
    {
        $this->opt['telur']->update(['max_qty' => 3]);
        $id = $this->order([$this->sel('telur', 2)])->assertCreated()->json('id');

        $receipt = $this->getJson("/api/v1/orders/{$id}/receipt")->assertOk();
        $receipt->assertJsonPath('items.0.modifiers.0.qty', 2)->assertJsonPath('items.0.modifiers.0.label', 'Telur ×2')
            ->assertJsonPath('items.0.modifiers.0.price_delta', '6000.00');

        $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('items.0.modifiers.0.label', 'Telur ×2');
    }
}
