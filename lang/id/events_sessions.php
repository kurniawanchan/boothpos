<?php

return [
    'no_open_session' => 'Tidak ada sesi terbuka.',
    'already_has_open_session' => 'Anda masih memiliki sesi kasir yang terbuka. Tutup sesi tersebut terlebih dahulu.',
    'opening_cash_entries_mismatch' => 'Total rincian kas per outlet harus sama dengan kas awal.',
    'not_authorized_close_session' => 'Anda tidak berhak menutup sesi ini.',
    'session_already_closed' => 'Sesi sudah ditutup.',
    'not_authorized_view_summary' => 'Anda tidak berhak melihat ringkasan sesi ini.',
    'cannot_close_with_open_tabs' => 'Shift hanya bisa ditutup setelah semua tab terbuka dibayar, dibatalkan, atau diserahkan.',
    'denomination_duplicate' => 'Pecahan :value dimasukkan dua kali. Gabungkan menjadi satu baris.',
    'opening_denominations_mismatch' => 'Total rincian pecahan harus sama dengan kas awal.',
    'cash_movement_not_owner' => 'Kas hanya bisa dicatat di shift Anda sendiri.',
    'invalid_cash_reason' => 'Alasan kas tidak dikenal untuk arah ini.',
    'reason_note_required' => 'Tulis catatan untuk alasan "Lainnya".',
    'cash_out_needs_approval' => 'Kas keluar di atas batas harus disetujui. Persetujuan belum tersedia di aplikasi ini.',
    'pending_qris_payments' => 'Ada :count pembayaran QRIS yang belum dicek. Cek dulu sebelum menutup shift.',
    'variance_needs_approval' => 'Selisih kas di atas toleransi harus disetujui. Persetujuan belum tersedia di aplikasi ini.',
    'count_or_closing_required' => 'Isi jumlah kas akhir (closing_cash) atau hitungan per pecahan.',
    // 065 — label alasan kas masuk/keluar untuk daftar kas di web (kode tetap sumber kebenaran).
    'cash_reason' => [
        'float_topup' => 'Tambah modal',
        'change_exchange' => 'Tukar uang kecil',
        'petty_cash' => 'Uang kecil / belanja',
        'supplier' => 'Bayar supplier',
        'bank_deposit' => 'Setor ke bank / brankas',
        'refund' => 'Refund',
        'other' => 'Lainnya',
    ],
    'count_one_method_only' => 'Hitungan hanya boleh satu cara: per pecahan atau total ketikan, tidak keduanya.',
    'counted_total_whole' => 'Jumlah uang harus rupiah utuh (tanpa sen).',
];
