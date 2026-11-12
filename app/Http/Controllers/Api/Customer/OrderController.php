<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ListOrdersRequest;
use App\Http\Requests\Customer\PlaceOrderRequest;
use App\Http\Requests\Customer\QuoteOrderRequest;
use App\Http\Resources\Customer\CustomerOrderDetailResource;
use App\Http\Resources\Customer\CustomerOrderSummaryResource;
use App\Http\Resources\Customer\CustomerOrderTrackingResource;
use App\Services\CustomerCommerce\CustomerCheckoutService;
use App\Services\CustomerCommerce\CustomerOrderQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer commerce (CONTRACT §8) — quote, checkout, dan baca pesanan milik
 * akun yang sedang masuk.
 *
 * TERPISAH dari App\Http\Controllers\Api\OrderController (penjualan POS):
 * tidak ada kode bersama, tidak ada tabel bersama. Pelanggan hanya bisa
 * MEMBUAT pesanan dan MEMBACA miliknya; tidak ada rute di sini yang mengubah
 * status (itu aksi staf lewat CustomerOrderTransitionService).
 *
 * `{orderNumber}` adalah nomor publik acak, dicari lewat pemiliknya di
 * CustomerOrderQueryService — bukan route-model binding dan bukan id numerik.
 */
class OrderController extends Controller
{
    public function __construct(
        private CustomerCheckoutService $checkout,
        private CustomerOrderQueryService $orders,
    ) {}

    public function quote(QuoteOrderRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->checkout->quote($request->user('customer'), $request->validated()),
        ]);
    }

    /** 201 untuk pesanan baru, 200 bila `Idempotency-Key` yang sama mengembalikan pesanan lama. */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $order = $this->checkout->place(
            $request->user('customer'),
            $request->validated(),
            $request->idempotencyKey(),
            $replayed,
        );

        return response()->json(['data' => new CustomerOrderDetailResource($order)], $replayed ? 200 : 201);
    }

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $orders = $this->orders->paginateFor($request->user('customer'), $request->validated());

        return response()->json([
            'data' => CustomerOrderSummaryResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->orders->findFor($request->user('customer'), $orderNumber);

        return response()->json(['data' => new CustomerOrderDetailResource($order)]);
    }

    public function tracking(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->orders->findFor($request->user('customer'), $orderNumber);

        return response()->json(['data' => new CustomerOrderTrackingResource($order)]);
    }
}
