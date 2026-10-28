# Quickstart / verifikasi: Nama Varian di Ekspor Pre-order

1. Backend (host, DB `boothpos_test`): `php -d memory_limit=1G vendor/bin/phpunit --filter PreorderExportImportTest`.
2. Manual: buat pre-order dengan dua varian (satu bernama mengandung koma), ekspor dari layar Pre-order, buka xlsx → `variant_names` bersebelahan dengan `products`, jumlah bagian sama.
3. Round-trip: unggah berkas hasil ekspor ke Impor dengan `dry_run=1` → tanpa galat baru, SKU/kuantitas/harga sama.
4. Keamanan: ganti nama sebuah varian menjadi `=1+1`, ekspor, buka di Excel → tampil sebagai teks `=1+1`, bukan `2`.
5. Template: unduh template impor → tidak ada kolom `variant_names`.
