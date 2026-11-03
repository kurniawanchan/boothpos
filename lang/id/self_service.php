<?php

// Pesan fitur pesan mandiri via QR meja (090). Kunci ditambahkan per-user-story.
// Halaman publik pelanggan SELALU berbahasa Indonesia; berkas en hanya untuk paritas kunci.
return [
    // Token QR / meja (Foundational) — pesan seragam: sengaja tidak membedakan sebab ke luar.
    'qr_invalid' => 'Kode QR ini tidak berlaku. Silakan minta bantuan staf.',
    'table_unavailable' => 'Meja ini sedang tidak melayani pesanan mandiri. Silakan minta bantuan staf.',

    // Kiriman (US1)
    'qris_unavailable' => 'Pembayaran QRIS belum tersedia di outlet ini. Silakan minta bantuan staf.',
    'item_unavailable' => 'Beberapa item tidak bisa dipesan saat ini. Silakan perbarui keranjang Anda.',
    'proof_required' => 'Foto bukti pembayaran wajib dilampirkan.',
    'proof_invalid' => 'Bukti pembayaran tidak valid. Silakan unggah ulang fotonya.',
    'proof_used' => 'Bukti pembayaran ini sudah dipakai. Silakan unggah foto yang baru.',
    'cart_empty' => 'Keranjang masih kosong.',
    'local_ref_taken' => 'Kiriman ini tidak dapat diproses. Silakan coba lagi.',
    'proof_file_invalid' => 'Berkas harus berupa foto JPEG atau PNG, maksimal 5 MB.',

    // QR meja (US2)
    'qr_base_url_invalid' => 'Alamat dasar Self-Service belum diatur atau tidak bisa dijangkau ponsel pelanggan (jangan localhost). Atur di Pengaturan → Self-Service.',
    'qr_batch_one_of' => 'Kirim salah satu: daftar meja terpilih (ids) atau filter.',
    'qr_batch_too_large' => 'Terlalu banyak meja ({max} maksimal). Persempit filter atau pilih meja lebih sedikit.',
    // Tindakan staf (US6)
    'invalid_transition' => 'Pesanan ini sudah berstatus "{status}" dan tidak bisa diproses dengan cara ini.',
    'verify_not_allowed' => 'Anda tidak berwenang memverifikasi atau menolak pembayaran QRIS.',
    'reference_min' => 'Referensi pembayaran minimal 4 karakter.',
    'reject_reason_required' => 'Alasan penolakan wajib diisi.',
    'no_open_shift' => 'Buka shift di outlet ini dulu sebelum mengirim pesanan ke dapur.',
    'total_changed' => 'Harga atau pajak berubah sejak pelanggan memesan, sehingga total tidak lagi sama dengan yang sudah dibayar. Tolak pesanan ini dan minta pelanggan memesan ulang.',
    'status_awaiting_verification' => 'menunggu verifikasi',
    'status_verified' => 'terverifikasi',
    'status_dispatched' => 'sudah dikirim ke dapur',
    'status_rejected' => 'ditolak',
    'receipt_not_found' => 'Struk tidak ditemukan. Periksa tautannya atau minta bantuan staf.',
    'customer_name_required' => 'Nama wajib diisi bila Anda mengisi data diri.',
    'customer_phone_invalid' => 'Nomor telepon tidak valid. Contoh: 081234567890.',
];
