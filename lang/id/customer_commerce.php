<?php

// Customer commerce (etalase + aplikasi Android pelanggan, 2026-11-11).
//
// Pesan untuk /api/v1/customer/* dan /api/v1/storefront/*. `titles` dan
// `details` dikunci per KODE mesin (CONTRACT §3): aplikasi Android memutuskan
// tindakan dari `code`, teks ini hanya untuk ditampilkan. Teksnya harus aman
// untuk publik: tanpa nama tabel/kelas, tanpa petunjuk apakah sebuah e-mail
// terdaftar, tanpa data pelanggan lain. Kunci harus sama persis dengan
// lang/en/customer_commerce.php (dijaga CustomerCommerceErrorEnvelopeTest).
return [
    'titles' => [
        'unauthenticated' => 'Sesi tidak valid',
        'invalid_credentials' => 'Gagal masuk',
        'forbidden' => 'Tidak diizinkan',
        'not_found' => 'Tidak ditemukan',
        'method_not_allowed' => 'Metode tidak didukung',
        'idempotency_key_reused' => 'Kunci permintaan sudah dipakai',
        'cart_invalid' => 'Keranjang perlu diperbarui',
        'price_changed' => 'Harga berubah',
        'address_limit_reached' => 'Batas alamat tercapai',
        'validation_failed' => 'Data tidak valid',
        'registration_failed' => 'Pendaftaran tidak dapat diproses',
        'idempotency_key_required' => 'Kunci permintaan wajib ada',
        'too_many_requests' => 'Terlalu banyak permintaan',
        'customer_commerce_disabled' => 'Toko sedang tidak menerima pesanan',
        'payload_too_large' => 'Permintaan terlalu besar',
        'installation_locked' => 'Toko belum diaktifkan',
        'server_error' => 'Kesalahan server',
        'request_failed' => 'Permintaan tidak dapat diproses',
    ],

    'details' => [
        'unauthenticated' => 'Sesi Anda tidak valid atau sudah berakhir. Silakan masuk kembali.',
        'invalid_credentials' => 'E-mail atau kata sandi salah.',
        'forbidden' => 'Anda tidak diizinkan melakukan tindakan ini.',
        'not_found' => 'Data yang Anda cari tidak ditemukan.',
        'method_not_allowed' => 'Metode permintaan ini tidak didukung.',
        'idempotency_key_reused' => 'Permintaan ini memakai kunci yang sudah dipakai untuk pesanan lain. Mulai ulang proses pemesanan.',
        'cart_invalid' => 'Sebagian barang di keranjang tidak lagi tersedia atau jumlahnya melebihi yang tersedia.',
        'price_changed' => 'Harga atau total pesanan berubah. Periksa ringkasan terbaru sebelum memesan.',
        'address_limit_reached' => 'Jumlah alamat tersimpan sudah mencapai batas. Hapus salah satu alamat untuk menambah yang baru.',
        'validation_failed' => 'Data yang dikirim tidak valid. Periksa kembali isian Anda.',
        'registration_failed' => 'Pendaftaran tidak dapat diproses. Periksa data Anda, atau masuk jika sudah punya akun.',
        'idempotency_key_required' => 'Permintaan pesanan harus menyertakan kunci permintaan yang valid.',
        'too_many_requests' => 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.',
        'customer_commerce_disabled' => 'Toko sedang tidak menerima pesanan baru lewat aplikasi. Silakan coba lagi nanti.',
        'payload_too_large' => 'Permintaan terlalu besar. Kirim data yang lebih sedikit.',
        'installation_locked' => 'Toko ini belum diaktifkan. Silakan coba lagi nanti.',
        'server_error' => 'Terjadi kesalahan di server. Silakan coba lagi beberapa saat lagi.',
        'request_failed' => 'Permintaan tidak dapat diproses. Periksa kembali lalu coba lagi.',
    ],

    // Galat per-field checkout (422 `validation_failed`) yang hanya bisa diputuskan service.
    'checkout' => [
        'duplicate_variant' => 'Satu barang hanya boleh muncul sekali di keranjang.',
        'address_invalid' => 'Alamat pengiriman tidak valid. Pilih salah satu alamat tersimpan Anda.',
        'contact_name_required' => 'Nama pemesan wajib diisi.',
        'contact_phone_required' => 'Nomor telepon pemesan wajib diisi.',
    ],

    // Galat field akun (422 `validation_failed`) yang diputuskan aturan kita sendiri.
    'account' => [
        'email_ascii' => 'E-mail hanya boleh memuat huruf, angka, dan simbol standar (tanpa huruf beraksen).',
        'password_too_long' => 'Kata sandi paling banyak :max byte (huruf beraksen dan non-Latin memakai lebih dari satu byte).',
    ],

    // Pesan per-field 422 `validation_failed`. Proyek ini tidak punya teks validasi, jadi Laravel mengembalikan
    // kunci mentah ("validation.in"); CustomerCommerceProblem menggantinya dengan kalimat di bawah (HANYA rute
    // pelanggan/etalase). Tanpa nama atribut: aplikasi menampilkan kalimatnya di samping field-nya.
    // Urutan pencarian: `<rule>.<type>` (rule ukuran: string/numeric/array/file), lalu `<rule>`, lalu `generic`.
    'validation_rules' => [
        'required' => 'Wajib diisi.',
        'required_if' => 'Wajib diisi untuk pilihan ini.',
        'string' => 'Harus berupa teks.',
        'max' => [
            'string' => 'Teks terlalu panjang.',
            'numeric' => 'Angka terlalu besar.',
            'array' => 'Jumlah isian terlalu banyak.',
            'file' => 'Berkas terlalu besar.',
        ],
        'min' => [
            'string' => 'Teks terlalu pendek.',
            'numeric' => 'Angka terlalu kecil.',
            'array' => 'Jumlah isian terlalu sedikit.',
            'file' => 'Berkas terlalu kecil.',
        ],
        'between' => 'Nilai berada di luar rentang yang diizinkan.',
        'size' => 'Ukuran atau jumlahnya tidak sesuai.',
        'email' => 'Masukkan alamat e-mail yang valid.',
        'in' => 'Pilihan yang dipilih tidak valid.',
        'integer' => 'Harus berupa bilangan bulat.',
        'numeric' => 'Harus berupa angka.',
        'array' => 'Harus berupa daftar.',
        'boolean' => 'Harus berupa ya atau tidak.',
        'regex' => 'Format isian tidak valid.',
        'distinct' => 'Nilai ini muncul lebih dari sekali.',
        'exists' => 'Pilihan yang dipilih tidak ditemukan.',
        'unique' => 'Nilai ini sudah dipakai.',
        'date' => 'Harus berupa tanggal yang valid.',
        'digits' => 'Jumlah digit tidak sesuai.',
        'generic' => 'Nilai ini tidak valid.',
    ],

    // Fulfilment oleh STAF (rute /customer-orders, amplop staf `{message, code}`). Kunci = kode mesin.
    // Teks untuk staf, bukan pelanggan; tetap tanpa data pribadi pelanggan.
    'fulfilment' => [
        'not_authorized' => 'Anda tidak memiliki akses ke pesanan pelanggan.',
        'invalid_transition' => 'Pesanan berstatus ":status" tidak bisa diproses dengan tindakan ini.',
        'wrong_fulfilment_method' => 'Tindakan ini tidak berlaku untuk metode pemenuhan pesanan ini.',
        'insufficient_stock' => 'Stok tidak cukup untuk mengonfirmasi pesanan ini. Tidak ada stok yang dikurangi.',
        'variant_unavailable' => 'Ada barang pesanan yang variannya sudah tidak tersedia. Tidak ada stok yang diubah.',
        'shipping_cost_courier_only' => 'Ongkos kirim hanya bisa diisi untuk pesanan yang dikirim kurir.',
        'shipping_cost_required' => 'Isi ongkos kirim untuk mengonfirmasi pesanan kurir (isi 0 bila gratis).',
        'delivered_before_dispatch' => 'Waktu diterima tidak boleh sebelum waktu pengiriman.',
        'shipment_nothing_to_update' => 'Isi nomor resi atau nama layanan yang ingin diubah.',
        'plain_text' => ':attribute harus berupa teks biasa, tanpa tag HTML atau karakter kontrol.',
    ],

    // Validasi pengaturan fitur (PUT /settings, staf).
    'settings' => [
        'enabled_must_be_boolean' => 'Saklar toko online hanya boleh bernilai aktif atau nonaktif.',
        'instructions_must_be_plain_text' => 'Petunjuk harus berupa teks biasa paling banyak :max karakter, tanpa tag HTML.',
    ],
];
