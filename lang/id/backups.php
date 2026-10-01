<?php

return [
    'not_authorized' => 'Hanya owner/admin yang dapat mencadangkan atau memulihkan database.',
    'not_found' => 'Cadangan tidak ditemukan.',
    'invalid_dump' => 'Berkas ini tidak tampak seperti cadangan database aplikasi ini (tabel settings dan users tidak ditemukan).',
    'safety_backup_failed' => "Cadangan pengaman data saat ini gagal dibuat, sehingga pemulihan dibatalkan dan tidak ada yang diubah: :reason",
    'upload_failed' => 'Berkas gagal diunggah. Pastikan berkas .sql berukuran paling besar 50 MB, lalu coba lagi.',
];
