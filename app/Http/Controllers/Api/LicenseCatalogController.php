<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLicenseRequest;
use App\Http\Requests\UpdateLicenseRequest;
use App\Http\Resources\LicenseResource;
use App\Models\License;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 019-billing-system — rename dari PackageController. Nama class
 * SENGAJA LicenseCatalogController, bukan LicenseController — nama
 * itu sudah dipakai App\Http\Controllers\Api\LicenseController milik
 * 018-license-activation (gerbang aktivasi instalasi, konsep yang
 * sama sekali berbeda). Lihat research.md R0.
 */
class LicenseCatalogController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', License::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $licenses = License::query()
            ->withCount('companies')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('license_tier'), fn ($q) => $q->where('license_tier', $request->string('license_tier')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => LicenseResource::collection($licenses->items()),
            'meta' => [
                'current_page' => $licenses->currentPage(),
                'per_page' => $licenses->perPage(),
                'total' => $licenses->total(),
                'last_page' => $licenses->lastPage(),
            ],
        ]);
    }

    public function store(StoreLicenseRequest $request): JsonResponse
    {
        $license = License::create($request->validated());

        DB::transaction(function () use ($license, $request) {
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'License',
                entityId: $license->id,
                description: "Menambah lisensi {$license->name}.",
                newValues: $license->only($license->getFillable()),
            );
        });

        return response()->json(new LicenseResource($license), 201);
    }

    public function show(License $license): JsonResponse
    {
        $this->authorize('view', $license);

        $license->loadCount('companies');

        return response()->json(new LicenseResource($license));
    }

    public function update(UpdateLicenseRequest $request, License $license): JsonResponse
    {
        $license->update($request->validated());

        return response()->json(new LicenseResource($license->fresh()));
    }

    public function destroy(Request $request, License $license): JsonResponse
    {
        $this->authorize('delete', $license);

        // Lisensi yang masih dirujuk company manapun tidak boleh dihapus
        // diam-diam — konsisten dengan pola VendorController/
        // BusinessTypeController (FR-010).
        if ($license->companies()->exists()) {
            return response()->json([
                'message' => __('licenses.license_delete_has_companies'),
            ], 409);
        }

        DB::transaction(function () use ($license, $request) {
            $snapshot = $license->only($license->getFillable());

            $license->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'License',
                entityId: $license->id,
                description: "Menghapus lisensi {$license->name}.",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }
}
