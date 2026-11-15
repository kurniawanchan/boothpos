<?php

namespace App\Support;

use App\Models\Preorder;

/**
 * 069-courier-shipping-label-a4 — data label kurir untuk sekumpulan pre-order.
 *
 * Satu-satunya sumber data label, dipakai unduhan tunggal (drawer detail) DAN
 * massal (pilihan daftar). Drawer mengedit objek `shipment` DI TEMPAT, jadi
 * hanya server yang memegang versi TERSIMPAN — label selalu dibangun dari sini
 * (research D1).
 *
 * Kelayakan (research D2), dinilai berurutan per id yang diminta:
 *  1. tak terlihat lewat model ber-scope (tidak ada / mode DEMO-LIVE lain /
 *     seller lain) -> `not_found`, nomor TIDAK dikirim (ketiganya harus tampak sama);
 *  2. bukan Mail Order (`fulfillment` != courier, termasuk pickup yang masih
 *     menyimpan baris shipment lama) -> `not_mail_order`;
 *  3. belum punya shipment -> `no_shipment`;
 *  4. selain itu -> label.
 * Status pre-order bukan saringan. Label diurutkan menurut nomor pre-order
 * (research D4: pilihan bisa mencakup halaman lain yang urutannya tak diketahui
 * browser); daftar yang dilewati mengikuti urutan permintaan.
 * Invarian: jumlah label + jumlah dilewati = jumlah id yang diminta.
 */
class PreorderShippingLabels
{
    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_NOT_MAIL_ORDER = 'not_mail_order';

    public const REASON_NO_SHIPMENT = 'no_shipment';

    /**
     * @param  int[]  $ids
     * @return array{data: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    public static function build(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // Satu query lewat model ber-scope (mode + seller) dan shipment di-eager-load.
        $preorders = Preorder::query()
            ->whereIn('id', $ids)
            ->with('shipment')
            ->get(['id', 'preorder_number', 'fulfillment'])
            ->keyBy('id');

        $labels = [];
        $skipped = [];

        foreach ($ids as $id) {
            $preorder = $preorders->get($id);

            if ($preorder === null) {
                $skipped[] = ['preorder_id' => $id, 'preorder_number' => null, 'reason' => self::REASON_NOT_FOUND];

                continue;
            }

            if ($preorder->fulfillment !== 'courier') {
                $skipped[] = self::skip($preorder, self::REASON_NOT_MAIL_ORDER);

                continue;
            }

            $shipment = $preorder->shipment;
            if ($shipment === null) {
                $skipped[] = self::skip($preorder, self::REASON_NO_SHIPMENT);

                continue;
            }

            $labels[] = [
                'preorder_id' => $preorder->id,
                'preorder_number' => $preorder->preorder_number,
                'courier_name' => $shipment->courier_name,
                'tracking_number' => $shipment->tracking_number,
                'recipient_name' => $shipment->recipient_name,
                'recipient_phone' => $shipment->recipient_phone,
                'address_line' => $shipment->address_line,
            ];
        }

        usort($labels, fn (array $a, array $b) => [$a['preorder_number'], $a['preorder_id']] <=> [$b['preorder_number'], $b['preorder_id']]);

        return ['data' => $labels, 'skipped' => $skipped];
    }

    private static function skip(Preorder $preorder, string $reason): array
    {
        return ['preorder_id' => $preorder->id, 'preorder_number' => $preorder->preorder_number, 'reason' => $reason];
    }
}
