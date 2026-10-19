<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 048 / T058 — diskon tab, tingkat order atau tingkat baris.
 *
 * - Persen dihitung SERVER dari basis yang sebenarnya (subtotal untuk order, harga×qty untuk baris);
 *   klien tidak pernah mengirim jumlah rupiah.
 * - Di atas batas peran (`Role::discountLimit()`) butuh persetujuan orang lain (`requireFor` tanpa bypass):
 *   token terikat ke tab, cakupan, baris, persen, dan alasan — bukan ke tab/baris lain.
 * - Diskon dikurangkan SEBELUM pajak: `TabService::recalculate()` memakai TaxCalculator atas (subtotal − diskon).
 * - DELETE hanya menghapus diskon tingkat order dan tidak butuh token: itu menaikkan tagihan, bukan menguranginya.
 */
class DiscountService
{
    public function __construct(
        private TabService $tabs,
        private OverrideGate $gate,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  array{percent: float|string, reason_code: string, scope: string, line_id?: int|null, override_token?: string|null}  $data
     */
    public function apply(Order $tab, User $user, array $data): Order
    {
        $percent = round((float) $data['percent'], 2);
        $scope = (string) $data['scope'];
        $lineId = $scope === 'line' ? (int) $data['line_id'] : null;
        $reasonCode = (string) $data['reason_code'];

        return DB::transaction(function () use ($tab, $user, $data, $percent, $scope, $lineId, $reasonCode) {
            $order = $this->tabs->lockOpen($tab);
            $this->tabs->assertSessionOpen(CashierSession::findOrFail($order->session_id));

            $line = null;
            if ($scope === 'line') {
                // Baris milik tab lain tidak ditemukan (404) — sama dengan aturan baris tab lainnya.
                $line = $order->items()->whereKey($lineId)->firstOrFail();
                $base = round((float) $line->sell_price * $line->qty, 2);
            } else {
                $base = round((float) $order->items()->sum('line_total'), 2);
            }
            $amount = round($base * $percent / 100, 2);

            $limit = $user->role?->discountLimit() ?? 0.0;
            if ($percent > $limit) {
                $this->gate->requireFor(
                    'discount',
                    ['tab_id' => $order->id, 'scope' => $scope, 'line_id' => $lineId, 'percent' => $percent, 'reason_code' => $reasonCode],
                    $data['override_token'] ?? null,
                    $user,
                    (int) $order->outlet_id,
                    "order:{$order->id}",
                    bypassByPermission: false,
                );
            }

            if ($line !== null) {
                // Diskon baris menggantikan diskon baris sebelumnya; subtotal dihitung ulang dari baris.
                $line->forceFill([
                    'discount_amount' => $amount, 'line_total' => round($base - $amount, 2),
                    // 057 / US8 — persen dan alasan disimpan di baris (bukan hanya di activity log).
                    'discount_percent' => $percent, 'discount_reason_code' => $reasonCode,
                ])->save();
                $this->tabs->recalculate($order);
            } else {
                // Diskon order disimpan sebagai jumlah rupiah pada saat diterapkan (tidak ikut berubah bila baris berubah).
                // 057 / US8 — persen dan alasan diskon order disimpan di order, dalam transaksi yang sama.
                $order->forceFill(['discount_percent' => $percent, 'discount_reason_code' => $reasonCode])->save();
                $this->tabs->recalculate($order, $amount);
            }

            $this->activity->log(
                $user->id,
                'discount_applied',
                'order',
                $order->id,
                'Diskon '.number_format($percent, 2, ',', '.').'% ('.$scope.')',
                null,
                ['scope' => $scope, 'line_id' => $lineId, 'percent' => number_format($percent, 2, '.', ''), 'amount' => number_format($amount, 2, '.', ''), 'reason_code' => $reasonCode],
            );

            return $this->tabs->loaded($order);
        });
    }

    /** Menghapus diskon tingkat order. Tanpa token: menaikkan tagihan, tidak mengurangi. */
    public function clearOrderDiscount(Order $tab, User $user): Order
    {
        return DB::transaction(function () use ($tab, $user) {
            $order = $this->tabs->lockOpen($tab);
            $this->tabs->assertSessionOpen(CashierSession::findOrFail($order->session_id));

            $before = number_format((float) $order->discount_amount, 2, '.', '');
            // 057 / US8 — diskon dihapus: persen dan alasan ikut dikosongkan.
            $order->forceFill(['discount_percent' => null, 'discount_reason_code' => null])->save();
            $this->tabs->recalculate($order, 0.0);

            $this->activity->log($user->id, 'discount_removed', 'order', $order->id, 'Diskon tingkat order dihapus', ['discount_amount' => $before], ['discount_amount' => '0.00']);

            return $this->tabs->loaded($order);
        });
    }
}
