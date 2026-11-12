<?php

// Customer commerce (etalase + aplikasi Android pelanggan, 2026-11-11).
//
// Semua angka di sini adalah KEPUTUSAN PRODUK yang diambil pada nilai bawaan
// yang konservatif (lihat docs/android-customer-commerce/design.md); sengaja
// dikumpulkan di satu berkas supaya murah diubah tanpa menyentuh kode.
// Saklar fiturnya sendiri BUKAN di sini: ia satu baris `settings`
// (`customer_commerce_enabled`, bawaan MATI) yang bisa diubah pemilik toko.
return [

    // Umur token pelanggan dalam menit (14 hari). Tidak ada alur refresh di v1:
    // token kedaluwarsa = pelanggan masuk ulang. `sanctum.expiration` global
    // TIDAK dipakai karena itu juga akan mengubah token staf.
    'token_ttl_minutes' => (int) env('CUSTOMER_COMMERCE_TOKEN_TTL_MINUTES', 20160),

    // Batas keranjang: melindungi stok dari satu pesanan raksasa dan membatasi
    // jumlah baris yang dikunci (lockForUpdate) dalam satu transaksi checkout.
    'max_qty_per_line' => 20,
    'max_lines_per_order' => 50,

    // Ukuran badan permintaan maksimum (byte) untuk rute /customer dan /storefront: lebih dari ini ditolak
    // 413 `payload_too_large` sebelum diurai (middleware `customer.body`). Keranjang terbesar yang sah
    // (max_lines_per_order baris) hanya beberapa KB, jadi 64 KB sangat longgar.
    'max_body_bytes' => (int) env('CUSTOMER_COMMERCE_MAX_BODY_BYTES', 65536),

    // Alamat tersimpan per akun.
    'max_addresses' => 10,

    // Permintaan per MENIT. Limiter bernama (customer-register, customer-login,
    // storefront, customer-api, customer-checkout) membaca angka ini; kuncinya
    // diberi awalan sendiri supaya tidak pernah berbagi ember dengan
    // `throttle:5,1` milik login staf.
    'rate_limits' => [
        'register' => ['per_ip' => 5, 'per_email' => 3],
        'login' => ['per_ip' => 10, 'per_email' => 5],
        'storefront' => ['per_ip' => 120],
        'customer_api' => ['per_account' => 120],
        'checkout' => ['per_account' => 10],
    ],

    // Nama kurir (App\Support\Couriers::OPTIONS) => templat URL pelacakan,
    // wajib diawali https:// dan memuat {tracking_number}. Sengaja KOSONG:
    // belum ada templat kurir yang diverifikasi, jadi `tracking_url` selalu
    // null di v1. Jangan mengisi templat tebakan; tautan dibuat hanya dari
    // daftar ini, tidak pernah dari input staf.
    'tracking_url_templates' => [],

];
