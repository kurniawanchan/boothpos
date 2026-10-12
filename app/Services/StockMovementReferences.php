<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * 036-bom-variant-stock-ux — mengubah (reference_type, reference_id) sebuah
 * pergerakan stok menjadi nomor transaksi yang bisa dibaca orang, untuk layar
 * Stok dan riwayat transaksi per varian.
 *
 * KENAPA kuncinya (type pergerakan, reference_type), bukan reference_type saja:
 * `reference_id` TIDAK berarti hal yang sama di tiap penulis —
 *   - sale   + order_item     : id ORDER           (OrderService::create)
 *   - return + order_item     : id ITEM order      (OrderService::void)
 *   - stock_transfer          : id TRANSFER        (045)
 * 046: pergerakan lama yang menunjuk pre-order (`purchase`/`preorder_handover` dengan reference_type
 * `preorder_item`/`preorder`) TETAP ada di buku besar sebagai riwayat, tetapi modul pre-order sudah dihapus —
 * referensinya tidak lagi diselesaikan (hasilnya null: tanpa tautan, nomornya tidak ditampilkan).
 *
 * PENJAGA: setiap penyelesaian wajib membuktikan bahwa order itu
 * memuat varian pergerakannya; bila tidak, hasilnya null. Menampilkan "tidak
 * ada referensi" jauh lebih baik daripada nomor yang salah.
 *
 * Query dikelompokkan per jenis (bukan per baris) supaya satu halaman riwayat
 * tidak menjadi N+1 (Constitution V); scope DEMO/LIVE model dipertahankan.
 */
class StockMovementReferences
{
    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array<int, array{type: string, id: int, number: string}|null> dikunci id pergerakan
     */
    public function resolve(Collection $movements): array
    {
        $result = array_fill_keys($movements->pluck('id')->all(), null);

        $this->resolveOrders($movements, $result);
        $this->resolveTransfers($movements, $result);

        return $result;
    }

    /** 045 Stage 6 — pergerakan transfer_* menunjuk nomor transfer (reference_id = id transfer). */
    private function resolveTransfers(Collection $movements, array &$result): void
    {
        $moved = $movements->filter(fn ($m) => $m->reference_type === 'stock_transfer' && $m->reference_id);
        if ($moved->isEmpty()) {
            return;
        }
        $numbers = \App\Models\StockTransfer::withoutGlobalScopes()->whereIn('id', $moved->pluck('reference_id')->unique())->pluck('number', 'id');
        foreach ($moved as $m) {
            if (isset($numbers[$m->reference_id])) {
                $result[$m->id] = ['type' => 'stock_transfer', 'id' => (int) $m->reference_id, 'number' => $numbers[$m->reference_id]];
            }
        }
    }

    private function resolveOrders(Collection $movements, array &$result): void
    {
        $sales = $movements->filter(fn ($m) => $m->type === 'sale' && $m->reference_type === 'order_item' && $m->reference_id);
        $returns = $movements->filter(fn ($m) => $m->type === 'return' && $m->reference_type === 'order_item' && $m->reference_id);

        if ($sales->isNotEmpty()) {
            // reference_id = id order; sah bila order itu memuat item varian ini.
            $rows = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.order_id', $sales->pluck('reference_id')->unique())
                ->whereIn('order_items.variant_id', $sales->pluck('variant_id')->unique())
                ->get(['order_items.order_id', 'order_items.variant_id', 'orders.order_number']);

            foreach ($sales as $m) {
                $hit = $rows->first(fn ($r) => (int) $r->order_id === (int) $m->reference_id && (int) $r->variant_id === (int) $m->variant_id);
                $result[$m->id] = $hit ? ['type' => 'order', 'id' => (int) $hit->order_id, 'number' => $hit->order_number] : null;
            }
        }

        if ($returns->isNotEmpty()) {
            // reference_id = id item order; sah bila item itu milik varian ini.
            $rows = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.id', $returns->pluck('reference_id')->unique())
                ->get(['order_items.id as item_id', 'order_items.variant_id', 'order_items.order_id', 'orders.order_number'])
                ->keyBy('item_id');

            foreach ($returns as $m) {
                $hit = $rows->get($m->reference_id);
                $result[$m->id] = $hit && (int) $hit->variant_id === (int) $m->variant_id
                    ? ['type' => 'order', 'id' => (int) $hit->order_id, 'number' => $hit->order_number]
                    : null;
            }
        }
    }
}
