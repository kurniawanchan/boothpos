<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F13.4 — sisi baca dari log aktivitas. Tanpa ini, jejak audit yang
 * ditulis ActivityLogger tidak bisa ditinjau lewat API sama sekali.
 * Owner/admin saja — mengikuti pola inline yang sama seperti
 * ReportController::profit() (bukan Policy penuh, karena satu-satunya
 * aksi di sini adalah "lihat", tidak ada create/update/delete lewat API).
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => 'Tidak berhak mengakses log aktivitas.'], 403);
        }

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $logs = ActivityLog::query()
            ->with('user:id,name,username')
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', $request->integer('entity_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = collect($logs->items())->map(fn (ActivityLog $log) => [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'user_name' => $log->user?->name,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'description' => $log->description,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'created_at' => $log->created_at,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }
}
