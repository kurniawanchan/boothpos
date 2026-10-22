<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 048 / S3 — SATU kanonikalisasi subjek persetujuan, dihitung di PHP. Subjek = identitas objek (id path:
 * tab, baris, sesi, produk, pembayaran) + bidang body aksi yang dijaga. Penerbitan token dan aksi memakai
 * fungsi ini dengan bidang yang sama; hash yang tidak cocok → 422 `override_mismatch`.
 *
 * Mengapa id path ikut: tanpa itu, persetujuan membatalkan baris A bisa dipakai membatalkan baris B yang
 * alasannya sama (celah yang ditemukan di tinjauan S3). Token karena itu terikat ke objek yang disetujui,
 * aksi, bidang body, outlet, peminta, 120 dtk, dan sekali pakai. Daftar bidang per aksi ada di FIELDS; kunci
 * diurutkan, jadi urutan bidang tidak mengubah hash; id dinormalisasi ke integer.
 */
final class OverrideSubject
{
    /** @var array<string, list<string>> */
    public const FIELDS = [
        'void_line' => ['tab_id', 'line_id', 'reason_code', 'kitchen_status', 'stock_decision', 'reason'],
        'cancel_order' => ['tab_id', 'reason_code', 'reason'],
        'cash_out' => ['session_id', 'direction', 'amount', 'reason_code'],
        'variance' => ['session_id', 'counted_by_denomination', 'counted_total'],
        'availability' => ['product_id', 'reason_code_id', 'note', 'outlet_ids'],
        'qris_verify' => ['tab_id', 'payment_id', 'reference'],
        'qris_fail' => ['tab_id', 'payment_id'],
        // 048 / S4 — diskon: id tab + cakupan + id baris (bila baris) + persen + alasan.
        'discount' => ['tab_id', 'scope', 'line_id', 'percent', 'reason_code'],
        // Cetak ulang: id order + nomor salinan yang akan dicetak (mengikat token ke salinan itu, bukan salinan lain).
        'reprint' => ['order_id', 'copy_no'],
        // Refund: id order + alasan. Token tidak bisa dipakai untuk order lain.
        'refund' => ['order_id', 'reason_code', 'reason'],
    ];

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::FIELDS);
    }

    /** @param  array<string, mixed>  $body  bidang body permintaan aksi (hanya yang tercantum di FIELDS yang dipakai) */
    public static function hash(string $action, array $body): string
    {
        if (! isset(self::FIELDS[$action])) {
            throw new InvalidArgumentException("Aksi persetujuan tidak dikenal: {$action}");
        }

        $canon = ['action' => $action];
        foreach (self::FIELDS[$action] as $field) {
            $canon[$field] = self::normalise($field, $body[$field] ?? null);
        }
        ksort($canon);

        return hash('sha256', json_encode($canon, JSON_THROW_ON_ERROR));
    }

    private static function normalise(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($field === 'amount' || $field === 'percent' || $field === 'counted_total') {
            return number_format((float) $value, 2, '.', '');
        }
        if ($field === 'counted_by_denomination') {
            $rows = [];
            foreach ((array) $value as $row) {
                $rows[] = [(int) ($row['denomination'] ?? 0), (int) ($row['qty'] ?? 0)];
            }
            usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

            return $rows;
        }
        if ($field === 'outlet_ids') {
            $ids = array_map('intval', (array) $value);
            sort($ids);

            return $ids;
        }
        if (str_ends_with($field, '_id')) {
            return (int) $value;
        }

        return (string) $value;
    }
}
