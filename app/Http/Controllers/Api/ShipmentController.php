<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Preorder;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function store(Request $request, Preorder $preorder): JsonResponse
    {
        // CATATAN OTORISASI (dipertimbangkan, sengaja TIDAK ditambah) —
        // PRD 7.11 punya ASSUMPTION "ongkos kirim diinput admin", yang
        // sekilas menyarankan endpoint ini digerbang owner/admin/inventory
        // seperti StockAdjustmentRequest. Tapi
        // tests/Feature/PreorderTest::test_shipment_can_only_be_created_for_courier_fulfillment
        // sudah menjalankan endpoint ini SEBAGAI CASHIER dan mengharapkan
        // 409 (bukan 403) untuk kasus fulfillment salah — bukti konkret
        // bahwa akses kasir ke endpoint ini disengaja, konsisten dengan
        // seluruh endpoint preorder lain (store/updateStatus/storePayment)
        // yang juga terbuka untuk semua peran terautentikasi. Menambah
        // gerbang peran di sini akan mematahkan test yang sudah ada dan
        // membalik keputusan desain yang sudah dibuat, bukan memperbaiki
        // bug. Dicatat di sini, bukan diam-diam diubah.
        if ($preorder->fulfillment !== 'courier') {
            return response()->json(['message' => __('preorders.not_courier_fulfillment')], 409);
        }

        if ($preorder->shipment) {
            return response()->json(['message' => __('preorders.shipment_already_exists')], 409);
        }

        $validated = $request->validate([
            'courier_name' => ['required', 'string', 'max:50'],
            'tracking_number' => ['nullable', 'string', 'max:50'],
            'shipping_cost' => ['sometimes', 'numeric', 'min:0'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'max:30'],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
        ]);

        $shipment = $preorder->shipment()->create($validated);

        return response()->json($shipment, 201);
    }

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        // Sama seperti store() di atas — tidak digerbang, sengaja, agar
        // konsisten dengan seluruh alur preorder yang terbuka untuk semua
        // peran terautentikasi.
        $validated = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'in:pending,packed,shipped,delivered'],
            'notes' => ['nullable', 'string'],
        ]);

        if (isset($validated['status'])) {
            $timestampField = match ($validated['status']) {
                'shipped' => 'shipped_at',
                'delivered' => 'delivered_at',
                default => null,
            };
            if ($timestampField) {
                $validated[$timestampField] = now();
            }
        }

        $shipment->update($validated);

        return response()->json($shipment->fresh());
    }
}
