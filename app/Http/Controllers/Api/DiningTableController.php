<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDiningTableRequest;
use App\Http\Requests\UpdateDiningTableRequest;
use App\Http\Resources\DiningTableResource;
use App\Models\DiningTable;
use App\Models\Order;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 028 US1 — manajemen meja. Gerbang: menu `dining_setup`.
 */
class DiningTableController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureSetupAccess($request);

        $tables = DiningTable::query()
            ->withExists('openTab as has_open_tab')
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('area')->orderBy('display_order')->orderBy('name')
            ->get();

        return response()->json(['data' => DiningTableResource::collection($tables)]);
    }

    public function store(StoreDiningTableRequest $request): JsonResponse
    {
        $outletId = $request->filled('outlet_id') ? $request->integer('outlet_id') : null;
        if ($outletId !== null && ! app(\App\Support\OutletContext::class)->isPermitted($outletId)) {
            return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
        }

        // outlet_id tidak ada di $fillable: diset di sini dari pilihan yang sudah divalidasi (default: BelongsToOutlet).
        $table = new DiningTable($request->payload());
        if ($outletId !== null) {
            $table->outlet_id = $outletId;
        }
        $table->save();

        return response()->json(new DiningTableResource($table), 201);
    }

    public function show(Request $request, DiningTable $diningTable): JsonResponse
    {
        $this->ensureSetupAccess($request);

        return response()->json(new DiningTableResource($this->withOpenFlag($diningTable)));
    }

    public function update(UpdateDiningTableRequest $request, DiningTable $diningTable): JsonResponse
    {
        $data = $request->payload();
        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'] && $diningTable->is_active;

        // Meja yang sedang dipakai tab tidak boleh dinonaktifkan — pelayan
        // masih bisa menambah pesanan ke tab itu, tapi meja "hilang" dari lantai.
        if ($deactivating && $diningTable->openTab()->exists()) {
            return response()->json(['message' => __('restaurant.table_has_open_tab')], 409);
        }

        DB::transaction(function () use ($diningTable, $data, $request) {
            $wasActive = $diningTable->is_active;
            $diningTable->update($data);

            if ($wasActive !== $diningTable->is_active) {
                $this->activityLogger->log(
                    userId: $request->user()?->id,
                    action: $diningTable->is_active ? 'activated' : 'deactivated',
                    entityType: 'DiningTable',
                    entityId: $diningTable->id,
                    description: ($diningTable->is_active ? 'Mengaktifkan' : 'Menonaktifkan')." meja {$diningTable->name}.",
                );
            }
        });

        return response()->json(new DiningTableResource($this->withOpenFlag($diningTable->fresh())));
    }

    public function destroy(Request $request, DiningTable $diningTable): JsonResponse
    {
        $this->ensureSetupAccess($request);

        // Meja yang PERNAH dipakai order apa pun (status mana pun) tidak boleh
        // dihapus — struk lama harus tetap bisa menunjukkan mejanya. Pakai
        // query mentah supaya tidak tergantung DataModeScope: FK `restrict`
        // di skema tidak peduli mode.
        if (DB::table('orders')->where('table_id', $diningTable->id)->exists()) {
            return response()->json(['message' => __('restaurant.table_has_history')], 409);
        }

        // F13.4 — hapus data adalah tindakan sensitif; log ikut transaksi.
        DB::transaction(function () use ($diningTable, $request) {
            $snapshot = $diningTable->only($diningTable->getFillable());
            $diningTable->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'DiningTable',
                entityId: $diningTable->id,
                description: "Menghapus meja {$diningTable->name}".($diningTable->area !== '' ? " ({$diningTable->area})" : '').'.',
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    private function ensureSetupAccess(Request $request): void
    {
        abort_unless($request->user()?->canAccessMenu('dining_setup'), 403, __('restaurant.forbidden'));
    }

    private function withOpenFlag(DiningTable $table): DiningTable
    {
        $table->has_open_tab = $table->openTab()->exists();

        return $table;
    }
}
