<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\PreorderItem;
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
 *   - purchase/preorder_handover + preorder_item : id ITEM pre-order (tiba / serah terima)
 *   - purchase + preorder     : id PRE-ORDER       (selisih edit pre-order, sejak 036)
 * Baris lama dari jalur edit pre-order tersimpan sebagai `preorder_item`
 * berisi id pre-order; ia diselesaikan sebagai item dan hanya lolos bila item
 * itu memang milik varian yang sama (risiko sisa: id pre-order kebetulan sama
 * dengan id item varian yang sama — langka, dicatat di research.md).
 *
 * PENJAGA: setiap penyelesaian wajib membuktikan bahwa order/pre-order itu
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
        $this->resolvePreorders($movements, $result);

        return $result;
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

    private function resolvePreorders(Collection $movements, array &$result): void
    {
        $viaItem = $movements->filter(fn ($m) => in_array($m->type, ['purchase', 'preorder_handover'], true) && $m->reference_type === 'preorder_item' && $m->reference_id);
        $viaPreorder = $movements->filter(fn ($m) => $m->type === 'purchase' && $m->reference_type === 'preorder' && $m->reference_id);

        if ($viaItem->isNotEmpty()) {
            $rows = PreorderItem::query()
                ->join('preorders', 'preorders.id', '=', 'preorder_items.preorder_id')
                ->whereIn('preorder_items.id', $viaItem->pluck('reference_id')->unique())
                ->get(['preorder_items.id as item_id', 'preorder_items.variant_id', 'preorder_items.preorder_id', 'preorders.preorder_number'])
                ->keyBy('item_id');

            foreach ($viaItem as $m) {
                $hit = $rows->get($m->reference_id);
                $result[$m->id] = $hit && (int) $hit->variant_id === (int) $m->variant_id
                    ? ['type' => 'preorder', 'id' => (int) $hit->preorder_id, 'number' => $hit->preorder_number]
                    : null;
            }
        }

        if ($viaPreorder->isNotEmpty()) {
            $rows = PreorderItem::query()
                ->join('preorders', 'preorders.id', '=', 'preorder_items.preorder_id')
                ->whereIn('preorder_items.preorder_id', $viaPreorder->pluck('reference_id')->unique())
                ->whereIn('preorder_items.variant_id', $viaPreorder->pluck('variant_id')->unique())
                ->get(['preorder_items.preorder_id', 'preorder_items.variant_id', 'preorders.preorder_number']);

            foreach ($viaPreorder as $m) {
                $hit = $rows->first(fn ($r) => (int) $r->preorder_id === (int) $m->reference_id && (int) $r->variant_id === (int) $m->variant_id);
                $result[$m->id] = $hit ? ['type' => 'preorder', 'id' => (int) $hit->preorder_id, 'number' => $hit->preorder_number] : null;
            }
        }
    }
}
