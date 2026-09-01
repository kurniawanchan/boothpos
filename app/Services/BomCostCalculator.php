<?php

namespace App\Services;

use App\Models\ProductVariant;

/**
 * Menghitung `bom_cost` — total modal bahan baku suatu varian produk
 * berdasarkan BOM-nya — TANPA pernah menulis ke `cost_price`.
 *
 * KEPUTUSAN DESAIN PENTING: `cost_price` di ProductVariant sudah dipakai
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
     * @return array{lines: array<int, array<string, mixed>>, bom_cost: string}
     */
    public function breakdown(ProductVariant $variant): array
    {
        $variant->loadMissing('bomLines.material.vendorPrices');

        $lines = [];
        $total = 0.0;

        foreach ($variant->bomLines as $line) {
            $reference = $line->material->referencePrice();
            $unitCost = $reference?->price !== null ? (float) $reference->price : 0.0;
            $qty = (float) $line->qty_needed;
            $lineCost = $unitCost * $qty;
            $total += $lineCost;

            $lines[] = [
                'bom_line_id' => $line->id,
                'material_id' => $line->material_id,
                'material_name' => $line->material->name,
                'unit' => $line->material->unit,
                'qty_needed' => number_format($qty, 4, '.', ''),
                'unit_cost' => number_format($unitCost, 2, '.', ''),
                'line_cost' => number_format($lineCost, 2, '.', ''),
                'has_price' => $reference !== null,
                'reference_vendor_id' => $reference?->vendor_id,
                'reference_vendor_name' => $reference?->vendor?->name,
                'reference_is_preferred' => $reference?->is_preferred ?? false,
            ];
        }

        return [
            'lines' => $lines,
            'bom_cost' => number_format($total, 2, '.', ''),
        ];
    }
}
