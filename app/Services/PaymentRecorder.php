<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentProof;
use Illuminate\Validation\ValidationException;

/**
 * Dipakai bersama oleh OrderService dan PreorderService — DUA modul
 * berbeda butuh logika identik ("non-tunai wajib punya bukti valid,
 * proof_token dikonsumsi sekali"), jadi diekstrak di sini alih-alih
 * disalin. Ambang DRY yang sama seperti StockService.
 */
class PaymentRecorder
{
    /**
     * @param array{method:string,channel_id:?int,purpose:string,amount:float|string,proof_token:?string,notes:?string} $input
     * @throws ValidationException
     */
    public function record(array $input, ?int $orderId, ?int $preorderId): Payment
    {
        $method = $input['method'];

        if ($method !== 'cash') {
            $token = $input['proof_token'] ?? null;

            if (! $token) {
                throw ValidationException::withMessages([
                    'payments' => 'Bukti pembayaran wajib untuk metode non-tunai.',
                ]);
            }

            $proof = PaymentProof::where('proof_token', $token)->whereNull('payment_id')->first();

            if (! $proof) {
                throw ValidationException::withMessages([
                    'payments' => 'Token bukti pembayaran tidak valid atau sudah dipakai.',
                ]);
            }
        }

        $payment = Payment::create([
            'order_id' => $orderId,
            'preorder_id' => $preorderId,
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
        ]);

        if (isset($proof)) {
            $proof->update(['payment_id' => $payment->id]);
        }

        return $payment;
    }
}
