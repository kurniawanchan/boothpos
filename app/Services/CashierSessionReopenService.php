<?php

namespace App\Services;

use App\Exceptions\SessionReopenException;
use App\Models\CashierSession;
use App\Models\CashierSessionReopen;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 063 — SATU-SATUNYA penulis `cashier_session_reopens` dan satu-satunya jalur closed → open.
 *
 * Shift dikembalikan ke bentuk "terbuka" biasa (status open, closed_at dan tiga angka penutupan NULL),
 * sehingga rumus kas diharapkan langsung, atribusi kas, ringkasan, dan close() bekerja tanpa diubah dan
 * penutupan ulang menghitung ulang angkanya. Angka yang dikosongkan disimpan di baris riwayat (append-only).
 *
 * Sengaja TIDAK menyentuh order, pembayaran, stok, rekap, laporan, maupun settlement seller, dan tidak
 * peduli status event (spec FR-008/FR-009). Aturan "hanya Owner" diperiksa pemanggil (controller).
 */
class CashierSessionReopenService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function reopen(CashierSession $session, User $actor, string $reason): CashierSession
    {
        return DB::transaction(function () use ($session, $actor, $reason) {
            // Kunci baris shift lalu periksa ULANG statusnya: dua Owner yang menekan tombol bersamaan hanya
            // boleh menghasilkan satu pembukaan-kembali.
            $locked = CashierSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'closed') {
                throw new SessionReopenException(__('events_sessions.session_not_closed'), 'session_not_closed');
            }

            // Tak ada UNIQUE di DB untuk "satu shift terbuka per kasir", jadi baris kasir dikunci agar dua
            // pembukaan-kembali shift berbeda milik kasir yang sama tak lolos bersamaan.
            User::whereKey($locked->user_id)->lockForUpdate()->first();

            $hasOther = CashierSession::where('user_id', $locked->user_id)
                ->where('status', 'open')
                ->whereKeyNot($locked->id)
                ->exists();
            if ($hasOther) {
                throw new SessionReopenException(__('events_sessions.cashier_has_open_session'), 'cashier_has_open_session');
            }

            $previous = [
                'previous_closed_at' => $locked->closed_at,
                'previous_closing_cash' => $locked->closing_cash,
                'previous_expected_cash' => $locked->expected_cash,
                'previous_cash_difference' => $locked->cash_difference,
            ];

            CashierSessionReopen::create([
                'session_id' => $locked->id,
                'reopened_by' => $actor->id,
                'reopened_at' => now(),
                'reason' => $reason,
            ] + $previous);

            $locked->update([
                'status' => 'open',
                'closed_at' => null,
                'closing_cash' => null,
                'expected_cash' => null,
                'cash_difference' => null,
            ]);

            // Di DALAM transaksi yang sama (Constitution IV): rollback tak boleh meninggalkan log "palsu".
            $this->logger->log(
                $actor->id,
                'session_reopened',
                'CashierSession',
                $locked->id,
                $reason,
                [
                    'status' => 'closed',
                    'closed_at' => $previous['previous_closed_at']?->toIso8601String(),
                    'closing_cash' => $previous['previous_closing_cash'],
                    'expected_cash' => $previous['previous_expected_cash'],
                    'cash_difference' => $previous['previous_cash_difference'],
                ],
                ['status' => 'open', 'reason' => $reason],
            );

            return $locked->fresh();
        });
    }
}
