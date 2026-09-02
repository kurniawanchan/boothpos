<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBomLineRequest;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\StoreVendorMaterialPriceRequest;
use App\Http\Requests\UpdateBomLineRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Http\Requests\UpdateVendorMaterialPriceRequest;
use App\Http\Resources\BomLineResource;
use App\Http\Resources\MaterialResource;
use App\Http\Resources\VendorMaterialPriceResource;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\VendorMaterialPrice;
use App\Services\ActivityLogger;
use App\Services\BomCostCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Material::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $materials = Material::query()
            ->withCount('vendorPrices')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => MaterialResource::collection($materials->items()),
            'meta' => [
                'current_page' => $materials->currentPage(),
                'per_page' => $materials->perPage(),
                'total' => $materials->total(),
                'last_page' => $materials->lastPage(),
            ],
        ]);
    }

    public function store(StoreMaterialRequest $request): JsonResponse
    {
        $material = Material::create($request->validated());

        DB::transaction(function () use ($material, $request) {
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'Material',
                entityId: $material->id,
                description: "Menambah bahan {$material->code} ({$material->name}).",
                newValues: $material->only($material->getFillable()),
            );
        });

        return response()->json(new MaterialResource($material), 201);
    }

    public function show(Material $material): JsonResponse
    {
        $this->authorize('view', $material);

        $material->loadCount('vendorPrices')->load('vendorPrices.vendor');

        return response()->json(new MaterialResource($material));
    }

    public function update(UpdateMaterialRequest $request, Material $material): JsonResponse
    {
        $material->update($request->validated());

        return response()->json(new MaterialResource($material->fresh()));
    }

    public function destroy(Request $request, Material $material): JsonResponse
    {
        $this->authorize('delete', $material);

        // Bahan yang masih dipakai baris harga vendor ATAU baris BOM tidak
        // boleh dihapus diam-diam — keduanya akan jadi yatim dan diam-diam
        // mengubah bom_cost varian yang memakainya.
        if ($material->vendorPrices()->exists()) {
            return response()->json([
                'message' => 'Bahan masih memiliki harga vendor yang terdaftar dan tidak dapat dihapus.',
            ], 409);
        }

        if ($material->bomLines()->exists()) {
            return response()->json([
                'message' => 'Bahan masih dipakai pada BOM salah satu varian produk dan tidak dapat dihapus.',
            ], 409);
        }

        DB::transaction(function () use ($material, $request) {
            $snapshot = $material->only($material->getFillable());

            $material->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Material',
                entityId: $material->id,
                description: "Menghapus bahan {$material->code} ({$material->name}).",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    // =====================================================================
    // Harga vendor per bahan (vendor mana saja + berapa harganya)
    // =====================================================================

    public function storeVendorPrice(StoreVendorMaterialPriceRequest $request, Material $material): JsonResponse
    {
        $price = DB::transaction(function () use ($request, $material) {
            if ($request->boolean('is_preferred')) {
                // Hanya satu vendor preferred per bahan — ditegakkan di sini,
                // bukan constraint DB, karena "preferred" adalah pilihan
                // bisnis (bisa berubah kapan saja), bukan invariant struktural.
                $material->vendorPrices()->update(['is_preferred' => false]);
            }

            return $material->vendorPrices()->create($request->validated());
        });

        return response()->json(new VendorMaterialPriceResource($price->load('vendor')), 201);
    }

    public function updateVendorPrice(UpdateVendorMaterialPriceRequest $request, VendorMaterialPrice $vendorPrice): JsonResponse
    {
        DB::transaction(function () use ($request, $vendorPrice) {
            if ($request->boolean('is_preferred')) {
                VendorMaterialPrice::where('material_id', $vendorPrice->material_id)
                    ->where('id', '!=', $vendorPrice->id)
                    ->update(['is_preferred' => false]);
            }

            $vendorPrice->update($request->validated());
        });

        return response()->json(new VendorMaterialPriceResource($vendorPrice->fresh()->load('vendor')));
    }

    public function destroyVendorPrice(Request $request, VendorMaterialPrice $vendorPrice): JsonResponse
    {
        if (! $request->user()->canAccessMenu('materials')) {
            return response()->json(['message' => 'Hanya owner/admin/inventory yang dapat mengelola harga vendor.'], 403);
        }

        $vendorPrice->delete();

        return response()->json(null, 204);
    }

    // =====================================================================
    // BOM per varian produk (materialnya sama, endpoint digantung di sini
    // karena bahannya adalah aggregate root — lihat routes/api.php untuk
    // alasan path /variants/{variant}/bom)
    // =====================================================================

    public function storeBomLine(StoreBomLineRequest $request, ProductVariant $variant): JsonResponse
    {
        $line = DB::transaction(function () use ($request, $variant) {
            $line = $variant->bomLines()->create($request->validated());

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'ProductVariantBomLine',
                entityId: $line->id,
                description: "Menambah baris BOM varian {$variant->sku}: bahan #{$line->material_id} x {$line->qty_needed}.",
                newValues: $line->only($line->getFillable()),
            );

            return $line;
        });

        return response()->json(new BomLineResource($line->load('material')), 201);
    }

    public function updateBomLine(UpdateBomLineRequest $request, ProductVariantBomLine $bomLine): JsonResponse
    {
        $bomLine->update($request->validated());

        return response()->json(new BomLineResource($bomLine->fresh()->load('material')));
    }

    public function destroyBomLine(Request $request, ProductVariantBomLine $bomLine): JsonResponse
    {
        if (! $request->user()->canAccessMenu('products')) {
            return response()->json(['message' => 'Hanya owner/admin/inventory yang dapat mengelola BOM.'], 403);
        }

        DB::transaction(function () use ($bomLine, $request) {
            $snapshot = $bomLine->only($bomLine->getFillable());

            $bomLine->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'ProductVariantBomLine',
                entityId: $bomLine->id,
                description: "Menghapus baris BOM varian #{$bomLine->product_variant_id}: bahan #{$bomLine->material_id}.",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    public function bomIndex(Request $request, ProductVariant $variant): JsonResponse
    {
        if (! $request->user()->canAccessMenu('products')) {
            return response()->json(['message' => 'Hanya owner/admin/inventory yang dapat melihat BOM.'], 403);
        }

        return response()->json([
            'data' => BomLineResource::collection($variant->bomLines()->with('material')->get()),
        ]);
    }

    /**
     * F-baru: rincian modal bahan per varian (BOM lines + harga satuan +
     * biaya per baris + total bom_cost). Read-only, tidak menyentuh
     * cost_price — lihat dokblok BomCostCalculator.
     */
    public function costBreakdown(Request $request, ProductVariant $variant, BomCostCalculator $calculator): JsonResponse
    {
        if (! $request->user()->canAccessMenu('products')) {
            return response()->json(['message' => 'Hanya owner/admin/inventory yang dapat melihat rincian modal.'], 403);
        }

        $breakdown = $calculator->breakdown($variant);

        return response()->json([
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'cost_price' => number_format((float) $variant->cost_price, 2, '.', ''),
            'bom_cost' => $breakdown['bom_cost'],
            'lines' => $breakdown['lines'],
        ]);
    }
}
