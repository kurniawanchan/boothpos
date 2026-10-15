<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / T005 (api-gap B14, B12) — terminal POS terdaftar per outlet dan penghitung nomor.
 *
 * `devices.outlet_id` diturunkan server saat pendaftaran (tidak pernah dari body). Kode (mis. `T01`) unik per
 * outlet dan per mode data supaya DEMO dan LIVE tidak berbenturan. `terminal_counters` menyimpan urutan nomor
 * struk per terminal dan DIKUNCI per baris saat dialokasikan; `outlet_queue_counters` urutan antrean per
 * outlet per hari bisnis. Perangkat yang dicabut tidak dihapus (jejak audit), hanya diberi `revoked_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->string('code', 12);
            $table->string('name', 80);
            $table->string('app_version', 40)->nullable();
            $table->string('os_info', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_devices_data_mode');
            $table->timestamps();

            $table->unique(['outlet_id', 'data_mode', 'code'], 'uniq_devices_outlet_mode_code');
        });

        Schema::create('terminal_counters', function (Blueprint $table) {
            $table->foreignId('device_id')->primary()->constrained('devices')->cascadeOnDelete();
            $table->unsignedInteger('receipt_seq')->default(0);
        });

        Schema::create('outlet_queue_counters', function (Blueprint $table) {
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->date('business_date');
            $table->unsignedInteger('queue_seq')->default(0);
            $table->primary(['outlet_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_queue_counters');
        Schema::dropIfExists('terminal_counters');
        Schema::dropIfExists('devices');
    }
};
