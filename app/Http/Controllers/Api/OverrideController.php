<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PinRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueOverrideRequest;
use App\Models\User;
use App\Services\OverrideGate;
use App\Support\OutletContext;
use App\Support\OverrideSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 048 / S3 — `POST /overrides` (terbitkan token) dan `GET /overrides/approvers` (daftar penyetuju). Controller
 * tipis: aturan di {@see OverrideGate}. Outlet persetujuan = outlet aktif peminta (X-Outlet-Id atau satu-satunya).
 */
class OverrideController extends Controller
{
    public function store(IssueOverrideRequest $request, OverrideGate $gate): JsonResponse
    {
        try {
            $result = $gate->issue(
                $request->user(),
                $request->validated('action'),
                $request->validated('subject'),
                (int) $request->validated('approver_user_id'),
                $request->validated('pin'),
                $this->activeOutletId(),
            );
        } catch (PinRejectedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->extra, $e->status);
        }

        return response()->json($result, 201);
    }

    /** Respons: larik polos {id, name, role} (bukan dibungkus `data`). */
    public function approvers(Request $request, OverrideGate $gate): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string', Rule::in(OverrideSubject::actions())]]);

        $list = $gate->approvers($data['action'], $request->user(), $this->activeOutletId())
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role?->name])
            ->values()
            ->all();

        return response()->json($list);
    }

    private function activeOutletId(): int
    {
        $context = app(OutletContext::class);
        $id = $context->selectedId() ?? $context->singleId();
        if ($id === null) {
            throw ValidationException::withMessages(['outlet_id' => __('outlets.choose_outlet')]);
        }

        return (int) $id;
    }
}
