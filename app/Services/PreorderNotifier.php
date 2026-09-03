<?php

namespace App\Services;

use App\Mail\PreorderStatusMail;
use App\Models\Preorder;
use App\Models\PreorderNotification;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 007-preorder-import-export-notify (US4) — SATU jalur sah untuk "kirim
 * notifikasi status/invoice pre-order ke pelanggan", dipanggil baik dari
 * PreorderService::transitionStatus() (otomatis) maupun endpoint kirim
 * ulang manual (PreorderController::resendNotification()).
 *
 * Dipanggil SETELAH transaksi DB perubahan status commit, bukan di
 * dalamnya (research.md R7) — pengiriman email adalah operasi jaringan
 * lambat/rawan gagal yang TIDAK BOLEH membuat perubahan status bisnis itu
 * sendiri gagal atau rollback. Setiap percobaan (berhasil maupun tidak)
 * dicatat sebagai baris PreorderNotification miliknya sendiri, bukan
 * bagian dari transaksi status.
 */
class PreorderNotifier
{
    public function notifyStatusChange(Preorder $preorder, string $trigger): PreorderNotification
    {
        $email = $preorder->customer?->email;

        if (empty($email)) {
            return $this->record($preorder, $trigger, null, 'skipped_no_email');
        }

        // BUKAN mengasumsikan mailer 'log' berarti "tidak terkonfigurasi"
        // secara umum — tapi 'log' adalah default apa adanya yang dikirim
        // bersama produk ini (.env.example), jadi toko yang belum pernah
        // mengubahnya memang belum mengonfigurasi email keluar (research.md R5).
        if (config('mail.default') === 'log') {
            return $this->record($preorder, $trigger, $email, 'skipped_not_configured');
        }

        try {
            Mail::to($email)->send(new PreorderStatusMail($preorder));

            return $this->record($preorder, $trigger, $email, 'sent');
        } catch (Throwable $e) {
            return $this->record($preorder, $trigger, $email, 'failed', $e->getMessage());
        }
    }

    private function record(
        Preorder $preorder,
        string $trigger,
        ?string $email,
        string $status,
        ?string $errorMessage = null
    ): PreorderNotification {
        return PreorderNotification::create([
            'preorder_id' => $preorder->id,
            'trigger' => $trigger,
            'triggered_by_status' => $trigger === 'status_change' ? $preorder->status : null,
            'recipient_email' => $email,
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_at' => now(),
        ]);
    }
}
