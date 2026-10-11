<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign — riwayat harga dasar per varian (append-only), ditulis HANYA oleh
 * PriceService. Menggantikan pembacaan baris audit `price_changed` untuk linimasa di halaman item.
 * Backfill: satu baris `created` per varian yang sudah ada (harga saat ini + waktu dibuat) supaya
 * linimasa selalu berakhir dengan "Item dibuat".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_history')) {
            Schema::create('price_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->unsignedBigInteger('outlet_id')->nullable();
                $table->decimal('old_price', 14, 2)->nullable();
                $table->decimal('new_price', 14, 2);
                $table->enum('source', ['created', 'edit', 'bulk', 'import', 'release']);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('effective_at');
                $table->unsignedBigInteger('release_id')->nullable();
                $table->unsignedBigInteger('change_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamp('created_at')->nullable();

                $table->index(['variant_id', 'created_at'], 'idx_price_history_variant');
                $table->index('data_mode', 'idx_price_history_mode');
            });
        }

        DB::table('product_variants')->orderBy('id')->chunkById(500, function ($variants) {
            foreach ($variants as $v) {
                if (DB::table('price_history')->where('variant_id', $v->id)->exists()) {
                    continue;
                }
                $at = $v->created_at ?? now();
                DB::table('price_history')->insert([
                    'variant_id' => $v->id, 'old_price' => null, 'new_price' => $v->sell_price, 'source' => 'created',
                    'effective_at' => $at, 'data_mode' => $v->data_mode ?? 'live', 'created_at' => $at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
