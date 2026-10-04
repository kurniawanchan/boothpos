<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Preorder;
use Illuminate\Support\Collection;

/**
 * 028-partial-split-payment (research Decision 2) — SATU-SATUNYA rumus ringkasan
 * pembayaran, untuk pre-order dan penjualan POS. Murni (tanpa I/O selain
 * membaca relasi payments bila koleksinya tak diberikan).
 *
 * Total terbayar SELALU dihitung dari entri pembayaran, tidak dipercaya dari
 * kolom `paid_amount` (cache yang pernah menyimpang — lihat komentar 010):
 *  - entri `rejected` tidak dihitung (selaras SalesTransactionsService);
 *  - entri non-tunai berstatus `pending` TETAP dihitung, karena tak ada alur
 *    yang pernah memverifikasinya;
 *  - untuk ORDER, `payments.amount` menyimpan uang yang DISERAHKAN pembeli,
 *    sedangkan kembalian disimpan terpisah di `orders.change_amount` — jadi
 *    total terbayar = jumlah entri − kembalian.
 */
class PaymentSummary
{
    public const UNPAID = 'unpaid';

    public const PARTIALLY_PAID = 'partially_paid';

    public const FULLY_PAID = 'fully_paid';

    /**
     * @return array{grand_total: string, total_paid: string, remaining: string, status: string, payment_count: int}
     */
    public static function for(Preorder|Order $target, ?Collection $payments = null): array
    {
        $counted = ($payments ?? $target->payments)->where('verification', '!=', 'rejected');

        $grandTotal = round((float) $target->total_amount, 2);
        $change = $target instanceof Order ? (float) $target->change_amount : 0.0;
        $totalPaid = round((float) $counted->sum('amount') - $change, 2);
        $remaining = round(max(0.0, $grandTotal - $totalPaid), 2);
        $count = $counted->count();

        $status = match (true) {
            $remaining > 0 && $count === 0 => self::UNPAID,
            $remaining > 0 => self::PARTIALLY_PAID,
            default => self::FULLY_PAID,
        };

        return [
            'grand_total' => self::money($grandTotal),
            'total_paid' => self::money(max(0.0, $totalPaid)),
            'remaining' => self::money($remaining),
            'status' => $status,
            'payment_count' => $count,
        ];
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
