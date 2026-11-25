<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 094 / FR-005 (keputusan pemilik 2026-11-25, opsi C) — penanda peran "Boleh memakai aplikasi manajemen". Bukan turunan
 * kunci menu: peran Supervisor dengan menu Lantai saja tidak boleh ikut lolos, jadi penandanya berdiri sendiri.
 *
 * Bawaan mati; dinyalakan untuk Owner/Admin (peran bawaan) dan peran bernama "Outlet Manager" (seed dev 048). Pencocokan
 * nama tanpa beda huruf besar/kecil. Peran terhapus lunak ikut diisi supaya pemulihan peran tidak mengejutkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('manager_app_enabled')->default(false)->after('all_outlets');
        });

        DB::table('roles')
            ->whereIn(DB::raw('LOWER(name)'), ['owner', 'admin', 'outlet manager'])
            ->update(['manager_app_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('manager_app_enabled');
        });
    }
};
