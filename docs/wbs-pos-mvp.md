**Klasifikasi: INTERNAL**

# WBS — BoothPOS

*Sistem POS event-based multi-artist untuk toko merchandise*

| Field | Isi |
|---|---|
| Versi | v1.4 |
| Tanggal | 2 September 2026 |
| Cakupan | MVP Oktober 2026, ditambah status pelaksanaan pasca-MVP per tanggal dokumen ini |
| Sumber daya | Tim developer dengan bantuan AI |
| Acuan | PRD v1.6, `schema-pos-mvp.sql`, `uml-pos-mvp.md`, `openapi-pos-mvp.yaml` |

---

**Addendum v1.2** — menambahkan task 2.8 (gate lisensi Pro/Master, 4 jam) sesuai keputusan produk multi-artist toggle. Ini penambahan aditif +4 jam terhadap total 215 jam pada bagian 3; tidak mengubah struktur jadwal mingguan di bagian 5 secara material karena berada di paket yang sama (2.0 Master data, minggu 2) dan masih di bawah cadangan yang ada. Tidak dilakukan kalkulasi ulang penuh kaskade seluruh dokumen untuk penambahan sekecil ini — proporsional dengan skala perubahan.

**Addendum v1.4 (2 September 2026)** — dokumen ini berhenti diperbarui sejak commit pertama (`31c588c`) meski seluruh cakupan MVP di bagian 2 sudah dikerjakan, plus kapabilitas pasca-MVP yang tidak pernah punya baris WBS sama sekali: Log Aktivitas (F13.4), Settings admin CRUD, backup/restore (WBS 9.2 — sudah dieksekusi dan diverifikasi, lihat catatan pada 9.2 di bawah), seluruh frontend Vue, unggah gambar produk/kategori/kanal pembayaran, ekspor/impor Excel master data (8 sheet), dan modul Vendor/Bahan Baku/BOM. Bagian ini menandai baris yang sudah selesai (kolom **Status**, ditambahkan pada setiap tabel paket kerja) dan menambahkan baris baru untuk kapabilitas yang genuinely baru (paket 2.9, 6.6, 9.7–9.9, 10.0, 11.0, dan tiga baris F9.5/F10.6/F11.6 di paket 8.0). **Tidak dilakukan kalkulasi ulang jam/jadwal/kapasitas di bagian 3–6** — proyek sudah lewat dari fase estimasi ke fase eksekusi; baris baru diberi estimasi jam sendiri untuk keperluan pencatatan cakupan, bukan untuk disusun ulang ke jadwal mingguan yang sudah lewat. Item F9.5/F10.6/F11.6 (PRD, ditambahkan 2026-09-02) **belum dibangun** per tanggal dokumen ini — dicatat "Belum dikerjakan" apa adanya, bukan diberi tanda selesai.

---

## 1. Ringkasan temuan

**Cakupan penuh dipertahankan.** Pre-order dan seluruh fitur pendukung tetap masuk MVP Oktober, tanpa pemotongan.

| Perhitungan | Jam |
|---|---|
| Estimasi dasar tanpa bantuan AI | 292 |
| Estimasi dengan bantuan AI | 215 |
| Cadangan bug 15% | 32 |
| **Kebutuhan total** | **247** |
| Kapasitas 1 developer selama 8 minggu | 240 |
| **Selisih** | **-7** |

Dengan satu developer dibantu AI, angkanya nyaris pas — dan "nyaris pas" berarti tidak ada ruang sama sekali untuk sakit, satu minggu yang buruk, atau satu masalah teknis yang memakan tiga hari. Penambahan kapasitas yang direncanakan mengubah ini dari taruhan menjadi rencana yang wajar. Bagian 4 memuat rinciannya.

ASSUMPTION — kapasitas dihitung dari 8 minggu × 5 hari × 6 jam produktif per hari. Bila developer tidak bekerja penuh waktu, angka 240 jam harus dikoreksi turun.

---

## 2. Struktur rincian kerja

Estimasi dalam jam, mencakup API dan antarmuka sekaligus kecuali disebut lain.

### 1.0 Fondasi & lingkungan — 32 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 1.1 | Instalasi lingkungan localhost dan skrip setup | 6 | — | Laptop dapat menjalankan PHP, MySQL, web server |
| 1.2 | Inisialisasi Laravel, struktur folder, konfigurasi environment | 3 | 1.1 | Kerangka backend berjalan |
| 1.3 | Setup Vue: build pipeline, routing, layout dasar | 6 | 1.2 | Halaman kosong ter-render dari Laravel |
| 1.4 | Migration seluruh tabel dari skema | 8 | 1.2 | 18 tabel terbentuk beserta constraint |
| 1.5 | Seeder data awal: pengguna, pengaturan, kanal pembayaran | 3 | 1.4 | Sistem dapat login dengan data contoh |
| 1.6 | Autentikasi token dan middleware peran | 6 | 1.5 | Endpoint terlindungi sesuai peran |

