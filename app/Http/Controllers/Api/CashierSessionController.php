<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierSession;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Services\ActivityLogger;
use App\Services\ShiftReportService;
use App\Support\CashDenominations;
use App\Support\PosSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // 048 — kas awal boleh diberikan sebagai pecahan (jumlahnya diturunkan server); `opening_cash` lama tetap diterima.
            'opening_cash' => ['nullable', 'numeric', 'min:0'],
            'opening_denominations' => ['nullable', 'array', 'max:10'],
            'opening_denominations.*.denomination' => ['required', 'integer', Rule::in(CashDenominations::VALUES)],
            'opening_denominations.*.qty' => ['required', 'integer', 'min:0', 'max:'.CashDenominations::MAX_QTY],
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

        // 048 — rincian pecahan: jumlahnya dihitung server. Bila `opening_cash` juga dikirim, harus sama.
        if (! isset($validated['opening_denominations']) && ! isset($validated['opening_cash'])) {
            throw ValidationException::withMessages(['opening_cash' => __('events_sessions.count_or_closing_required')]);
        }
        $denominations = isset($validated['opening_denominations'])
            ? CashDenominations::normalise($validated['opening_denominations'], 'opening_denominations')
            : null;
        $openingCash = $denominations !== null ? $denominations['total'] : round((float) $validated['opening_cash'], 2);
        if ($denominations !== null && isset($validated['opening_cash']) && round((float) $validated['opening_cash'], 2) !== $openingCash) {
            return response()->json([
                'message' => __('events_sessions.opening_denominations_mismatch'),
                'errors' => ['opening_cash' => [__('events_sessions.opening_denominations_mismatch')]],
            ], 422);
        }

        // 048 — terminal pembuka (X-Device-Id): harus terminal aktif milik outlet shift ini; tanpa header (SPA web) tetap jalan.
        $device = null;
        if (! empty($request->header('X-Device-Id'))) {
            $device = Device::query()->whereNull('revoked_at')->where('outlet_id', $outletId)->find((int) $request->header('X-Device-Id'));
            if ($device === null) {
                throw ValidationException::withMessages(['device_id' => __('devices.invalid_device')]);
            }
        }

        $entries = $validated['opening_cash_entries'] ?? [];
        if ($entries) {
            $entriesSum = round(array_sum(array_column($entries, 'amount')), 2);
            if ($entriesSum !== $openingCash) {
                return response()->json([
                    'message' => __('events_sessions.opening_cash_entries_mismatch'),
                    'errors' => ['opening_cash_entries' => [__('events_sessions.opening_cash_entries_mismatch')]],
                ], 422);
            }
        }

        $session = DB::transaction(function () use ($validated, $entries, $request, $outletId, $openingCash, $denominations, $device) {
            $session = new CashierSession([
                'user_id' => $request->user()->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'notes' => $validated['notes'] ?? null,
                'status' => 'open',
            ]);
            // outlet_id dan device_id tidak ada di $fillable: diset langsung dari keputusan server di atas.
            $session->outlet_id = $outletId;
            $session->device_id = $device?->id;
            $session->save();

            foreach ($denominations['rows'] ?? [] as $row) {
                if ($row['qty'] > 0) {
                    $session->openingDenominations()->create($row);
                }
            }

            foreach ($entries as $entry) {
                $session->openingCashEntries()->create([
                    'outlet_id' => $entry['outlet_id'] ?? null,
                    'amount' => $entry['amount'],
                    'created_at' => now(),
                ]);
            }

            return $session;
        });

        return response()->json($session->load(['openingCashEntries', 'openingDenominations', 'outlet:id,code,name']), 201);
    }

    public function close(Request $request, CashierSession $session, ShiftReportService $report): JsonResponse
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
            // 048 — salah satu dari dua: hitungan buta per pecahan (protokol Android) ATAU angka tunggal lama.
            // Dicek manual di bawah, bukan `required_without`: array kosong (tanpa uang sama sekali) dianggap "tidak ada" oleh aturan itu.
            'closing_cash' => ['nullable', 'numeric', 'min:0'],
            'counted_by_denomination' => ['nullable', 'array', 'max:10'],
            'counted_by_denomination.*.denomination' => ['required', 'integer', Rule::in(CashDenominations::VALUES)],
            'counted_by_denomination.*.qty' => ['required', 'integer', 'min:0', 'max:'.CashDenominations::MAX_QTY],
            'edc_batch_total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            // 048 / S3 — persetujuan selisih di atas toleransi (token dari POST /overrides, action variance).
            'variance_override_token' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
        ]);

        // 048 — hitungan buta: jumlah dihitung SERVER dari pecahan. Hanya protokol ini yang memeriksa
        // QRIS tertunda dan toleransi selisih; penutupan lama (`closing_cash`) tetap berperilaku seperti
        // sebelumnya, karena SPA web belum punya jalur persetujuan untuk 409 itu (dibangun di S3/US4).
        $blind = is_array($validated['counted_by_denomination'] ?? null);
        if (! $blind && ($validated['closing_cash'] ?? null) === null) {
            throw ValidationException::withMessages(['closing_cash' => __('events_sessions.count_or_closing_required')]);
        }
        $counted = $blind ? CashDenominations::normalise($validated['counted_by_denomination']) : null;
        $closingCash = $blind ? $counted['total'] : round((float) $validated['closing_cash'], 2);
        $edcBatch = isset($validated['edc_batch_total']) ? round((float) $validated['edc_batch_total'], 2) : null;

        return DB::transaction(function () use ($request, $session, $validated, $report, $blind, $counted, $closingCash, $edcBatch) {
            // Kunci baris sesi: penutupan dan kas keluar yang berjalan bersamaan harus berurutan.
            $locked = CashierSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                return response()->json(['message' => __('events_sessions.session_already_closed')], 409);
            }

            // 028 (FR-011) — shift TIDAK boleh ditutup selama masih memegang tab
            // terbuka: uang tunai yang sudah diterima di tab itu ada di laci shift
            // ini, jadi menutup shift akan membuat selisih kas yang tak terjelaskan.
            // Tab harus dibayar, dibatalkan, atau diserahkan ke shift lain dulu.
            $openTabs = Order::query()
                ->where('session_id', $locked->id)
                ->where('status', Order::STATUS_OPEN)
                ->with('table:id,name')
                ->orderBy('id')
                ->get();

            if ($openTabs->isNotEmpty()) {
                return response()->json([
                    'message' => __('events_sessions.cannot_close_with_open_tabs'),
                    'code' => 'open_tabs',
                    'open_tabs' => $openTabs->map(fn (Order $o) => [
                        'id' => $o->id,
                        'order_number' => $o->order_number,
                        'table' => $o->table?->name,
                        'tab_label' => $o->tab_label,
                    ])->values(),
                ], 409);
            }

            // 048 — QRIS yang belum dicek tidak boleh ikut tertutup diam-diam: uangnya belum pasti masuk.
            if ($blind) {
                $pendingQris = Payment::where('session_id', $locked->id)
                    ->where('method', 'qr_ewallet')
                    ->where('verification', 'pending')
                    ->whereHas('order', fn ($q) => $q->where('status', '!=', 'voided'))
                    ->count();

                if ($pendingQris > 0) {
                    return response()->json([
                        'message' => __('events_sessions.pending_qris_payments', ['count' => $pendingQris]),
                        'code' => 'pending_payments',
                        'count' => $pendingQris,
                    ], 409);
                }
            }

            // BUG YANG DITEMUKAN & DIPERBAIKI (026, code review) — dulu menjumlah
            // SEMUA pembayaran tunai terverifikasi apa adanya: `payments.amount` adalah
            // uang yang DISERAHKAN pembeli (kembalian disimpan terpisah di
            // `orders.change_amount`), dan order yang dibatalkan ikut terhitung.
            // Akibatnya kas yang diharapkan melonjak sebesar total kembalian + tunai
            // order batal tepat saat shift ditutup, tak cocok dengan panel shift di
            // halaman Sales. Rumusnya sekarang satu tempat: ShiftReportService (sama
            // dengan summary dan X-report), dan kas masuk/keluar ikut dihitung.
            $expectedCash = $report->expectedCash($locked);
            $variance = round($closingCash - $expectedCash, 2);
            $tolerance = PosSettings::varianceTolerance();

            // 048 / S3 — selisih di atas toleransi: persetujuan `variance` di titik aksi, terikat ke hitungan (body),
            // dikonsumsi dalam transaksi tutup ini. Pemegang shift.variance.approve lewat tanpa token.
            $varianceApprovalId = null;
            if ($blind && abs($variance) > $tolerance) {
                $varianceApprovalId = app(\App\Services\OverrideGate::class)->requireFor(
                    'variance',
                    ['session_id' => $locked->id, 'counted_by_denomination' => $validated['counted_by_denomination']],
                    $validated['variance_override_token'] ?? null,
                    $request->user(),
                    (int) $locked->outlet_id,
                    "session:{$locked->id}",
                );
            }

            // Z-report: salinan angka saat tutup, disimpan supaya laporan lama tak berubah bila data berubah.
            $zReport = $report->figures($locked) + [
                'closed_at' => now()->toIso8601String(),
                'counted_total' => $blind ? $report->money($closingCash) : null,
                'variance' => $report->money($variance),
                'variance_tolerance' => $report->money($tolerance),
                'edc_batch_total' => $edcBatch !== null ? $report->money($edcBatch) : null,
                'counted_by_denomination' => $counted['rows'] ?? null,
            ];

            $locked->update([
                'closed_at' => now(),
                'closing_cash' => $closingCash,
                'expected_cash' => $expectedCash,
                'cash_difference' => $variance,
                'status' => 'closed',
                'notes' => $validated['notes'] ?? $locked->notes,
                'counted_by_denomination' => $counted['rows'] ?? null,
                'counted_total' => $blind ? $closingCash : null,
                'edc_batch_total' => $edcBatch,
                'variance' => $blind ? $variance : null,
                'variance_override_id' => $varianceApprovalId,
                'z_report' => $zReport,
            ]);

            app(ActivityLogger::class)->log(
                $request->user()->id,
                'session_closed',
                'cashier_session',
                $locked->id,
                'Shift ditutup, selisih '.number_format($variance, 2, ',', '.'),
                null,
                ['expected_cash' => number_format($expectedCash, 2, '.', ''), 'closing_cash' => number_format($closingCash, 2, '.', ''), 'variance' => number_format($variance, 2, '.', '')],
            );

            return response()->json($locked->fresh());
        });
    }

    /** 048 — X-report: angka shift saat ini, dihitung langsung dan TIDAK disimpan (tidak menutup shift). */
    public function xReport(Request $request, CashierSession $session, ShiftReportService $report): JsonResponse
    {
        if ($session->user_id !== $request->user()->id && ! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => __('events_sessions.not_authorized_view_summary')], 403);
        }

        // 048 — tanpa `expected_cash` (blind count): lihat catatan di summary().
        return response()->json(['generated_at' => now()->toIso8601String()] + Arr::except($report->figures($session), ['expected_cash']));
    }

    public function summary(Request $request, CashierSession $session, ShiftReportService $report): JsonResponse
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

        // 048 — angka kas dari ShiftReportService (satu rumus untuk summary, X-report, dan penutupan).
        $figures = $report->figures($session);

        return response()->json([
            'session' => $session->load('outlet:id,code,name'),
            'order_count' => $orders->count(),
            'total_sales' => $orders->sum('total_amount'),
            'by_method' => $byMethod,
            // 048 — `expected_cash` SENGAJA tidak dikirim di sini: kasir melihat ringkasan sebelum menghitung,
            // dan angka seharusnya baru boleh tampil setelah hitungan buta dikirim (respons tutup, Z-report).
            'cash_in' => $figures['cash_in'],
            'cash_out' => $figures['cash_out'],
            'change_given' => $figures['change_given'],
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
