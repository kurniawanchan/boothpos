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
        if (array_key_exists('menu_keys', $data) || array_key_exists('menu_actions', $data)) {
            // 057 — "mampu" = AKSI PENUH pada users DAN roles: dinilai pada peran SETELAH perubahan (hipotetis).
            $after = new Role;
            $after->name = $role->name;
            $after->is_system_default = $role->is_system_default;
            $after->menu_keys = $data['menu_keys'] ?? $role->menu_keys;
            $after->menu_actions = array_key_exists('menu_actions', $data) ? $data['menu_actions'] : $role->menu_actions;
            $stillCapable = count(array_intersect(MenuKeys::RESERVED, $after->menu_keys ?? [])) === count(MenuKeys::RESERVED)
                && $after->hasFullAccessTo(MenuKeys::RESERVED);

            if (! $stillCapable && app(\App\Policies\RolePolicy::class)->wouldLeaveNoRoleCapableOfManagingAccess($role->id)) {
                return response()->json([
                    'message' => __('policies.role_would_leave_no_capable_role'),
                ], 409);
            }

            // 057 (FR-013) — peran Owner bawaan selalu akses penuh: perubahan yang MEMBATASI aksinya ditolak.
            // `isOwner()` curi-hasil (selalu penuh), jadi data yang DIUSULKAN diperiksa pada salinan tanpa penanda sistem.
            $probe = new Role(['menu_keys' => $after->menu_keys, 'menu_actions' => $after->menu_actions]);
            if ($role->isOwner() && array_key_exists('menu_actions', $data) && ! $probe->hasFullAccessTo($probe->menu_keys ?? [])) {
                return response()->json(['message' => __('permissions.owner_locked')], 422);
            }
        }

        $old = $role->only(['menu_keys', 'menu_actions']);
        DB::transaction(function () use ($role, $data, $old, $request) {
            $role->update($data);
            if (array_key_exists('menu_actions', $data) || array_key_exists('menu_keys', $data)) {
                $this->activityLogger->log(
                    userId: $request->user()?->id,
                    action: 'updated',
                    entityType: 'Role',
                    entityId: $role->id,
                    description: "Mengubah akses peran {$role->name}.",
                    oldValues: $old,
                    newValues: $role->only(['menu_keys', 'menu_actions']),
                );
            }
        });
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
        // 057 — tiap menu membawa aksi yang tersedia (label mengikuti bahasa pengguna) untuk grid pada editor peran.
        return response()->json(['data' => array_map(
            fn (array $m) => $m + ['actions' => \App\Support\MenuActions::describe($m['key'])],
            MenuKeys::list(),
        )]);
    }
}
