<?php

namespace App\Services\CustomerCommerce;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer commerce (CONTRACT §9) — baca pesanan pelanggan untuk layar STAF
 * (daftar + detail). Hanya membaca; penulisnya CustomerOrderTransitionService.
 *
 * Berbeda dari CustomerOrderQueryService (sisi pelanggan) yang SELALU lewat
 * pemilik pesanan: staf melihat seluruh pesanan mode data aktif
 * (DataModeScope; pesanan DEMO tidak pernah tampil di LIVE dan sebaliknya).
 */
class StaffCustomerOrderQuery
{
    public const DEFAULT_PER_PAGE = 25;

    /**
     * Terbaru lebih dulu. `q` mencocokkan nomor pesanan atau nama pemesan
     * (LIKE dengan `%`, `_`, `\` di-escape). Jumlah baris dan nama barang
     * pertama diambil sebagai subquery — tidak ada query per pesanan.
     *
     * @param  array{status?: ?string, fulfilment_method?: ?string, q?: ?string, page?: int|string|null, per_page?: int|string|null}  $filters  sudah divalidasi
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $like = '%'.addcslashes($term, '%_\\').'%';

        return CustomerOrder::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['fulfilment_method'] ?? null, fn (Builder $q, string $method) => $q->where('fulfilment_method', $method))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('order_number', 'like', $like)
                ->orWhere('contact_name', 'like', $like)))
            ->withCount('items')
            ->addSelect(['first_item_name' => CustomerOrderItem::query()
                ->select('name_snapshot')
                ->whereColumn('customer_order_items.customer_order_id', 'customer_orders.id')
                ->orderBy('id')
                ->limit(1),
            ])
            ->orderByDesc('customer_orders.created_at')
            ->orderByDesc('customer_orders.id')
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), ['*'], 'page', max((int) ($filters['page'] ?? 1), 1));
    }

    /**
     * Pesanan TERBARU dari basis data beserta semua relasi yang dibaca
     * StaffCustomerOrderDetailResource. Varian dimuat lewat scope biasanya,
     * jadi varian yang sudah tidak terjangkau tampil sebagai "tidak tersedia".
     */
    public function loadDetail(CustomerOrder $order): CustomerOrder
    {
        return CustomerOrder::query()
            ->with(['items.variant', 'shipment', 'statusEvents.actor:id,name'])
            ->findOrFail($order->getKey());
    }
}
