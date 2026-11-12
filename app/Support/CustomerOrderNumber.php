<?php

namespace App\Support;

use App\Models\CustomerOrder;

/**
 * Customer commerce — nomor pesanan pelanggan: "CO-" + 10 karakter Crockford
 * base32 acak (tanpa I, L, O, U supaya tidak tertukar saat dibacakan).
 *
 * Kenapa bukan pola hitung-per-hari seperti TRX-/PO- (OrderService,
 * PreorderService): nomor ini adalah parameter rute publik
 * (`/customer/orders/{orderNumber}`), jadi tidak boleh bisa ditebak atau
 * diurutkan, dan pola hitungan tanpa kunci bisa menghasilkan nomor kembar
 * saat dua checkout berjalan bersamaan.
 *
 * Kolomnya UNIQUE untuk SELURUH tabel (bukan per mode DEMO/LIVE), jadi
 * pemeriksaan memakai withoutGlobalScopes(); indeks unik tetap menjadi
 * pengaman terakhir bila dua transaksi memilih nomor yang sama.
 */
final class CustomerOrderNumber
{
    public const PREFIX = 'CO-';

    public const LENGTH = 10;

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Untuk `->where('orderNumber', …)` pada rute pelanggan (tanpa jangkar). */
    public const ROUTE_PATTERN = 'CO-[0-9A-HJKMNP-TV-Z]{10}';

    private const MAX_ATTEMPTS = 10;

    /**
     * @param  (callable(): string)|null  $candidate  sumber kandidat; hanya diisi test
     */
    public static function generate(?callable $candidate = null): string
    {
        $candidate ??= self::random(...);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $number = $candidate();

            if (! CustomerOrder::withoutGlobalScopes()->where('order_number', $number)->exists()) {
                return $number;
            }
        }

        // 32^10 kemungkinan: sampai di sini berarti sumber acaknya rusak, bukan kebetulan.
        throw new \RuntimeException('Gagal membuat nomor pesanan pelanggan yang unik.');
    }

    private static function random(): string
    {
        $out = self::PREFIX;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }
}
