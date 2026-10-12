<?php

// Pesan fitur restoran (028). Kunci ditambahkan per-user-story.
return [
    'forbidden' => 'Anda tidak diizinkan melakukan ini.',

    // Meja (US1)
    'table_name_taken' => 'Meja dengan nama ini sudah ada di area tersebut.',
    'table_has_open_tab' => 'Meja ini memiliki tab yang masih terbuka. Selesaikan atau batalkan tab sebelum menonaktifkan meja.',
    'table_has_history' => 'Meja ini pernah dipakai order dan tidak bisa dihapus. Nonaktifkan saja.',

    // Tab (US2)
    'local_ref_not_a_tab' => 'Referensi ini milik penjualan, bukan tab.',
    'table_inactive' => 'Meja ini nonaktif dan tidak bisa menerima tab baru.',
    'table_occupied' => 'Meja ini sudah punya tab yang terbuka.',
    'outlet_mismatch' => 'Meja ini milik outlet lain; tab tidak bisa dipindahkan antar-outlet.',
    'tab_not_open' => 'Tab ini sudah tidak terbuka.',
    'tab_not_empty' => 'Tab ini masih berisi item. Hapus itemnya, atau batalkan tab.',
    'empty_tab_reason' => 'Tab kosong ditutup',
    'tab_empty' => 'Tab belum berisi item untuk dibayar.',
    'line_already_sent' => 'Item ini sudah dikirim ke dapur dan tidak bisa diubah di sini.',
    'total_below_paid' => 'Total tab tidak boleh turun di bawah jumlah yang sudah dibayar.',

    // Modifier (US3)
    'modifier_rule_violated' => 'Grup ":group": pilih :range opsi.',
    'modifier_option_invalid' => 'Salah satu opsi yang dipilih tidak tersedia untuk item ini.',
    'modifier_price_negative' => 'Opsi yang dipilih membuat harga item menjadi negatif.',
    'modifier_min_above_max' => 'Pilihan minimum tidak boleh lebih besar dari maksimum.',
    'modifier_option_not_in_group' => 'Id opsi tidak termasuk dalam grup ini.',
    'modifier_group_has_no_options' => 'Tambahkan minimal satu opsi aktif sebelum melampirkan grup ini.',
    'modifier_group_in_use' => 'Grup ini pernah dipakai pada order dan tidak bisa dihapus. Nonaktifkan saja.',
    'attachment_not_found' => 'Satu atau lebih item yang dipilih tidak ada.',

    // Dapur (US4)
    'station_name_taken' => 'Stasiun dengan nama ini sudah ada.',
    'station_in_use' => 'Stasiun ini ditugaskan ke kategori/produk, atau pernah menerima tiket. Nonaktifkan saja.',
    'station_not_found' => 'Stasiun yang dipilih tidak ada.',
    'nothing_to_send' => 'Tidak ada item baru untuk dikirim ke dapur.',
    'ticket_transition_invalid' => 'Item berstatus ":from" tidak bisa dipindah ke ":to".',

    // Pindah / gabung / pisah tagihan (US5)
    'merge_same_tab' => 'Tab tidak bisa digabung ke dirinya sendiri.',
    'merge_different_shift' => 'Hanya tab dari shift kasir yang sama yang bisa digabung.',
    'merge_source_has_payments' => 'Tab yang akan digabung sudah punya pembayaran dan tidak bisa digabung.',
    'merged_reason' => 'Digabung ke :number',
    'split_has_payments' => 'Tab yang sudah punya pembayaran tidak bisa dipisah.',
    'split_partial_sent_line' => '":name" hanya bisa dipisah per baris utuh selagi masih diproses.',
    'split_qty_exceeds' => 'Jumlah yang dipisah melebihi jumlah ":name" pada tab.',
    'split_paid_in_full_reason' => 'Dibayar penuh melalui pisah tagihan',

    // Batal item / batal tab (US6)
    'cancel_reason_required' => 'Alasan wajib diisi untuk membatalkan item yang sudah dikirim.',
    'cancel_after_prep_forbidden' => 'Hanya owner atau admin yang bisa membatalkan item yang sudah mulai diproses.',
    'void_has_payments' => 'Tab yang sudah punya pembayaran tidak bisa dibatalkan.',
];
