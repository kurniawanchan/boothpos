<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // 045: header shift menyebut outletnya.
        return response()->json($session->load('outlet:id,code,name'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            // 045 Stage 2 — outlet shift. Opsional: tanpa nilai, dipakai outlet tunggal yang berlaku (D7).
            'outlet_id' => ['nullable', 'integer'],
            // 006-purchase-order-and-ops (US5) — ADITIF, opsional. Server
            // merekonsiliasi jumlahnya terhadap opening_cash di atas
            // (bukan mempercayai keduanya independen dari klien,
            // Constitution IV) — lihat research.md R9.
            'opening_cash_entries' => ['sometimes', 'array'],
            'opening_cash_entries.*.outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'opening_cash_entries.*.amount' => ['required_with:opening_cash_entries', 'numeric', 'min:0'],
        ]);

        $hasOpenSession = CashierSession::where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenSession) {
            return response()->json([
                'message' => __('events_sessions.already_has_open_session'),
            ], 409);
        }

        // 045 Stage 2 — outlet shift ditentukan SERVER (D7): body hanya boleh memilih dari outlet yang
        // boleh dilihat pengguna; tidak pernah memperluasnya. Setelah ini, order/pembayaran/tiket di
        // shift ini mewarisi outletnya.
        $outletId = $this->shiftOutletFor($request, $validated['outlet_id'] ?? null);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $entries = $validated['opening_cash_entries'] ?? [];
        if ($entries) {
            $entriesSum = round(array_sum(array_column($entries, 'amount')), 2);
            if ($entriesSum !== round((float) $validated['opening_cash'], 2)) {
                return response()->json([
                    'message' => __('events_sessions.opening_cash_entries_mismatch'),
                    'errors' => ['opening_cash_entries' => [__('events_sessions.opening_cash_entries_mismatch')]],
                ], 422);
            }
        }

        $session = DB::transaction(function () use ($validated, $entries, $request, $outletId) {
            $session = new CashierSession([
                'user_id' => $request->user()->id,
                'opened_at' => now(),
                'opening_cash' => $validated['opening_cash'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'open',
            ]);
            // outlet_id tidak ada di $fillable: diset langsung dari keputusan server di atas.
            $session->outlet_id = $outletId;
            $session->save();

            foreach ($entries as $entry) {
                $session->openingCashEntries()->create([
                    'outlet_id' => $entry['outlet_id'] ?? null,
                    'amount' => $entry['amount'],
                    'created_at' => now(),
                ]);
            }

            return $session;
        });

        return response()->json($session->load(['openingCashEntries', 'outlet:id,code,name']), 201);
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

        // 028 (FR-011) — shift TIDAK boleh ditutup selama masih memegang tab
        // terbuka: uang tunai yang sudah diterima di tab itu ada di laci shift
        // ini, jadi menutup shift akan membuat selisih kas yang tak terjelaskan.
        // Tab harus dibayar, dibatalkan, atau diserahkan ke shift lain dulu.
        $openTabs = Order::query()
            ->where('session_id', $session->id)
            ->where('status', Order::STATUS_OPEN)
            ->with('table:id,name')
            ->orderBy('id')
            ->get();

        if ($openTabs->isNotEmpty()) {
            return response()->json([
                'message' => __('events_sessions.cannot_close_with_open_tabs'),
                'open_tabs' => $openTabs->map(fn (Order $o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'table' => $o->table?->name,
                    'tab_label' => $o->tab_label,
                ])->values(),
            ], 409);
        }

        // BUG YANG DITEMUKAN & DIPERBAIKI (026, code review) — dulu menjumlah
        // SEMUA pembayaran tunai terverifikasi apa adanya: `payments.amount` adalah
        // uang yang DISERAHKAN pembeli (kembalian disimpan terpisah di
        // `orders.change_amount`), dan order yang dibatalkan ikut terhitung.
        // Akibatnya kas yang diharapkan melonjak sebesar total kembalian + tunai
        // order batal tepat saat shift ditutup, tak cocok dengan panel shift di
        // halaman Sales (useSalesFilters: tunai diterima MINUS kembalian, tanpa
        // yang batal). Sekarang keduanya memakai rumus yang sama.
        // 028-partial-split-payment (research Decision 7) — tunai dihitung di shift
        // TEMPAT UANG DITERIMA (`payments.session_id`), bukan shift penjualan
        // aslinya: pembayaran susulan atas penjualan lama masuk ke shift saat ini,
        // dan shift yang sudah ditutup tak berubah. Kembalian tetap dikurangkan di
        // shift penjualannya (kembalian hanya terjadi saat checkout). Order batal
        // dikeluarkan seperti sebelumnya; pembayaran tanpa shift (session_id NULL, mis. pembayaran
        // pre-order lama) tetap di luar kas shift.
        $cashPaymentsTotal = Payment::where('session_id', $session->id)
            ->where('method', 'cash')
            ->where('verification', 'verified')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'voided'))
            ->sum('amount');

        $changeGiven = Order::where('session_id', $session->id)->where('status', '!=', 'voided')->sum('change_amount');

        $expectedCash = (float) $session->opening_cash + (float) $cashPaymentsTotal - (float) $changeGiven;
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

        // 028 — dikelompokkan menurut shift tempat pembayaran DITERIMA (bukan shift penjualan).
        $byMethod = Payment::where('session_id', $session->id)
            ->whereHas('order', fn ($q) => $q->where('status', 'completed'))
            ->where('verification', 'verified')
            ->selectRaw('method, count(*) as count, sum(amount) as amount')
            ->groupBy('method')
            ->get();

        return response()->json([
            'session' => $session->load('outlet:id,code,name'),
            'order_count' => $orders->count(),
            'total_sales' => $orders->sum('total_amount'),
            'by_method' => $byMethod,
            'opening_cash_entries' => $session->openingCashEntries()->with('outlet:id,name')->get()->map(fn ($e) => [
                'outlet_id' => $e->outlet_id,
                'outlet_name' => $e->outlet?->name,
                'amount' => number_format((float) $e->amount, 2, '.', ''),
            ]),
        ]);
    }

    /**
     * 045 Stage 2 — outlet untuk shift baru. Mengembalikan id outlet, atau respons galat:
     *  - outlet dipilih tapi di luar daftar yang boleh dilihat → 403 outlet_forbidden;
     *  - tidak dipilih, dan tepat satu yang berlaku → outlet itu;
     *  - tidak dipilih, lebih dari satu → 422 errors.outlet_id;
     *  - pengguna tanpa penugasan (bukan admin) → 409 outlet_unassigned;
     *  - admin tanpa outlet sama sekali (instalasi baru) → outlet bawaan.
     */
    private function shiftOutletFor(Request $request, ?int $requested): int|JsonResponse
    {
        $context = app(\App\Support\OutletContext::class);

        if ($requested !== null) {
            if (! $context->isPermitted($requested)) {
                return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
            }

            return $requested;
        }

        $single = $context->singleId();
        if ($single !== null) {
            return $single;
        }

        // Instalasi baru tanpa outlet sama sekali: shift pertama membuat outlet bawaan (berlaku untuk
        // siapa pun yang login, bukan hanya admin), lalu pengguna ini ikut ditugaskan ke sana.
        if ($context->effectiveIds() === [] && ! \App\Models\Outlet::query()->where('is_active', true)->exists()) {
            return \App\Support\OutletDefaults::forNewRecord();
        }

        if (count($context->effectiveIds()) > 1) {
            return response()->json([
                'message' => __('outlets.choose_outlet'),
                'errors' => ['outlet_id' => [__('outlets.choose_outlet')]],
            ], 422);
        }

        if (! $request->user()->hasAllOutlets()) {
            return response()->json(['message' => __('outlets.unassigned'), 'code' => 'outlet_unassigned'], 409);
        }

        return \App\Support\OutletDefaults::forNewRecord();
    }
}
