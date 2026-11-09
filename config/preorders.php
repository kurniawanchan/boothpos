<?php

// 065 — aksi massal pada daftar Pre-orders. Batas jumlah pre-order per SATU aksi (research D10): sama dengan
// aksi massal lain (`verify-payments`, `bulk-receipts`, persetujuan massal = 200). Tiap pre-order memakai satu
// transaksi pendek (+ email status yang sinkron), jadi batas ini menjaga satu permintaan tetap selesai dalam batas
// waktu PHP; lewat batas = 422 yang menyebut batasnya, tak pernah diproses sebagian.
return [
    'bulk_max' => (int) env('PREORDER_BULK_MAX', 200),
];
