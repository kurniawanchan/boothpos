<?php

namespace App\Services;

use App\Exceptions\ShiftRuleException;
use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 048 / T060 — refund penuh order yang sudah lunas.
 *
 * Batasan (ASUMSI, lihat laporan):
 *  - hanya order `completed`, sudah lunas penuh, belum pernah direfund, dan tidak punya QRIS tertunda;
 *  - SELALU butuh persetujuan (`refund`), tanpa bypass izin peminta; subjek = id order + alasan;
 *  - refund dicatat di SHIFT BERJALAN pengguna yang meminta (kas keluar `refund`), bukan di shift asal:
 *    laci tempat uang keluar adalah laci hari ini. Shift asal dan laporan lama TIDAK diubah.
 *
 * Yang TIDAK terjadi: total, paid_amount, change_amount, status, dan payments order asal tidak diubah.
 */
class RefundService
{
    public function __construct(
        private OverrideGate $gate,
        private StockService $stock,
        private ActivityLogger $activity,
    ) {}

    /** @return array{order_id: int, refunded_at: string, refund_total: string, cash_returned: string, session_id: int} */
    public function refund(Order $order, User $user, string $reasonCode, ?string $reason, ?string $overrideToken): array
    {
        return DB::transaction(function () use ($order, $user, $reasonCode, $reason, $overrideToken) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Order::STATUS_COMPLETED) {
                throw new ShiftRuleException(__('overrides.refund_not_refundable'), 'order_not_refundable', [], 409);
            }
            if ($locked->refunded_at !== null) {
                throw new ShiftRuleException(__('overrides.refund_already'), 'order_already_refunded', [], 409);
            }

            $total = round((float) $locked->total_amount, 2);
            if (round((float) $locked->paid_amount, 2) < $total) {
                throw new ShiftRuleException(__('overrides.refund_not_fully_paid'), 'order_not_fully_paid', [], 409);
            }
            if (Payment::where('order_id', $locked->id)->where('method', 'qr_ewallet')->where('verification', 'pending')->exists()) {
                throw new ShiftRuleException(__('overrides.refund_pending_qris'), 'pending_payments', [], 409);
            }

            // 065 — refund tercatat di shift SUMBER permintaan ini (tablet atau web), bukan shift sumber lain.
            $session = \App\Support\ShiftSource::openFor($user, lock: true);
            if ($session === null) {
                throw new ShiftRuleException(__('events_sessions.no_open_session'), 'shift_required', [], 409);
            }

            $overrideId = $this->gate->requireFor(
                'refund',
                ['order_id' => $locked->id, 'reason_code' => $reasonCode, 'reason' => $reason],
                $overrideToken,
                $user,
                (int) $locked->outlet_id,
                "order:{$locked->id}",
                bypassByPermission: false,
            );

            // Kas yang kembali = tunai ter-verifikasi untuk order ini dikurangi kembalian, dibatasi total.
            // Bagian non-tunai (EDC, QRIS, transfer) dikembalikan di luar laci dan tidak tercatat sebagai kas.
            $cashReceived = (float) Payment::where('order_id', $locked->id)->where('method', 'cash')->where('verification', 'verified')->sum('amount');
            $cashNet = max(0.0, round($cashReceived - (float) $locked->change_amount, 2));
            $cashReturn = round(min($cashNet, $total), 2);

            if ($cashReturn > 0) {
                // Sengaja TIDAK lewat CashMovementService: alasan `refund` tidak boleh bisa dipilih kasir
                // sebagai kas keluar biasa (itu akan melewati persetujuan). Persetujuan refund sudah dicatat.
                $movement = new CashMovement([
                    'session_id' => $session->id,
                    'direction' => CashMovement::OUT,
                    'reason_code' => 'refund',
                    'reason_note' => 'Refund '.$locked->order_number,
                    'amount' => $cashReturn,
                    'created_by' => $user->id,
                ]);
                $movement->outlet_id = $session->outlet_id;
                $movement->override_id = $overrideId;
                $movement->save();
            }

            foreach ($locked->items()->get() as $item) {
                $variant = ProductVariant::withoutGlobalScopes()->find($item->variant_id);
                if ($variant !== null) {
                    // Argumen bernama: uji invarian stok memeriksa outlet secara eksplisit (`outletId:`).
                    // 087 US6 — mengikuti snapshot sumber stok baris (bukan pengaturan produk saat ini).
                    $this->stock->applyLineMovementIfTracked(
                        line: $item,
                        variant: $variant,
                        outletId: (int) $locked->outlet_id,
                        type: 'return',
                        qtyChange: (int) $item->qty,
                        referenceType: 'order_item',
                        referenceId: $item->id,
                        reason: 'Refund '.$locked->order_number,
                        userId: $user->id,
                    );
                }
            }

            // 087 — bahan pilihan modifier kembali sebesar potongan bersih yang tercatat (research D7).
            app(ModifierMaterialConsumption::class)->restoreOrder($locked, $user);

            // Tanggal bisnis refund (zona toko): refund mengurangi penjualan PADA HARI INI, bukan hari penjualan.
            $refundedAt = now();
            $locked->forceFill([
                'refunded_at' => $refundedAt,
                'refund_business_date' => \App\Support\BusinessDay::dateFor($refundedAt),
                'refunded_by' => $user->id,
                'refund_reason_code' => $reasonCode,
            ])->save();

            $this->activity->log(
                $user->id,
                'order_refunded',
                'order',
                $locked->id,
                'Refund penuh '.$locked->order_number,
                null,
                ['refund_total' => number_format($total, 2, '.', ''), 'cash_returned' => number_format($cashReturn, 2, '.', ''), 'reason_code' => $reasonCode, 'session_id' => $session->id],
            );

            return [
                'order_id' => $locked->id,
                'refunded_at' => $locked->refunded_at->toIso8601String(),
                'refund_total' => number_format($total, 2, '.', ''),
                'cash_returned' => number_format($cashReturn, 2, '.', ''),
                'session_id' => $session->id,
            ];
        });
    }
}
