<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublishReleaseRequest;
use App\Models\MenuRelease;
use App\Services\ReleaseService;
use App\Support\MenuSettings;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 043 — /menu/releases (daftar, detail, terbitkan, batalkan jadwal). Logika di ReleaseService; Tahap B menambah outlet. */
class MenuReleaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));

        $rows = MenuRelease::query()->withCount('changes')->orderByDesc('version')->get();

        return response()->json(['release_enabled' => MenuSettings::releaseEnabled(), 'data' => $rows->map(fn ($r) => $this->present($r))->values()]);
    }

    public function show(Request $request, MenuRelease $release): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));

        $release->load(['changes.requester:id,name', 'releaser:id,name']);

        return response()->json($this->present($release) + [
            'changes' => $release->changes->map(fn ($c) => [
                'id' => $c->id, 'kind' => $c->kind, 'product_name' => $c->payload['product_name'] ?? null, 'variant_name' => $c->payload['variant_name'] ?? null,
                'old_price' => isset($c->payload['old_price']) ? number_format((float) $c->payload['old_price'], 2, '.', '') : null,
                'new_price' => isset($c->payload['new_price']) ? number_format((float) $c->payload['new_price'], 2, '.', '') : null,
                'requested_by' => $c->requester?->name, 'status' => $c->status,
            ])->values(),
        ]);
    }

    public function store(PublishReleaseRequest $request, ReleaseService $service): JsonResponse
    {
        $this->assertEnabled();

        $at = $request->filled('publish_at') ? CarbonImmutable::parse($request->input('publish_at')) : null;
        $release = $service->publish($request->user(), $at, $request->input('note'), $request->input('change_ids'));

        return response()->json($this->present($release->loadCount('changes')), 201);
    }

    public function cancel(Request $request, MenuRelease $release, ReleaseService $service): JsonResponse
    {
        $this->assertEnabled();
        abort_unless($request->user()->hasPermission(PermissionCatalog::RELEASE_PUBLISH), 403, __('reports.not_authorized_generic'));

        return response()->json($this->present($service->cancel($release, $request->user())));
    }

    private function assertEnabled(): void
    {
        abort_unless(MenuSettings::releaseEnabled(), 409, __('products.release_disabled'));
    }

    private function present(MenuRelease $r): array
    {
        return [
            'id' => $r->id, 'version' => $r->version, 'status' => $r->status,
            'publish_at' => $r->publish_at?->toIso8601String(), 'published_at' => $r->published_at?->toIso8601String(),
            'note' => $r->note, 'change_count' => $r->changes_count ?? null, 'released_by' => $r->releaser?->name,
        ];
    }
}
