<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerOrder\CancelCustomerOrderRequest;
use App\Http\Requests\CustomerOrder\ConfirmCustomerOrderRequest;
use App\Http\Requests\CustomerOrder\DeliverCustomerOrderRequest;
use App\Http\Requests\CustomerOrder\ListCustomerOrdersRequest;
use App\Http\Requests\CustomerOrder\MoveCustomerOrderRequest;
use App\Http\Requests\CustomerOrder\ShipCustomerOrderRequest;
use App\Http\Requests\CustomerOrder\UpdateCustomerOrderShipmentRequest;
use App\Http\Resources\StaffCustomerOrderDetailResource;
use App\Http\Resources\StaffCustomerOrderResource;
use App\Models\CustomerOrder;
use App\Services\CustomerCommerce\CustomerOrderTransitionService;
use App\Services\CustomerCommerce\StaffCustomerOrderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer commerce (CONTRACT §9) — fulfilment pesanan pelanggan oleh STAF
 * (menu `customer_orders`): daftar, detail, dan satu rute per transisi.
 *
 * TERPISAH dari App\Http\Controllers\Api\Customer\OrderController (sisi
 * pelanggan, guard `customer`, hanya membuat dan membaca miliknya) dan dari
 * OrderController POS. Controller ini tipis: validasi → delegasi ke
 * CustomerOrderTransitionService (SATU-satunya penulis status, riwayat,
 * pengiriman, dan stok pesanan pelanggan) → bentuk respons.
 *
 * Penegakan akses, berlapis:
 *  1. rute: `auth:sanctum` (token pelanggan ditolak 401 oleh guard),
 *     `seller.forbidden`, dan `ability:customer_orders,<aksi>` pada mutasi;
 *  2. FormRequest::authorize() (menu `customer_orders`) pada semua rute yang
 *     punya request; authorizeMenu() untuk detail.
 * Pelanggaran aturan bisnis dilempar service sebagai CustomerCommerceException
 * (409 + `code`), yang di rute staf merender `{message, code}`.
 *
 * `{customerOrder}` adalah id numerik lewat route-model binding ber-scope mode
 * data: pesanan mode lain = 404. Setiap aksi tulis mengembalikan DETAIL
 * terbaru supaya layar staf tidak perlu memuat ulang.
 */
class CustomerOrderFulfilmentController extends Controller
{
    public function __construct(
        private readonly CustomerOrderTransitionService $transitions,
        private readonly StaffCustomerOrderQuery $orders,
    ) {}

    private function authorizeMenu(Request $request): void
    {
        abort_unless($request->user()?->canAccessMenu('customer_orders'), 403, __('customer_commerce.fulfilment.not_authorized'));
    }

    private function detail(CustomerOrder $order): JsonResponse
    {
        return response()->json(['data' => new StaffCustomerOrderDetailResource($this->orders->loadDetail($order))]);
    }

    public function index(ListCustomerOrdersRequest $request): JsonResponse
    {
        $page = $this->orders->paginate($request->validated());

        return response()->json([
            'data' => StaffCustomerOrderResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->authorizeMenu($request);

        return $this->detail($customerOrder);
    }

    public function confirm(ConfirmCustomerOrderRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->confirm($customerOrder, $request->user(), $request->validated()));
    }

    public function pack(MoveCustomerOrderRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->pack($customerOrder, $request->user(), $request->validated()));
    }

    public function ready(MoveCustomerOrderRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->ready($customerOrder, $request->user(), $request->validated()));
    }

    public function pickup(MoveCustomerOrderRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->pickup($customerOrder, $request->user(), $request->validated()));
    }

    public function ship(ShipCustomerOrderRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->ship($customerOrder, $request->user(), $request->validated()));
    }

    public function updateShipment(UpdateCustomerOrderShipmentRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->updateShipment($customerOrder, $request->user(), $request->changes()));
    }

    public function deliver(DeliverCustomerOrderRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->deliver($customerOrder, $request->user(), $request->validated()));
    }

    public function cancel(CancelCustomerOrderRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        return $this->detail($this->transitions->cancel($customerOrder, $request->user(), $request->validated('reason')));
    }
}
