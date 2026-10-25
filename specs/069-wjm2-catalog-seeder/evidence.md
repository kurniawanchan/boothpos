# Bukti verifikasi: 069 pengisian katalog Odoo Warkop WJM 2

**Klasifikasi**: CONFIDENTIAL — merujuk katalog nyata milik pelanggan.

Semua perintah tes memakai flag wajib: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test …`. Tanggal kerja: 2026-10-25.

## 1. Pembangkitan katalog (T004–T008)

**Alat bangkit** (`specs/068-odoo-catalog-seeder/tools/build_catalog.py`) kini mengenali ekspor berbahasa Indonesia (berkas menurut akhiran model, kolom menurut alias), menerima nilai atribut dari `## Nilai atribut`, dan menulis format 2.

**T007 — katalog WJM 3 dibangkitkan ulang ke format 2 dan dibandingkan dengan versi 1.** Perbedaan hanya pada kunci format 2:

| Bagian | Hasil |
|---|---|
| Kunci akar | `outlet`, `withdraw_others`, `stations` ditambah; `outlet_name` dihapus; `format` 1 → 2 |
| `attributes[]` | hanya kunci `values_from` (semuanya `odoo`) |
| `categories[]` | hanya kunci `source_name` |
| `items`, `source`, `approval`, `initial_stock`, `excluded`, `option_groups` | identik |
| `photos/` | identik (58 berkas) |

**T008 — katalog WJM 2** dari `~/Downloads/WJM 2` dan `specs/069-wjm2-catalog-seeder/catalog-mapping.md` (DRAF):

| Hitungan | Hasil |
|---|---|
| Item / varian | 80 / 141 |
| Kategori dipakai / dikecualikan (tanpa item) | 8 / 5 (MAKANAN, LAIN LAIN, NASI, ROTI & PISANG, SIGNATURE COFFEE) |
| Baris sumber | 13 kategori, 0 nilai atribut dari ekspor, 80 produk |
| Foto | 11 berkas untuk 11 item (69 terpotong di ekspor XLSX) |
| Atribut dipakai (`values_from: pemetaan`) / dikecualikan | 5 / 9 |
| Nama varian sementara "(belum diisi n)" | 34 (JASJUS ×4, MINUMAN DINGIN SHOWCASE ×19, MIE SEDAP ×11) |
| Nama dirapikan | `MARTABAK TELUR  KORNET MINI` → `MARTABAK TELUR KORNET MINI` |
| Kolom "Penanggung Jawab" (nama staf) | tidak dibaca; `grep -i "penanggung\|responsible"` pada keluaran kosong |
| `approval.status` | `draft` |

Berkas `Order Point of Sale (pos.order).xlsx` di folder sumber **tidak dibuka**.

## 2. Temuan selama implementasi

Tiga hal ditemukan lewat tes yang ditulis lebih dulu, bukan lewat tinjauan:

1. **Tiket dapur salah alamat** (`WjmCatalogKitchenRoutingTest`): item outlet kedua dengan kategori bersama mengirim tiket ke stasiun outlet pertama (ID stasiun 1, bukan 3). Diperbaiki dengan `products.station_id` per item.
2. **Baris stok milik outlet lain** (`WjmCatalogSecondOutletTest`): hook pembuatan varian membukukan saldo nol di "outlet bawaan", yaitu outlet aktif pertama di mode itu bila tidak ada pengguna. Untuk katalog WJM 2, itu berarti baris stok nol untuk item WJM 2 di outlet WJM 3, yang muncul di daftar "stok menipis"-nya. ASSUMPTION (dibaca dari kode `OutletStockSync::defaultOutlet()`, tidak diverifikasi pada data): kelemahan yang sama ada di 068 pada mode DEMO, tempat outlet aktif pertama adalah outlet contoh lain, bukan WJM 3. Diperbaiki: hook dilewati saat pembuatan, saldo nol dibuat di outlet tujuan.
3. **`GET /pos/categories` tidak boleh menyembunyikan kategori tanpa item** (tes 055 `test_a_category_without_sellable_products_is_kept_with_count_zero`). Rumusan D10 diperhalus: hanya kategori yang punya item tetapi seluruhnya dijual di outlet lain yang disembunyikan.

