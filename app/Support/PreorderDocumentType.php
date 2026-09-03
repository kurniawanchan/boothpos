<?php

namespace App\Support;

/**
 * 007-preorder-import-export-notify (US2/US4) — SATU sumber kebenaran
 * untuk pemetaan status pre-order → jenis dokumen cetak & tema subjek
 * email (data-model.md), dipakai baik oleh PreorderController::invoice()
 * maupun PreorderNotifier — supaya pemetaan ini tidak pernah didefinisikan
 * dua kali dan berisiko berbeda (Constitution I, "satu jalur sah per
 * concern"). Lihat research.md R2.
 */
class PreorderDocumentType
{
    private const MAP = [
        'ordered' => ['document_type' => 'invoice', 'subject_theme' => 'Pesanan diterima'],
        'dp_paid' => ['document_type' => 'invoice', 'subject_theme' => 'DP diterima'],
        'arrived' => ['document_type' => 'invoice', 'subject_theme' => 'Barang tiba'],
        'settled' => ['document_type' => 'receipt', 'subject_theme' => 'Lunas'],
        'handed_over' => ['document_type' => 'receipt', 'subject_theme' => 'Pesanan diserahkan'],
        'cancelled' => ['document_type' => 'cancelled', 'subject_theme' => 'Pesanan dibatalkan'],
    ];

    public static function forStatus(string $status): string
    {
        return self::MAP[$status]['document_type'] ?? 'invoice';
    }

    public static function subjectThemeForStatus(string $status): string
    {
        return self::MAP[$status]['subject_theme'] ?? ucfirst($status);
    }
}
