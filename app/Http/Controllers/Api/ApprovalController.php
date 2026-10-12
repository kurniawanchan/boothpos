<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\MenuChange;
use App\Services\ApprovalService;
use App\Support\MenuSettings;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 043 — /approvals (daftar, setujui, tolak). Aturan peminta ≠ pemutus ditegakkan ApprovalService. */
class ApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(PermissionCatalog::PRICE_APPROVE), 403, __('reports.not_authorized_generic'));

        $rows = Approval::query()
            ->with(['requester:id,name', 'decider:id,name'])
            ->where('kind', $request->input('kind', Approval::KIND_MENU_PRICE))
            ->when($request->filled('status'), fn ($q) => $q->where('decision', $request->string('status')))
            ->orderByDesc('id')->get();

        $changes = MenuChange::query()->whereIn('id', $rows->pluck('subject_id'))->get()->keyBy('id');

        return response()->json(['threshold_pct' => MenuSettings::approvalThresholdPct(), 'data' => $rows->map(function (Approval $a) use ($changes) {
            $c = $changes[$a->subject_id] ?? null;

            return [
                'id' => $a->id, 'decision' => $a->decision, 'difference_pct' => (float) $a->difference_pct, 'amount' => number_format((float) $a->amount, 2, '.', ''),
                'product_name' => $c?->payload['product_name'] ?? null, 'variant_name' => $c?->payload['variant_name'] ?? null, 'outlet_code' => $c?->payload['outlet_code'] ?? null,
                'old_price' => isset($c?->payload['old_price']) ? number_format((float) $c->payload['old_price'], 2, '.', '') : null,
                'new_price' => isset($c?->payload['new_price']) ? number_format((float) $c->payload['new_price'], 2, '.', '') : null,
                'requested_by' => $a->requester?->name, 'requested_by_id' => $a->requested_by, 'decided_by' => $a->decider?->name, 'decided_at' => $a->decided_at?->toIso8601String(),
                'change_id' => $a->subject_id,
            ];
        })->values()]);
    }

    public function approve(Request $request, Approval $approval, ApprovalService $service): JsonResponse
    {
        // Izin dicek DI SINI (sama seperti index) dan sekali lagi di ApprovalService::lockPending — pertahanan berlapis.
        abort_unless($request->user()->hasPermission(PermissionCatalog::PRICE_APPROVE), 403, __('reports.not_authorized_generic'));
        abort_unless(MenuSettings::releaseEnabled(), 409, __('products.release_disabled'));

        return response()->json(['id' => $approval->id, 'decision' => $service->approve($approval, $request->user())->decision]);
    }

    public function reject(Request $request, Approval $approval, ApprovalService $service): JsonResponse
    {
        // Izin dicek DI SINI (sama seperti index) dan sekali lagi di ApprovalService::lockPending — pertahanan berlapis.
        abort_unless($request->user()->hasPermission(PermissionCatalog::PRICE_APPROVE), 403, __('reports.not_authorized_generic'));
        abort_unless(MenuSettings::releaseEnabled(), 409, __('products.release_disabled'));
        $data = $request->validate(['reason_code_id' => ['required', 'integer'], 'note' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return response()->json(['id' => $approval->id, 'decision' => $service->reject($approval, $request->user(), (int) $data['reason_code_id'], $data['note'] ?? null)->decision]);
    }
}
