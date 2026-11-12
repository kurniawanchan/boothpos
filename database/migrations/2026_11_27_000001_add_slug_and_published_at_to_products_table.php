<?php

use App\Support\ProductSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer commerce (2026-11-11) — produk mendapat pengenal publik dan status terbit.
 *
 * `slug`: pengenal produk di etalase pelanggan (`/storefront/products/{slug}`).
 * `code_prefix`/SKU tidak bisa dipakai karena ditulis ulang saat segmen produk
 * diubah. Unik per MODE DATA (`data_mode`, `slug`), bukan global: klon
 * LIVE → DEMO menyalin baris apa adanya dengan insert mentah, jadi indeks
 * global akan langsung bentrok dengan baris LIVE sumbernya. Ini indeks unik
 * pertama di skema yang menyertakan `data_mode` — disengaja.
 *
 * Baris lama diisi di sini (kedua mode, termasuk yang sudah dihapus lunak —
 * indeks unik tidak mengenal `deleted_at`) lewat DB::table(), bukan model:
 * scope DEMO/LIVE dan scope seller tidak boleh ikut menyaring backfill.
 * Aturannya sama persis dengan hook `creating` di model (App\Support\ProductSlug).
 * Sesudah terisi barulah kolom dijadikan NOT NULL lewat ALTER mentah
 * (Blueprint::change() butuh doctrine/dbal yang tidak terpasang).
 *
 * `published_at`: NULL = belum terbit. TIDAK ada backfill: produk lama tidak
 * pernah otomatis tampil di etalase; staf yang menerbitkannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', ProductSlug::COLUMN_LENGTH)->nullable()->after('name');
            $table->timestamp('published_at')->nullable()->after('is_active');
            $table->index('published_at', 'idx_products_published_at');
        });

        /** @var array<string,array<string,true>> $taken mode => [slug => true] */
        $taken = [];

        // lazyById: urut id menaik, jadi baris tertua mendapat slug tanpa akhiran.
        foreach (DB::table('products')->select(['id', 'name', 'data_mode'])->lazyById(500) as $row) {
            $slug = ProductSlug::dedupe(ProductSlug::base($row->name), $taken[$row->data_mode] ?? []);
            $taken[$row->data_mode][$slug] = true;

            // updated_at sengaja tidak disentuh: ini bukan perubahan oleh pengguna.
            DB::table('products')->where('id', $row->id)->update(['slug' => $slug]);
        }

        DB::statement('ALTER TABLE products MODIFY slug VARCHAR('.ProductSlug::COLUMN_LENGTH.') NOT NULL');

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['data_mode', 'slug'], 'uq_products_data_mode_slug');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('uq_products_data_mode_slug');
            $table->dropIndex('idx_products_published_at');
            $table->dropColumn(['slug', 'published_at']);
        });
    }
};
