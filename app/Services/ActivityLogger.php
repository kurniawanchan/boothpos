<?php

namespace App\Services;

use App\Models\ActivityLog;

/**
 * Satu jalur tunggal untuk menulis activity_logs (F13.4 — log aktivitas
 * untuk tindakan sensitif: hapus data, penyesuaian stok, ubah harga).
 * Dijadikan service terpisah, bukan dipanggil `ActivityLog::create()`
 * langsung dari tiap controller, dengan alasan sama seperti StockService/
 * PaymentRecorder: lebih dari satu pemanggil butuh bentuk yang identik,
 * dan menaruh `now()`/normalisasi di satu tempat mencegah satu pemanggil
 * lupa mengisi timestamp atau membentuk snapshot secara berbeda.
 *
 * CATATAN TRANSAKSIONAL — panggil method ini DI DALAM transaksi database
 * yang sama dengan tindakan sensitifnya (delete/update/stock adjustment),
 * bukan setelah commit. Kalau tindakan sensitifnya batal (exception,
 * rollback), baris log ini harus ikut batal juga — log tidak boleh
 * mengklaim sesuatu terjadi padahal sebenarnya tidak.
 */
class ActivityLogger
{
    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'created_at' => now(),
        ]);
    }
}
