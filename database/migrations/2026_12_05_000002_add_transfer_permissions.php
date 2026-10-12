<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 045 Stage 6 — izin transfer stok untuk peran yang SUDAH punya daftar izin eksplisit (`roles.permissions` bukan NULL).
 * Peran dengan NULL memakai bawaan menurut nama (PermissionCatalog::defaultsFor), jadi tidak perlu disentuh.
 * Owner/Admin: ketiganya; inventory: kirim + terima.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant(['owner', 'admin'], PermissionCatalog::transferKeys());
        $this->grant(['inventory'], [PermissionCatalog::TRANSFER_DISPATCH, PermissionCatalog::TRANSFER_RECEIVE]);
    }

    public function down(): void
    {
        foreach (DB::table('roles')->whereNotNull('permissions')->get(['id', 'permissions']) as $role) {
            $kept = array_values(array_diff((array) json_decode($role->permissions, true), PermissionCatalog::transferKeys()));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($kept)]);
        }
    }

    private function grant(array $names, array $keys): void
    {
        foreach (DB::table('roles')->whereNotNull('permissions')->whereIn(DB::raw('LOWER(name)'), $names)->get(['id', 'permissions']) as $role) {
            $merged = array_values(array_unique(array_merge((array) json_decode($role->permissions, true), $keys)));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($merged)]);
        }
    }
};
