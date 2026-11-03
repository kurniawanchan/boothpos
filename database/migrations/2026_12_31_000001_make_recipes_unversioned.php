<?php

use App\Support\BusinessDay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 089 (keputusan pemilik 2026-11-02) — resep tidak lagi berversi; satu varian boleh punya banyak resep aktif dan
 * SEMUANYA dijumlahkan saat penjualan (potong stok) dan saat menghitung harga modal.
 *
 * MENGAPA data lama harus dirapikan DULU: di model lama versi baru menggantikan versi lama untuk varian yang sama
 * (`activeFor` = versi terbaru). Bila semua tautan `recipe_variants` dibiarkan, versi lama yang sudah tergantikan
 * ikut "aktif" lagi dan bahan terpotong dua kali. Jadi untuk tiap varian hanya versi aktif hari ini yang dipertahankan;
 * pasangan (resep, varian) yang tergantikan dilepas dan dicatat di log aktivitas (`recipe_migrated`) supaya jejaknya
 * tidak hilang. Resep yang tidak tertaut ke varian mana pun lagi disimpan sebagai arsip baca-saja (`archived_at`).
 *
 * Resep MASA DEPAN pada varian yang sudah punya resep aktif dilepas tautannya (lihat komentar di langkah 1): kalau tidak, pada
 * tanggalnya keduanya aktif dan bahan terpotong dua kali.
 *
 * Satu arah: `down()` tidak memulihkan versi (nomor versi dan tautan yang dilepas tidak dapat disusun ulang dengan
 * benar). Pemulihan data dev memakai cadangan (`php artisan app:backup`).
 *
 * Idempoten (cek kolom) supaya tes dapat menyalakan kembali bentuk lama dan menjalankan ulang langkah datanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('recipes', 'name')) {
                // Wajib diisi untuk resep baru lewat validasi request; bawaan hanya untuk baris hasil migrasi.
                $table->string('name', 100)->default('Resep')->after('product_id');
            }
            if (! Schema::hasColumn('recipes', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('cost_applied_at');
            }
        });

        if (Schema::hasColumn('recipes', 'version')) {
            DB::transaction(fn () => $this->migrateData());

            if (Schema::hasIndex('recipes', 'uniq_recipes_product_version')) {
                Schema::table('recipes', function (Blueprint $table) {
                    $table->dropUnique('uniq_recipes_product_version');
                });
            }
            Schema::table('recipes', function (Blueprint $table) {
                $table->dropColumn('version');
            });
        }
    }

    /** Hanya melepas tautan tergantikan, memberi nama, dan mengarsipkan; tidak pernah menghapus baris resep. */
    private function migrateData(): void
    {
        $today = BusinessDay::today();
        $recipes = DB::table('recipes')->get()->keyBy('id');
        $links = DB::table('recipe_variants')->get()->groupBy('variant_id');

        foreach ($links as $variantId => $rows) {
            $current = $rows->map(fn ($l) => $recipes[$l->recipe_id])
                ->filter(fn ($r) => $r->effective_from <= $today);
            // Versi aktif = tanggal berlaku terbaru, lalu nomor versi terbesar (aturan `activeFor` lama).
            $active = $current->sort(fn ($a, $b) => [$b->effective_from, $b->version] <=> [$a->effective_from, $a->version])->first();
            if ($active === null) {
                continue;   // hanya resep masa depan: biarkan tertaut, mulai berlaku pada tanggalnya
            }

            // Varian yang SUDAH punya resep aktif DAN versi masa depan: di model lama versi masa depan MENGGANTIKAN yang aktif
            // pada tanggalnya. Model baru menjumlahkan semua resep aktif, jadi membiarkan keduanya tertaut berarti bahan
            // terpotong dua kali sejak tanggal itu. Lebih aman melepas tautan versi masa depan (dicatat, resepnya tetap ada
            // tanpa varian) dan membiarkan pemilik menautkannya lagi secara sadar, daripada pergantian diam-diam jadi dobel.
            foreach ($rows->map(fn ($l) => $recipes[$l->recipe_id])->filter(fn ($r) => $r->effective_from > $today) as $future) {
                DB::table('recipe_variants')->where('recipe_id', $future->id)->where('variant_id', $variantId)->delete();
                DB::table('activity_logs')->insert([
                    'user_id' => null,
                    'action' => 'recipe_migrated',
                    'entity_type' => 'Recipe',
                    'entity_id' => $future->id,
                    'description' => "Resep v{$future->version} (berlaku {$future->effective_from}) dilepas dari varian #{$variantId}: varian ini sudah punya resep aktif v{$active->version}; tautkan ulang bila resep ini memang pengganti (089).",
                    'old_values' => json_encode(['variant_id' => (int) $variantId, 'version' => (int) $future->version, 'effective_from' => $future->effective_from]),
                    'new_values' => json_encode(['active_recipe_id' => (int) $active->id, 'active_version' => (int) $active->version]),
                    'created_at' => now(),
                ]);
            }

            foreach ($current->where('id', '!=', $active->id) as $superseded) {
                DB::table('recipe_variants')->where('recipe_id', $superseded->id)->where('variant_id', $variantId)->delete();
                DB::table('activity_logs')->insert([
                    'user_id' => null,
                    'action' => 'recipe_migrated',
                    'entity_type' => 'Recipe',
                    'entity_id' => $superseded->id,
                    'description' => "Resep v{$superseded->version} dilepas dari varian #{$variantId}: tergantikan v{$active->version} (089, resep tanpa versi).",
                    'old_values' => json_encode(['variant_id' => (int) $variantId, 'version' => (int) $superseded->version]),
                    'new_values' => json_encode(['active_recipe_id' => (int) $active->id, 'active_version' => (int) $active->version]),
                    'created_at' => now(),
                ]);
            }
        }

        $stillLinked = DB::table('recipe_variants')->pluck('recipe_id')->flip();
        $perProduct = $recipes->groupBy('product_id');

        foreach ($recipes as $recipe) {
            $archive = ! $stillLinked->has($recipe->id) && $recipe->effective_from <= $today;
            $siblings = $perProduct[$recipe->product_id]->filter(fn ($r) => $stillLinked->has($r->id) || $r->id === $recipe->id)->count();
            // Dibedakan dengan nomor versi lama hanya bila perlu (arsip, atau beberapa resep hidup pada satu produk).
            $name = ($archive || $siblings > 1) ? "Resep v{$recipe->version}" : 'Resep';

            DB::table('recipes')->where('id', $recipe->id)->update(['name' => $name] + ($archive ? ['archived_at' => now()] : []));
        }
    }

    public function down(): void
    {
        // Sengaja kosong: lihat docblock (satu arah).
    }
};
