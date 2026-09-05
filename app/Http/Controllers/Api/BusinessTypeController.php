<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBusinessTypeRequest;
use App\Http\Requests\UpdateBusinessTypeRequest;
use App\Http\Resources\BusinessTypeResource;
use App\Models\BusinessType;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessTypeController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BusinessType::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $businessTypes = BusinessType::query()
            ->withCount('companies')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => BusinessTypeResource::collection($businessTypes->items()),
            'meta' => [
                'current_page' => $businessTypes->currentPage(),
                'per_page' => $businessTypes->perPage(),
                'total' => $businessTypes->total(),
                'last_page' => $businessTypes->lastPage(),
            ],
        ]);
    }

    public function store(StoreBusinessTypeRequest $request): JsonResponse
    {
        $businessType = BusinessType::create($request->validated());

        DB::transaction(function () use ($businessType, $request) {
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'BusinessType',
                entityId: $businessType->id,
                description: "Menambah jenis bisnis {$businessType->code} ({$businessType->name}).",
                newValues: $businessType->only($businessType->getFillable()),
            );
        });

        return response()->json(new BusinessTypeResource($businessType), 201);
    }

    public function show(BusinessType $businessType): JsonResponse
    {
        $this->authorize('view', $businessType);

        $businessType->loadCount('companies');

        return response()->json(new BusinessTypeResource($businessType));
    }

    public function update(UpdateBusinessTypeRequest $request, BusinessType $businessType): JsonResponse
    {
        $businessType->update($request->validated());

        return response()->json(new BusinessTypeResource($businessType->fresh()));
    }

    public function destroy(Request $request, BusinessType $businessType): JsonResponse
    {
        $this->authorize('delete', $businessType);

        // Jenis bisnis yang masih dirujuk company manapun tidak boleh
        // dihapus diam-diam — menghapusnya akan membuat rujukan company
        // itu yatim. Konsisten dengan pola VendorController.
        if ($businessType->companies()->exists()) {
            return response()->json([
                'message' => __('companies.business_type_delete_has_companies'),
            ], 409);
        }

        DB::transaction(function () use ($businessType, $request) {
            $snapshot = $businessType->only($businessType->getFillable());

            $businessType->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'BusinessType',
                entityId: $businessType->id,
                description: "Menghapus jenis bisnis {$businessType->code} ({$businessType->name}).",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }
}
