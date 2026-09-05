<?php

return [
    // 018-license-activation — kunci PUBLIK Ed25519 (research.md R1).
    // Aman dikomit sebagai default dev/demo — verifikasi dengan kunci
    // publik TIDAK BISA dipakai memalsukan lisensi baru. Timpa lewat
    // env LICENSE_PUBLIC_KEY dengan kunci publik produksi sungguhan
    // sebelum instalasi ini benar-benar dijual ke pelanggan; kunci
    // privat yang berpasangan TIDAK PERNAH masuk ke berkas ini atau ke
    // .env manapun yang dikirim ke pelanggan (lihat GenerateLicense).
    'public_key' => env('LICENSE_PUBLIC_KEY', 'Ahk/844wVGdWZ0QPsHKD5o8DBaerDq7FnXpVHdkijww='),
];
