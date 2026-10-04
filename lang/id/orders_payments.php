<?php

return [
    'not_authorized_void' => 'Hanya owner/admin yang dapat membatalkan transaksi.',
    'unsupported_file_type' => 'Tipe berkas tidak didukung. Hanya JPEG atau PNG.',
    'not_authorized_proof_access' => 'Tidak berhak mengakses berkas ini.',
    'proof_not_found' => 'Berkas tidak ditemukan.',
    'not_authorized' => 'Tidak berhak.',
    'session_not_open' => 'Sesi kasir tidak dalam status terbuka.',
    'variant_inactive' => 'Varian :sku tidak aktif.',
    'payment_insufficient' => 'Total pembayaran tidak menutup total transaksi.',
    'proof_required_for_non_cash' => 'Bukti pembayaran wajib untuk metode non-tunai.',
    'proof_token_invalid' => 'Token bukti pembayaran tidak valid atau sudah dipakai.',
    'change_exceeds_cash_received' => 'Kembalian tidak dapat melebihi jumlah tunai yang diterima.',
    'already_voided' => 'Transaksi sudah dibatalkan sebelumnya.',
    'customer_not_found' => 'Customer tidak ditemukan.',
    'discount_exceeds_line_value' => 'Diskon untuk varian :sku melebihi nilai baris itu sendiri.',
    'discount_exceeds_subtotal' => 'Diskon transaksi melebihi subtotal.',
    'channel_in_use' => 'Kanal ini sedang dipakai dan tidak dapat dihapus.',

    // 028-partial-split-payment
    'payment_exceeds_balance' => 'Jumlah melebihi sisa tagihan. Maksimal yang bisa dibayar :max.',
    'payment_already_fully_paid' => 'Transaksi ini sudah lunas.',
    'payment_target_closed' => 'Transaksi ini sudah ditutup (diserahkan, dibatalkan, atau dibatalkan/void), sehingga pembayaran tidak bisa ditambah atau dihapus lagi.',
    'payment_session_required' => 'Pembayaran tunai membutuhkan sesi kasir yang terbuka. Buka sesi kasir dulu, lalu catat pembayarannya.',
    'payment_client_ref_conflict' => 'Kunci referensi pembayaran ini sudah dipakai untuk transaksi lain.',
    'customer_required_for_partial_payment' => 'Penjualan yang tidak dibayar penuh wajib memiliki pelanggan, supaya jelas siapa yang masih berutang.',
    'payment_delete_closed_shift' => 'Pembayaran tunai ini milik shift kasir yang sudah ditutup dan direkonsiliasi, sehingga tidak bisa dihapus.',
];
