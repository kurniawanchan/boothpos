# Bukti verifikasi: 068 pengisian katalog Odoo WJM

**Klasifikasi**: INTERNAL — hanya jumlah dan nama menu; tanpa data pribadi.

**Tanggal**: 2026-10-24/25 · **Cabang**: `068-odoo-catalog-seeder`

## 1. Tes fitur (T023, T028, T033, T037)

Perintah (flag `-e` wajib, CLAUDE.md):

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array \
  app php artisan test --filter='WjmCatalog|ImageUploadServiceLocalFile'
```

| Berkas tes | Hasil |
|---|---|
| `WjmCatalogFileTest` (18 kasus, termasuk 13 katalog rusak) | lulus |
| `WjmCatalogCodeAllocationTest` (5) | lulus |
| `ImageUploadServiceLocalFileTest` (3) | lulus |
| `WjmCatalogSeedLiveTest` (7) + `WjmCatalogSeedCommandTest` (5) — US1 | lulus |
| `WjmCatalogSeedDemoTest` (8) — US2 | lulus |
| `WjmCatalogSeedRerunTest` (6) — US3 | lulus |
| `WjmCatalogSeedPreviewTest` (4) — US4 | lulus |

Siklus TDD: tiap kelompok tes dijalankan dan GAGAL sebelum implementasinya (26 gagal di fondasi, 10 di US1, 4 di US3). Pengecualian: cabang pratinjau (T035/T036) ditulis lebih awal bersama T022 agar `--dry-run` tidak pernah menulis data sungguhan; tesnya (T034) memverifikasi kode yang sudah ada.

Dua bug yang ditemukan di tes saat menjalankannya (bukan di implementasi), dicatat di komentar tes:
- `expectsOutputToContain()`: Mockery berhenti di ekspektasi pertama yang cocok, jadi dua teks pada satu baris keluaran membuat ekspektasi kedua tidak pernah terpenuhi.
- `Eloquent\Collection::only()` menyaring menurut primary key, bukan kunci larik.

## 2. Suite backend penuh (T040)

Perintah yang sama tanpa `--filter`, dijalankan 2026-10-24 ±23:57–00:00 WIB. Durasi 168 detik.

**Hasil: 1940 lulus, 1 dilewati, 1 gagal (9650 asersi).** Termasuk `StockLedgerInvariantTest` dan `OutletScopeRegistryTest` (pengaman jalur stok dan kueri ber-outlet), serta `SakanaFridgeDemoSeederTest` dan `WarkopOutletsDemoSeederTest`; semuanya lulus.

Satu kegagalan, **di luar 068**: `ShiftReportParityTest::test_the_summary_keeps_every_existing_key_and_value` (`tests/Feature/ShiftReportParityTest.php:61`). Ringkasan shift kasir memuat `expected_cash`, padahal tes 048 (hitung buta) mengharuskannya tidak ada. Bukti bahwa ini bukan akibat 068:

- Gagal identik bila dijalankan sendiri (deterministik, bukan efek pergantian hari).
- Jalur yang diuji (`ShiftReportService`, `CashierSessionController`, berkas tesnya) tidak merujuk `ProductCodeGenerator`, `ImageUploadService`, maupun kelas 068 mana pun. Tes membuat produk lewat factory, bukan generator kode.
- Perubahan terakhir pada kode yang menentukan `expected_cash` berasal dari 065 (`4e2bd13e`, `568aa563`). Catatan sesi 065 menyebut suite penuhnya (T050) belum pernah diverifikasi.
- Perubahan 068 pada berkas aplikasi yang sudah ada hanya bersifat tambahan: `ImageUploadService::storeLocalFile()`, serta metode baru plus refaktor `buildCodePrefix()` yang perilakunya dikunci tesnya sendiri.

Tes 068 dijalankan ulang setelah perapian gaya Pint (2 berkas): 56 lulus, 638 asersi.

## 3. Pratinjau terhadap DB dev (T041, sebagian)

`docker compose exec -T app php artisan app:seed-wjm-catalog --dry-run` pada DB dev (`system_mode = demo`; DEMO berisi menu contoh WJ3 dan tiga outlet Sakana; LIVE kosong). Durasi ±3 detik untuk kedua mode (SC-008: < 2 menit).

| Mode | Outlet | Stasiun | Kategori | Item | Varian | Grup | Foto | Catatan |
|---|---|---|---|---|---|---|---|---|
| LIVE | "Warkop WJM 3" `WJM`, akan dibuat (`WJ3` dipakai DEMO) | 2 baru | 10 | 79 | 111 | 1 | 61 | — |
| DEMO | "Warkop WJM 3" `WJ3`, sudah ada | 2 sudah ada | 10 | 79 | 111 | 1 | 61 | "Rasa → Rasa (WJM)"; 32 item contoh akan ditarik |

- Pertanggungjawaban sumber kedua mode: produk 80 = 79 + 0 + 1 dikecualikan; kategori 10; nilai atribut 26 = 19 dipakai varian + 3 opsi grup + 4 dikecualikan.
- Daftar laporan: 5 dikecualikan, 18 tanpa foto (6 terpotong di ekspor XLSX, 12 kosong), 1 harga 0 ("Nasi Putih"), 7 kit/BoM.
- Setelah pratinjau, DB dev tidak berubah: LIVE tetap tanpa outlet; DEMO tetap 4 outlet, 41 produk, 8 kategori, 6 grup; 0 log `catalog_seeded`; 32 item contoh masih dijual di WJ3; 0 berkas foto baru di `storage/app/public/products`.

Catatan saat itu: pengisian sungguhan ditolak karena katalog masih DRAF (FR-007). Lihat §4 untuk pengisian sesudah persetujuan.

## 4. Persetujuan, pengisian sungguhan, dan jalan ulang di DB dev (T042, T041)

**Persetujuan (T042).** Pada 2026-10-25 pemilik menyetujui semua usulan apa adanya. Status di `catalog-mapping.md` menjadi `Disetujui`, dengan peran Pemilik. `build_catalog.py` dijalankan ulang (T003). Diff `catalog.json` hanya mengubah blok `approval` (`draft` → `approved`, plus tanggal dan peran). Jumlahnya tetap: 10 kategori, 79 item, 111 varian, 57 berkas foto. `grep -ri responsible` tidak menemukan apa pun. Tes katalog: 56 lulus, 638 asersi.

**Cadangan.** `app:backup` → `storage/app/backups/2026-10-25_041515` (hanya lokal; `BACKUP_EXTERNAL_PATH` tidak diset).

**Pratinjau ulang.** Hasilnya identik dengan §3, kode keluar 0.

**Pengisian sungguhan** (`docker compose exec -T app php artisan app:seed-wjm-catalog`, kode keluar 0; durasi tidak diukur langsung, tetapi log LIVE dan DEMO berselisih 2 detik (04:15:33 → 04:15:35 UTC); SC-008):

| Mode | Outlet | Stasiun | Kategori | Item | Varian | Grup | Foto | Status |
|---|---|---|---|---|---|---|---|---|
| LIVE | "Warkop WJM 3" `WJM`, dibuat baru | 2 | 10 | 79 | 111 | 1 | 61 | applied |
| DEMO | "Warkop WJM 3" `WJ3` | 0 (2 sudah ada) | 10 | 79 | 111 | 1 | 61 | applied; "Rasa → Rasa (WJM)"; 32 item contoh ditarik |

**Pemeriksaan DB sesudah pengisian:**

- LIVE: 79 produk milik `WJM`, semuanya dijual di `WJM`. 61 produk berfoto.
- DEMO: produk baru milik `WJ3` = 79, dijual di WJ3 = 79 dan di NEK/YUK/HOS = 0 (FR-004). Ke-32 item contoh lama milik WJ3 kini tidak dijual di WJ3 (SC-002). Item itu tetap dijual di NEK/YUK/HOS karena penarikan hanya berlaku di outlet tujuan.
- Contoh harga LIVE (SC-001): Caramel Latte 17.000; Americano 1 shoot / 2 shoot 10.000; Jus Jambu / Alpukat 15.000; Nasi Putih 1 Porsi / ½ Porsi 0 (disetujui pemilik, dilaporkan "Harga 0, tinjau pemilik").
- Log aktivitas: 2 entri `catalog_seeded` per jalan (WJM, WJ3), id 216–217 dari pengisian dan 218–219 dari jalan ulang. Ini sesuai FR-019: satu entri per mode yang berhasil.

**Jalan ulang (§5):** semua "Dibuat" = 0, semua "Sudah ada" (10/79/111/61), status `applied` untuk kedua mode, kode keluar 0 (SC-003).

**Belum dilakukan:** pemeriksaan POS di peramban. Server MCP Playwright/Chrome DevTools gagal tersambung di sesi ini. Butir yang tersisa: foto tampil di kartu POS, tiket Hot/Ice masuk Bar dan makanan masuk Dapur, konsol bersih, dan uji harga hasil suntingan lewat layar Produk lalu jalan ulang. Ketahanan harga hasil suntingan sudah dikunci oleh `WjmCatalogSeedRerunTest`.

## 5. Pembersihan DB dev dan salinan suntingan DEMO → LIVE (2026-10-25)

**Pembersihan** (atas permintaan pengembang; cadangan `storage/app/backups/2026-10-25_050641`). Yang tersisa hanya outlet WJ3 (DEMO) dan WJM (LIVE) beserta katalog WJM, ditambah akun, peran, setelan, lisensi, kanal pembayaran, kode alasan, log aktivitas, serta perangkat dan meja WJ3. Outlet NEK/YUK/HOS, 41 produk contoh, bahan, vendor, BOM, dan seluruh transaksi DEMO dihapus dalam satu transaksi, dengan pemeriksaan FK tetap aktif. Pratinjau seeder sesudahnya: semua "Sudah ada", tidak ada yang ditarik.

**Suntingan di DEMO** (dibuat pengguna lewat layar Produk): harga 19 varian (Ice dan paket Ayam), nama 6 varian paket Ayam, dan foto Drink Beng-Beng.

- **Sumber seeder**: `catalog-mapping.md` mendapat 19 baris `## Harga varian khusus`, bagian baru `## Nama varian khusus` (6 baris), dan bagian baru `## Foto pengganti` (berkas di `photo-overrides/`). `build_catalog.py` kini membaca kedua bagian baru. Foto pengganti diperiksa dengan aturan yang sama dengan foto ekspor (JPEG/PNG, ≤ 5 MB, harus berada di folder tabel). Hasil bangkit ulang: 58 berkas foto untuk 62 item, 5 terpotong, 12 kosong. Isi `catalog.json` (item, varian, harga) sama persis dengan DEMO. Tes katalog: 56 lulus.
- **LIVE**: cadangan `storage/app/backups/2026-10-25_054428`. Harga diubah lewat `PriceService::setBasePrice` (riwayat harga + log `price_changed`). Nama varian dan foto (disalin lewat `ImageUploadService::storeLocalFile`) dicatat lewat `ActivityLogger`. Sesudahnya DEMO = LIVE = `catalog.json` untuk 111 varian (item, varian, harga, ada-tidaknya foto).