**Status (2 September 2026): 1.1–1.6 seluruhnya selesai.** Autentikasi token (Sanctum) dan middleware peran berjalan; 214 test backend dan 90 test frontend lulus di atas fondasi ini.

### 2.0 Master data — 48 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 2.1 | CRUD artist | 6 | 1.6 | Modul artist berfungsi |
| 2.2 | CRUD kategori | 5 | 1.6 | Modul kategori berfungsi |
| 2.3 | Generator kode produk: prefix 8 karakter dan SKU 12 karakter | 6 | 2.1, 2.2 | Kode unik terbentuk otomatis |
| 2.4 | API produk dan varian | 8 | 2.3 | Endpoint produk sesuai kontrak |
| 2.5 | Antarmuka produk dan varian | 10 | 2.4 | Form multi-varian dapat dipakai |
| 2.6 | Unggah dan tampil foto produk | 4 | 2.5 | Foto tersimpan di penyimpanan lokal |
| 2.7 | CRUD pelanggan dan pencarian cepat | 5 | 1.6 | Modul pelanggan berfungsi |
| 2.8 | Gate lisensi Pro/Master: setting `multi_artist_enabled`, endpoint status fitur, penegakan kuota artist | 4 | 2.1 | Instalasi Pro membatasi 1 artist, Master tidak terbatas |
| 2.9 | *(baru, pasca-MVP)* Unggah/ganti gambar kategori — `POST /categories/{id}/image`, pola sama dengan 2.6 | 2 | 2.2 | Kategori punya gambar seperti produk |

**Status (2 September 2026): 2.1–2.9 seluruhnya selesai**, termasuk 2.9 yang tidak ada di versi WBS sebelumnya (kategori sebelumnya tidak punya kolom gambar sama sekali; `image_path` ditambahkan lewat migrasi `2026_10_07_000001_add_image_path_to_categories_table`). Lihat paket 10.0 dan 11.0 di bawah untuk kapabilitas Master Data pasca-MVP yang lebih besar (impor/ekspor Excel, Vendor/Bahan Baku/BOM) — sengaja dipisah sebagai paket sendiri, bukan ditambahkan ke sini, karena keduanya adalah modul baru dengan kontrak API sendiri, bukan perluasan kecil dari 2.1–2.8.

### 3.0 Stok — 20 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 3.1 | Service pergerakan stok: append-only, transaksional | 8 | 2.4 | Semua perubahan stok lewat satu jalur |
| 3.2 | Penyesuaian stok massal untuk stok awal dan opname | 6 | 3.1 | Stok awal dapat diinput |
| 3.3 | Halaman riwayat pergerakan beserta filter | 4 | 3.1 | Riwayat dapat ditelusuri |
| 3.4 | Peringatan stok menipis | 2 | 3.1 | Indikator pada daftar produk |

**Status (2 September 2026): 3.1–3.4 seluruhnya selesai.** Jalur `StockService::applyMovement()` juga menjadi jalur tunggal yang dipakai fitur pasca-MVP (impor Excel sheet `stock`, paket 10.0) — konsisten dengan aturan "satu jalur tulis stok" yang sudah ditegakkan 3.1.

### 4.0 Event & sesi kasir — 22 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 4.1 | CRUD event dan state machine status | 6 | 1.6 | Event dapat dibuat dan ditutup |
| 4.2 | Buka sesi kasir dengan kas awal | 4 | 4.1 | Sesi tercatat |
| 4.3 | Tutup sesi: hitung kas seharusnya dan selisih | 6 | 4.2, 5.3 | Selisih kas terhitung otomatis |
| 4.4 | Ringkasan sesi per metode bayar | 4 | 4.3 | Rekap sesi dapat dilihat |
| 4.5 | Penegakan aturan sesi terbuka pada transaksi | 2 | 4.2 | Transaksi ditolak bila sesi tertutup |

**Status (2 September 2026): 4.1–4.5 seluruhnya selesai.**

