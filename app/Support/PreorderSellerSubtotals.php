<?php

namespace App\Support;

/**
 * 039-preorder-seller-subtotal — subtotal per penjual untuk laporan pre-order
 * "Per Penjual" (layar DAN ekspor Excel).
 *
 * Satu-satunya implementasi penjumlahan ini: API menyertakannya sebagai
 * `subtotals`, ekspor menyisipkan baris yang sama, dan layar hanya
 * MENEMPATKANNYA — jadi layar, file, dan Grand Total tidak bisa berbeda.
 *
 * Aturan:
 *  - Subtotal TURUNAN (tidak pernah disimpan): jumlah dari baris yang
 *    ditampilkan untuk penjual itu.
 *  - Uang dijumlahkan dalam SEN (ReportSplit::cents/money, pembantu fitur 033
 *    untuk kebutuhan "tanpa selisih pembulatan"), bukan float.
 *  - `total_outstanding` adalah JUMLAH nilai outstanding tiap baris (yang sudah
 *    dibatasi >= 0 per baris), BUKAN total_order_value − total_collected:
 *    satu baris "Paid" bisa punya collected > order value (pembayaran lama
 *    sebelum pembayaran dibatasi) dengan outstanding 0, sehingga selisih
 *    subtotal tidak akan cocok dengan penjumlahan baris yang terlihat.
 *  - `preorder_count` dijumlahkan apa adanya: baris satu penjual saling lepas
 *    (satu pre-order punya tepat satu status dan satu kelengkapan bayar); pre-order
 *    berisi item dua penjual dihitung sekali per penjual (perilaku yang sudah ada).
 *  - Pengelompokan menurut artist_id dengan urutan KEMUNCULAN pertama, jadi baris
 *    yang tidak berurutan tetap benar dan dua penjual bernama sama tidak tertukar.
 */
final class PreorderSellerSubtotals
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  baris hasil preordersByArtist()
     * @return array<int, array{artist_id: int, artist_name: string, preorder_count: int, total_order_value: string, total_collected: string, total_outstanding: string}>
     */
    public static function fromRows(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $id = (int) $row['artist_id'];

            $groups[$id] ??= [
                'artist_id' => $id,
                'artist_name' => (string) $row['artist_name'],
                'preorder_count' => 0,
                'order_value' => 0,
                'collected' => 0,
                'outstanding' => 0,
            ];

            $groups[$id]['preorder_count'] += (int) $row['preorder_count'];
            $groups[$id]['order_value'] += ReportSplit::cents($row['total_order_value']);
            $groups[$id]['collected'] += ReportSplit::cents($row['total_collected']);
            $groups[$id]['outstanding'] += ReportSplit::cents($row['total_outstanding']);
        }

        return array_values(array_map(fn (array $g) => [
            'artist_id' => $g['artist_id'],
            'artist_name' => $g['artist_name'],
            'preorder_count' => $g['preorder_count'],
            'total_order_value' => ReportSplit::money($g['order_value']),
            'total_collected' => ReportSplit::money($g['collected']),
            'total_outstanding' => ReportSplit::money($g['outstanding']),
        ], $groups));
    }
}
