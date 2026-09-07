<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (id selalu 1) berisi informasi pembayaran invoice — dijadikan
 * default `payment_information` saat invoice baru dibuat (FR-008/FR-015).
 *
 * Sengaja BUKAN HasDataMode (data administratif, sama kategori dengan
 * PaymentChannel — bukan data bisnis/transaksional) dan BUKAN SoftDeletes
 * (baris ini hanya pernah diperbarui, tidak pernah dihapus).
 */
class InvoicePaymentSetting extends Model
{
    // 'id' sengaja dimasukkan ke $fillable: updateOrCreate(['id' => 1], ...)
    // di controller butuh id ikut ter-mass-assign saat baris belum ada,
    // agar baris baru selalu dibuat dengan id=1 (bukan mengikuti counter
    // AUTO_INCREMENT sisa test sebelumnya) — menjaga invarian singleton.
    protected $fillable = ['id', 'bank_name', 'account_number', 'account_holder', 'instructions'];
}
