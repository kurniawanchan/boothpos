<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuChange;
use App\Services\ReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 043 — GET /menu/changes, POST /menu/changes/{id}/cancel. Logika di ReleaseService. */
class MenuChangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));

        $rows = MenuChange::query()
            ->with(['requester:id,name', 'approval:id,decision,difference_pct'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when(! $request->filled('status'), fn ($q) => $q->unreleased())
            ->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (MenuChange $c) => [
            'id' => $c->id, 'kind' => $c->kind, 'status' => $c->status, 'subject_id' => $c->subject_id,
            'product_name' => $c->payload['product_name'] ?? null, 'variant_name' => $c->payload['variant_name'] ?? null, 'outlet_code' => $c->payload['outlet_code'] ?? null,
            'old_price' => isset($c->payload['old_price']) ? number_format((float) $c->payload['old_price'], 2, '.', '') : null,
            'new_price' => isset($c->payload['new_price']) ? number_format((float) $c->payload['new_price'], 2, '.', '') : null,
            'effective_from' => $c->effective_from?->toDateString(),
            'requested_by' => $c->requester?->name, 'requested_at' => $c->created_at?->toIso8601String(),
            'approval' => $c->approval ? ['id' => $c->approval->id, 'decision' => $c->approval->decision, 'difference_pct' => (float) $c->approval->difference_pct] : null,
            'release_id' => $c->release_id,
        ])->values()]);
    }

    public function cancel(Request $request, MenuChange $change, ReleaseService $service): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));

        $c = $service->cancelChange($change, $request->user());

        return response()->json(['id' => $c->id, 'status' => $c->status]);
    }
}
