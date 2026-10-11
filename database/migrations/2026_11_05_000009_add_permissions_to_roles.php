<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign — izin tingkat-aksi di samping `menu_keys` (research.md D9). NULL = pakai bawaan
 * menurut nama peran sistem (App\Support\PermissionCatalog::defaultsFor), supaya peran buatan pabrik tes
 * dan peran lama tetap bekerja. Seed eksplisit untuk peran sistem agar layar Peran menampilkannya.
 * Juga menetapkan bawaan pengaturan menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'permissions')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->json('permissions')->nullable()->after('menu_keys');
            });
        }

        foreach (DB::table('roles')->whereNull('permissions')->get(['id', 'name']) as $role) {
            $defaults = \App\Support\PermissionCatalog::defaultsFor((string) $role->name);
            if ($defaults !== []) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($defaults)]);
            }
        }

        $now = now();
        foreach ([
            ['menu_release_enabled', 'false', 'boolean'],
            ['price_approval_threshold_pct', '15', 'decimal'],
            ['price_rounding_default', '500', 'string'],
        ] as [$key, $value, $type]) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'type' => $type, 'group' => 'menu', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'permissions')) {
            Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('permissions'));
        }
    }
};
