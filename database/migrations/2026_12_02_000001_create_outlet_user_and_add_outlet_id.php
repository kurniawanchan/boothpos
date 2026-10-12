<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 045-outlets-per-outlet-menu, Stage 2 (S2a) — fondasi outlet.
 *
 * Yang dikerjakan di migrasi INI (aman untuk instalasi satu outlet):
 *  - tabel pivot `outlet_user` (penugasan pengguna ke outlet);
 *  - `roles.all_outlets` — Owner dan Admin melihat semua outlet tanpa baris pivot;
 *  - kolom `outlet_id` NULLABLE pada tabel operasional, lalu DIISI (backfill) ke
 *    outlet pertama dengan mode data yang sama (D8). Baris yang sudah ada sebelum
 *    migrasi ditandai `outlet_backfilled = true` (orders, preorders) sehingga
 *    atribusi laporannya tetap pada pemilik lama (D9);
 *  - semua pengguna non-admin yang ada ditugaskan ke SEMUA outlet yang ada, supaya
 *    tidak ada yang hilang sebelum layar Pengguna mempersempitnya (D6).
 *
 * Yang SENGAJA BELUM dikerjakan di sini (langkah S2b, setelah penstempelan dan
 * tes kebocoran selesai): kolom menjadi NOT NULL dan FK-nya diperketat. Alasannya:
 * lebih dari 90 file tes membuat sesi/order/meja secara langsung tanpa outlet;
 * kolom wajib sebelum penstempelan terpasang akan merusak seluruh suite.
 *
 * Migrasi ini re-runnable: setiap langkah memeriksa keadaan sebelum mengubah.
 */
return new class extends Migration
{
    /** Tabel operasional yang menerima kolom outlet_id (data-model.md §2). */
    private const TABLES = [
        'cashier_sessions', 'orders', 'payments', 'kitchen_tickets',
        'dining_tables', 'stations', 'preorders',
    ];

    /** Tabel yang baris lamanya ditandai outlet_backfilled = true. */
    private const LEGACY_FLAGGED = ['orders', 'preorders'];

    public function up(): void
    {
        if (! Schema::hasTable('outlet_user')) {
            Schema::create('outlet_user', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->primary(['user_id', 'outlet_id']);
            });
        }

        if (! Schema::hasColumn('roles', 'all_outlets')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('all_outlets')->default(false);
            });
        }
        DB::table('roles')->whereIn(DB::raw('LOWER(name)'), ['owner', 'admin'])->update(['all_outlets' => true]);

        // Tandai baris lama SEBELUM kolom baru terisi, supaya baris baru tidak ikut tertandai.
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'outlet_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                });
            }
        }
        foreach (self::LEGACY_FLAGGED as $table) {
            if (! Schema::hasColumn($table, 'outlet_backfilled')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('outlet_backfilled')->default(false);
                });
            }
        }

        foreach (self::LEGACY_FLAGGED as $table) {
            // Hanya baris yang outlet_id-nya masih kosong = baris lama (belum diisi sebelumnya).
            DB::table($table)->whereNull('outlet_id')->update(['outlet_backfilled' => true]);
        }

        foreach (['live', 'demo'] as $mode) {
            $outletId = $this->firstOutletFor($mode);

            foreach (self::TABLES as $table) {
                $hasLegacy = DB::table($table)->where('data_mode', $mode)->whereNull('outlet_id')->exists();
                if ($hasLegacy && $outletId === null) {
                    $outletId = $this->createDefaultOutlet($mode);
                }
                if ($outletId !== null) {
                    DB::table($table)->where('data_mode', $mode)->whereNull('outlet_id')->update(['outlet_id' => $outletId]);
                }
            }
        }

        $outletIds = DB::table('outlets')->pluck('id');
        $nonAdminIds = DB::table('users')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->where(fn ($q) => $q->whereNull('roles.all_outlets')->orWhere('roles.all_outlets', false))
            ->pluck('users.id');

        foreach ($nonAdminIds as $userId) {
            foreach ($outletIds as $outletId) {
                DB::table('outlet_user')->insertOrIgnore(['user_id' => $userId, 'outlet_id' => $outletId]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'outlet_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('outlet_id');
                });
            }
        }
        foreach (self::LEGACY_FLAGGED as $table) {
            if (Schema::hasColumn($table, 'outlet_backfilled')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('outlet_backfilled'));
            }
        }
        if (Schema::hasColumn('roles', 'all_outlets')) {
            Schema::table('roles', fn (Blueprint $blueprint) => $blueprint->dropColumn('all_outlets'));
        }
        Schema::dropIfExists('outlet_user');
    }

    /** Outlet pertama dengan mode data tertentu (urutan id, sama dengan "outlet pertama" di D8). */
    private function firstOutletFor(string $mode): ?int
    {
        $id = DB::table('outlets')->where('data_mode', $mode)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Instalasi yang sudah punya data operasional tetapi belum punya outlet sama
     * sekali (mis. sebelum fitur sellers ada) mendapat satu outlet bawaan, supaya
     * data lama tidak menggantung tanpa outlet.
     */
    private function createDefaultOutlet(string $mode): int
    {
        $code = $mode === 'demo' ? 'UTD' : 'UTM';
        $name = $mode === 'demo' ? 'Outlet utama (DEMO)' : 'Outlet utama';

        return (int) DB::table('outlets')->insertGetId([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'data_mode' => $mode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
