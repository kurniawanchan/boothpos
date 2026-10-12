<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOutletRequest;
use App\Http\Requests\UpdateOutletRequest;
use App\Http\Resources\OutletResource;
use App\Models\Outlet;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OutletController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Outlet::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $outlets = Outlet::query()
            ->withCount('products')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => OutletResource::collection($outlets->items()),
            'meta' => [
                'current_page' => $outlets->currentPage(),
                'per_page' => $outlets->perPage(),
                'total' => $outlets->total(),
                'last_page' => $outlets->lastPage(),
            ],
        ]);
    }

    public function store(StoreOutletRequest $request): JsonResponse
    {
        $outlet = Outlet::create($request->validated());

        return response()->json(new OutletResource($outlet), 201);
    }

    public function show(Outlet $outlet): JsonResponse
    {
        $this->authorize('view', $outlet);

        $outlet->loadCount('products');

        return response()->json(new OutletResource($outlet));
    }

    public function update(UpdateOutletRequest $request, Outlet $outlet): JsonResponse
    {
        $outlet->update($request->validated());

        return response()->json(new OutletResource($outlet->fresh()));
    }

    public function destroy(Request $request, Outlet $outlet): JsonResponse
    {
        $this->authorize('delete', $outlet);

        // TODO (Increment 2 — setelah modul Product ada): blokir hapus
        // bila masih ada produk aktif milik artist ini, konsisten dengan
        // aturan Category (PRD F5.4). Belum diimplementasikan sekarang
        // karena tabel 'products' belum dibangun fiturnya; memakai model
        // stub sekarang berisiko divergen dari skema Product yang
        // sebenarnya. Dicatat sebagai gap eksplisit, bukan diasumsikan aman.
        if (Schema::hasTable('products')) {
            $hasActiveProducts = DB::table('products')
                ->where('home_outlet_id', $outlet->id)
                ->where('is_active', true)
                ->exists();

            if ($hasActiveProducts) {
                return response()->json([
                    'message' => __('master_data.outlet_delete_has_active_products'),
                ], 409);
            }
        }

        // F13.4 — hapus data adalah tindakan sensitif; log ditulis DI DALAM
        // transaksi yang sama dengan delete-nya (atomik, ikut rollback bersama).
        DB::transaction(function () use ($outlet, $request) {
            $snapshot = $outlet->only($outlet->getFillable());

            $outlet->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Outlet',
                entityId: $outlet->id,
                description: "Menghapus outlet {$outlet->code} ({$outlet->name}).",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }
}
