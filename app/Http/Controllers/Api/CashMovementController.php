<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ShiftRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordCashMovementRequest;
use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Services\CashMovementService;
use App\Services\ShiftReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 048 / S2 — kas masuk/keluar shift (api-gap B3). Lihat {@see CashMovementService} untuk aturannya.
 */
class CashMovementController extends Controller
{
    public function index(Request $request, CashierSession $session, ShiftReportService $report): JsonResponse
    {
        // Pemilik shift, atau owner/admin: sama dengan `summary()`.
        if ($session->user_id !== $request->user()->id && ! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => __('events_sessions.not_authorized_view_summary')], 403);
        }

        $rows = CashMovement::query()
            ->where('session_id', $session->id)
            ->with('creator:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (CashMovement $m) => $this->present($m, $report));

        return response()->json(['data' => $rows->values()]);
    }

    public function store(RecordCashMovementRequest $request, CashierSession $session, CashMovementService $service, ShiftReportService $report): JsonResponse
    {
        try {
            $movement = $service->record($session, $request->user(), $request->validated());
        } catch (ShiftRuleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason] + $e->context, $e->status);
        }

        return response()->json($this->present($movement->load('creator:id,name'), $report), 201);
    }

    /** @return array<string, mixed> */
    private function present(CashMovement $m, ShiftReportService $report): array
    {
        return [
            'id' => $m->id,
            'session_id' => $m->session_id,
            'direction' => $m->direction,
            'reason_code' => $m->reason_code,
            'reason_note' => $m->reason_note,
            'amount' => $report->money($m->amount),
            'created_by_name' => $m->creator?->name,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
