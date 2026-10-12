<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * 028-partial-split-payment (research Decision 2) — SATU-SATUNYA rumus ringkasan
 * pembayaran penjualan POS (046: pre-order sudah dihapus). Murni (tanpa I/O selain
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
    public static function for(Order $target, ?Collection $payments = null): array
    {
        $counted = ($payments ?? $target->payments)->where('verification', '!=', 'rejected');

        $grandTotal = round((float) $target->total_amount, 2);
        $change = (float) $target->change_amount;
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

    /**
     * 042-dashboard-revamp — versi SET-BASED dari rumus yang sama (untuk menghitung tab dan status pembayaran
     * ratusan order dalam satu query). Rumus TETAP satu: potongan SQL di bawah hanya menerjemahkan `for()`
     * dan dijaga oleh PaymentSummarySqlParityTest (setiap kombinasi harus memberi status yang sama).
     *
     * Terbayar = SUM(entri bukan `rejected`) − kembalian; sisa = ROUND(GREATEST(0, total − terbayar), 2).
     */
    public static function orderPaidSql(string $orders = 'orders'): string
    {
        return "(COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = {$orders}.id AND p.verification <> 'rejected'), 0) - {$orders}.change_amount)";
    }

    public static function orderRemainingSql(string $orders = 'orders'): string
    {
        return 'ROUND(GREATEST(0, '.$orders.'.total_amount - '.self::orderPaidSql($orders).'), 2)';
    }

    public static function orderPaymentCountSql(string $orders = 'orders'): string
    {
        return "(SELECT COUNT(*) FROM payments p WHERE p.order_id = {$orders}.id AND p.verification <> 'rejected')";
    }

    /** Ada entri non-tunai yang masih menunggu verifikasi (tidak ada alur "ditolak" yang tersisa di sini). */
    public static function orderHasPendingNonCashSql(string $orders = 'orders'): string
    {
        return "EXISTS (SELECT 1 FROM payments p WHERE p.order_id = {$orders}.id AND p.verification = 'pending' AND p.method <> 'cash')";
    }

    /** Status ringkasan (unpaid|partially_paid|fully_paid) dalam SQL — identik dengan `for()['status']`. */
    public static function orderStatusSql(string $orders = 'orders'): string
    {
        $remaining = self::orderRemainingSql($orders);
        $count = self::orderPaymentCountSql($orders);

        return "CASE WHEN {$remaining} > 0 AND {$count} = 0 THEN 'unpaid' WHEN {$remaining} > 0 THEN 'partially_paid' ELSE 'fully_paid' END";
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
