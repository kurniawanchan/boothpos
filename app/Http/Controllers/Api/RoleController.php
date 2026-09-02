<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\ActivityLogger;
use App\Support\MenuKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $roles = Role::query()
            ->withCount(['users as active_users_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => RoleResource::collection($roles->items()),
            'meta' => [
                'current_page' => $roles->currentPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'last_page' => $roles->lastPage(),
            ],
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        // ->fresh() supaya is_system_default (default DB `false`, tidak
        // pernah dikirim klien — lihat StoreRoleRequest) terbaca benar,
        // bukan null seperti langsung setelah insert tanpa refetch.
        $role = Role::create($request->validated())->fresh();

        DB::transaction(function () use ($role, $request) {
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'Role',
                entityId: $role->id,
                description: "Menambah peran {$role->name}.",
                newValues: $role->only($role->getFillable()),
            );
        });

        $role->loadCount(['users as active_users_count' => fn ($q) => $q->where('is_active', true)]);

        return response()->json(new RoleResource($role), 201);
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role->loadCount(['users as active_users_count' => fn ($q) => $q->where('is_active', true)]);

        return response()->json(new RoleResource($role));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();

        // FR-013 — hanya perlu dicek bila permintaan ini benar-benar
        // mengubah menu_keys DAN hasil barunya tidak lagi mencakup kedua
        // kunci reserved. Mengubah name saja, atau mengubah menu_keys tapi
        // tetap mempertahankan keduanya, tidak pernah bisa melanggar guard
        // ini — dicek dulu di sini murah, sebelum query lintas-peran.
        if (array_key_exists('menu_keys', $data)) {
            $stillCapable = count(array_intersect(MenuKeys::RESERVED, $data['menu_keys'])) === count(MenuKeys::RESERVED);

            if (! $stillCapable && app(\App\Policies\RolePolicy::class)->wouldLeaveNoRoleCapableOfManagingAccess($role->id)) {
                return response()->json([
                    'message' => __('policies.role_would_leave_no_capable_role'),
                ], 409);
            }
        }

        $role->update($data);
        $role->refresh()->loadCount(['users as active_users_count' => fn ($q) => $q->where('is_active', true)]);

        return response()->json(new RoleResource($role));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $policy = app(\App\Policies\RolePolicy::class);

        // FR-014 — dicek sebelum FR-013 karena pesannya lebih spesifik dan
        // lebih sering menjadi alasan sebenarnya penolakan (peran dipakai
        // jauh lebih umum daripada peran-terakhir-pengelola).
        $activeUserCount = $role->users()->where('is_active', true)->count();
        if ($activeUserCount > 0) {
            return response()->json([
                'message' => __('policies.role_delete_in_use', ['count' => $activeUserCount]),
            ], 409);
        }

        if ($policy->wouldLeaveNoRoleCapableOfManagingAccess($role->id)) {
            return response()->json([
                'message' => __('policies.role_delete_would_leave_no_capable_role'),
            ], 409);
        }

        DB::transaction(function () use ($role, $request) {
            $snapshot = $role->only($role->getFillable());

            $role->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Role',
                entityId: $role->id,
                description: "Menghapus peran {$role->name}.",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    /**
     * GET /menu-keys — registry tunggal App\Support\MenuKeys sebagai
     * {key, label}[], dikonsumsi RoleMenuPicker.vue supaya checkbox layar
     * pengaturan peran tidak pernah di-hardcode terpisah dari backend.
     */
    public function menuKeys(): JsonResponse
    {
        return response()->json(['data' => MenuKeys::list()]);
    }
}
