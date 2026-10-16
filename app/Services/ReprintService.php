<?php

namespace App\Services;

use App\Exceptions\ShiftRuleException;
use App\Models\Order;
use App\Models\User;
use App\Support\PosSettings;
use Illuminate\Support\Facades\DB;

/**
 * 048 / T060 — cetak ulang struk. Salinan asli dihitung sebagai salinan 1; cetak ulang pertama (copy 2)
 * gratis menurut `reprint_free_copies`, cetak ulang berikutnya butuh persetujuan (`reprint`).
 *
 * `copy_no` yang dikirim klien harus sama dengan salinan berikutnya di server; bila sudah berubah (ada
 * cetak lain di antara), permintaan ditolak 409 `reprint_copy_changed` — persetujuan untuk nomor lama tidak
 * boleh dipakai untuk salinan baru.
 */
class ReprintService
{
    public function __construct(
        private OverrideGate $gate,
        private ActivityLogger $activity,
    ) {}

    /** @return array{order_id: int, copy_no: int, free: bool, receipt_no: string|null} */
    public function reprint(Order $order, User $user, int $copyNo, ?string $overrideToken): array
    {
        return DB::transaction(function () use ($order, $user, $copyNo, $overrideToken) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Order::STATUS_COMPLETED) {
                throw new ShiftRuleException(__('overrides.reprint_not_completed'), 'order_not_completed', [], 409);
            }

            $expected = $locked->receipt_copies + 2; // salinan asli = 1, cetak ulang pertama = 2
            if ($copyNo !== $expected) {
                throw new ShiftRuleException(__('overrides.reprint_copy_changed'), 'reprint_copy_changed', ['copy_no' => $expected], 409);
            }

            $reprintIndex = $locked->receipt_copies + 1;
            $free = $reprintIndex <= PosSettings::reprintFreeCopies();
            if (! $free) {
                $this->gate->requireFor(
                    'reprint',
                    ['order_id' => $locked->id, 'copy_no' => $copyNo],
                    $overrideToken,
                    $user,
                    (int) $locked->outlet_id,
                    "order:{$locked->id}:copy:{$copyNo}",
                    bypassByPermission: false,
                );
            }

            $locked->forceFill(['receipt_copies' => $locked->receipt_copies + 1])->save();

            $this->activity->log(
                $user->id,
                'receipt_reprinted',
                'order',
                $locked->id,
                'Cetak ulang struk salinan '.$copyNo.($free ? ' (gratis)' : ' (dengan persetujuan)'),
                null,
                ['copy_no' => $copyNo, 'free' => $free],
            );

            return ['order_id' => $locked->id, 'copy_no' => $copyNo, 'free' => $free, 'receipt_no' => $locked->receipt_no];
        });
    }
}
