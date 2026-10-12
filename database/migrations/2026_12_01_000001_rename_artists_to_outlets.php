<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 045 Stage 1 (T0xx, data-model.md §1) — "penjual" (artists) menjadi "outlet" secara DI TEMPAT: identitas, kode, dan
 * riwayat tidak disalin. Pemetaan dilakukan menurut MAKNA kolom, bukan string:
 *
 *   - products / order_items / preorder_items .artist_id  → home_outlet_id   (outlet asal item; tetap)
 *   - purchase_orders .artist_id                          → outlet_id        (outlet pembeli)
 *   - session_opening_cash_entries .artist_id             → outlet_id        (kas awal per outlet; baris lama tetap)
 *   - artist_settlements (artist_id)                      → outlet_settlements (outlet_id)
 *   - roles.menu_keys: 'artists' → 'outlets'
 *   - settings: multi_artist_enabled → multi_outlet_enabled
 *
 * Re-runnable: setiap langkah diperiksa dulu (hasTable/hasColumn). Tidak mengubah angka apa pun (lihat
 * `outlets:reconcile`). Pemulihan: `down()` membalik semua langkah ini.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> tabel => [kolom lama, kolom baru] */
    private const COLUMNS = [
        'products' => ['artist_id', 'home_outlet_id'],
        'order_items' => ['artist_id', 'home_outlet_id'],
        'preorder_items' => ['artist_id', 'home_outlet_id'],
        'purchase_orders' => ['artist_id', 'outlet_id'],
        'session_opening_cash_entries' => ['artist_id', 'outlet_id'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('artists') && ! Schema::hasTable('outlets')) {
            Schema::rename('artists', 'outlets');
        }

        foreach (self::COLUMNS as $table => [$old, $new]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $old) && ! Schema::hasColumn($table, $new)) {
                Schema::table($table, fn (Blueprint $t) => $t->renameColumn($old, $new));
            }
        }

        if (Schema::hasTable('artist_settlements') && ! Schema::hasTable('outlet_settlements')) {
            Schema::rename('artist_settlements', 'outlet_settlements');
        }
        if (Schema::hasTable('outlet_settlements') && Schema::hasColumn('outlet_settlements', 'artist_id')) {
            Schema::table('outlet_settlements', fn (Blueprint $t) => $t->renameColumn('artist_id', 'outlet_id'));
        }

        $this->rewriteMenuKeys('artists', 'outlets');

        if (DB::table('settings')->where('key', 'multi_artist_enabled')->exists() && ! DB::table('settings')->where('key', 'multi_outlet_enabled')->exists()) {
            DB::table('settings')->where('key', 'multi_artist_enabled')->update(['key' => 'multi_outlet_enabled']);
        }
    }

    public function down(): void
    {
        if (DB::table('settings')->where('key', 'multi_outlet_enabled')->exists() && ! DB::table('settings')->where('key', 'multi_artist_enabled')->exists()) {
            DB::table('settings')->where('key', 'multi_outlet_enabled')->update(['key' => 'multi_artist_enabled']);
        }

        $this->rewriteMenuKeys('outlets', 'artists');

        if (Schema::hasTable('outlet_settlements') && Schema::hasColumn('outlet_settlements', 'outlet_id')) {
            Schema::table('outlet_settlements', fn (Blueprint $t) => $t->renameColumn('outlet_id', 'artist_id'));
        }
        if (Schema::hasTable('outlet_settlements') && ! Schema::hasTable('artist_settlements')) {
            Schema::rename('outlet_settlements', 'artist_settlements');
        }

        foreach (self::COLUMNS as $table => [$old, $new]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $new) && ! Schema::hasColumn($table, $old)) {
                Schema::table($table, fn (Blueprint $t) => $t->renameColumn($new, $old));
            }
        }

        if (Schema::hasTable('outlets') && ! Schema::hasTable('artists')) {
            Schema::rename('outlets', 'artists');
        }
    }

    /** `roles.menu_keys` adalah JSON daftar; hanya kunci menu ini yang diganti, urutan dan kunci lain tetap. */
    private function rewriteMenuKeys(string $from, string $to): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'menu_keys')) {
            return;
        }

        foreach (DB::table('roles')->select('id', 'menu_keys')->get() as $role) {
            $keys = json_decode((string) $role->menu_keys, true);
            if (! is_array($keys) || ! in_array($from, $keys, true)) {
                continue;
            }
            $keys = array_values(array_unique(array_map(fn ($k) => $k === $from ? $to : $k, $keys)));
            DB::table('roles')->where('id', $role->id)->update(['menu_keys' => json_encode($keys)]);
        }
    }
};
