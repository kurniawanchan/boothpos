<?php

namespace App\Services;

use App\Exceptions\PinRejectedException;
use App\Exceptions\ShiftRuleException;
use App\Models\Override;
use App\Models\User;
use App\Support\OverrideSubject;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 048 / S3 — satu-satunya gerbang persetujuan di titik aksi (research D11, tasks T051–T052).
 *
 * - {@see issue()}: penyetuju memasukkan PIN-nya; server menghitung hash subjek (bidang body) dan mengembalikan
 *   token sekali pakai (120 dtk). PIN penyetuju memakai kunci 5-salah yang SAMA dengan login.
 * - {@see requireFor()}: dipanggil DI DALAM transaksi aksi. Pemegang izin aksi lewat tanpa token; yang lain
 *   wajib token yang cocok (aksi, bidang body, outlet, peminta), belum dipakai, dan belum kedaluwarsa. Token
 *   dikonsumsi di transaksi yang sama, sehingga bila aksi gagal, konsumsi ikut dibatalkan.
 */
final class OverrideGate
{
    /** Masa berlaku token (detik). */
    public const TTL_SECONDS = 120;

    /** aksi → izin yang membebaskan dari token. */
    public const ACTION_PERMISSION = [
        'void_line' => PermissionCatalog::ORDER_VOID,
        'cancel_order' => PermissionCatalog::ORDER_CANCEL,
        'cash_out' => PermissionCatalog::CASH_OUT_APPROVE,
        'variance' => PermissionCatalog::SHIFT_VARIANCE_APPROVE,
        'availability' => PermissionCatalog::MENU_AVAILABILITY_SET,
        'qris_verify' => PermissionCatalog::PAYMENT_QRIS_VERIFY,
        'qris_fail' => PermissionCatalog::PAYMENT_QRIS_VERIFY,
        'discount' => PermissionCatalog::ORDER_DISCOUNT_APPROVE,
        'reprint' => PermissionCatalog::ORDER_REPRINT_APPROVE,
        'refund' => PermissionCatalog::ORDER_REFUND,
    ];

    public function __construct(
        private PinAuthService $pins,
        private ActivityLogger $log,
    ) {}

