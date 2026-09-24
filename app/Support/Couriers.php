<?php

namespace App\Support;

/**
 * 021-preorder-form-updates — satu sumber daftar kurir, dipakai bersama
 * oleh dropdown kurir di form New Preorder, dropdown kurir di form
 * Shipment (ShipmentController tetap memvalidasi courier_name sebagai
 * string bebas — daftar ini hanya menuntun pilihan di frontend + validasi
 * PreorderService/PreorderExportImportService), dan validasi import
 * pre-order. Satu definisi, bukan diduplikasi per layar (research.md
 * Decision 5).
 */
class Couriers
{
    public const OPTIONS = ['JNE', 'J&T', 'SiCepat', 'Pos Indonesia', 'Other'];

    public const DEFAULT = 'JNE';
}
