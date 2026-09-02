<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierSession;
use App\Models\Event;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierSessionController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $session = CashierSession::where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->first();

        if (! $session) {
            return response()->json(['message' => __('events_sessions.no_open_session')], 404);
        }

        return response()->json($session);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $hasOpenSession = CashierSession::where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenSession) {
            return response()->json([
                'message' => __('events_sessions.already_has_open_session'),
            ], 409);
        }

        $event = Event::findOrFail($validated['event_id']);
        if ($event->status !== 'active') {
            return response()->json(['message' => __('events_sessions.session_only_on_active_event')], 409);
        }

        $session = CashierSession::create([
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
            'opened_at' => now(),
            'opening_cash' => $validated['opening_cash'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'open',
        ]);

        return response()->json($session, 201);
    }

    public function close(Request $request, CashierSession $session): JsonResponse
    {
        // Otorisasi objek: sesi hanya boleh ditutup oleh pemiliknya atau
        // owner/admin. Ini mencegah IDOR — kasir A menutup paksa sesi
        // kasir B hanya dengan mengganti {id} di URL.
        if ($session->user_id !== $request->user()->id && ! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => __('events_sessions.not_authorized_close_session')], 403);
        }

        if ($session->status !== 'open') {
            return response()->json(['message' => __('events_sessions.session_already_closed')], 409);
        }

        $validated = $request->validate([
            'closing_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $cashPaymentsTotal = Payment::whereHas('order', fn ($q) => $q->where('session_id', $session->id))
            ->where('method', 'cash')
            ->where('verification', 'verified')
            ->sum('amount');

        $expectedCash = (float) $session->opening_cash + (float) $cashPaymentsTotal;
        $closingCash = (float) $validated['closing_cash'];

        $session->update([
            'closed_at' => now(),
            'closing_cash' => $closingCash,
            'expected_cash' => $expectedCash,
            'cash_difference' => round($closingCash - $expectedCash, 2),
            'status' => 'closed',
            'notes' => $validated['notes'] ?? $session->notes,
        ]);

        return response()->json($session->fresh());
    }

    public function summary(Request $request, CashierSession $session): JsonResponse
    {
        // Otorisasi objek — celah IDOR yang sama seperti disebutkan pada
        // close(): tanpa ini, kasir A bisa membaca total penjualan dan
        // rincian metode bayar sesi kasir B hanya dengan menebak {id}.
        if ($session->user_id !== $request->user()->id && ! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => __('events_sessions.not_authorized_view_summary')], 403);
        }

        $orders = $session->orders()->where('status', 'completed')->get();

        $byMethod = Payment::whereIn('order_id', $orders->pluck('id'))
            ->where('verification', 'verified')
            ->selectRaw('method, count(*) as count, sum(amount) as amount')
            ->groupBy('method')
            ->get();

        return response()->json([
            'session' => $session,
            'order_count' => $orders->count(),
            'total_sales' => $orders->sum('total_amount'),
            'by_method' => $byMethod,
        ]);
    }
}