### 5.0 Transaksi penjualan — 48 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 5.1 | Endpoint pencarian varian untuk kasir | 4 | 2.4 | Respons di bawah 300 ms |
| 5.2 | Antarmuka kasir: grid produk, pencarian, keranjang | 14 | 5.1 | Layar kasir dapat dioperasikan |
| 5.3 | Service pembuatan order: transaksional, snapshot harga, potong stok | 12 | 3.1, 4.5 | Transaksi tersimpan utuh atau batal seluruhnya |
| 5.4 | Idempotensi melalui `local_ref` | 3 | 5.3 | Kirim ulang tidak menghasilkan duplikat |
| 5.5 | Perhitungan total, diskon, dan kembalian | 5 | 5.3 | Angka konsisten dengan server |
| 5.6 | Struk di layar dan tampil ulang dari riwayat | 6 | 5.3 | Struk terbaca jelas saat difoto |
| 5.7 | Pembatalan transaksi dan pengembalian stok | 4 | 5.3 | Void tercatat, stok kembali |

**Status (2 September 2026): 5.1–5.7 seluruhnya selesai.** 5.7 kini juga menulis baris Log Aktivitas (paket 9.7) di transaksi database yang sama — lihat `uml-pos-mvp.md` bagian 10.

### 6.0 Pembayaran & bukti — 26 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 6.1 | CRUD kanal pembayaran dan penyamaran nomor rekening | 4 | 1.6 | Rekening BCA dan Mandiri terkonfigurasi |
| 6.2 | Unggah bukti: validasi tipe, ukuran, penyimpanan aman | 8 | 1.6 | Berkas tersimpan di luar direktori publik |
| 6.3 | Integrasi webcam untuk memotret bukti | 8 | 6.2 | Foto dapat diambil dari peramban |
| 6.4 | Antarmuka pemilihan metode dan tampilan rekening atau QR | 4 | 6.1 | Nomor terbaca dari jarak meja |
| 6.5 | Penegakan bukti wajib di sisi server | 2 | 6.2, 5.3 | Transaksi non-tunai ditolak tanpa bukti |
| 6.6 | *(baru, pasca-MVP)* Unggah/ganti gambar QR kanal pembayaran — `POST /payment-channels` (field `qr_image`) dan `POST /payment-channels/{id}` (endpoint update yang sebelumnya tidak ada sama sekali) | 5 | 6.1 | Kanal QR (Gopay dkk) bisa diberi/diganti gambar QR |

**Status (2 September 2026): 6.1–6.6 seluruhnya selesai**, termasuk perbaikan dua bug yang sempat membuat checkout non-tunai buntu: (1) backend memanggil nama route yang tidak pernah didefinisikan saat merender `qr_image_url`, menyebabkan `GET /payment-channels` gagal 500 begitu ada satu kanal berQR; (2) frontend (`ChannelPicker.vue`) tidak auto-pilih kanal saat hanya ada satu channel aktif, sehingga layar checkout QRIS tampak kosong selamanya. Detail di `uml-pos-mvp.md` bagian 13.2.

### 7.0 Pre-order & pengiriman — 38 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 7.1 | API pre-order beserta item | 8 | 2.7, 3.1 | Endpoint sesuai kontrak |
| 7.2 | Antarmuka pre-order: form, daftar, detail | 10 | 7.1 | Pre-order dapat dicatat di venue |
| 7.3 | State machine status dan efek stok pada `arrived` dan `handed_over` | 8 | 7.1 | Lompatan status ditolak |
| 7.4 | Pembayaran DP dan pelunasan | 6 | 7.1, 6.2 | Sisa tagihan terhitung |
| 7.5 | Pengiriman: alamat, kurir, ongkir, resi, status | 6 | 7.1 | Pengiriman dapat dilacak |

**Status (2 September 2026): 7.1–7.5 seluruhnya selesai**, termasuk perbaikan bug F13.4-terkait di mana `GET /preorders/{id}` (dan hasil `PATCH`/`POST` pre-order lainnya) sempat menghilangkan `customer`, `payments`, dan `shipment` dari response karena relasi tidak dimuat sebelum `present()` membacanya (pola `relationLoaded()` gagal senyap). Sudah diperbaiki — lihat `uml-pos-mvp.md` bagian 4.

### 8.0 Laporan & ekspor — 28 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 8.1 | Laporan penjualan per event dan pengelompokan | 8 | 5.3 | Angka cocok dengan transaksi |
| 8.2 | Rekap hasil artist dan pencatatan pembayaran | 8 | 5.3 | Rekap otomatis per event |
| 8.3 | Laporan modal dan keuntungan | 5 | 5.3 | Laba kotor dan bersih terhitung |
| 8.4 | Ekspor Excel untuk laporan | 7 | 8.1, 8.2 | Berkas xlsx dapat diunduh |
| 8.5 | *(baru, PRD F9.5, 2026-09-02)* Laporan modal dan laba kotor per artist, terpisah dari biaya event | 6 | 8.2, 8.3 | Modal & laba per artist terlihat tanpa dua kali potong `event_cost` |
| 8.6 | *(baru, PRD F10.6, 2026-09-02)* Pencarian pada daftar transaksi laporan penjualan (nomor transaksi/nama pelanggan/nama kasir) | 3 | 8.1 | Filter tanpa reload seluruh laporan |
| 8.7 | *(baru, PRD F11.6, 2026-09-02)* Drill-down transaksi penyusun rekap artist, di layar dan di berkas ekspor | 5 | 8.2, 8.4 | Detail order per artist terlihat, bukan cuma angka total |