    /**
     * Penerbitan token. Galat PIN dikembalikan dari transaksi lalu dilempar setelah commit, supaya hitungan
     * salah-PIN tidak ikut batal (pola login).
     *
     * @param  array<string, mixed>  $body  bidang body aksi (lihat OverrideSubject::FIELDS)
     * @return array{override_token: string, expires_at: string}
     *
     * @throws PinRejectedException 401 invalid_pin / 429 pin_locked
     * @throws ShiftRuleException 403 approver_not_permitted|outlet_forbidden, 409 same_person
     */
    public function issue(User $requester, string $action, array $body, int $approverId, string $pin, int $outletId): array
    {
        $permission = $this->permissionFor($action);
        if (! app(\App\Support\OutletContext::class)->isPermitted($outletId)) {
            throw new ShiftRuleException(__('outlets.forbidden'), 'outlet_forbidden', [], 403);
        }
        if ($approverId === $requester->id) {
            throw new ShiftRuleException(__('overrides.same_person'), 'same_person', [], 409);
        }

        $hash = OverrideSubject::hash($action, $body);

        $result = DB::transaction(function () use ($requester, $permission, $outletId, $hash, $approverId, $pin, $action) {
            $approver = User::query()->lockForUpdate()->find($approverId);
            if ($approver === null || ! $approver->is_active || ! $this->inOutlet($approver, $outletId) || ! $approver->hasPermission($permission)) {
                throw new ShiftRuleException(__('overrides.not_permitted'), 'approver_not_permitted', [], 403);
            }

            $pinError = $this->pins->verifyApproverPin($approver, $pin);
            if ($pinError !== null) {
                return $pinError;
            }

            $token = bin2hex(random_bytes(24));
            $issuedAt = now();
            $override = new Override([
                'action' => $action,
                'subject_hash' => $hash,
                'token_hash' => hash('sha256', $token),
                'requested_by' => $requester->id,
                'approved_by' => $approver->id,
                'issued_at' => $issuedAt,
                'expires_at' => $issuedAt->copy()->addSeconds(self::TTL_SECONDS),
            ]);
            // outlet_id tidak ada di $fillable (BelongsToOutlet): dari outlet aktif peminta yang sudah diuji.
            $override->outlet_id = $outletId;
            $override->save();

            $this->log->log(
                $requester->id,
                'override_issued',
                'override',
                $override->id,
                'Persetujuan '.$action.' diterbitkan oleh penyetuju',
                null,
                ['action' => $action, 'approved_by' => $approver->id],
            );

            return ['override_token' => $token, 'expires_at' => $override->expires_at->toIso8601String()];
        });

        if ($result instanceof PinRejectedException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Penjagaan aksi. Harus dipanggil di dalam DB::transaction aksi itu.
     *
     * @param  array<string, mixed>  $body  bidang body aksi versi server (sama dengan saat penerbitan)
     * @return int|null id persetujuan yang dikonsumsi; null bila peminta memegang izin aksi
     */
    public function requireFor(string $action, array $body, ?string $token, User $requester, int $outletId, ?string $ref = null, bool $bypassByPermission = true): ?int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('OverrideGate::requireFor harus dipanggil di dalam transaksi aksi, agar konsumsi token ikut dibatalkan bila aksi gagal.');
        }

        // 048 / S4: diskon di atas batas, cetak ulang, dan refund TIDAK pernah lolos karena izin peminta sendiri
        // (`$bypassByPermission = false`): batas milik peminta tidak boleh menyetujui dirinya sendiri.
        if ($bypassByPermission && $requester->hasPermission($this->permissionFor($action))) {
            return null;
        }

        if ($token === null || $token === '') {
            throw new ShiftRuleException(__('overrides.required'), 'override_required', ['action' => $action], 409);
        }

        $row = Override::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))
            ->lockForUpdate()
            ->first();

        $hash = OverrideSubject::hash($action, $body);
        if ($row === null
            || $row->action !== $action
            || ! hash_equals((string) $row->subject_hash, $hash)
            || (int) $row->outlet_id !== $outletId
            || (int) $row->requested_by !== $requester->id) {
            throw new ShiftRuleException(__('overrides.mismatch'), 'override_mismatch', ['action' => $action], 422);
        }

        if ($row->consumed_at !== null) {
            throw new ShiftRuleException(__('overrides.consumed'), 'override_expired', ['action' => $action], 409);
        }
        if ($row->expires_at->isPast()) {
            throw new ShiftRuleException(__('overrides.expired'), 'override_expired', ['action' => $action], 409);
        }

        $row->forceFill(['consumed_at' => now(), 'consumed_by_ref' => $ref !== null ? mb_substr($ref, 0, 120) : null])->save();

        $this->log->log(
            $requester->id,
            'override_consumed',
            'override',
            $row->id,
            'Persetujuan '.$action.' dipakai',
            null,
            ['action' => $action, 'ref' => $ref],
        );

        return (int) $row->id;
    }

    /**
     * Penyetuju yang memenuhi syarat untuk aksi di outlet itu: aktif, punya PIN, bukan peminta, dan memegang
     * izin aksi. Hanya id, nama, dan nama peran yang dikembalikan.
     *
     * @return Collection<int, User>
     */
    public function approvers(string $action, User $requester, int $outletId): Collection
    {
        $permission = $this->permissionFor($action);

        return User::query()
            ->with('role')
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->where('id', '!=', $requester->id)
            ->where(fn ($q) => $q->whereHas('outlets', fn ($o) => $o->whereKey($outletId))->orWhereHas('role', fn ($r) => $r->where('all_outlets', true)))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->hasPermission($permission))
            ->values();
    }

    private function inOutlet(User $user, int $outletId): bool
    {
        return $user->hasAllOutlets() || $user->outlets()->whereKey($outletId)->exists();
    }

    private function permissionFor(string $action): string
    {
        return self::ACTION_PERMISSION[$action] ?? throw new \InvalidArgumentException("Aksi persetujuan tidak dikenal: {$action}");
    }
}
