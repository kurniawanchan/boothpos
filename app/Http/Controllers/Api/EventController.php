<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Event::class);
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $events = Event::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('start_date')
            ->paginate($perPage);

        return response()->json([
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(), 'per_page' => $events->perPage(),
                'total' => $events->total(), 'last_page' => $events->lastPage(),
            ],
        ]);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        // 'status' diisi eksplisit di sini, bukan dibiarkan jatuh ke
        // default kolom database — Eloquent tidak membaca balik nilai
        // default DB ke instance model setelah insert, jadi tanpa baris
        // ini $event->status akan null di response walau baris DB-nya
        // sudah benar 'draft' (bug yang ditemukan saat bootstrap).
        $event = Event::create([...$request->validated(), 'status' => 'draft']);

        return response()->json($event, 201);
    }

    public function show(Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        return response()->json($event);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $event->update($request->validated());
        return response()->json($event->fresh());
    }

    public function updateStatus(Request $request, Event $event): JsonResponse
    {
        $this->authorize('transitionStatus', $event);

        $validated = $request->validate([
            'status' => ['required', 'in:active,closed,cancelled'],
        ]);

        $newStatus = $validated['status'];

        if (! $event->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => __('events_sessions.invalid_status_transition', ['from' => $event->status, 'to' => $newStatus]),
            ], 409);
        }

        if ($newStatus === 'closed') {
            $openSessions = $event->cashierSessions()->where('status', 'open')->count();
            if ($openSessions > 0) {
                return response()->json([
                    'message' => __('events_sessions.cannot_close_open_sessions', ['count' => $openSessions]),
                ], 409);
            }
        }

        $event->update(['status' => $newStatus]);

        if ($newStatus === 'closed') {
            // Diwire penuh setelah modul Order/SettlementService dibangun
            // (lihat app/Services/SettlementService.php). Dipanggil di sini
            // lewat container agar EventController tidak perlu tahu detail
            // agregasi order_items.
            app(\App\Services\SettlementService::class)->recalculateForEvent($event);
        }

        return response()->json($event->fresh());
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        // Guard — event yang pernah punya order/pre-order (status apa pun)
        // tidak boleh dihapus, mengikuti pola guard hapus Artist/Category
        // (lihat plan 009-ui-ux-refinements R6). CATATAN: Order/Preorder
        // TIDAK memakai trait SoftDeletes (dikonfirmasi di kedua model dan
        // migrasinya, tidak ada kolom deleted_at) — ->withTrashed() akan
        // fatal error (BadMethodCallException) bila dipanggil di sini,
        // jadi 'any status' saja sudah setara 'any status, any-trashed'
        // untuk kedua model ini.
        $hasTransactions = $event->orders()->exists() || $event->preorders()->exists();

        if ($hasTransactions) {
            return response()->json([
                'message' => __('events_sessions.event_delete_has_transactions'),
            ], 409);
        }

        // F13.4 — hapus data adalah tindakan sensitif; log ditulis DI DALAM
        // transaksi yang sama dengan delete-nya (atomik, ikut rollback bersama).
        DB::transaction(function () use ($event, $request) {
            $snapshot = $event->only($event->getFillable());

            $event->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Event',
                entityId: $event->id,
                description: "Menghapus event {$event->name}.",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }
}