**Status (2 September 2026): 8.1–8.4 selesai. 8.5–8.7 BELUM DIKERJAKAN** — F9.5/F10.6/F11.6 baru ditambahkan ke PRD pada tanggal dokumen ini (lihat PRD §7.9/§7.10/§7.11, "Catatan penambahan — 2026-09-02"); PRD sendiri mencatat ketiganya "belum dibangun per tanggal catatan ini". Baris 8.5–8.7 dicatat di sini supaya cakupannya tidak hilang dari WBS, bukan diberi tanda selesai.

### 9.0 Operasional & rilis — 34 jam

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 9.1 | Skrip cadangan otomatis basis data dan berkas | 6 | 1.4 | Cadangan berjalan terjadwal |
| 9.2 | Uji pemulihan dari cadangan | 3 | 9.1 | Terbukti dapat dipulihkan |
| 9.3 | Penanganan galat dan pengerasan validasi | 8 | 5.3, 7.1 | Galat tertangani, tidak layar putih |
| 9.4 | Input data produk sungguhan | 4 | 2.5 | Katalog siap pakai |
| 9.5 | Simulasi event penuh sebagai uji terima | 8 | Seluruhnya | Daftar bug prioritas |
| 9.6 | Panduan operasional dan pelatihan operator | 5 | 9.5 | Operator dapat menjalankan sendiri |
| 9.7 | *(baru, pasca-MVP, F13.4)* Log aktivitas: `ActivityLogger` service, penulisan di transaksi yang sama dengan hapus/penyesuaian stok/perubahan harga/pengaturan, endpoint baca `GET /activity-logs` | 8 | 1.6 | Tindakan sensitif tercatat dan bisa ditinjau owner/admin |
| 9.8 | *(baru, pasca-MVP)* Settings admin CRUD: `GET/PUT /settings`, `GET /settings/features`, jalur resmi upgrade lisensi Pro→Master (menggantikan `tinker`) | 5 | 1.6, 2.8 | Pengaturan toko & upgrade lisensi lewat endpoint, tercatat di log aktivitas |
| 9.9 | *(baru)* Frontend Vue SPA lengkap: Pinia stores, router, composables (`usePaginatedList`, error handler 422/409/403/401), seluruh layar (dashboard, kasir, master data, laporan, settings) | — | 5.2, dst. | 90 test frontend (Vitest) lulus, `npm run build` menghasilkan `public/build` |

**Status (2 September 2026): 9.1–9.6 seluruhnya selesai, plus 9.7–9.9 baru (tidak ada di versi WBS sebelumnya).** Catatan khusus untuk **9.2 (uji pemulihan dari cadangan)** — sebelumnya tercatat sebagai kewajiban yang ditunda: **sudah dieksekusi dan diverifikasi sungguhan pada sesi ini.** `php artisan app:backup` dijalankan terhadap database dev, menghasilkan dump MySQL penuh (22 tabel) plus arsip `payment-proofs.tar.gz`, keduanya tersalin ke `BACKUP_EXTERNAL_PATH`; dump dipulihkan ke database terpisah lewat `php artisan app:restore` dan jumlah baris/isi data (username, role, key/value pengaturan) dikonfirmasi cocok persis dengan sumbernya; arsip bukti pembayaran dikonfirmasi bisa diekstrak dan isinya cocok dengan lokasi penyimpanan sesungguhnya. Test suite tetap lulus setelah database restorasi di-reset ulang oleh `RefreshDatabase`. Verifikasi memakai shim `docker exec` sementara (tidak dikomit, tidak bagian dari produk) karena mesin dev hanya punya MySQL di kontainer Docker — kode `BackupPos`/`RestorePos` sendiri tetap mengasumsikan `mysqldump`/`mysql` ada langsung di `PATH` server toko sungguhan. Yang MASIH belum dilakukan: uji pemulihan dari media eksternal fisik (flashdisk/HDD nyata) dan penjadwalan otomatis harian (`Schedule::command('app:backup')` belum ada di `routes/console.php`) — detail lengkap di `README.md` bagian "Cadangan & pemulihan (WBS 9.2)".

### 10.0 Impor/ekspor Excel master data — pasca-MVP (PRD 7.15, diaktifkan kembali 2026-09-01) — 24 jam