## 6. Stok awal 100 (2026-10-25)

Atas permintaan pengembang, semua varian katalog WJM melacak stok dengan saldo 100.

- **Seeder**: `catalog-mapping.md` mendapat baris `**Stok awal**: 100`. `build_catalog.py` menulisnya ke `catalog.json` sebagai `initial_stock`, dan `WjmCatalog` memvalidasinya (bilangan bulat ≥ 0 atau `null`; kunci wajib ada, fixture uji memakai `null`). `WjmCatalogSeedService` menyalakan `track_stock` dan mencatat gerakan `initial` lewat `StockService::applyMovement(outletId: …)` hanya untuk varian yang baru dibuat. Karena itu jalan ulang tidak menambah stok. Tes baru `test_an_initial_stock_tracks_every_new_variant_and_stocks_it_once_at_the_target_outlet` dibuat lebih dulu dan terbukti gagal sebelum implementasi. Hasil: 69 lulus, termasuk `StockLedgerInvariantTest` dan `OutletScopeRegistryTest`.
- **DB dev**: cadangan `storage/app/backups/2026-10-25_060327`. Untuk 222 varian (111 per mode), `track_stock` dinyalakan dan dicatat lewat `ActivityLogger`; gerakan `initial` +100 dicatat di outlet pemilik (WJM untuk LIVE, WJ3 untuk DEMO) lewat `StockService`. Hasil: semua `current_stock` = 100, semua saldo `variant_outlet_stock` = 100, dan 222 gerakan `initial` dengan total 22.200.
