<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 034-seller-po-bom — purchase order kini punya SELLER (satu per PO).
 * Nullable: PO yang dibuat sebelum fitur ini sengaja dibiarkan tanpa
 * seller (tidak ada tebakan/backfill dari "Linked Product", yang bisa
 * salah dan diam-diam menentukan BOM mana yang boleh memakainya) —
 * PO tanpa seller tidak pernah ditawarkan sebagai sumber BOM sampai
 * owner/admin menetapkannya secara eksplisit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('artist_id')->nullable()->after('vendor_id')
                ->constrained('artists')->restrictOnDelete();
            $table->index(['artist_id', 'status'], 'idx_purchase_orders_artist_status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // FK dulu: index (artist_id, status) ikut menjadi index pendukung FK,
            // MySQL menolak menghapusnya selagi FK masih ada.
            $table->dropForeign(['artist_id']);
            $table->dropIndex('idx_purchase_orders_artist_status');
            $table->dropColumn('artist_id');
        });
    }
};