Dicoret dari cakupan MVP awal (PRD §10.2 lama), diaktifkan kembali atas permintaan eksplisit pemilik produk pada 2026-09-01. Tidak ada di versi WBS sebelumnya sama sekali.

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 10.1 | `MasterDataSheets`: kamus judul kolom bersama untuk ekspor/template/impor, 8 sheet (artists, categories, products, stock, vendors, materials, vendor_prices, bom) | 3 | 2.0, 11.0 | Satu sumber kebenaran nama sheet & kolom |
| 10.2 | `GET /exports/{entity}`: ekspor satu entitas ke `.xlsx` | 3 | 10.1 | Berkas round-trip balik ke impor |
| 10.3 | `GET /imports/master-data/template`: unduh workbook gabungan 8 sheet dengan contoh baris | 2 | 10.1 | Template siap pakai |
| 10.4 | `MasterDataImportService`: validasi penuh + transaksi tunggal semua-atau-tidak-sama-sekali, urutan pemrosesan tetap, `dry_run` | 10 | 3.1, 10.1 | Impor 8 sheet konsisten, tanpa data setengah jadi |
| 10.5 | Resolusi SKU tertunda: sheet `stock`/`bom` boleh menunjuk SKU yang baru dibuat sheet `products` pada berkas yang sama | 3 | 10.4 | Satu berkas bisa membuat varian baru sekaligus mengisi stok/BOM-nya |
| 10.6 | Task 6 — gambar via `image_filename` + `images[]`: pencocokan nama berkas, galat per-baris bila referensi tidak ada padanan | 3 | 10.4, 2.9 | Produk/kategori bisa dapat gambar lewat impor massal |

**Status (2 September 2026): 10.1–10.6 seluruhnya selesai.** Digerbang `canManageMasterData()` (owner/admin/inventory) — lebih ketat dari endpoint baca per-entitas, karena ekstraksi massal adalah permukaan risiko sendiri.

### 11.0 Vendor, Bahan Baku, dan BOM — pasca-MVP (ditambahkan 2026-09-01) — 26 jam

Bukan kebangkitan "vendor management"/"materials & production" yang dicoret PRD §10.2 — sengaja lebih sempit (tidak ada purchase order, tidak ada penjadwalan produksi), tidak berkorespondensi dengan nomor F- manapun. Tidak ada di versi WBS sebelumnya sama sekali.

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 11.1 | CRUD vendor (`code` permanen, soft delete dengan guard) | 4 | 1.6 | Modul vendor berfungsi, konsisten pola Artist/Category |
| 11.2 | CRUD bahan baku (`code`, `unit` bebas, soft delete dengan guard ganda: harga vendor + BOM) | 4 | 1.6 | Modul bahan berfungsi |
| 11.3 | Harga vendor per bahan: attach/update/detach, unique (vendor,bahan), `is_preferred` saling eksklusif per bahan | 6 | 11.1, 11.2 | Satu bahan bisa punya harga dari banyak vendor |
| 11.4 | BOM per varian: attach/update/detach baris (bahan + qty per unit), diikat ke `ProductVariant` bukan `Product` | 6 | 11.2, 2.4 | Resep bahan per SKU tercatat |
| 11.5 | `BomCostCalculator` + `GET /variants/{id}/cost-breakdown`: pemilihan harga (preferred, lalu termurah), `bom_cost` terpisah dari `cost_price` | 6 | 11.3, 11.4 | Modal bahan terhitung, TIDAK pernah menimpa `cost_price` |

**Status (2 September 2026): 11.1–11.5 seluruhnya selesai.** Seluruh endpoint digerbang `canManageMasterData()`, setara tingkat Products/Categories/Stock. Delete guard pada Vendor (masih punya `vendor_material_prices`) dan Material (masih punya `vendor_material_prices` ATAU baris BOM) menghasilkan 409, ditulis ke log aktivitas (paket 9.7) untuk create/delete.

### 12.0 Manajemen Pengguna & Peran kustom, Profil Toko — pasca-MVP (`001-user-store-settings`, ditambahkan 2026-09-02) — 32 jam

Membangun F13.1 (CRUD pengguna) penuh dan menaikkan F13.5 (peran kustom) dari stretch (Prioritas C) menjadi terbangun penuh — lihat catatan di PRD §7.13. Tidak ada di versi WBS sebelumnya sama sekali; dikerjakan lewat alur Spec Kit penuh (constitution → specify → plan → tasks → implement).

