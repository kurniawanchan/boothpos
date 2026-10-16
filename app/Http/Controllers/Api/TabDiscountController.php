<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTabErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClearDiscountRequest;
use App\Http\Requests\DiscountRequest;
use App\Http\Resources\TabResource;
use App\Models\Order;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;

/** 048 / T058 — diskon tab. Logika ada di DiscountService; controller hanya memeriksa dan meneruskan. */
class TabDiscountController extends Controller
{
    use HandlesTabErrors;

    public function store(DiscountRequest $request, Order $order, DiscountService $discounts): JsonResponse
    {
        return $this->guarded(fn () => new TabResource($discounts->apply($order, $request->user(), $request->validated())));
    }

    public function destroy(ClearDiscountRequest $request, Order $order, DiscountService $discounts): JsonResponse
    {
        return $this->guarded(fn () => new TabResource($discounts->clearOrderDiscount($order, $request->user())));
    }
}
