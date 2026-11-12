<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Support\CustomerCommerceGate;
use Illuminate\Http\JsonResponse;

/**
 * Customer commerce (CONTRACT §6) — GET /storefront/config: konfigurasi
 * publik yang dibaca aplikasi saat dibuka.
 *
 * Satu-satunya rute etalase yang TIDAK digerbang saklar fitur: aplikasi
 * perlu jawaban `enabled: false` untuk menampilkan "toko sedang tidak
 * menerima pesanan" (GET /settings/features khusus staf dan tidak bisa
 * dibaca aplikasi pelanggan). Isinya hanya hal yang memang untuk publik.
 */
class ConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => CustomerCommerceGate::enabled(),
            'store_name' => CustomerCommerceGate::storeName(),
            // Satu mata uang; harga di seluruh API adalah string desimal rupiah.
            'currency' => 'IDR',
            'fulfilment_methods' => CustomerOrder::FULFILMENT_METHODS,
            'pickup_instructions' => CustomerCommerceGate::pickupInstructions(),
            'courier_instructions' => CustomerCommerceGate::courierInstructions(),
            // Batas keranjang, supaya aplikasi bisa menolak lebih awal; server tetap penegaknya saat checkout.
            'max_qty_per_line' => (int) config('customer_commerce.max_qty_per_line'),
            'max_lines_per_order' => (int) config('customer_commerce.max_lines_per_order'),
        ]]);
    }
}
