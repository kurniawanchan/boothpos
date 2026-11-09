<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Preorder;
use App\Models\Shipment;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentService $shipmentService)
    {
    }

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
        // 065 — aturan (hanya Mail Order, satu pengiriman per pre-order, bidang wajib) kini di
        // ShipmentService, dipakai bersama aksi massal. Urutan respons sama seperti sebelumnya:
        // konflik 409 dinilai SEBELUM validasi bentuk 422.
        if ($blocker = $this->shipmentService->blocker($preorder)) {
            return response()->json(['message' => $blocker['message']], 409);
        }

        $validated = $request->validate(ShipmentService::STORE_RULES);

        try {
            $shipment = $this->shipmentService->createFor($preorder, $validated);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($shipment, 201);
    }

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        // Sama seperti store() di atas — tidak digerbang, sengaja, agar
        // konsisten dengan seluruh alur preorder yang terbuka untuk semua
        // peran terautentikasi.
        $validated = $request->validate([
            'courier_name' => ['sometimes', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['sometimes', 'string', 'max:100'],
            'recipient_phone' => ['sometimes', 'string', 'max:20'],
            'address_line' => ['sometimes', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:pending,packed,shipped,delivered'],
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