| ID | Tugas | Jam | Prasyarat | Keluaran |
|---|---|---|---|---|
| 12.1 | Model `Role` (`menu_keys` JSON, soft delete) + `App\Support\MenuKeys` sebagai satu sumber kebenaran menu; migrasi dua tahap `role_id` (nullable → backfill 4 peran default → non-nullable, drop enum lama) | 8 | 1.6 | Otorisasi berbasis menu-key dinamis, bukan enum 4-nilai tetap |
| 12.2 | Migrasi ~15 titik pemeriksaan otorisasi (controller/policy/request) dari `isOwnerOrAdmin()`/`canManageMasterData()` ke `User::canAccessMenu()` | 6 | 12.1 | Satu primitif otorisasi, perilaku lama untuk 4 peran default terverifikasi identik lewat login sungguhan |
| 12.3 | `UserController` CRUD + foto (`ImageUploadService`) + `last_access_at` + search/filter, `UserPolicy` dengan guard self-lockout (409, bukan 403) | 6 | 12.1, 12.2 | Kelola akun pengguna penuh, tidak bisa menonaktifkan/menghapus akun sendiri |
| 12.4 | `RoleController` CRUD + `RolePolicy` dengan guard "tidak boleh menyisakan nol peran yang bisa kelola users+roles" dan guard `user_count > 0` | 6 | 12.1 | Peran kustom bisa dibuat/diedit/dihapus tanpa mengunci sistem |
| 12.5 | Profil toko lengkap: field alamat/logo/kontak person/telepon/email di `Setting` (key-value, bukan tabel baru), `POST /settings/store-logo`, struk (`GET /orders/{id}/receipt`) menampilkan identitas toko lengkap | 3 | 9.8 | Struk mencantumkan alamat, logo, dan kontak toko |
| 12.6 | Impor/ekspor Excel diperluas 8→10 sheet (+`roles`, +`users`), sheet baru dirujuk lewat nama (bukan `code`, karena `Role` tidak punya kolom kode) | 3 | 10.4, 12.1, 12.4 | Satu workbook master-data mencakup peran & pengguna |

**Status (2 September 2026): 12.1–12.6 seluruhnya selesai.** Regresi penuh: 258/258 test backend lulus, 111/113 test frontend lulus (2 di-skip, keduanya karena flake lintas-berkas pra-eksisting pada Vitest, bukan bug aplikasi — perilaku sungguhannya sudah diverifikasi independen lewat pemanggilan API langsung). Perubahan sidebar (menu Pengaturan digabung jadi grup dengan sub-menu General/Pengguna/Peran) dikerjakan sebagai permintaan terpisah di tengah implementasi, bukan task bernomor dari `tasks.md`.

---

## 3. Rekapitulasi dan dampak bantuan AI

Bantuan AI tidak mempercepat semua jenis pekerjaan secara merata. Tugas berpola berulang seperti CRUD, migration, dan kueri laporan terbantu besar. Sebaliknya, penyiapan lingkungan, kekhasan peramban, uji lapangan, dan input data nyaris tidak terbantu.

| Tingkat bantuan | Jenis pekerjaan | Penghematan |
|---|---|---|
| Tinggi | CRUD, migration, seeder, endpoint mengikuti kontrak, kueri laporan, ekspor | 35–40% |
| Sedang | Logika bisnis inti, state machine, antarmuka kasir | 15–25% |
| Rendah | Setup lingkungan, integrasi webcam, uji pemulihan, simulasi event, input data, pelatihan | 0–15% |

Alasan logika bisnis inti hanya masuk kategori sedang: kode yang menyentuh uang dan stok wajib dibaca ulang baris per baris. Waktu yang dihemat dari mengetik berpindah menjadi waktu memeriksa, dan pemeriksaan itu tidak boleh dipangkas.

| Paket kerja | Dasar | Dengan AI | Hemat |
|---|---|---|---|
| 1.0 Fondasi & lingkungan | 32 | 24 | 8 |
| 2.0 Master data | 44 | 29 | 15 |
| 3.0 Stok | 20 | 15 | 5 |
| 4.0 Event & sesi kasir | 22 | 17 | 5 |
| 5.0 Transaksi penjualan | 48 | 37 | 11 |
| 6.0 Pembayaran & bukti | 26 | 21 | 5 |
| 7.0 Pre-order & pengiriman | 38 | 26 | 12 |
| 8.0 Laporan & ekspor | 28 | 18 | 10 |
| 9.0 Operasional & rilis | 34 | 28 | 6 |
| **Total** | **292** | **215** | **77** |

Penghematan keseluruhan sekitar 26 persen. Angka ini sengaja tidak dibuat lebih agresif: proyek yang mengasumsikan AI memangkas separuh waktu kerja umumnya meleset, karena bagian yang paling memakan waktu justru integrasi, perbaikan bug, dan pengujian — dan ketiganya paling sedikit terbantu.

