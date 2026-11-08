<?php

// 059 — persetujuan transaksi oleh seller. Pesan server; teks layar ada di locales/*.json.
return [
    'order_voided' => 'Penjualan ini sudah dibatalkan, persetujuan tidak dapat dicatat.',
    'approval_not_allowed' => 'Anda tidak berhak menyetujui item seller ini.',
    'already_approved' => 'Item seller ini sudah disetujui.',
    'approval_stale' => 'Item seller ini berubah sejak halaman dibuka. Muat ulang lalu tinjau kembali sebelum menyetujui.',
    'seller_not_required' => 'Seller ini tidak punya item pada penjualan ini.',
    'image_required' => 'Tanda tangan atau gambar persetujuan wajib diisi untuk metode ini.',
    'image_not_allowed_for_stamp' => 'Metode stempel tidak memakai gambar.',
    'image_forbidden' => 'Anda tidak berhak melihat gambar persetujuan ini.',
    'image_missing' => 'Berkas gambar persetujuan tidak ditemukan.',
    'unsupported_file_type' => 'Hanya gambar PNG atau JPG yang diterima.',
    'approved_log' => 'Seller :seller menyetujui item penjualan :number (:method:behalf).',
    'invalidated_log' => 'Persetujuan seller :seller pada penjualan :number dibatalkan karena item berubah.',
    'behalf_suffix' => ', atas nama seller',
    'methods' => ['draw' => 'tanda tangan digambar', 'upload' => 'gambar diunggah', 'stamp' => 'stempel'],
    'bulk_limit_exceeded' => 'Maksimal :max penjualan dalam satu persetujuan massal. Kurangi pilihan lalu coba lagi.',
    'bulk_approved_log' => 'Persetujuan massal (:method) oleh :user: :approved dari :selected penjualan disetujui, :skipped dilewati.',
    'bulk_via' => 'lewat persetujuan massal',
    'skip_reasons' => [
        'not_found' => 'Penjualan tidak ditemukan atau tidak tersedia bagi Anda.',
        'voided' => 'Penjualan sudah dibatalkan.',
        'already_approved' => 'Sudah disetujui.',
        'no_approvable_seller' => 'Tidak ada seller yang bisa Anda setujui pada penjualan ini.',
        'changed' => 'Penjualan berubah sejak pratinjau. Muat ulang dan tinjau lagi.',
        'failed' => 'Gagal disetujui. Coba lagi.',
    ],
];
