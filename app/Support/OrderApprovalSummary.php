<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\Order;
use App\Models\OrderApproval;

/**
 * 059 (research D2/D3) — SATU-SATUNYA rumus persetujuan seller: syarat (seller unik pada baris penjualan),
 * status per seller, status keseluruhan, dan hash isi baris. Murni: tidak menulis apa pun (GET tidak boleh
 * menyetujui atau membatalkan), sama seperti PaymentSummary.
 *
 * - Satu seller unik = SATU syarat, berapa pun jumlah barisnya. Baris tanpa seller tak membentuk syarat.
 * - Status keseluruhan tidak disimpan di mana pun, jadi tak bisa menyimpang dari catatan per seller
 *   dan penjualan lama (tanpa baris) otomatis `pending`.
 * - Persetujuan hanya BERLAKU selama `items_hash`-nya sama dengan hash baris seller itu SEKARANG. Itulah
 *   mekanisme pembatalan: perubahan baris seller X mengubah hash X saja (seller lain tak terusik), dan jalur
 *   perubahan baris apa pun di masa depan otomatis tercakup tanpa perlu pengait.
 *
 * Hash `v1` SENGAJA tanpa id apa pun (order_items.id, variant_id, artist_id): clone LIVE→DEMO memetakan
 * ulang id, jadi hash berbasis id akan membuat semua persetujuan hasil clone tak valid. Mengubah bentuk
 * kanonik berarti membatalkan SEMUA persetujuan tersimpan — pakai awalan versi baru, jangan ubah v1.
 */
class OrderApprovalSummary
{
    public const HASH_VERSION = 'v1';

    public const PENDING = 'pending';

    public const PARTIAL = 'partial';

    public const FULL = 'full';

    public const VOIDED = 'voided';

    /**
     * @param  array<int,string>|null  $artistNames  id seller => nama; daftar penjualan mengirim satu peta untuk seluruh
     *                                               halaman supaya tak ada kueri per baris. Nama dibaca TANPA scope seller:
     *                                               pengguna ber-seller tetap harus melihat nama seller lain (058 Q1:B).
     * @return array{status: string, total: int, approved: int, pending: int, sellers: array<int, array<string, mixed>>}
     */
    public static function for(Order $order, ?array $artistNames = null): array
    {
        $order->loadMissing(['items', 'approvals']);

        $groups = $order->items->whereNotNull('artist_id')->groupBy('artist_id');
        $names = $artistNames ?? self::namesFor($groups->keys()->map(fn ($id) => (int) $id)->all());
        $active = $order->approvals->whereNull('invalidated_at')->keyBy('artist_id');

        $sellers = [];
        foreach ($groups as $artistId => $items) {
            $artistId = (int) $artistId;
            $lines = $items->map(fn ($i) => self::line($i))->values()->all();
            $hash = self::hashLines($lines);
            /** @var OrderApproval|null $stored */
            $stored = $active->get($artistId);
            $valid = $stored !== null && hash_equals($stored->items_hash, $hash);

            $sellers[$artistId] = [
                'artist_id' => $artistId,
                'artist_name' => $names[$artistId] ?? $stored?->artist_name ?? '',
                'items' => $lines,
                'subtotal' => number_format((float) $items->sum('line_total'), 2, '.', ''),
                'items_hash' => $hash,
                'state' => $valid ? 'approved' : 'pending',
                'stale' => $stored !== null && ! $valid,
                'approval' => $valid ? $stored : null,
            ];
        }
        uasort($sellers, fn ($a, $b) => [$a['artist_name'], $a['artist_id']] <=> [$b['artist_name'], $b['artist_id']]);

        $total = count($sellers);
        $approved = count(array_filter($sellers, fn ($s) => $s['state'] === 'approved'));

        return [
            'status' => self::overall($order->status, $total, $approved),
            'total' => $total,
            'approved' => $approved,
            'pending' => $total - $approved,
            'sellers' => $sellers,
        ];
    }

    /**
     * Bentuk RINGKAS untuk daftar Sales: hanya yang dibutuhkan ikon, popover, dan filter. Tidak membawa hash,
     * id persetujuan, maupun jejak gambar (daftar tak pernah memuat gambar; pemirsa yang tak berhak pun tak
     * mendapat petunjuk apa pun selain fakta status/penyetuju/metode/waktu yang memang terlihat bersama penjualan).
     *
     * @param  array{status: string, total: int, approved: int, pending: int, sellers: array<int, array<string, mixed>>}  $summary
     * @return array<string, mixed>
     */
    public static function listShape(array $summary): array
    {
        return [
            'status' => $summary['status'],
            'total' => $summary['total'],
            'approved' => $summary['approved'],
            'pending' => $summary['pending'],
            'sellers' => array_values(array_map(function (array $s) {
                /** @var OrderApproval|null $a */
                $a = $s['approval'];

                return [
                    'artist_id' => $s['artist_id'],
                    'name' => $s['artist_name'],
                    'status' => $s['state'],
                    'stale' => $s['stale'],
                    'approver_name' => $a?->approver_name,
                    'method' => $a?->method,
                    'approved_at' => $a?->approved_at?->toIso8601String(),
                    'on_behalf' => $a?->on_behalf,
                ];
            }, $summary['sellers'])),
        ];
    }

    public static function overall(?string $orderStatus, int $total, int $approved): string
    {
        return match (true) {
            $orderStatus === 'voided' => self::VOIDED,
            $total > 0 && $approved === $total => self::FULL,
            $approved === 0 => self::PENDING,
            default => self::PARTIAL,
        };
    }

    /**
     * Hash kanonik baris seorang seller: tiap baris jadi tuple string tetap, diurutkan (urutan input tak
     * berpengaruh), lalu SHA-256 dengan awalan versi.
     *
     * @param  array<int, array{sku: ?string, name: ?string, qty: int|string, sell_price: mixed, discount_amount: mixed, line_total: mixed}>  $lines
     */
    public static function hashLines(array $lines): string
    {
        $tuples = array_map(fn ($l) => [
            (string) ($l['sku'] ?? ''), (string) ($l['name'] ?? ''), (string) (int) $l['qty'],
            self::money($l['sell_price']), self::money($l['discount_amount']), self::money($l['line_total']),
        ], $lines);
        usort($tuples, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

        return self::HASH_VERSION.':'.hash('sha256', json_encode($tuples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Peta id => nama seller, tanpa scope (juga seller yang sudah dihapus lunak). */
    public static function namesFor(array $artistIds): array
    {
        if ($artistIds === []) {
            return [];
        }

        return Artist::withoutGlobalScopes()->withTrashed()->whereIn('id', $artistIds)->pluck('name', 'id')->all();
    }

    private static function line($item): array
    {
        return [
            'sku' => $item->sku_snapshot,
            'name' => $item->name_snapshot,
            'qty' => (int) $item->qty,
            'sell_price' => self::money($item->sell_price),
            'discount_amount' => self::money($item->discount_amount),
            'line_total' => self::money($item->line_total),
        ];
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