Catatan jujur: T029 (pratinjau) dan T030 (jalan ulang) lulus pada percobaan pertama tanpa fase merah, karena perilakunya sudah dibangun di US1/US2. Tes itu berfungsi sebagai tes regresi. T023 (potret outlet pertama) juga lulus sebelum penarikan menu (T025) ditulis, karena pengisian tidak pernah menyentuh baris outlet pertama.

Satu kejadian proses: percobaan awal tes paritas memakai `migrate:fresh` di dalam tes dan sempat menyisakan proses yang menahan kunci metadata di DB **uji** (`warkop_wjm_test`), sehingga proses tes berikutnya menggantung. Proses dan sesi MySQL yatim itu dimatikan, tes paritas ditulis ulang tanpa `migrate:fresh`, dan DB dev (`warkop_wjm`) terbukti tidak tersentuh (158 produk, 222 varian, 3 outlet, 12 pengguna, stok 100).

## 3. Suite backend penuh (T035)

Pint dijalankan pada 25 berkas PHP yang berubah; dua berkas diperbaiki gayanya. Satu hasilnya dibatalkan: Pint menulis ulang 79 baris tak terkait di `ProductController` (nama kelas lengkap menjadi `use`), jadi berkas itu dikembalikan dari git dan hanya perubahan dua baris milik fitur ini yang diterapkan ulang.

**Hasil: 1991 lulus, 1 dilewati, 1 gagal (10.048 asersi, 214 detik).** Termasuk `StockLedgerInvariantTest`, `OutletScopeRegistryTest`, `PosCategoriesTest` (055), dan semua tes 068.

Satu kegagalan, **di luar 069 dan sama dengan yang tercatat di 068**: `ShiftReportParityTest::test_the_summary_keeps_every_existing_key_and_value`. Ringkasan shift kasir memuat `expected_cash`, padahal tes 048 (hitung buta) mengharuskannya tidak ada. Berasal dari 065; tidak diperbaiki di sini.

Sesudah suite penuh, satu perbaikan kecil lagi: baris pertanggungjawaban "nilai atribut" menghitung nilai dari tabel pemetaan sebagai "dipakai varian" (tertulis `0 = 37 dipakai varian`). Kini hanya nilai dari ekspor yang dihitung (`0 = 0 …`), dengan tes. Tes katalog dan kategori POS dijalankan ulang: 111 lulus.

## 4. Pratinjau terhadap DB dev (T036)

Cadangan `storage/app/backups/2026-10-25_075019`. Potret data WJM 3 (LIVE `WJM`, DEMO `WJ3`; 15 kelompok per mode: outlet, produk, varian, riwayat harga, saldo dan gerakan stok, penanda menu di outlet itu, kategori yang dipakai, stasiun, meja, perangkat, pengguna outlet, grup pilihan beserta opsi dan tautan) diambil dengan skrip hash read-only dari scratchpad, sebelum dan sesudah pratinjau.

`app:seed-wjm-catalog --catalog=database/seeders/data/wjm2-odoo/catalog.json --dry-run`:

| Mode | Outlet | Stasiun | Kategori (baru / dipakai bersama) | Item | Varian | Foto | Stok awal | Ditarik dari menu |
|---|---|---|---|---|---|---|---|---|
| LIVE | "Warkop WJM 2" `WJD`, sudah ada | 2 baru | 2 / 6 | 80 | 141 | 11 | 141 | 79 item WJM 3 |
| DEMO | "Warkop WJM 2" `WJ2`, akan dibuat | 2 baru | 2 / 6 | 80 | 141 | 11 | 141 | 79 item WJM 3 |

- Pertanggungjawaban sumber di kedua mode: produk 80 = 80 + 0 + 0; kategori 13 = 2 + 6 + 5 dikecualikan; nilai atribut dari ekspor 0 = 0.
- Peringatan yang tampil: katalog masih DRAF (pengisian sungguhan ditolak), 3 item berharga 0 (ABC MOCCA, ALL VARIANT MINUMAN DINGIN(SHOWCASE), KAPAL API KOPI SUSU), 4 item dengan nama varian sementara, 69 item tanpa foto.
- **Potret WJM 3 sesudah pratinjau identik dengan sebelumnya** (`cmp` pada kedua berkas JSON). Jumlah baris DB tidak berubah: 3 outlet, 158 produk, 222 varian, 4 stasiun, 222 gerakan stok, 4 log `catalog_seeded`; WJD tetap menjual tepat 79 item.

