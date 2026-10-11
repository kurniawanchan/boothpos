<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTabErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachModifierGroupRequest;
use App\Http\Requests\StoreModifierGroupRequest;
use App\Http\Requests\UpdateModifierGroupRequest;
use App\Http\Resources\ModifierGroupResource;
use App\Models\ModifierGroup;
use App\Services\ModifierGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 028 US3 — grup modifier. Gerbang: menu `dining_setup`. Aturan bisnis ada di ModifierGroupService. */
class ModifierGroupController extends Controller
{
    use HandlesTabErrors;

    public function __construct(private ModifierGroupService $groups) {}

    public function index(Request $request): JsonResponse
    {
        // 043 — DAFTAR (baca saja) juga terbuka untuk menu `products`: penyunting item perlu memilih grup untuk ditautkan.
        // Menulis dan membaca satu grup tetap hanya `dining_setup`.
        abort_unless($request->user()?->canAccessAnyMenu(['dining_setup', 'products']), 403, __('restaurant.forbidden'));

        $groups = ModifierGroup::query()
            ->with(['options', 'products:id', 'categories:id'])
            ->orderBy('display_order')->orderBy('name')
            ->get();

        return response()->json(['data' => ModifierGroupResource::collection($groups)]);
    }

    public function store(StoreModifierGroupRequest $request): JsonResponse
    {
        return $this->guarded(fn () => new ModifierGroupResource($this->groups->create($request->validated())), 201);
    }

    public function show(Request $request, ModifierGroup $modifierGroup): JsonResponse
    {
        $this->ensureSetupAccess($request);

        return response()->json(new ModifierGroupResource($this->groups->loaded($modifierGroup)));
    }

    public function update(UpdateModifierGroupRequest $request, ModifierGroup $modifierGroup): JsonResponse
    {
        return $this->guarded(fn () => new ModifierGroupResource($this->groups->update($modifierGroup, $request->validated())));
    }

    public function attachments(AttachModifierGroupRequest $request, ModifierGroup $modifierGroup): JsonResponse
    {
        return $this->guarded(fn () => new ModifierGroupResource(
            $this->groups->setAttachments($modifierGroup, $request->validated('product_ids'), $request->validated('category_ids')),
        ));
    }

    public function destroy(Request $request, ModifierGroup $modifierGroup): JsonResponse
    {
        $this->ensureSetupAccess($request);

        return $this->guarded(function () use ($modifierGroup, $request) {
            $this->groups->delete($modifierGroup, $request->user());

            return null;
        }, 204);
    }

    private function ensureSetupAccess(Request $request): void
    {
        abort_unless($request->user()?->canAccessMenu('dining_setup'), 403, __('restaurant.forbidden'));
    }
}
