<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTabErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddTabLineRequest;
use App\Http\Requests\CancelTabLineRequest;
use App\Http\Requests\UpdateTabLineRequest;
use App\Http\Resources\TabResource;
use App\Models\Order;
use App\Services\TabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 028 US2 — baris tab. Mengembalikan tab terbaru pada setiap perubahan. */
class TabLineController extends Controller
{
    use HandlesTabErrors;

    public function __construct(private TabService $tabs) {}

    public function store(AddTabLineRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(
            fn () => new TabResource($this->tabs->addLine($order, $request->validated(), $request->user())),
            201,
        );
    }

    public function update(UpdateTabLineRequest $request, Order $order, int $item): JsonResponse
    {
        return $this->guarded(
            fn () => new TabResource($this->tabs->updateLine($order, $item, $request->validated(), $request->user())),
        );
    }

    /** Hapus (belum dikirim) atau BATALKAN (sudah dikirim, `reason` wajib) sebuah baris. */
    public function destroy(CancelTabLineRequest $request, Order $order, int $item): JsonResponse
    {
        return $this->guarded(
            fn () => new TabResource($this->tabs->removeLine($order, $item, $request->user(), $request->validated('reason'))),
        );
    }
}
