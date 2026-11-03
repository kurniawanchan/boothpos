<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Support\BusinessDay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 089 — migrasi resep berversi → resep tanpa versi (research D4). Bentuk lama dinyalakan kembali (kolom `version`),
 * data lama ditanam, lalu langkah migrasi dijalankan ulang (migrasinya idempoten).
 *
 * Catatan: DDL di MySQL meng-commit transaksi tes, jadi baris yang dibuat dibersihkan sendiri di `finally`.
 */
class RecipeUnversionMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    public function test_legacy_versions_become_unversioned_recipes_without_double_activation(): void
    {
        $today = Carbon::parse(BusinessDay::today());
        $a = $this->makeVariant();
        $b = $a->product->variants()->create(['sku' => 'RSTMIG0001', 'variant_name' => 'Large', 'sell_price' => 30000, 'cost_price' => 10000, 'current_stock' => 0]);
        $pid = $a->product_id;

        Schema::table('recipes', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->unique(['product_id', 'version'], 'uniq_recipes_product_version');
        });

        $ids = [];
        try {
            $mk = fn (int $v, Carbon $from, array $variants) => tap(DB::table('recipes')->insertGetId([
                'product_id' => $pid, 'version' => $v, 'yield' => 1, 'effective_from' => $from->toDateString(),
                'name' => 'x', 'data_mode' => 'live', 'created_at' => now(), 'updated_at' => now(),
            ]), function ($id) use ($variants) {
                foreach ($variants as $variantId) {
                    DB::table('recipe_variants')->insert(['recipe_id' => $id, 'variant_id' => $variantId]);
                }
            });

            $r1 = $ids[] = $mk(1, $today->copy()->subDays(10), [$a->id, $b->id]);   // masih aktif untuk B saja
            $r2 = $ids[] = $mk(2, $today->copy()->subDays(5), [$a->id]);             // menggantikan r1 untuk A
            $r3 = $ids[] = $mk(3, $today->copy()->addDays(5), [$b->id]);             // masa depan, padahal B sudah punya r1 aktif
            $r4 = $ids[] = $mk(4, $today->copy()->subDays(20), [$a->id]);            // tergantikan r2 untuk A, tak punya varian lain

            (require database_path('migrations/2026_12_31_000001_make_recipes_unversioned.php'))->up();

            $this->assertFalse(Schema::hasColumn('recipes', 'version'));
            $linked = fn (int $recipe) => DB::table('recipe_variants')->where('recipe_id', $recipe)->pluck('variant_id')->sort()->values()->all();

            $this->assertSame([$b->id], $linked($r1), 'v1 tinggal untuk B; tautan A dilepas supaya tidak terpotong dua kali');
            $this->assertSame([$a->id], $linked($r2));
            $this->assertSame([], $linked($r3), 'masa depan + sudah ada resep aktif: dilepas supaya tidak dobel pada tanggalnya');
            $this->assertSame([], $linked($r4));

            $this->assertNull(DB::table('recipes')->where('id', $r1)->value('archived_at'));
            $this->assertNull(DB::table('recipes')->where('id', $r2)->value('archived_at'));
            $this->assertNull(DB::table('recipes')->where('id', $r3)->value('archived_at'), 'tidak diarsipkan: pemilik dapat menautkannya lagi');
            $this->assertNotNull(DB::table('recipes')->where('id', $r4)->value('archived_at'), 'tanpa tautan tersisa = arsip, bukan terhapus');
            $this->assertSame(4, DB::table('recipes')->whereIn('id', $ids)->count(), 'tidak ada resep yang dihapus');

            $this->assertSame('Resep v1', DB::table('recipes')->where('id', $r1)->value('name'));
            $this->assertSame('Resep v4', DB::table('recipes')->where('id', $r4)->value('name'));

            $logged = ActivityLog::query()->where('action', 'recipe_migrated')->whereIn('entity_id', $ids)->get();
            $this->assertCount(3, $logged, 'r1→A, r4→A, dan r3→B dilepas dan tercatat');
            $this->assertEqualsCanonicalizing([$r1, $r3, $r4], $logged->pluck('entity_id')->all());
        } finally {
            DB::table('recipes')->whereIn('id', $ids)->delete();
            ActivityLog::query()->where('action', 'recipe_migrated')->delete();
        }
    }

    public function test_a_product_with_a_single_recipe_keeps_the_plain_name(): void
    {
        $a = $this->makeVariant();
        Schema::table('recipes', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        $id = DB::table('recipes')->insertGetId([
            'product_id' => $a->product_id, 'version' => 1, 'yield' => 1, 'effective_from' => BusinessDay::today(),
            'name' => 'x', 'data_mode' => 'live', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recipe_variants')->insert(['recipe_id' => $id, 'variant_id' => $a->id]);

        try {
            (require database_path('migrations/2026_12_31_000001_make_recipes_unversioned.php'))->up();
            $this->assertSame('Resep', DB::table('recipes')->where('id', $id)->value('name'));
            $this->assertNull(DB::table('recipes')->where('id', $id)->value('archived_at'));
        } finally {
            DB::table('recipes')->where('id', $id)->delete();
        }
    }

    public function test_a_future_only_recipe_stays_linked_until_its_date(): void
    {
        $a = $this->makeVariant();
        Schema::table('recipes', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        $id = DB::table('recipes')->insertGetId([
            'product_id' => $a->product_id, 'version' => 1, 'yield' => 1, 'effective_from' => Carbon::parse(BusinessDay::today())->addDays(4)->toDateString(),
            'name' => 'x', 'data_mode' => 'live', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recipe_variants')->insert(['recipe_id' => $id, 'variant_id' => $a->id]);

        try {
            (require database_path('migrations/2026_12_31_000001_make_recipes_unversioned.php'))->up();
            $this->assertSame(1, DB::table('recipe_variants')->where('recipe_id', $id)->count(), 'tanpa resep aktif lain: tetap tertaut, mulai pada tanggalnya');
        } finally {
            DB::table('recipes')->where('id', $id)->delete();
        }
    }
}
