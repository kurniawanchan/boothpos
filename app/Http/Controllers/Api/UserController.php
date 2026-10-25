<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserOutletsRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use App\Support\ModeGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ImageUploadService $imageUploadService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $users = User::query()
            ->with(['role', 'outlets:id'])
            // 003-seed-demo-live follow-up (FR-017) — daftar tampilan
            // saja; TIDAK memengaruhi login (lihat catatan di User model).
            ->where('data_mode', ModeGate::current())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($qq) => $qq->where('name', 'like', $term)->orWhere('username', 'like', $term));
            })
            ->when($request->filled('role_id'), fn ($q) => $q->where('role_id', $request->integer('role_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            // 074 — `has_pin=1` hanya yang punya PIN, `has_pin=0` hanya yang belum; tanpa parameter = semua.
            ->when($request->has('has_pin'), fn ($q) => $request->boolean('has_pin') ? $q->whereNotNull('pin_hash') : $q->whereNull('pin_hash'))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        DB::transaction(function () use ($user, $request) {
            // 045 Stage 2 — instalasi SATU outlet tetap berjalan tanpa langkah penugasan: pengguna non-admin baru
            // otomatis ditugaskan ke satu-satunya outlet aktif (tanpa ini ia tidak bisa membuka shift).
            if (! $user->load('role')->hasAllOutlets()) {
                $activeOutlets = \App\Models\Outlet::query()->where('is_active', true)->pluck('id');
                if ($activeOutlets->count() === 1) {
                    $user->outlets()->sync($activeOutlets->all());
                }
            }

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'User',
                entityId: $user->id,
                description: "Menambah pengguna {$user->username} ({$user->name}).",
                newValues: ['name' => $user->name, 'username' => $user->username, 'role_id' => $user->role_id, 'is_active' => $user->is_active],
            );
        });

        return response()->json(new UserResource($user->fresh(['role', 'outlets:id'])), 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json(new UserResource($user->load(['role', 'outlets:id'])));
    }

    /**
     * 045 Stage 2 — PUT /users/{user}/outlets: mengganti seluruh penugasan outlet pengguna.
     *
     * Owner/Admin dilarang ditugaskan (409): mereka selalu melihat semua outlet, jadi baris penugasan
     * hanya akan menyesatkan. Perubahan dicatat di activity_logs DALAM transaksi yang sama (aturan
     * ActivityLogger), sehingga penugasan yang gagal tersimpan tidak meninggalkan catatan palsu.
     */
    public function outlets(UpdateUserOutletsRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $actor = $request->user();

        // Tidak seorang pun mengatur penugasannya sendiri: memperluas outlet untuk diri sendiri adalah
        // eskalasi hak (temuan tinjauan keamanan 2026-10-12).
        if ($actor->id === $user->id) {
            return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
        }

        if ($user->hasAllOutlets()) {
            return response()->json(['message' => __('outlets.all_by_role'), 'code' => 'outlet_all_by_role'], 409);
        }

        $before = $user->outlets()->orderBy('outlets.id')->pluck('outlets.id')->map(fn ($id) => (int) $id)->values()->all();
        $after = collect($request->validated('outlet_ids'))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        // Pengelola tanpa flag all_outlets hanya boleh MEMBERI dan MELEPAS outlet yang SENDIRI ia lihat.
        // Tanpa batas ini, pengguna dengan menu `users` bisa menugaskan outlet yang tak pernah boleh ia
        // lihat, atau mencabut akses pengguna lain ke outlet yang tak bisa ia lihat.
        if (! $actor->hasAllOutlets()) {
            $permitted = app(\App\Support\OutletContext::class)->permittedIds();
            if (array_diff($after, $permitted) !== [] || array_diff($before, $permitted) !== []) {
                return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
            }
        }

        DB::transaction(function () use ($request, $user, $before, $after) {
            $user->outlets()->sync($after);

            app(ActivityLogger::class)->log(
                $request->user()->id,
                'user_outlets_updated',
                'user',
                $user->id,
                __('outlets.assignments_changed'),
                ['outlet_ids' => $before],
                ['outlet_ids' => $after],
            );
        });

        return response()->json(new UserResource($user->fresh(['role'])->load('outlets:id')));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        $deactivating = array_key_exists('is_active', $validated) && $validated['is_active'] === false && $user->is_active;
        $roleChanging = array_key_exists('role_id', $validated) && (int) $validated['role_id'] !== (int) $user->role_id;

        // FR-006 — konflik aturan bisnis, bukan soal hak akses, jadi 409
        // (bukan 403) dan dicek manual di sini, konsisten dengan pola
        // guard bisnis lain di kodebase ini (OutletController::destroy(),
        // dst.), bukan dilempar lewat $this->authorize().
        if (app(UserPolicy::class)->isSelfLockout($request->user(), $user, $deactivating, $roleChanging)) {
            return response()->json([
                'message' => __('policies.user_self_lockout'),
            ], 409);
        }

        if (array_key_exists('password', $validated)) {
            if (filled($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }
        }

        $user->update($validated);

        return response()->json(new UserResource($user->fresh('role')));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if (app(UserPolicy::class)->isSelfLockout($request->user(), $user, deactivating: true, roleChanging: false)) {
            return response()->json([
                'message' => __('policies.user_self_delete'),
            ], 409);
        }

        DB::transaction(function () use ($user, $request) {
            $snapshot = ['name' => $user->name, 'username' => $user->username, 'role_id' => $user->role_id];

            $user->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'User',
                entityId: $user->id,
                description: "Menghapus pengguna {$user->username} ({$user->name}).",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    public function uploadPhoto(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'image' => [
                'required',
                'file',
                Rule::file()->max(ImageUploadService::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
        ]);

        $oldPath = $user->photo_path;

        $user->photo_path = $this->imageUploadService->store($validated['image'], 'users');
        $user->save();

        $this->imageUploadService->delete($oldPath);

        return response()->json(new UserResource($user->fresh('role')));
    }
}
