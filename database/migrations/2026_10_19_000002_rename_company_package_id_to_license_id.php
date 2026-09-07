<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 019-billing-system — mengikuti rename packages->licenses (migration
 * sebelumnya): kolom FK `companies.package_id` diganti nama menjadi
 * `license_id` (research.md R1'). Constraint FK lama (`companies_
 * package_id_foreign`, nama otomatis dari `foreignId('package_id')->
 * constrained('packages')` di migration 017) sengaja di-drop lalu
 * dibuat ulang dengan nama baru yang merujuk `licenses` — MySQL TETAP
 * mempertahankan constraint apa adanya kalau kita hanya renameColumn
 * (constraint-nya masih menunjuk ke kolom yang benar), tapi nama
 * constraint akan tertinggal menyebut "package" selamanya kalau tidak
 * dibuat ulang; drop+recreate eksplisit menghindari itu, konsisten
 * dengan catatan research.md R1'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('package_id', 'license_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('license_id')->references('id')->on('licenses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['license_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('license_id', 'package_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('package_id')->references('id')->on('packages')->restrictOnDelete();
        });
    }
};
