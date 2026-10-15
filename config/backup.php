<?php

return [
    // Path ke media eksternal (flashdisk/HDD) untuk salinan kedua cadangan.
    // WAJIB diisi sebelum event pertama — tanpa ini, cadangan hanya ada
    // di satu laptop dan tidak melindungi dari kerusakan perangkat.
    'external_path' => env('BACKUP_EXTERNAL_PATH'),

    // Folder cadangan lokal (satu subfolder per cadangan). Bisa diubah lewat
    // BACKUP_PATH — test memakainya supaya tidak menyentuh cadangan asli.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
];
