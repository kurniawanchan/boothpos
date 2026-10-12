<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 046-hide-events-preorders — kunci menu `events` dan `preorders` dicabut dari semua peran
 * tersimpan. Kunci itu juga hilang dari App\Support\MenuKeys, dan Role::allows mengabaikan kunci
 * yang tidak dikenal, jadi migrasi ini hanya merapikan data (idempoten, aman dijalankan ulang).
 * `down()` sengaja kosong: kunci yang dicabut tidak bisa dipulihkan tanpa menebak peran mana
 * yang dulu memilikinya — pulihkan dari `app:backup` bila perlu.
 */
return new class extends Migration
{
    private const REMOVED = ['events', 'preorders'];

    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'menu_keys']) as $role) {
            $keys = json_decode($role->menu_keys, true) ?? [];
            $kept = array_values(array_diff($keys, self::REMOVED));

            if ($kept !== $keys) {
                DB::table('roles')->where('id', $role->id)->update(['menu_keys' => json_encode($kept)]);
            }
        }
    }

    public function down(): void
    {
        // Tidak dapat dipulihkan secara andal; lihat docblok.
    }
};
