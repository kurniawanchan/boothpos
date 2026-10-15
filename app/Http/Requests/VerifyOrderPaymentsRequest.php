<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 032-mark-payment-verified (US3) — bentuk saja untuk POST /orders/verify-payments (aksi massal dari
 * daftar Sales). Klien hanya mengirim id penjualan yang dipilih; aturan per pembayaran (siapa boleh,
 * tunai, batal, sudah terverifikasi, ditolak) dinilai PaymentService — yang tak bisa diverifikasi
 * DILEWATI dan dihitung, bukan membuat permintaan gagal. Batas 200 id menjaga satu permintaan tetap
 * pendek (satu transaksi per pembayaran).
 */
class VerifyOrderPaymentsRequest extends FormRequest
{
    // Semua peran terautentikasi lolos; siapa yang BOLEH memverifikasi pembayaran tertentu adalah aturan
    // per-objek (Payment::mayVerify) yang dinilai per pembayaran di service.
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer', 'distinct'],
        ];
    }
}
