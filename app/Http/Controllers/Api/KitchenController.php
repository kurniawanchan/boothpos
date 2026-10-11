<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTabErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\TicketItemStatusRequest;
use App\Http\Resources\TicketResource;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Station;
use App\Services\KitchenService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** 028 US4 — umpan stasiun, tampilan cetak tiket, dan perubahan status item. */
class KitchenController extends Controller
{
    use HandlesTabErrors;

    public function __construct(private KitchenService $kitchen) {}

    public function feed(Request $request, Station $station): JsonResponse
    {
        abort_unless($request->user()?->canAccessMenu('kitchen'), 403, __('restaurant.forbidden'));

        $rows = TicketResource::collection($this->kitchen->feed($station))->resolve($request);

        // Token versi = hash isi TANPA `waiting_seconds` (satu-satunya field yang berubah
        // menurut jam), supaya layar yang tak ada perubahan nyata tetap menerima "unchanged".
        $version = md5(json_encode(array_map(fn ($t) => Arr::except($t, 'waiting_seconds'), $rows)));

        if ($request->filled('version') && hash_equals($version, (string) $request->query('version'))) {
            return response()->json(['unchanged' => true, 'version' => $version]);
        }

        return response()->json(['tickets' => $rows, 'version' => $version]);
    }

    public function show(Request $request, KitchenTicket $ticket): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canAccessMenu('kitchen') || $user?->canAccessMenu('floor'), 403, __('restaurant.forbidden'));

        return response()->json(new TicketResource($this->kitchen->ticketForPrint($ticket)));
    }

    public function updateStatus(TicketItemStatusRequest $request, KitchenTicketItem $ticketItem): JsonResponse
    {
        try {
            return $this->guarded(function () use ($request, $ticketItem) {
                $item = $this->kitchen->transition($ticketItem, $request->validated('status'), $request->user());

                return [
                    'id' => $item->id, 'status' => $item->status,
                    'preparing_at' => $item->preparing_at?->toIso8601String(),
                    'ready_at' => $item->ready_at?->toIso8601String(),
                    'served_at' => $item->served_at?->toIso8601String(),
                ];
            });
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }
}
