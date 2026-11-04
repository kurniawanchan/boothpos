<?php

// 056 (US8) — ekspor Excel daftar Sales menanam gambar (referensi + bukti bayar) di baris tiap penjualan.
// Workbook dibuat utuh di memori, jadi jumlah gambar per ekspor dibatasi; lewat batas = 422 dengan pesan
// yang menyebut batasnya (tak pernah berkas terpotong diam-diam). Diukur (2026-11-05, foto 4000×3000):
// ≈0,13 dtk per gambar, memori datar (~140 MB puncak) — jadi WAKTU yang membatasi, bukan memori: 400 gambar
// ≈ 50 dtk, melewati batas 30 dtk PHP bawaan. 200 gambar ≈ 26 dtk; controller juga menaikkan batas waktu
// untuk permintaan ini saja (research.md D11).
return [
    'export_max_images' => (int) env('SALES_EXPORT_MAX_IMAGES', 200),
];
