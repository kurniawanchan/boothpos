<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReasonCodeRequest;
use App\Http\Requests\UpdateReasonCodeRequest;
use App\Models\ReasonCode;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 043-products-page-redesign — daftar alasan (86, diskontinu, penolakan persetujuan). Daftar terbuka untuk
 * semua pengguna yang login (dialog alasan perlu memuatnya); menulis hanya menu `settings`. Kode tidak
 * pernah dihapus — dinonaktifkan — karena riwayat merujuknya.
 */
class ReasonCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive') && $request->user()->canAccessMenu('settings');

        $rows = ReasonCode::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->orderBy('category')->orderBy('sort')->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (ReasonCode $r) => $this->present($r))->values()]);
    }

    public function store(StoreReasonCodeRequest $request, ActivityLogger $log): JsonResponse
    {
        $reason = DB::transaction(function () use ($request, $log) {
            $reason = ReasonCode::create($request->validated() + ['is_active' => true]);
            $log->log(userId: $request->user()->id, action: 'reason_code_created', entityType: 'ReasonCode', entityId: $reason->id,
                description: "Kode alasan {$reason->category}/{$reason->code} dibuat", newValues: $reason->toArray());

            return $reason;
        });

        return response()->json($this->present($reason), 201);
    }

    public function update(UpdateReasonCodeRequest $request, ReasonCode $reasonCode, ActivityLogger $log): JsonResponse
    {
        DB::transaction(function () use ($request, $reasonCode, $log) {
            $old = $reasonCode->toArray();
            $reasonCode->update($request->validated());
            $log->log(userId: $request->user()->id, action: 'reason_code_updated', entityType: 'ReasonCode', entityId: $reasonCode->id,
                description: "Kode alasan {$reasonCode->category}/{$reasonCode->code} diubah", oldValues: $old, newValues: $reasonCode->fresh()->toArray());
        });

        return response()->json($this->present($reasonCode->fresh()));
    }

    private function present(ReasonCode $r): array
    {
        return [
            'id' => $r->id, 'category' => $r->category, 'code' => $r->code, 'label' => $r->label(),
            'label_id' => $r->label_id, 'label_en' => $r->label_en, 'is_active' => $r->is_active, 'sort' => $r->sort,
        ];
    }
}
