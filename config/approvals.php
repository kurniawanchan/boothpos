<?php

// 064 — persetujuan massal dari daftar Sales. Batas jumlah penjualan per satu aksi (research D7): sama dengan
// aksi massal Sales lain (`verify-payments`, `bulk-receipts` = 200). Tiap penjualan memakai ±dua transaksi
// pendek, jadi batas ini menjaga satu permintaan tetap selesai dalam batas waktu PHP; lewat batas = 422 dengan
// batasnya disebut di pesan, tak pernah diproses sebagian.
return [
    'bulk_max' => (int) env('APPROVALS_BULK_MAX', 200),
];
