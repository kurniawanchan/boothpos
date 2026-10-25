# Quickstart: pengisian katalog Odoo WJM

**Klasifikasi**: INTERNAL

Panduan verifikasi untuk pengembang dan operator. Semua perintah dijalankan dari akar repo `warkop-wjm` (proyek Compose `warkop_wjm`: app `:8010`, MySQL `:3308`). **Jangan** menjalankannya terhadap kontainer atau database `boothpos`.

## 1. Bangkitkan katalog (pengembang, setelah pemilik menjawab)

1. Masukkan jawaban pemilik ke `specs/068-odoo-catalog-seeder/catalog-mapping.md`: kategori, varian, bagian `## Harga varian khusus` dan `## Grup pilihan`. Ubah `**Status**:` menjadi `Disetujui`, lalu isi peran dan tanggal.
2. Bangkitkan data (berkas XLSX tetap di luar repo):

   ```bash
   python3 -I specs/068-odoo-catalog-seeder/tools/build_catalog.py \
     --src "/Users/chan/Downloads/WJM 3" \
     --mapping specs/068-odoo-catalog-seeder/catalog-mapping.md \
     --out database/seeders/data/wjm-odoo
   git diff --stat database/seeders/data/wjm-odoo
   ```

   Yang diharapkan: `catalog.json` dengan 10 kategori, 79 item, dan 111 varian (bila jumlah varian tidak diubah pemilik), `approval.status = approved`, serta 57 foto di `photos/`. Tidak ada kunci `responsible` di berkas.

Sebelum disetujui, alat yang sama menghasilkan katalog berstatus `draft`: pratinjau bisa jalan, pengisian sungguhan ditolak.

## 2. Tes otomatis

Selalu dengan flag `-e` (CLAUDE.md; tanpa flag ini database dev bisa terhapus):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test \
  -e CACHE_STORE=array -e SESSION_DRIVER=array \
  app php artisan test --filter='WjmCatalog|ImageUploadServiceLocalFile'
```

Lalu jalankan suite penuh dengan perintah yang sama tanpa `--filter`, termasuk `StockLedgerInvariantTest` dan `OutletScopeRegistryTest`.

| Tes | Membuktikan |
|---|---|
| `WjmCatalogFileTest` | Katalog sungguhan lolos validasi (kecuali status draf); 10/79/111; tanpa kunci terlarang; foto sah; tiap aturan validasi menolak katalog rusak (FR-020, FR-023, SC-006) |
| `WjmCatalogCodeAllocationTest`, `ImageUploadServiceLocalFileTest` | Kode kategori/segmen deterministik dan bebas tabrakan lintas mode; foto lokal lewat aturan unggah yang sama |
| `WjmCatalogSeedLiveTest`, `WjmCatalogSeedCommandTest` | US1: LIVE kosong → outlet + stasiun + katalog; hanya outlet tujuan yang menjual; draf/outlet ambigu ditolak; log aktivitas; tidak ikut `DatabaseSeeder`; opsi, konfirmasi produksi, kode keluar |
| `WjmCatalogSeedDemoTest` | US2: item contoh ditarik (tidak dihapus); outlet lain tidak menjual; grup "Rasa" bentrok → "Rasa (WJM)"; kode LIVE/DEMO berbeda; mode terpisah; perintah dan seeder memberi hasil sama |
| `WjmCatalogSeedRerunTest` | US3: jalan ulang = 0 perubahan; suntingan pemilik bertahan; item terhapus tidak dihidupkan; gagal di tengah = rollBack + foto terhapus + tanpa log; laporan mencakup semua baris |
| `WjmCatalogSeedPreviewTest` | US4: pratinjau tanpa perubahan atau berkas; hasilnya sama dengan jalan sungguhan; boleh pada draf |

## 3. Pratinjau di database dev

```bash
docker compose exec app php artisan app:backup                 # cadangan dulu
docker compose exec app php artisan app:seed-wjm-catalog --dry-run
```

Yang diharapkan di DB dev saat ini:

- **LIVE**: outlet "Warkop WJM 3" akan dibuat (kode `WJM`, karena `WJ3` sudah dipakai DEMO), plus 2 stasiun, 10 kategori, 79 item, dan 111 varian.
- **DEMO**: outlet `WJ3` yang sudah ada; 32 item contoh akan ditarik.
- Status `previewed`, kode keluar 0, dan jumlah baris tabel tidak berubah.

## 4. Isi sungguhan dan periksa

```bash
docker compose exec app php artisan app:seed-wjm-catalog
```

Periksa di peramban (`http://localhost:8010`, akun `owner`):

1. **DEMO** (mode aktif dev): POS outlet Warkop WJM 3 menampilkan 10 kategori, item berfoto (SC-007), dan tidak ada item contoh (SC-002). Pesan satu item Hot/Ice dan satu makanan: tiket masuk Bar dan Dapur. Konsol peramban bersih.
2. Outlet DEMO lain (mis. NEK) tidak menampilkan item WJM (FR-004).
3. Ganti ke **LIVE** di Pengaturan: outlet Warkop WJM 3 berisi katalog yang sama; harga item sama dengan sumber (SC-001).
4. **Log aktivitas**: ada satu entri `catalog_seeded` per mode (FR-019).

## 5. Jalan ulang

```bash
docker compose exec app php artisan app:seed-wjm-catalog
```

Laporan: semua "Dibuat" = 0, semua item "Sudah ada", status `applied`, kode keluar 0 (SC-003). Ubah harga satu item lewat layar Produk, jalankan lagi: harga hasil suntingan bertahan.

## 6. Server pelanggan (produksi)

```bash
php artisan app:backup
php artisan app:seed-wjm-catalog --mode=live --dry-run      # baca laporannya
php artisan app:seed-wjm-catalog --mode=live --force        # APP_ENV=production butuh --force
php artisan app:seed-wjm-catalog --mode=demo --force        # opsional: menu latihan
```

Bila LIVE punya beberapa outlet tanpa nama "Warkop WJM 3", tambahkan `--outlet=KODE` bersama satu `--mode`. Pemulihan: `php artisan app:restore <berkas.sql>` (ingat: restore juga mengembalikan `license_activations`, CLAUDE.md).
