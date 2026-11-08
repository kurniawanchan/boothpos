<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OrderApprovalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkOrderApprovalRequest;
use App\Http\Requests\StoreOrderApprovalRequest;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Services\OrderApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** 059 — persetujuan seller atas penjualan POS. Controller hanya memvalidasi, mendelegasi, dan membentuk respons. */
class OrderApprovalController extends Controller
{
    public function index(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('sales'), 403);

        return response()->json(['data' => OrderApprovalService::present($order, $request->user())]);
    }

    public function store(StoreOrderApprovalRequest $request, Order $order, OrderApprovalService $service): JsonResponse
    {
        try {
            $order = $service->approve($order, $request->safe()->only(['artist_id', 'method', 'items_hash']), $request->file('image'), $request->user());
        } catch (OrderApprovalException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(['data' => OrderApprovalService::present($order->fresh(), $request->user())], 201);
    }

    /** 064 — pratinjau persetujuan massal: hanya baca, tidak menulis apa pun. */
    public function bulkPreview(BulkOrderApprovalRequest $request, OrderApprovalService $service): JsonResponse
    {
        return response()->json(['data' => $service->previewBatch($request->orderIds(), $request->user())]);
    }

    /**
     * 064 — terapkan persetujuan massal. Selalu 200 setelah validasi bentuk: masalah per penjualan adalah `skipped`
     * dengan alasan (pola `verify-payments`/`bulk-email`), bukan galat HTTP. Gambar yang salah → 422 SEBELUM apa pun diproses.
     */
    public function bulkStore(BulkOrderApprovalRequest $request, OrderApprovalService $service): JsonResponse
    {
        // Tiap penjualan ±dua transaksi pendek; 200 penjualan bisa melewati batas 30 dtk PHP bawaan (pola ekspor Sales).
        set_time_limit(120);

        try {
            $result = $service->approveBatch(
                $request->orderIds(),
                $request->validated('plan'),
                (string) $request->validated('method'),
                $request->file('image'),
                $request->user(),
            );
        } catch (OrderApprovalException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(['data' => $result]);
    }

    /** `{approval}` di-scope ke `{order}` oleh route (scopeBindings): persetujuan penjualan lain → 404. */
    public function image(Request $request, Order $order, OrderApproval $approval): Response|JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('sales'), 403);

        if (! OrderApprovalService::mayViewImage($approval, $request->user())) {
            // Stempel tak punya gambar → 404; selain itu pengguna ini memang tak berhak → 403.
            return $approval->image_path === null
                ? response()->json(['message' => __('approvals.image_missing')], 404)
                : response()->json(['message' => __('approvals.image_forbidden'), 'code' => 'image_forbidden'], 403);
        }
        if (! Storage::disk('local')->exists($approval->image_path)) {
            return response()->json(['message' => __('approvals.image_missing')], 404);
        }

        // Diautorisasi per permintaan lalu dilayani dari disk privat; tak pernah di-cache bersama.
        return response(Storage::disk('local')->get($approval->image_path))
            ->header('Content-Type', $approval->image_mime ?: 'application/octet-stream')
            ->header('Cache-Control', 'private, no-store');
    }
}
