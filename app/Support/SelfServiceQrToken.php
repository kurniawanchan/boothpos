<?php

namespace App\Support;

use App\Models\DiningTable;
use App\Models\Outlet;
use Illuminate\Support\Str;

/**
 * 090 / research D6 — token QR meja yang tidak bisa dipalsukan.
 *
 * Isi (id meja, id outlet, mode data, nonce meja) ditandatangani HMAC-SHA256 dengan APP_KEY. Mengganti id di
 * alamat membuat tanda tangan gagal; memutar `dining_tables.qr_nonce` ("Buat ulang QR") mematikan QR lama.
 * Kegagalan memakai SATU pengecualian dengan dua sebab yang boleh terlihat (lihat InvalidSelfServiceQr): token yang
 * tidak sah tidak boleh bisa diselidiki; pemegang token SAH boleh tahu mejanya nonaktif.
 *
 * Kelas ini hanya memeriksa dan menerbitkan. Ia tidak menulis apa pun kecuali `rotate()` (memutar nonce).
 */
final class SelfServiceQrToken
{
    /** Pemisah isi dan tanda tangan; base64url tidak pernah memuat titik. */
    private const SEP = '.';

    public static function issue(DiningTable $table): string
    {
        $payload = self::b64(implode(':', [
            (int) $table->getKey(),
            (int) $table->outlet_id,
            self::mode(),
            (string) $table->getAttribute('qr_nonce'),
        ]));

        return $payload.self::SEP.self::b64(hash_hmac('sha256', $payload, self::key(), true));
    }

    /**
     * @throws InvalidSelfServiceQr `qr_invalid` (format/tanda tangan/nonce/id) atau `table_unavailable` (meja/outlet nonaktif, mode berbeda)
     */
    public static function verify(string $token): DiningTable
    {
        $parts = explode(self::SEP, $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidSelfServiceQr;
        }

        [$payload, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', $payload, self::key(), true));
        if (! hash_equals($expected, $signature)) {
            throw new InvalidSelfServiceQr;
        }

        $fields = explode(':', (string) self::unb64($payload));
        if (count($fields) !== 4 || ! ctype_digit($fields[0]) || ! ctype_digit($fields[1])) {
            throw new InvalidSelfServiceQr;
        }
        [$tableId, $outletId, $mode, $nonce] = [(int) $fields[0], (int) $fields[1], $fields[2], $fields[3]];

        // Dari sini tanda tangan SAH: token ini memang diterbitkan sistem ini. Sebab penolakan berikutnya boleh
        // dibedakan ke pemegangnya (QR lama dari meja yang kini dinonaktifkan/dihapus, atau dari mode lain).
        if ($mode !== self::mode()) {
            throw new InvalidSelfServiceQr(InvalidSelfServiceQr::TABLE_UNAVAILABLE);
        }

        // Query ber-scope mode data; tanpa pengguna OutletScope tidak menyaring, jadi outlet dicek eksplisit di bawah.
        $table = DiningTable::query()->withoutGlobalScope(\App\Models\Concerns\OutletScope::class)->find($tableId);
        if ($table === null) {
            throw new InvalidSelfServiceQr(InvalidSelfServiceQr::TABLE_UNAVAILABLE);
        }

        // Id outlet di token tidak cocok dengan milik meja, atau nonce sudah diputar = QR dicabut/dipalsukan.
        if ((int) $table->outlet_id !== $outletId || ! hash_equals((string) $table->getAttribute('qr_nonce'), $nonce)) {
            throw new InvalidSelfServiceQr;
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $table->is_active || $outlet === null || ! $outlet->is_active) {
            throw new InvalidSelfServiceQr(InvalidSelfServiceQr::TABLE_UNAVAILABLE);
        }

        return $table;
    }

    /** Mencabut QR lama: nonce baru, token lama otomatis tidak sah. Mengembalikan token baru. */
    public static function rotate(DiningTable $table): string
    {
        $table->forceFill(['qr_nonce' => Str::random(16)])->save();

        return self::issue($table->refresh());
    }

    /** Mode data sistem saat ini ('demo' | 'live') — `ModeGate`, sumber yang sama dengan DataModeScope. */
    private static function mode(): string
    {
        return (string) ModeGate::current();
    }

    private static function key(): string
    {
        return (string) config('app.key');
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
