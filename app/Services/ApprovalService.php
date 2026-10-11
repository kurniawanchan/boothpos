<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\MenuChange;
use App\Models\ReasonCode;
use App\Models\User;
use App\Support\MenuSettings;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — SATU jalur persetujuan (rancangan 041, dipersempit ke `menu_price`).
 * Aturan yang ditegakkan DI SINI, bukan di UI: yang memutuskan harus pemegang izin `menu.price.approve`
 * DAN bukan peminta; penolakan butuh alasan; keputusan bersifat final dan dicatat di transaksi yang sama
 * (Konstitusi IV). Ambang selisih (% dari harga dasar) dibaca dari pengaturan.
 */
class ApprovalService
{
    public function __construct(private ActivityLogger $log) {}

    /** Selisih harga (% dari harga lama) melebihi ambang? Harga lama ≤ 0 tidak pernah butuh persetujuan. */
    public function exceedsThreshold(float $oldPrice, float $newPrice): bool
    {
        return $oldPrice > 0 && $this->differencePct($oldPrice, $newPrice) > MenuSettings::approvalThresholdPct();
    }

    public function differencePct(float $oldPrice, float $newPrice): float
    {
        return $oldPrice > 0 ? round(abs($newPrice - $oldPrice) / $oldPrice * 100, 2) : 0.0;
    }

    /** Membuat permintaan persetujuan untuk sebuah perubahan harga (dipanggil PriceService di transaksinya). */
    public function requestForChange(MenuChange $change, User $by, float $oldPrice, float $newPrice): Approval
    {
        $approval = Approval::create([
            'kind' => Approval::KIND_MENU_PRICE, 'subject_type' => 'menu_change', 'subject_id' => $change->id,
            'requested_by' => $by->id, 'decision' => Approval::PENDING,
            'difference_pct' => $this->differencePct($oldPrice, $newPrice), 'amount' => round(abs($newPrice - $oldPrice), 2),
        ]);

        $this->log->log(
            userId: $by->id, action: 'approval_requested', entityType: 'Approval', entityId: $approval->id,
            description: "Persetujuan harga diminta (selisih {$approval->difference_pct}%).",
            newValues: ['change_id' => $change->id, 'old_price' => $oldPrice, 'new_price' => $newPrice],
        );

        return $approval;
    }

    /** @throws ValidationException 403-setara: bukan pemegang izin; 409: peminta menyetujui sendiri / sudah diputuskan */
    public function approve(Approval $approval, User $by): Approval
    {
        return DB::transaction(function () use ($approval, $by) {
            $approval = $this->lockPending($approval, $by);

            $approval->update(['decision' => Approval::APPROVED, 'decided_by' => $by->id, 'decided_at' => now()]);
            MenuChange::where('id', $approval->subject_id)->where('status', MenuChange::AWAITING_APPROVAL)->update(['status' => MenuChange::APPROVED]);

            $this->log->log(userId: $by->id, action: 'approval_approved', entityType: 'Approval', entityId: $approval->id, description: 'Perubahan harga disetujui.');

            return $approval->fresh();
        });
    }

    /** @throws ValidationException */
    public function reject(Approval $approval, User $by, int $reasonCodeId, ?string $note = null): Approval
    {
        $reason = ReasonCode::query()->active()->where('category', ReasonCode::APPROVAL_REJECT)->find($reasonCodeId);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason_code_id' => __('products.reason_invalid')]);
        }

        return DB::transaction(function () use ($approval, $by, $reason, $note) {
            $approval = $this->lockPending($approval, $by);

            $approval->update(['decision' => Approval::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'reason_code_id' => $reason->id, 'note' => $note]);
            MenuChange::where('id', $approval->subject_id)->where('status', MenuChange::AWAITING_APPROVAL)->update(['status' => MenuChange::REJECTED]);

            $this->log->log(
                userId: $by->id, action: 'approval_rejected', entityType: 'Approval', entityId: $approval->id,
                description: "Perubahan harga ditolak ({$reason->code}).", newValues: ['reason' => $reason->code, 'note' => $note],
            );

            return $approval->fresh();
        });
    }

    private function lockPending(Approval $approval, User $by): Approval
    {
        if (! $by->hasPermission(PermissionCatalog::PRICE_APPROVE)) {
            throw ValidationException::withMessages(['approval' => __('products.approval_not_allowed')])->status(403);
        }

        $approval = Approval::query()->lockForUpdate()->findOrFail($approval->id);

        if ($approval->decision !== Approval::PENDING) {
            throw ValidationException::withMessages(['approval' => __('products.approval_already_decided')])->status(409);
        }
        if ($approval->requested_by !== null && $approval->requested_by === $by->id) {
            throw ValidationException::withMessages(['approval' => __('products.self_approval')])->status(409);
        }

        return $approval;
    }
}
