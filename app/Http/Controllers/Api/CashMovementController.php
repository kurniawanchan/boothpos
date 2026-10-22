<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ShiftRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordCashMovementRequest;
use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Services\CashMovementService;
use App\Services\ShiftListService;
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

    /**
     * 065 / US2 — daftar kas masuk/keluar lintas shift untuk web. Aturan lihat SAMA dengan daftar shift
     * (ShiftListService::visibleQuery: owner/admin semua, lainnya hanya shift miliknya). `totals` menjumlah SELURUH
     * hasil yang disaring, bukan hanya halaman ini.
     */
    public function list(Request $request, ShiftListService $shifts, ShiftReportService $report): JsonResponse
    {
        $f = $request->validate([
            'direction' => ['nullable', 'in:in,out'],
            'user_id' => ['nullable', 'integer'],
            'session_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();

        // Shift yang boleh dilihat (dan lolos filter tanggal/pengguna) — sub-kueri, bukan daftar id di PHP.
        $sessionIds = $shifts->visibleQuery($user)
            ->when($user->isOwnerOrAdmin() && ! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->when(! empty($f['date_from']), fn ($q) => $q->where('business_date', '>=', $f['date_from']))
            ->when(! empty($f['date_to']), fn ($q) => $q->where('business_date', '<=', $f['date_to']))
            ->select('id');

        $base = CashMovement::query()
            ->whereIn('session_id', $sessionIds)
            ->when(! empty($f['direction']), fn ($q) => $q->where('direction', $f['direction']))
            ->when(! empty($f['session_id']), fn ($q) => $q->where('session_id', (int) $f['session_id']));

        $totals = (clone $base)->selectRaw('SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) AS t_in, SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) AS t_out', [CashMovement::IN, CashMovement::OUT])->first();

        $page = (clone $base)
            ->with(['creator:id,name', 'session:id,device_id', 'session.device:id,name'])
            ->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (CashMovement $m) => $this->present($m, $report) + [
                'reason_label' => $this->reasonLabel($m->reason_code),
                'terminal_name' => $m->session?->device?->name,
                'source' => $m->session?->device_id === null ? 'web' : 'app',
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'totals' => ['in' => $report->money($totals->t_in ?? 0), 'out' => $report->money($totals->t_out ?? 0)],
        ]);
    }

    /** Label alasan dari lang (id/en); kode tak dikenal dikembalikan apa adanya, bukan dikosongkan. */
    private function reasonLabel(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $key = "events_sessions.cash_reason.{$code}";
        $label = __($key);

        return $label === $key ? $code : $label;
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
