<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentProof;
use Illuminate\Validation\ValidationException;

/**
 * Dipakai bersama oleh OrderService dan PreorderService (dan PurchaseOrderService) —
 * DUA modul berbeda butuh logika identik, jadi diekstrak di sini alih-alih
 * disalin. Ambang DRY yang sama seperti StockService.
 *
 * 031-optional-payment-proof — bukti bayar (foto/berkas) TIDAK lagi wajib untuk
 * pembayaran non-tunai pada penjualan dan pre-order: kasir boleh menyelesaikan
 * transaksi dulu dan menambahkan konfirmasi belakangan dari detail transaksi
 * (PaymentService::updateConfirmation). Yang tetap berlaku:
 *  - token bukti yang DIKIRIM harus valid dan belum terpakai (sekali pakai);
 *  - non-tunai wajib punya `channel_id`. Constraint DB `chk_payments_channel`
 *    sudah menuntutnya, tetapi request membiarkannya nullable dan sampai kini
 *    hanya terlindungi secara tidak langsung oleh syarat bukti; tanpa syarat itu
 *    panggilan tanpa kanal akan jadi error 500 dari constraint, jadi ditolak
 *    bersih di sini;
 *  - pembayaran ke pemasok pada PURCHASE ORDER tidak berubah: non-tunai tetap
 *    wajib bukti.
 */
class PaymentRecorder
{
    /**
     * @param array{method:string,channel_id:?int,purpose:string,amount:float|string,proof_token:?string,notes:?string,reference?:?string,client_ref?:?string,session_id?:?int,recorded_by?:?int} $input
     * @throws ValidationException
     */
    public function record(array $input, ?int $orderId, ?int $preorderId, ?int $purchaseOrderId = null): Payment
    {
        $method = $input['method'];

        if ($method !== 'cash') {
            if (empty($input['channel_id'])) {
                throw ValidationException::withMessages([
                    'payments' => __('orders_payments.channel_required_for_non_cash'),
                ]);
            }

            $token = $input['proof_token'] ?? null;

            if (! $token && $purchaseOrderId !== null) {
                throw ValidationException::withMessages([
                    'payments' => __('orders_payments.proof_required_for_non_cash'),
                ]);
            }

            if ($token) {
                $proof = PaymentProof::where('proof_token', $token)->whereNull('payment_id')->first();

                if (! $proof) {
                    throw ValidationException::withMessages([
                        'payments' => __('orders_payments.proof_token_invalid'),
                    ]);
                }
            }
        }

        $payment = Payment::create([
            'order_id' => $orderId,
            'preorder_id' => $preorderId,
            'purchase_order_id' => $purchaseOrderId,
            'channel_id' => $input['channel_id'] ?? null,
            'method' => $method,
            'purpose' => $input['purpose'] ?? 'full',
            'amount' => $input['amount'],
            // Tunai dianggap terverifikasi seketika (kasir menghitung
            // langsung di tempat). Non-tunai menunggu verifikasi manual
            // terhadap mutasi rekening.
            'verification' => $method === 'cash' ? 'verified' : 'pending',
            'paid_at' => now(),
            'notes' => $input['notes'] ?? null,
            // 028-partial-split-payment — jejak buku besar. Semuanya opsional supaya
            // pemanggil lama (mis. PurchaseOrderService) tetap berfungsi apa adanya.
            'reference' => $input['reference'] ?? null,
            'client_ref' => $input['client_ref'] ?? null,
            'session_id' => $input['session_id'] ?? null,
            'recorded_by' => $input['recorded_by'] ?? null,
        ]);

        if (isset($proof)) {
            $proof->update(['payment_id' => $payment->id]);
        }

        return $payment;
    }
}