**Belum dilakukan:** pengisian sungguhan (T038) dan pemeriksaan peramban/tablet, karena (1) tabel pemetaan masih DRAF dan 34 nama varian belum diisi pemilik (T037), dan (2) penulisan ke DB dev perlu izin pengembang.

## 5. Persetujuan dan pengisian sungguhan di DB dev (T037, T038)

**Persetujuan (2026-10-25).** Pengembang menyetujui usulan apa adanya, dicatat sebagai peran Pemilik (seperti WJM 3), dengan satu penyesuaian: **4 item ditunda** karena nama variannya belum diketahui (JASJUS ALL VARIANT, ALL VARIANT MINUMAN DINGIN(SHOWCASE), MIE SEDAP KUAH, MIE SEDAP DOUBLE; 34 varian). Pembangkit mendapat bagian `## Item ditunda`: item itu tetap dihitung sebagai produk dikecualikan ("Ditunda: …") dan dibuat kelak lewat jalan ulang tanpa menyentuh item yang sudah ada. Tiga item berharga Rp0 diterima apa adanya; satu di antaranya (SHOWCASE) ikut ditunda, jadi hanya ABC MOCCA dan KAPAL API KOPI SUSU yang diisi Rp0. Katalog WJM 3 dibangkitkan ulang dengan alat yang sama: **byte-identik** dengan yang ada di repo.

Katalog WJM 2: 76 item, 107 varian, 11 foto, `approval.status = approved`. Tes katalog: 100 lulus.

**Pengisian sungguhan.** Cadangan `storage/app/backups/2026-10-25_075825`. Pratinjau katalog yang disetujui lebih dulu (bersih, produk 80 = 76 + 4 dikecualikan), lalu `app:seed-wjm-catalog --catalog=database/seeders/data/wjm2-odoo/catalog.json`, kedua mode `applied`:

| Mode | Outlet | Stasiun | Kategori (baru / bersama) | Item | Varian | Foto | Stok awal |
|---|---|---|---|---|---|---|---|
| LIVE | "Warkop WJM 2" `WJD` (sudah ada) | 2 baru | 2 / 6 | 76 | 107 | 11 | 107 |
| DEMO | "Warkop WJM 2" `WJ2` (dibuat) | 2 baru | 2 / 6 | 76 | 107 | 11 | 107 |

**Pemeriksaan DB sesudahnya** (per mode):
- Semua 107 varian melacak stok, saldo 100 di outlet WJM 2, satu gerakan `initial` +100 per varian; **0 baris stok di outlet lain** (temuan §2 poin 2 terbukti tertutup pada data sungguhan).
- Stasiun "Dapur Minuman" dan "Dapur Makanan"; semua 76 item punya `station_id`.
- Item WJM 2 dijual hanya di outlet WJM 2 (76) dan di tidak satu outlet lain; **tidak ada item WJM 3 yang dijual di outlet WJM 2** (79 ditarik dari menunya).
- Total produk 310 = 158 + 2 × 76; varian 436 = 222 + 2 × 107; gerakan stok 436.
- **Potret data WJM 3 (15 kelompok per mode) identik** sebelum pratinjau, sebelum pengisian, sesudah pengisian, dan sesudah jalan ulang (`cmp` pada JSON).
- **Jalan ulang**: kode keluar 0, semua "Dibuat" = 0, "Stok awal" 0, gerakan stok tetap 436, produk tetap 310.

**Belum dilakukan:** pemeriksaan peramban/tablet (menu WJM 2 saja, tiket Bar/Dapur ke stasiun WJM 2, POS WJM 3 tanpa tab baru, konsol bersih) dan pengisian ke server pelanggan (T039, butuh otorisasi eksplisit). Empat item yang ditunda menunggu nama varian dari pemilik.

