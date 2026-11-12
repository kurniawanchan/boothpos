<?php

namespace App\Services\DemoClone;

use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sidik jari data LIVE: jumlah baris + SUM(CRC32(seluruh kolom)) per tabel.
 * Dibaca SEBELUM dan SESUDAH penyalinan di dalam transaksi yang sama —
 * selisih berarti perintah ini menyentuh LIVE, dan seluruhnya dibatalkan.
 *
 * Selain tabel yang disalin, ikut dijaga: tabel bersama (users, roles,
 * payment_channels, settings) serta dua tabel yang bisa tersentuh oleh
 * penghapusan DEMO lewat FK tanpa ikut disalin — preorder_notifications
 * (CASCADE dari preorders) dan pos_drafts (SET NULL dari events) — serta
 * tabel customer commerce yang hanya dihapus di sisi DEMO
 * (CloneRegistry::wipeOnly()) dan token selain milik akun pelanggan DEMO.
 */
final class LiveFingerprint
{
    private const SHARED = ['users', 'roles', 'payment_channels', 'settings'];

    /** @return array<string,array{count:int,crc:string}> */
    public static function capture(): array
    {
        $out = [];

        foreach (array_keys(CloneRegistry::tables()) as $table) {
            $out[$table] = self::of($table, CloneRegistry::scope($table, 'live'));
        }

        $out['preorder_notifications'] = self::of(
            'preorder_notifications',
            "preorder_id IN (SELECT id FROM preorders WHERE data_mode = 'live')",
        );
        $out['pos_drafts'] = self::of('pos_drafts', "data_mode = 'live'");

        // Customer commerce: tabel yang hanya dihapus di DEMO. Baris LIVE-nya tidak boleh
        // berubah sedikit pun — tanpa ini, penghapusan yang salah scope tidak akan ketahuan.
        foreach (array_keys(CloneRegistry::wipeOnly()) as $table) {
            $out[$table] = self::of($table, CloneRegistry::wipeOnlyScope($table, 'live'));
        }

        // Token: perintah ini hanya boleh menghapus token milik akun pelanggan DEMO. Semua token
        // lain (staf, akun pelanggan LIVE) ikut dijaga. Nama kelas dikirim sebagai binding:
        // garis miring terbalik di dalam literal SQL akan dimakan MySQL.
        $out['personal_access_tokens'] = self::of(
            'personal_access_tokens',
            "NOT (tokenable_type = ? AND tokenable_id IN (SELECT id FROM customer_accounts WHERE data_mode = 'demo'))",
            [(new CustomerAccount)->getMorphClass()],
        );

        foreach (self::SHARED as $table) {
            $out[$table] = self::of($table, '1 = 1');
        }

        return $out;
    }

    /** @return list<string> nama tabel yang berbeda */
    public static function diff(array $before, array $after): array
    {
        return array_values(array_keys(array_filter(
            $before,
            fn (array $fingerprint, string $table) => $fingerprint !== ($after[$table] ?? null),
            ARRAY_FILTER_USE_BOTH,
        )));
    }

    /** @return array{count:int,crc:string} */
    private static function of(string $table, string $where, array $bindings = []): array
    {
        $columns = array_map(
            fn (string $column) => "IFNULL(CAST(`{$column}` AS CHAR), '<NULL>')",
            Schema::getColumnListing($table),
        );

        $row = DB::selectOne(
            "SELECT COUNT(*) AS c, IFNULL(SUM(CRC32(CONCAT_WS('|', ".implode(', ', $columns)."))), 0) AS s FROM `{$table}` WHERE {$where}",
            $bindings,
        );

        return ['count' => (int) $row->c, 'crc' => (string) $row->s];
    }
}
