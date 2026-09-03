<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Http\Resources\VendorResource;
use App\Models\Vendor;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vendor::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $vendors = Vendor::query()
            ->withCount('materialPrices')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => VendorResource::collection($vendors->items()),
            'meta' => [
                'current_page' => $vendors->currentPage(),
                'per_page' => $vendors->perPage(),
                'total' => $vendors->total(),
                'last_page' => $vendors->lastPage(),
            ],
        ]);
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = Vendor::create($request->validated());

        DB::transaction(function () use ($vendor, $request) {
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'Vendor',
                entityId: $vendor->id,
                description: "Menambah vendor {$vendor->code} ({$vendor->name}).",
                newValues: $vendor->only($vendor->getFillable()),
            );
        });

        return response()->json(new VendorResource($vendor), 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $this->authorize('view', $vendor);

        $vendor->loadCount('materialPrices');

        return response()->json(new VendorResource($vendor));
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($request->validated());

        return response()->json(new VendorResource($vendor->fresh()));
    }

    public function destroy(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('delete', $vendor);

        // Vendor yang masih terdaftar sebagai penjual bahan (baris apa pun
        // di vendor_material_prices) tidak boleh dihapus diam-diam —
        // menghapusnya akan membuat baris harga itu yatim dan mengubah
        // perhitungan bom_cost produk lain tanpa jejak. Konsisten dengan pola
        // ArtistController/CategoryController: guard manual karena FK
        // restrictOnDelete tidak berlaku untuk soft delete.
        if ($vendor->materialPrices()->exists()) {
            return response()->json([
                'message' => __('vendors_materials.vendor_delete_has_prices'),
            ], 409);
        }

        DB::transaction(function () use ($vendor, $request) {
            $snapshot = $vendor->only($vendor->getFillable());

            $vendor->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Vendor',
                entityId: $vendor->id,
                description: "Menghapus vendor {$vendor->code} ({$vendor->name}).",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }
}
