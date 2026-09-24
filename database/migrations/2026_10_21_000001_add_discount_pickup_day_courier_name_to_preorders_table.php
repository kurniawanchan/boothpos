<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 021-preorder-form-updates — tiga kolom baru, semua nullable/berdefault,
 * jadi preorder yang sudah ada sebelum fitur ini tetap valid tanpa
 * backfill (discount=0, pickup_day=null, courier_name=null).
 *
 * - discount: nominal Rupiah tetap (bukan persen — lihat research.md
 *   Decision 3), dihitung & divalidasi di PreorderService::create(),
 *   tidak pernah dihitung ulang setelahnya (snapshot, sama seperti
 *   sell_price/cost_price per item).
 * - pickup_day: tanggal ASLI (bukan angka "1"/"2"), diturunkan dari
 *   rentang start_date-end_date event yang ditautkan (research.md
 *   Decision 2) — hanya terisi saat fulfillment=pickup DAN preorder
 *   punya event_id.
 * - courier_name: nilai DEFAULT/preferensi yang dipilih saat preorder
 *   dibuat (fulfillment=courier) — BUKAN pengganti shipments.courier_name
 *   yang tetap wajib diisi terpisah saat shipment sungguhan dibuat
 *   nanti (research.md Decision 1, sengaja tidak menyentuh skema
 *   shipments sama sekali).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->decimal('discount', 14, 2)->default(0)->after('shipping_cost');
            $table->date('pickup_day')->nullable()->after('expected_date');
            $table->string('courier_name', 50)->nullable()->after('pickup_day');
        });
    }

    public function down(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->dropColumn(['discount', 'pickup_day', 'courier_name']);
        });
    }
};
