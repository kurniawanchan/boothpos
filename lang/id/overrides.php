<?php

return [
    'required' => 'Aksi ini butuh persetujuan dari penyetuju yang berwenang.',
    'mismatch' => 'Persetujuan tidak cocok dengan aksi ini. Minta persetujuan ulang.',
    'expired' => 'Persetujuan sudah kedaluwarsa. Minta persetujuan ulang.',
    'consumed' => 'Persetujuan ini sudah dipakai.',
    'not_permitted' => 'Penyetuju tidak berwenang untuk aksi ini.',
    'same_person' => 'Penyetuju tidak boleh orang yang sama dengan peminta.',
    'has_payments' => 'Tab yang sudah dibayar tidak bisa dibatalkan.',
    'pending_qris' => 'Ada pembayaran QRIS yang belum dicek. Selesaikan dulu sebelum menerima non-tunai lain.',
    'duplicate_edc' => 'Kode approval EDC ini sudah tercatat baru-baru ini. Pastikan ini bukan transaksi ganda.',
    'approval_code_invalid' => 'Kode approval EDC harus 6–12 huruf kapital atau angka.',
    'non_cash_exceeds_remaining' => 'Pembayaran non-tunai melebihi sisa tagihan.',
    'kitchen_status_changed' => 'Status dapur berubah. Muat ulang tab lalu coba lagi.',
    'stock_decision_required' => 'Pilih keputusan stok: kembalikan atau catat sebagai terbuang.',
    'stock_return_after_prep' => 'Stok tidak bisa dikembalikan setelah item mulai dimasak.',
    'reference_min' => 'Referensi QRIS minimal 4 karakter.',
    'fail_only_qris' => 'Hanya pembayaran QRIS yang bisa ditandai tidak ditemukan.',
    'fail_only_open_tab' => 'Pembayaran hanya bisa ditandai tidak ditemukan selama tab masih terbuka.',
    'subject_outlet_required' => 'Subjek persetujuan harus memuat outlet_id.',
    'outlet_ids_empty' => 'outlet_ids tidak boleh kosong; sebutkan minimal satu outlet.',
];
