<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\PosSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 028 US2 — pembayaran tab. Memakai PaymentRecorder APA ADANYA (bukti non-tunai,
 * verifikasi) dan aturan kembalian kasir ritel: kembalian hanya boleh berasal
 * dari uang tunai. Beda dari penjualan ritel: pembayaran BOLEH sebagian
 * (saldo berjalan); tab baru selesai bila yang dibayar ≥ total.
 */
class TabSettlementService
{
    public function __construct(
        private ReceiptNumbering $numbering,
        private TabService $tabs,
        private PaymentRecorder $paymentRecorder,
        private OrderService $orders,
    ) {}

    /**
     * @param array{client_ref:string, payments:array<int,array>, discount_amount?:float|string|null} $data
     */
    public function settle(Order $tab, array $data, User $user): Order
    {
        $ref = $data['client_ref'];

        return DB::transaction(function () use ($tab, $data, $ref) {
            $order = $this->tabs->lock($tab);

            // Idempotensi: pengiriman ulang (jaringan putus) dengan client_ref
            // yang sama TIDAK mencatat apa pun lagi — kembalikan keadaan terkini.
            if (Payment::where('order_id', $order->id)->where('submission_ref', $ref)->exists()) {
                return $this->tabs->loaded($order);
            }

            if ($order->status !== Order::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => __('restaurant.tab_not_open')]);
            }

            $this->tabs->assertSessionOpen(CashierSession::findOrFail($order->session_id));

            if (! $order->items()->exists()) {
                throw ValidationException::withMessages(['tab' => __('restaurant.tab_empty')]);
            }

            if (array_key_exists('discount_amount', $data) && $data['discount_amount'] !== null) {
                $discount = round((float) $data['discount_amount'], 2);
                if ($discount > (float) $order->subtotal) {
                    throw ValidationException::withMessages(['discount_amount' => __('orders_payments.discount_exceeds_subtotal')]);
                }
                $this->tabs->recalculate($order, $discount);
            }

            $total = round((float) $order->total_amount, 2);
            $newPaid = round((float) collect($data['payments'])->sum('amount'), 2);
            $paidTotal = round((float) $order->paid_amount + $newPaid, 2);
            $completes = $paidTotal >= $total;

            // 048 / S3 — non-tunai: satu QRIS tertunda memblokir non-tunai lain; non-tunai tak boleh melebihi sisa.
            $nonCash = collect($data['payments'])->where('method', '!=', 'cash');
            if ($nonCash->isNotEmpty()) {
                if (Payment::where('order_id', $order->id)->where('method', 'qr_ewallet')->where('verification', 'pending')->exists()) {
                    throw new \App\Exceptions\ShiftRuleException(__('overrides.pending_qris'), 'pending_qris', [], 409);
                }
                $remaining = round($total - (float) $order->paid_amount, 2);
                if (round((float) $nonCash->sum('amount'), 2) > $remaining + 0.005) {
                    throw ValidationException::withMessages(['payments' => __('overrides.non_cash_exceeds_remaining')]);
                }
            }

            // 048 / S3 — EDC: kode approval saja; kode ganda dalam jendela waktu → 409 kecuali dikonfirmasi.
            foreach ($data['payments'] as $input) {
                if (($input['method'] ?? null) !== 'edc') {
                    continue;
                }
                $code = (string) ($input['approval_code'] ?? '');
                if (preg_match('/^[A-Z0-9]{6,12}$/', $code) !== 1) {
                    throw ValidationException::withMessages(['payments' => __('overrides.approval_code_invalid')]);
                }
                $duplicate = Payment::withoutGlobalScopes()
                    ->where('method', 'edc')
                    ->where('approval_code', $code)
                    ->where('verification', '!=', 'rejected')
                    ->where('paid_at', '>=', now()->subSeconds(PosSettings::edcDuplicateWindowSeconds()))
                    ->exists();
                if ($duplicate && empty($input['confirm_duplicate'])) {
                    throw new \App\Exceptions\ShiftRuleException(__('overrides.duplicate_edc'), 'duplicate_edc', [], 409);
                }
            }

            foreach ($data['payments'] as $input) {
                // 'full' hanya untuk pembayaran yang menuntaskan tab; sebelumnya uang muka.
                $input['purpose'] = $completes ? 'full' : 'down_payment';
                // 048 — uang tab diterima di shift TEMPAT tab dibuka (satu-satunya shift yang boleh membuka tab):
                // tanpa ini payments.session_id NULL dan tunai tab hilang dari kas shift, X-report, dan Z-report.
                $payment = $this->paymentRecorder->record(array_merge($input, ['session_id' => $order->session_id]), $order->id);
                // Kunci per-PENGIRIMAN: `client_ref` milik ledger UNIQUE per baris dan tak bisa dipakai bersama
                // oleh beberapa baris dalam satu pengiriman (lihat migrasi 2026_10_31_000009).
                $payment->forceFill(['submission_ref' => $ref])->save();
            }

            $update = ['paid_amount' => $paidTotal];

            if ($completes) {
                $change = round(max(0, $paidTotal - $total), 2);
                $cashReceived = (float) Payment::where('order_id', $order->id)->where('method', 'cash')->sum('amount');

                // Kembalian hanya masuk akal dari uang tunai (aturan yang sama dengan OrderService).
                if ($change > $cashReceived + 0.01) {
                    throw ValidationException::withMessages(['payments' => __('orders_payments.change_exceeds_cash_received')]);
                }

                $update += ['status' => Order::STATUS_COMPLETED, 'change_amount' => $change];
                $order->forceFill(['receipt_no' => $this->receiptFor($order)]);
            }

            $order->update($update);

            return $this->tabs->loaded($order);
        });
    }

    /**
     * Pisah tagihan: sebagian baris (atau sebagian unit sebuah baris) dibayar sebagai PENJUALAN
     * TERPISAH (order baru berstatus `completed`, nomor sendiri, `split_from_order_id` = tab ini),
     * sisanya tetap terbuka di meja yang sama.
     *
     *  - baris yang dipilih UTUH dipindah (re-parent: id baris yang sama, jadi pergerakan stok dan
     *    item tiket dapur tetap valid); sebagian unit → baris asal dikecilkan dan snapshot-nya diklon;
     *  - TIDAK memanggil StockService (stok sudah berkurang saat item ditambahkan; FR-016);
     *  - ditolak (409) bila tab sudah punya pembayaran apa pun: tidak ada logika alokasi;
     *  - sebagian unit hanya untuk baris yang belum dikirim atau sudah `served` (satu item tiket
     *    hanya menunjuk satu baris); baris UTUH selalu boleh, termasuk yang masih dimasak;
     *  - jumlah kedua sisi selalu persis sama dengan tagihan semula (jumlah qty × harga yang sama).
     *
     * @param array{items: array<int, array{item_id:int, qty:int}>, payments: array<int, array>, client_ref: string, discount_amount?: float|string|null} $data
     * @return array{paid_order: Order, tab: Order, replayed: bool}
     */
    public function split(Order $tab, array $data, User $user): array
    {
        $ref = $data['client_ref'];

        return DB::transaction(function () use ($tab, $data, $user, $ref) {
            $order = $this->tabs->lock($tab);

            // Idempotensi: pengiriman ulang dengan client_ref yang sama tidak memisah dua kali.
            $existing = Order::where('split_from_order_id', $order->id)
                ->whereHas('payments', fn ($q) => $q->where('submission_ref', $ref))
                ->first();
            if ($existing) {
                return ['paid_order' => $this->tabs->loaded($existing), 'tab' => $this->tabs->loaded($order), 'replayed' => true];
            }

            if ($order->status !== Order::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => __('restaurant.tab_not_open')]);
            }
            $this->tabs->assertSessionOpen(CashierSession::findOrFail($order->session_id));
            if ($order->payments()->exists()) {
                throw ValidationException::withMessages(['tab' => __('restaurant.split_has_payments')]);
            }

            // Validasi seluruh pilihan SEBELUM mengubah apa pun.
            $picks = [];
            foreach ($data['items'] as $pick) {
                $line = $order->items()->with('ticketItem')->findOrFail((int) $pick['item_id']); // baris tab lain → 404
                $qty = (int) $pick['qty'];

                if ($qty > $line->qty) {
                    throw ValidationException::withMessages(['items' => __('restaurant.split_qty_exceeds', ['name' => $line->name_snapshot])]);
                }
                if ($qty < $line->qty && $line->sent_at !== null && $line->ticketItem?->status !== KitchenTicketItem::SERVED) {
                    throw ValidationException::withMessages(['items' => __('restaurant.split_partial_sent_line', ['name' => $line->name_snapshot])]);
                }
                $picks[] = [$line, $qty];
            }

            // Dibuat langsung `completed`: tab asli masih memegang kunci "satu tab terbuka per meja".
            $paid = Order::create([
                'order_number' => $this->orders->generateOrderNumber(),
                'session_id' => $order->session_id,
                'customer_id' => $order->customer_id,
                'user_id' => $user->id,
                'channel' => 'offline',
                'service_type' => $order->service_type,
                'table_id' => $order->table_id,
                'tab_label' => $order->tab_label,
                'split_from_order_id' => $order->id,
                'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0, 'total_cost' => 0,
                'paid_amount' => 0, 'change_amount' => 0,
                'status' => Order::STATUS_COMPLETED,
            ]);

            foreach ($picks as [$line, $qty]) {
                if ($qty === $line->qty) {
                    $line->update(['order_id' => $paid->id]);

                    continue;
                }

                $paid->items()->create([
                    'variant_id' => $line->variant_id,
                    'home_outlet_id' => $line->home_outlet_id,
                    'sku_snapshot' => $line->sku_snapshot,
                    'name_snapshot' => $line->name_snapshot,
                    'qty' => $qty,
                    'cost_price' => $line->cost_price,
                    'sell_price' => $line->sell_price,
                    'discount_amount' => 0,
                    'line_total' => round((float) $line->sell_price * $qty, 2),
                    'modifiers' => $line->modifiers,
                    'note' => $line->note,
                    'sent_at' => $line->sent_at,
                ]);
                $remaining = $line->qty - $qty;
                $line->update(['qty' => $remaining, 'line_total' => round((float) $line->sell_price * $remaining - (float) $line->discount_amount, 2)]);
            }

            $this->tabs->recalculate($paid);

            if (array_key_exists('discount_amount', $data) && $data['discount_amount'] !== null) {
                $discount = round((float) $data['discount_amount'], 2);
                if ($discount > (float) $paid->subtotal) {
                    throw ValidationException::withMessages(['discount_amount' => __('orders_payments.discount_exceeds_subtotal')]);
                }
                $this->tabs->recalculate($paid, $discount);
            }

            $total = round((float) $paid->total_amount, 2);
            $paidAmount = round((float) collect($data['payments'])->sum('amount'), 2);
            if ($paidAmount < $total) {
                throw ValidationException::withMessages(['payments' => __('orders_payments.payment_insufficient')]);
            }

            foreach ($data['payments'] as $input) {
                $input['purpose'] = 'full';
                $payment = $this->paymentRecorder->record(array_merge($input, ['session_id' => $paid->session_id]), $paid->id);
                $payment->forceFill(['submission_ref' => $ref])->save();
            }

            $change = round(max(0, $paidAmount - $total), 2);
            $cash = (float) collect($data['payments'])->where('method', 'cash')->sum('amount');
            if ($change > $cash + 0.01) {
                throw ValidationException::withMessages(['payments' => __('orders_payments.change_exceeds_cash_received')]);
            }
            $paid->forceFill(['device_id' => $order->device_id, 'receipt_no' => $this->receiptFor($order)]);
            $paid->update(['paid_amount' => $paidAmount, 'change_amount' => $change]);

            // Sisa tab: dihitung ulang; bila tak ada baris tersisa, tab (kini kosong) ditutup dan mejanya kosong.
            $this->tabs->recalculate($order);
            if (! $order->items()->exists()) {
                $order->update(['status' => Order::STATUS_VOIDED, 'void_reason' => __('restaurant.split_paid_in_full_reason')]);
            }

            return ['paid_order' => $this->tabs->loaded($paid), 'tab' => $this->tabs->loaded($order), 'replayed' => false];
        });
    }

    /**
     * 048 / T059 — bagi rata tagihan (pratinjau, TANPA pembayaran dan TANPA mengubah tab). Setiap bagian = bagian
     * rupiah utuh; sisa pembulatan (dan sen, bila ada) masuk ke bagian TERAKHIR, sehingga jumlah bagian sama
     * persis dengan tagihan termasuk pajak. Kasir mencatat tiap bagian lewat pembayaran biasa.
     *
     * @return array{total: string, mode: string, parts: array<int, array{index: int, amount: string}>}
     */
    public function equalSplitPreview(Order $tab, int $parts): array
    {
        if ($tab->status !== Order::STATUS_OPEN) {
            throw ValidationException::withMessages(['status' => __('restaurant.tab_not_open')]);
        }
        if (! $tab->items()->exists()) {
            throw ValidationException::withMessages(['tab' => __('restaurant.tab_empty')]);
        }

        $totalCents = (int) round(((float) $tab->total_amount) * 100);
        $baseCents = intdiv(intdiv($totalCents, 100), $parts) * 100; // bagian rupiah utuh (dibulatkan ke bawah)

        $amounts = [];
        for ($i = 1; $i <= $parts; $i++) {
            $cents = $i < $parts ? $baseCents : $totalCents - $baseCents * ($parts - 1);
            $amounts[] = ['index' => $i, 'amount' => number_format($cents / 100, 2, '.', '')];
        }

        return ['total' => number_format($totalCents / 100, 2, '.', ''), 'mode' => 'equal', 'parts' => $amounts];
    }

    /**
     * 048 — hanya order yang dibuka dari terminal terdaftar yang bernomor struk; order tanpa terminal (SPA web) null.
     * 064 — nomornya urut polos per OUTLET order (bukan per terminal); terminalnya tertaut lewat `device_id`.
     */
    private function receiptFor(Order $order): ?string
    {
        if ($order->device_id === null || $order->receipt_no !== null) {
            return $order->receipt_no;
        }
        $outletId = $order->outlet_id ?? \App\Models\Device::withoutGlobalScopes()->whereKey($order->device_id)->value('outlet_id');

        return $outletId === null ? null : $this->numbering->receiptFor((int) $outletId);
    }
}
