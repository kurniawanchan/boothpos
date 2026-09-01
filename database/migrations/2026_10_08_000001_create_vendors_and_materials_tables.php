<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul vendor/bahan baku/BOM ditambahkan PASCA-MVP pada 2026-09-01 atas
 * permintaan eksplisit pemilik produk — PRD §10.2 sebelumnya mencoret
 * "vendor management" dan "materials/production" dari cakupan MVP. Ini
 * BUKAN kebangkitan salah satu butir yang dicoret itu (keduanya jauh lebih
 * luas: manajemen PO, biaya produksi penuh, dsb) — cakupannya sengaja
 * dipersempit menjadi: vendor mana saja yang menjual suatu bahan, berapa
 * harganya, dan berapa modal produk berdasarkan BOM-nya. Lihat catatan
 * bertanggal yang sama di CLAUDE.md/README.md/PRD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            // 'code' dibutuhkan supaya sheet Excel vendor_material_prices/bom
            // bisa merujuk vendor lewat identitas stabil yang bisa diketik
            // manusia di spreadsheet — pola yang sama dengan artist_code/
            // category_code pada sheet 'products'. Panjang dibuat longgar
            // (bukan char(3) seperti Artist) karena kode vendor tidak dipakai
            // membentuk SKU/code_prefix produk mana pun.
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            // Satuan sengaja string bebas (pcs, gram, meter, lembar, ...)
            // alih-alih enum tetap — daftar unit bahan baku booth merchandise
            // terlalu beragam untuk dibatasi di skema, dan salah ketik satuan
            // tidak berisiko merusak perhitungan lain seperti halnya salah
            // ketik pada kolom uang/angka.
            $table->string('unit', 20);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('vendor_material_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('price', 14, 2);
            // Satu bahan boleh punya banyak vendor (PRD baru: "vendor mana
            // saja"), tapi biaya BOM butuh SATU harga acuan yang jelas.
            // is_preferred menandai vendor utama; lihat dokblok
            // BomCostCalculator untuk aturan fallback bila tidak ada yang
            // ditandai.
            $table->boolean('is_preferred')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'material_id']);
            $table->index('material_id');
        });

        Schema::create('product_variant_bom_lines', function (Blueprint $table) {
            $table->id();
            // BOM diikat ke VARIAN, bukan produk induk — keputusan desain
            // eksplisit: varian ukuran/warna berbeda dari produk yang sama
            // (mis. keychain kecil vs besar) masuk akal butuh jumlah bahan
            // yang berbeda, dan ProductVariant sudah jadi entitas kelas satu
            // di kodebase ini (harga, stok, SKU semua per-varian) sehingga
            // BOM per-varian konsisten dengan pola yang sudah ada, bukan
            // menambah level baru.
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            // decimal(12,4) — jumlah bahan per unit produk jadi bisa pecahan
            // kecil (mis. 0.05 meter tali, 2.5 gram lem), bukan hanya bilangan
            // bulat seperti stok unit jadi.
            $table->decimal('qty_needed', 12, 4);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_variant_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_bom_lines');
        Schema::dropIfExists('vendor_material_prices');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('vendors');
    }
};
