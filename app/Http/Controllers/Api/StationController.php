<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStationRequest;
use App\Http\Requests\UpdateStationRequest;
use App\Http\Resources\StationResource;
use App\Models\Station;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 028 US4 — stasiun persiapan. Tulis: `dining_setup`. Baca daftar: `dining_setup` ATAU
 * `kitchen` (layar dapur butuh daftar stasiun untuk pengalihnya).
 */
class StationController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canAccessMenu('dining_setup') || $user?->canAccessMenu('kitchen'), 403, __('restaurant.forbidden'));

        $stations = Station::query()
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('display_order')->orderBy('name')
            ->get();

        return response()->json(['data' => StationResource::collection($stations)]);
    }

    public function store(StoreStationRequest $request): JsonResponse
    {
        return response()->json(new StationResource(Station::create($request->validated())), 201);
    }

    public function show(Request $request, Station $station): JsonResponse
    {
        $this->ensureSetupAccess($request);

        return response()->json(new StationResource($station));
    }

    public function update(UpdateStationRequest $request, Station $station): JsonResponse
    {
        $station->update($request->validated());

        return response()->json(new StationResource($station->fresh()));
    }

    public function destroy(Request $request, Station $station): JsonResponse
    {
        $this->ensureSetupAccess($request);

        // Stasiun yang masih ditunjuk kategori/produk, atau pernah menerima tiket, tidak
        // boleh dihapus (FK restrict di skema; nonaktifkan saja). Query mentah: tidak
        // bergantung DataModeScope dan ikut menghitung baris soft-deleted.
        $inUse = DB::table('categories')->where('station_id', $station->id)->exists()
            || DB::table('products')->where('station_id', $station->id)->exists()
            || DB::table('kitchen_tickets')->where('station_id', $station->id)->exists();

        if ($inUse) {
            return response()->json(['message' => __('restaurant.station_in_use')], 409);
        }

        DB::transaction(function () use ($station, $request) {
            $snapshot = $station->only($station->getFillable());
            $station->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Station',
                entityId: $station->id,
                description: "Menghapus stasiun {$station->name}.",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    private function ensureSetupAccess(Request $request): void
    {
        abort_unless($request->user()?->canAccessMenu('dining_setup'), 403, __('restaurant.forbidden'));
    }
}
