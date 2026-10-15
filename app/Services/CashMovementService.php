<?php

namespace App\Services;

use App\Exceptions\ShiftRuleException;
use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\User;
use App\Support\PosSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 048 / S2 — kas masuk/keluar di luar penjualan. Satu-satunya penulis `cash_movements`.
 *
 * - Hanya di shift TERBUKA milik pengguna yang mencatat (owner/admin tidak membuka laci orang lain
 *   lewat jalur ini). Baris sesi dikunci dulu, jadi kas keluar tidak bisa menyelinap di antara
 *   penutupan dan pencatatan angka.
 * - Kas keluar di atas `cash_out_approval_threshold` ditolak 409 `override_required`. Persetujuan
 *   (override) belum dibangun (S3/US4): sampai itu, kas besar hanya bisa keluar lewat jalur persetujuan
 *   yang belum ada, dan itu disengaja, bukan celah.
 * - Ditulis di dalam transaksi yang sama dengan catatan aktivitas (Constitution: jejak audit).
 */
class CashMovementService
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array{direction: string, reason_code: string, reason_note?: string|null, amount: float|string}  $data
     */
    public function record(CashierSession $session, User $user, array $data): CashMovement
    {
        return DB::transaction(function () use ($session, $user, $data) {
            $locked = CashierSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'open') {
                throw new ShiftRuleException(__('events_sessions.session_already_closed'), 'session_closed');
            }
            if ($locked->user_id !== $user->id) {
                throw new ShiftRuleException(__('events_sessions.cash_movement_not_owner'), 'session_not_owner', [], 403);
            }

            $direction = $data['direction'];
            $reason = $data['reason_code'];
            if (! in_array($reason, CashMovement::REASONS[$direction] ?? [], true)) {
                throw ValidationException::withMessages(['reason_code' => __('events_sessions.invalid_cash_reason')]);
            }

            $note = trim((string) ($data['reason_note'] ?? '')) ?: null;
            if ($reason === CashMovement::REASON_NEEDS_NOTE && $note === null) {
                throw ValidationException::withMessages(['reason_note' => __('events_sessions.reason_note_required')]);
            }

            $amount = round((float) $data['amount'], 2);
            $threshold = PosSettings::cashOutApprovalThreshold();
            if ($direction === CashMovement::OUT && $amount > $threshold) {
                throw new ShiftRuleException(
                    __('events_sessions.cash_out_needs_approval'),
                    'override_required',
                    ['threshold' => number_format($threshold, 2, '.', '')],
                );
            }

            $movement = new CashMovement([
                'session_id' => $locked->id,
                'direction' => $direction,
                'reason_code' => $reason,
                'reason_note' => $note,
                'amount' => $amount,
                'created_by' => $user->id,
            ]);
            // outlet_id tidak ada di $fillable (BelongsToOutlet): diambil dari shift, bukan dari permintaan.
            $movement->outlet_id = $locked->outlet_id;
            $movement->save();

            $this->activity->log(
                $user->id,
                'cash_movement_recorded',
                'cashier_session',
                $locked->id,
                'Kas '.($direction === CashMovement::IN ? 'masuk' : 'keluar').' '.number_format($amount, 0, ',', '.'),
                null,
                ['direction' => $direction, 'reason_code' => $reason, 'amount' => number_format($amount, 2, '.', '')],
            );

            return $movement;
        });
    }
}
