<?php

namespace App\Http\Requests\CustomerOrder;

use App\Models\CustomerOrder;
use App\Services\CustomerCommerce\CustomerOrderTransitionService;

/**
 * Customer commerce (CONTRACT §9) — POST /customer-orders/{id}/confirm.
 *
 * `shipping_cost` WAJIB untuk pesanan kurir yang sedang menunggu konfirmasi
 * (nol eksplisit boleh = gratis ongkir). Tanpa itu, konfirmasi `{}` diam-diam
 * menjadikan ongkir 0 dan tidak ada jalur untuk membetulkannya sesudahnya
 * (pesanan sudah `confirmed`, ongkirnya sudah jadi snapshot pengiriman).
 * Untuk pesanan ambil di tempat nilainya tetap DILARANG; bahwa ia hanya
 * berlaku untuk kurir diputuskan service terhadap baris pesanan yang
 * TERKUNCI (422 `errors.shipping_cost`), bukan terhadap salinan yang dibaca
 * sebelum transaksi.
 *
 * Kewajibannya hanya berlaku pada status `awaiting_confirmation`: pesanan
 * kurir yang sudah lewat tahap itu tetap dijawab 409 `invalid_transition`
 * oleh service, bukan 422 yang menyesatkan karena ongkirnya tidak diisi.
 * Pesanan yang tidak ditemukan (route-model binding bermode data/seller
 * meleset) tidak membawa model: aturan wajib dilewati dan controller 404.
 */
class ConfirmCustomerOrderRequest extends CustomerOrderFulfilmentRequest
{
    public function rules(): array
    {
        return [
            // Batas atas = lebar kolom DECIMAL(14,2); dua desimal supaya tidak ada pembulatan diam-diam.
            'shipping_cost' => [$this->requiresShippingCost() ? 'required' : 'nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'note' => $this->noteRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'shipping_cost.required' => __('customer_commerce.fulfilment.shipping_cost_required'),
        ];
    }

    private function requiresShippingCost(): bool
    {
        $order = $this->route('customerOrder');

        return $order instanceof CustomerOrder
            && $order->isCourier()
            && $order->status === CustomerOrderTransitionService::INITIAL_STATUS;
    }
}