ASSUMPTION — persentase di atas adalah perkiraan perencanaan, bukan angka terukur. Perlu dikalibrasi ulang setelah minggu 2 dengan membandingkan estimasi terhadap waktu aktual.

---

## 4. Kapasitas

### 4.1 Kebutuhan versus ketersediaan

| Skenario | Kapasitas | Kebutuhan 247 jam | Sisa |
|---|---|---|---|
| 1 developer | 240 | 247 | -7, tanpa ruang gerak |
| 1 developer + 1 paruh waktu 50% | 360 | 247 | +113, nyaman |
| 2 developer penuh | 480 | 247 | +233, berlebih |

Menambah satu developer paruh waktu sudah cukup. Dua developer penuh waktu untuk cakupan sebesar ini justru berisiko: pekerjaan menjadi terlalu terpecah, dan waktu terpakai untuk koordinasi.

ASSUMPTION — angka kapasitas dua orang tidak dikalikan dua secara utuh dalam praktiknya. Selalu ada waktu terpakai untuk penyelarasan dan peninjauan kode antar orang.

### 4.2 Pembagian kerja bila kapasitas ditambah

Agar dua orang tidak saling menunggu, pembagiannya perlu mengikuti batas modul yang bergantungannya sedikit:

| Jalur | Paket kerja | Catatan |
|---|---|---|
| Jalur utama | 1.0, 3.0, 5.0, 6.0 | Fondasi, stok, kasir, pembayaran. Dikerjakan orang yang paling memahami alur inti |
| Jalur pendukung | 2.0, 4.0, 7.0, 8.0 | Master data, event, pre-order, laporan. Bergantung pada fondasi tetapi tidak saling mengunci |
| Bersama | 9.0 | Pengerasan dan uji lapangan dikerjakan bersama pada dua minggu terakhir |

Syaratnya paket 1.0 diselesaikan lebih dulu oleh satu orang. Sebelum fondasi, migration, dan autentikasi berdiri, orang kedua tidak punya pijakan untuk bekerja.

---

## 5. Jadwal mingguan

Jadwal berikut memakai cakupan penuh dan estimasi dengan bantuan AI, yakni 215 jam kerja ditambah cadangan.

| Minggu | Paket kerja | Jam | Milestone |
|---|---|---|---|
| 1 | 1.0 Fondasi | 24 | Login berfungsi, seluruh tabel terbentuk |
| 2 | 2.1–2.5 Master data | 23 | Produk dan varian dapat dibuat dengan kode otomatis |
| 3 | 2.6–2.7, 3.0 Stok | 21 | Stok awal dapat diinput, riwayat tercatat |
| 4 | 4.0 Event & sesi, 5.1–5.2 | 31 | Layar kasir tampil dan dapat menambah keranjang |
| 5 | 5.3–5.7 Transaksi | 23 | Transaksi tunai tersimpan utuh dengan struk |
| 6 | 6.0 Pembayaran & bukti | 21 | Transaksi non-tunai dengan bukti wajib berfungsi |
| 7 | 7.0 Pre-order & pengiriman | 26 | Pre-order tercatat sampai pengiriman |
| 8 | 8.0 Laporan | 18 | Rekap artist keluar, ekspor berfungsi |
| 9 | 9.1–9.3 Pengerasan | 13 | Cadangan teruji, galat tertangani |
| 10 | 9.4–9.6 Uji lapangan | 15 | Simulasi event lulus, operator terlatih |

Total terjadwal 215 jam.

Perhatikan bahwa tabel ini memuat 10 minggu, sementara waktu tersedia hanya 8 minggu. Dengan satu developer, dua paket terakhir tidak muat. Ada dua cara menutupnya:

1. **Menambah kapasitas** sesuai bagian 4.1, sehingga minggu 7 sampai 10 dapat dikerjakan paralel dan selesai dalam dua minggu kalender.
2. **Memulai lebih awal**, bila tanggal event memungkinkan.

Bila keduanya tidak terjadi, pemotongan cakupan kembali menjadi keharusan — dan yang paling masuk akal dipotong tetap paket 7.0, karena volumenya besar dan dapat dijalankan manual.

### Jadwal bila kapasitas ditambah

| Minggu | Jalur utama | Jalur pendukung |
|---|---|---|
| 1 | 1.0 Fondasi | Menunggu fondasi, menyiapkan data produk |
| 2 | 3.0 Stok | 2.1–2.5 Master data |
| 3 | 5.1–5.2 Kasir | 2.6–2.7, 4.0 Event & sesi |
| 4 | 5.3–5.7 Transaksi | 7.1–7.3 Pre-order |
| 5 | 6.0 Pembayaran | 7.4–7.5 Pembayaran PO & pengiriman |
| 6 | 9.3 Pengerasan | 8.0 Laporan |
| 7 | 9.1–9.2 Cadangan, perbaikan bug | 9.4 Input data sungguhan |
| 8 | 9.5–9.6 Uji lapangan bersama | 9.5–9.6 Uji lapangan bersama |

