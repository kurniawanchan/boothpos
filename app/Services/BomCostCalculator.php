<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Support\ReportSplit;

/**
 * Menghitung `bom_cost` — total modal bahan baku suatu varian produk
 * berdasarkan BOM-nya — TANPA pernah menulis ke `cost_price`.
 *
 * 034-seller-po-bom — kalkulator ini TETAP hanya menghitung. Satu-satunya
 * yang menyalin bom_cost ke cost_price adalah VariantBomService, dan itu
 * hanya untuk varian yang BOM-nya sudah ditandai SELESAI (lihat
 * syncCostPriceIfComplete()); varian lain tetap memakai cost_price manual.
 *
 * KEPUTUSAN DESAIN ASLI (tetap berlaku untuk varian yang BOM-nya belum selesai): `cost_price` di ProductVariant sudah dipakai
 * laporan laba (F-report profit), perhitungan settlement artist, dan diuji
 * di banyak tempat. Mengganti isinya secara otomatis dari hasil BOM
 * berisiko nyata merusak logika yang sudah teruji di seluruh kodebase ini
 * hanya karena pemilik toko mengisi BOM. Karena itu `bom_cost` SELALU
 * berupa angka terpisah dan read-only — pemilik toko membandingkan
 * `cost_price` (manual) dengan `bom_cost` (dari BOM) sendiri, lalu
 * memutuskan sendiri apakah mau menyalinnya lewat layar edit produk biasa.
 *
 * PEMILIHAN HARGA VENDOR SAAT SATU BAHAN PUNYA >1 VENDOR:
 * 1) vendor yang ditandai `is_preferred` pada bahan itu, kalau ada.
 * 2) kalau tidak ada yang ditandai preferred, harga TERMURAH dipakai.
 *    Alasan: ini kalkulator MODAL, bukan rekomendasi belanja — memakai
 *    asumsi harga terendah adalah estimasi yang defensif/optimistis untuk
 *    modal, mendorong pemilik toko menandai vendor utama secara eksplisit
 *    bila mereka sebenarnya membeli dari vendor yang lebih mahal. Aturan
 *    ini didokumentasikan di sini dan di Material::referencePrice(), satu
 *    sumber tunggal — jangan duplikasi logika ini di tempat lain.
 * 3) Bahan tanpa harga vendor sama sekali dihitung sebagai biaya NOL untuk
 *    baris itu, dan ditandai `has_price = false` di breakdown supaya UI
 *    bisa menonjolkan bahwa datanya belum lengkap alih-alih diam-diam
 *    meremehkan biaya sebenarnya.
 */
class BomCostCalculator
{
    /**
     * 034-seller-po-bom — dua jenis baris, SATU tempat hitungnya:
     *  - baris bersumber PO: biaya satuan = SNAPSHOT unit_cost yang disalin
     *    saat baris dibuat (tidak pernah mengikuti harga PO yang berubah);
     *  - baris LEGACY (tanpa baris PO): harga acuan vendor hidup seperti
     *    sebelumnya, dihitung sebagai biaya bahan dan ditandai is_legacy.
     * Biaya baris dibulatkan ke sen LEBIH DULU, total = jumlah baris yang
     * tampil — supaya tabel yang dilihat pengguna selalu menjumlah persis
     * ke totalnya. Biaya dipecah material vs service.
     *
     * @return array{lines: array<int, array<string, mixed>>, bom_cost: string, material_cost: string, service_cost: string, has_legacy: bool}
     */
    public function breakdown(ProductVariant $variant): array
    {
        $variant->loadMissing('bomLines.material');

        $lines = [];
        $materialCents = 0;
        $serviceCents = 0;
        $hasLegacy = false;

        foreach ($variant->bomLines as $line) {
            $qty = (float) $line->qty_needed;
            $legacy = $line->isLegacy();
            $reference = null;

            if ($legacy) {
                $hasLegacy = true;
                $reference = $line->material?->referencePrice();
                $unitCost = $reference?->price !== null ? (float) $reference->price : 0.0;
            } else {
                $unitCost = (float) $line->unit_cost;
            }

            $lineCents = ReportSplit::cents($unitCost * $qty);

            if ($line->line_type === 'service') {
                $serviceCents += $lineCents;
            } else {
                $materialCents += $lineCents;
            }

            $lines[] = [
                'bom_line_id' => $line->id,
                'line_type' => $line->line_type,
                'is_legacy' => $legacy,
                'purchase_order_item_id' => $line->purchase_order_item_id,
                'po_number' => $line->po_number,
                'item_name' => $legacy ? $line->material?->name : $line->item_name,
                'material_id' => $line->material_id,
                'material_name' => $line->material?->name ?? $line->item_name,
                'unit' => $line->material?->unit,
                'qty_needed' => number_format($qty, 4, '.', ''),
                'unit_cost' => number_format($unitCost, 2, '.', ''),
                'line_cost' => ReportSplit::money($lineCents),
                'has_price' => $legacy ? $reference !== null : true,
                'reference_vendor_id' => $legacy ? $reference?->vendor_id : $line->vendor_id,
                'reference_vendor_name' => $legacy ? $reference?->vendor?->name : $line->vendor_name,
                'reference_is_preferred' => $legacy ? ($reference?->is_preferred ?? false) : false,
            ];
        }

        return [
            'lines' => $lines,
            'bom_cost' => ReportSplit::money($materialCents + $serviceCents),
            'material_cost' => ReportSplit::money($materialCents),
            'service_cost' => ReportSplit::money($serviceCents),
            'has_legacy' => $hasLegacy,
        ];
    }
}
