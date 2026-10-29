# Quickstart / verifikasi: Sinkronisasi Stok

> JANGAN menjalankan tes di dalam container `app` tanpa `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`. Di host: `php -d memory_limit=1G vendor/bin/phpunit --filter StockTransactionSyncTest`.

1. **Tes**: `StockTransactionSyncTest` — dry-run tidak menulis, apply menulis tepat sesuai Excel, idempoten, DEMO tidak tersentuh, hash basi ditolak, klem ke 0 dengan kekurangan.
2. **Dry-run nyata** pada database uji bersih (bukan data pemilik): buat varian + stok awal + penjualan + serah-terima + void, ketik stok lewat impor/penyesuaian, jalankan perintah, buka Excel.
3. **Apply**: `--apply --approve=<hash dari langkah 2> --by=owner` → stok berubah, dry-run ulang = nol.
4. **Data pemilik**: jalankan dry-run SAJA, tinjau Excel (kolom acuan, lembar Sumber), baru apply bila angka cocok. Pulihkan dengan `app:restore` pada cadangan pengaman bila perlu.