Minggu 8 tetap tidak boleh dipakai menambah fitur, berapa pun kapasitas yang tersedia.

---

## 6. Milestone dan gerbang keputusan

| Milestone | Target | Gerbang |
|---|---|---|
| M1 — Fondasi siap | Akhir minggu 1 | Bila meleset lebih dari 3 hari, kapasitas tambahan harus segera dipastikan |
| M2 — Master data selesai | Akhir minggu 3 | Data produk sungguhan mulai diinput paralel |
| M3 — Kasir tunai berfungsi | Akhir minggu 5 | Titik aman minimum: sistem sudah dapat dipakai walau hanya tunai |
| M4 — Kasir lengkap | Akhir minggu 6 | Bila meleset, event dijalankan dengan mode tunai saja |
| M5 — Pre-order selesai | Akhir minggu 7 | Bila meleset, pre-order dicatat manual di event dan diinput setelahnya |
| M6 — Siap uji | Akhir minggu 7 atau 9 | Cadangan wajib sudah teruji sebelum masuk uji lapangan |
| M7 — Lulus uji lapangan | Akhir minggu 8 atau 10 | Bila gagal, siapkan pencatatan manual sebagai cadangan di event |

M3 tetap gerbang terpenting. Sistem yang hanya melayani tunai jauh lebih baik daripada tidak ada sistem sama sekali, sehingga milestone ini tidak boleh dikorbankan demi mengejar fitur lain.

M5 adalah jaring pengaman untuk pre-order: modulnya tetap dibangun, tetapi bila belum siap menjelang event, pencatatan manual tetap tersedia sebagai jalan keluar tanpa mengganggu operasional kasir.

---

## 7. Definition of done

Sebuah tugas dianggap selesai bila memenuhi seluruh butir berikut:

| Kriteria | Keterangan |
|---|---|
| Kode dibaca ulang | Kode hasil bantuan AI ditinjau baris per baris sebelum digabungkan, terutama yang menyentuh uang dan stok |
| Sesuai kontrak API | Bentuk permintaan dan respons cocok dengan `openapi-pos-mvp.yaml` |
| Validasi di sisi server | Aturan bisnis tidak hanya ditegakkan di antarmuka |
| Integritas transaksi | Operasi multi-tabel berjalan dalam satu transaksi database |
| Teruji manual | Jalur normal dan minimal satu jalur gagal sudah dicoba |
| Galat tertangani | Kegagalan menampilkan pesan yang dapat dipahami operator |
| Terdokumentasi | Perubahan kontrak API tercatat di berkas spesifikasi pada commit yang sama |

---

## 8. Risiko pelaksanaan

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Estimasi meleset lebih dari 20% | Cakupan tidak selesai sebelum event | Evaluasi progres setiap akhir minggu terhadap milestone; potong cakupan lebih awal, bukan menambah jam |
| Penghematan AI ternyata lebih kecil dari 26% | Jadwal 8 minggu tidak tercapai | Kalibrasi estimasi terhadap waktu aktual di akhir minggu 2, lalu sesuaikan rencana sebelum terlambat |
| Kode bantuan AI lolos tanpa ditinjau | Salah hitung uang atau stok baru ketahuan saat rekap tidak cocok | Jadikan peninjauan sebagai syarat selesai; uji perhitungan dengan angka yang hasilnya sudah diketahui |
| Kapasitas tambahan datang terlambat | Orang baru masuk saat jadwal sudah kritis, justru memperlambat | Pastikan penambahan kapasitas paling lambat awal minggu 2, saat fondasi sudah berdiri |
| Layar kasir memakan waktu lebih lama dari 14 jam | Minggu 4 dan 5 bergeser | Bangun versi paling sederhana lebih dulu, perbaiki tampilan setelah alur berfungsi |
| Developer berhalangan | Pengembangan berhenti total | Kode di repositori bersama sejak hari pertama, dengan catatan cara menjalankan sistem |
| Data produk belum siap saat uji lapangan | Simulasi tidak mencerminkan kondisi nyata | Mulai input data sungguhan sejak M2, bukan menunggu minggu 8 |
| Fitur baru diminta di tengah jalan | Jadwal yang sudah ketat semakin mustahil | Seluruh permintaan baru masuk daftar pasca-event tanpa pengecualian |
