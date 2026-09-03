<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosDraft;
use App\Services\PosDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 006-purchase-order-and-ops (US4) — tidak ada Policy terpisah: setiap
 * kasir yang login sudah boleh bertransaksi POS (StoreOrderRequest juga
 * begitu), jadi draft cukup dilingkupi kepemilikan (findOrFail dalam
 * scope user_id milik pemanggil), bukan menu_keys tambahan.
 */
class PosDraftController extends Controller
{
    public function __construct(private PosDraftService $posDraftService) {}

    public function index(Request $request): JsonResponse
    {
        $drafts = $this->posDraftService->listForUser($request->user());

        return response()->json(['data' => $drafts->map(fn (PosDraft $d) => $this->present($d))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'customer_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.sku' => ['nullable', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            // sell_price di sini HANYA untuk tampilan ringkasan draft (list
            // & label total) — snapshot apa adanya saat disimpan, BUKAN
            // sumber harga sungguhan; checkout tetap menghitung ulang dari
            // master data saat draft dilanjutkan jadi transaksi (Constitution
            // IV, sama seperti setiap transaksi lain di sistem ini).
            'items.*.sell_price' => ['nullable', 'numeric'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $draft = $this->posDraftService->save($validated, $request->user());

        return response()->json($this->present($draft), 201);
    }

    public function show(Request $request, PosDraft $posDraft): JsonResponse
    {
        abort_unless($posDraft->user_id === $request->user()->id, 404);

        $resumed = $this->posDraftService->resume($posDraft);

        return response()->json($resumed);
    }

    public function destroy(Request $request, PosDraft $posDraft): JsonResponse
    {
        abort_unless($posDraft->user_id === $request->user()->id, 404);

        $this->posDraftService->discard($posDraft);

        return response()->json(null, 204);
    }

    private function present(PosDraft $draft): array
    {
        $items = $draft->cart_snapshot['items'] ?? [];

        return [
            'id' => $draft->id,
            'label' => $draft->label,
            'item_count' => count($items),
            'total' => number_format(collect($items)->sum(fn ($i) => ($i['sell_price'] ?? 0) * ($i['qty'] ?? 0)), 2, '.', ''),
            'customer_id' => $draft->customer_id,
            'created_at' => $draft->created_at?->toIso8601String(),
        ];
    }
}
