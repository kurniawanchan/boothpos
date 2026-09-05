<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePackageRequest;
use App\Http\Requests\UpdatePackageRequest;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Package::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $packages = Package::query()
            ->withCount('companies')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('license_tier'), fn ($q) => $q->where('license_tier', $request->string('license_tier')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => PackageResource::collection($packages->items()),
            'meta' => [
                'current_page' => $packages->currentPage(),
                'per_page' => $packages->perPage(),
                'total' => $packages->total(),
                'last_page' => $packages->lastPage(),
            ],
        ]);
    }

    public function store(StorePackageRequest $request): JsonResponse
    {
        $package = Package::create($request->validated());

        DB::transaction(function () use ($package, $request) {
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'Package',
                entityId: $package->id,
                description: "Menambah paket {$package->name}.",
                newValues: $package->only($package->getFillable()),
            );
        });

        return response()->json(new PackageResource($package), 201);
    }

    public function show(Package $package): JsonResponse
    {
        $this->authorize('view', $package);

        $package->loadCount('companies');

        return response()->json(new PackageResource($package));
    }

    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $package->update($request->validated());

        return response()->json(new PackageResource($package->fresh()));
    }

    public function destroy(Request $request, Package $package): JsonResponse
    {
        $this->authorize('delete', $package);

        // Paket yang masih dirujuk company manapun tidak boleh dihapus
        // diam-diam — konsisten dengan pola VendorController/
        // BusinessTypeController (FR-010).
        if ($package->companies()->exists()) {
            return response()->json([
                'message' => __('companies.package_delete_has_companies'),
            ], 409);
        }

        DB::transaction(function () use ($package, $request) {
            $snapshot = $package->only($package->getFillable());

            $package->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Package',
                entityId: $package->id,
                description: "Menghapus paket {$package->name}.",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }
}
