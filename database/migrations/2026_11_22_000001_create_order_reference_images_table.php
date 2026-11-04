<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 055 — referensi transaksi boleh punya BANYAK gambar (maks. 10, dijaga OrderReferenceService).
 *
 * Gambar pindah dari dua kolom di `orders` (054: satu gambar per penjualan) ke tabel anak, satu baris per
 * gambar. Tabel ini TIDAK punya `data_mode`: gambar selalu dijangkau lewat Order yang sudah ber-scope
 * DEMO/LIVE, dan clone LIVE→DEMO menyalinnya lewat `via orders.order_id` (lihat CloneRegistry).
 *
 * Penyalinan memakai DB::table() dengan sengaja — harus memindahkan gambar KEDUA mode; global scope Eloquent
 * hanya melihat mode yang sedang aktif. Berkas di disk tidak disentuh, hanya penunjuknya yang pindah, jadi
 * migrasi ini tidak bisa menghilangkan berkas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_reference_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes')->nullable(); // NULL hanya untuk gambar hasil migrasi 054
            $table->unsignedSmallInteger('position');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'position']);
        });

        DB::table('orders')->whereNotNull('reference_image_path')->orderBy('id')
            ->select(['id', 'reference_image_path', 'reference_image_mime', 'reference_updated_by', 'reference_updated_at'])
            ->chunkById(500, function ($orders) {
                DB::table('order_reference_images')->insert($orders->map(fn ($o) => [
                    'order_id' => $o->id,
                    'path' => $o->reference_image_path,
                    'mime' => $o->reference_image_mime ?: 'application/octet-stream',
                    'size_bytes' => null,
                    'position' => 1,
                    'uploaded_by' => $o->reference_updated_by,
                    'created_at' => $o->reference_updated_at ?? now(),
                    'updated_at' => $o->reference_updated_at ?? now(),
                ])->all());
            });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['reference_image_path', 'reference_image_mime']);
        });
    }

    /**
     * Kembali ke bentuk 054: hanya gambar PERTAMA (posisi terkecil) tiap penjualan yang bisa dikembalikan ke
     * kolom tunggal; gambar lainnya hilang dari database saat rollback. Berkasnya TIDAK dihapus.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('reference_image_path', 255)->nullable()->after('reference_note');
            $table->string('reference_image_mime', 50)->nullable()->after('reference_image_path');
        });

        $first = DB::table('order_reference_images')
            ->select('order_id', DB::raw('MIN(position) AS position'))->groupBy('order_id');

        DB::table('order_reference_images AS i')
            ->joinSub($first, 'f', fn ($j) => $j->on('f.order_id', '=', 'i.order_id')->on('f.position', '=', 'i.position'))
            ->orderBy('i.id')
            ->select(['i.id', 'i.order_id', 'i.path', 'i.mime'])
            ->chunkById(500, function ($images) {
                foreach ($images as $image) {
                    DB::table('orders')->where('id', $image->order_id)
                        ->update(['reference_image_path' => $image->path, 'reference_image_mime' => $image->mime]);
                }
            }, 'i.id', 'id');

        Schema::dropIfExists('order_reference_images');
    }
};
