<?php

namespace App\Services\CustomerCommerce;

use App\Exceptions\CustomerCommerceException;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Customer commerce (CONTRACT §8) — baca pesanan milik SATU akun pelanggan
 * (daftar, detail, pelacakan). Hanya membaca; tidak ada jalur tulis di sini.
 *
 * Setiap pencarian lewat `$account->orders()`: relasi membatasi pemilik dan
 * DataModeScope membatasi mode DEMO/LIVE. Nomor pesanan memang acak, tetapi
 * kerahasiaan nomor BUKAN batas aksesnya — nomor milik akun lain dijawab 404
 * yang sama dengan nomor yang tidak pernah ada.
 */
class CustomerOrderQueryService
{
    /**
     * Terbaru lebih dulu. Jumlah baris dan nama barang pertama diambil
     * sebagai subquery, jadi satu halaman tidak menjalankan query per pesanan.
     *
     * @param  array{page?: int|string|null, per_page?: int|string|null}  $filters  sudah divalidasi
     */
    public function paginateFor(CustomerAccount $account, array $filters): LengthAwarePaginator
    {
        $perPage = min(
            max((int) ($filters['per_page'] ?? StorefrontCatalogService::DEFAULT_PER_PAGE), 1),
            StorefrontCatalogService::MAX_PER_PAGE,
        );
        $page = max((int) ($filters['page'] ?? 1), 1);

        return $account->orders()
            ->withCount('items')
            ->addSelect(['first_item_name' => CustomerOrderItem::query()
                ->select('name_snapshot')
                ->whereColumn('customer_order_items.customer_order_id', 'customer_orders.id')
                ->orderBy('id')
                ->limit(1),
            ])
            ->orderByDesc('customer_orders.created_at')
            ->orderByDesc('customer_orders.id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Pesanan milik akun beserta item, pengiriman, dan riwayat (terbaru dulu).
     *
     * @throws CustomerCommerceException 404 `not_found` bila nomor tidak ada ATAU milik akun lain
     */
    public function findFor(CustomerAccount $account, string $orderNumber): CustomerOrder
    {
        return $account->orders()
            ->where('order_number', $orderNumber)
            ->with(['items', 'shipment', 'statusEvents'])
            ->first()
            ?? throw CustomerCommerceException::make('not_found', 404);
    }
}
