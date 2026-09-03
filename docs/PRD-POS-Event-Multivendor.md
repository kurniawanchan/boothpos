**Klasifikasi: INTERNAL**

# PRD — BoothPOS

*Sistem POS event-based multi-artist untuk toko merchandise*

| Field | Isi |
|---|---|
| Produk | BoothPOS — POS untuk toko merchandise yang berjualan online & bazaar/event |
| Versi dokumen | v1.7 |
| Tanggal | 30 Agustus 2026 |
| Status | Parameter operasional terkonfirmasi |
| Pemilik dokumen | Tim internal (Product) |

---

## 1. Ringkasan

**Perubahan positioning (v1.5):** Sistem ini semula dirancang untuk kebutuhan internal satu bisnis. Rencana ke depan, BoothPOS dijadikan produk yang dijual ke toko merchandise lain (bukan hanya online shop tapi juga penjual bazaar/event pada umumnya, tidak terbatas ke satu segmen), dengan model **lisensi sekali bayar** dan instalasi lokal per toko — bukan SaaS multi-tenant dengan biaya berlangganan.

ASSUMPTION — perubahan ini murni pada branding dan positioning bisnis. Fungsionalitas pada dokumen ini TIDAK diubah mengikuti pivot ini. Satu pertanyaan produk yang masih terbuka dan sengaja tidak dijawab di sini: apakah modul multi-artist/konsinyasi (bagian 7.3, 7.11, 7.25, 7.26) tetap wajib untuk semua pembeli BoothPOS, atau menjadi modul opsional untuk toko yang tidak memakai model titip-jual. Ini keputusan produk yang perlu dibuat terpisah, di luar update dokumen ini.

**Glosarium istilah**

Dua istilah berikut dipakai konsisten di seluruh dokumen dan tidak boleh tertukar, karena keduanya adalah entitas berbeda dengan relasi berbeda:

| Istilah | Definisi | Posisi dalam alur |
|---|---|---|
| **Artist** | Pemilik merchandise yang dijual melalui sistem. Barangnya dititipkan atau diproduksi atas namanya, dan hasil penjualannya direkap untuk diserahkan kepadanya. | Hulu penjualan — pemilik produk |
| **Vendor** | Pihak eksternal tempat artist membeli atau memproduksi merchandise, misalnya percetakan, jasa produksi akrilik, atau pemasok bahan baku. | Hulu produksi — pemasok artist |

Relasinya: **Vendor → Artist → Produk → Penjualan**. Vendor tidak memiliki produk dan tidak menerima bagi hasil penjualan; vendor hanya muncul sebagai pihak yang dibayar dalam pencatatan biaya produksi dan pembelian bahan baku.

**Parameter operasional terkonfirmasi**

| Parameter | Nilai |
|---|---|
| Jumlah artist | 5 |
| Skala katalog | Puluhan SKU |
| Perangkat kasir | 1 laptop, dijalankan lokal |
| Target event | Oktober 2026 |
| Waktu pengembangan | 2 bulan |
| Kapasitas tim | 1 developer |
| Metode pembayaran | Tunai, transfer bank, QR e-wallet |
| Rekening tujuan | 2 rekening (BCA dan Mandiri) |
| Bukti transfer | Wajib untuk setiap pembayaran non-tunai |
| Struk | Ditampilkan di layar untuk difoto pelanggan, tanpa printer |
| Panjang kode produk | Maksimal 12 karakter |
| Produksi | Dikerjakan sendiri, sebagian dicetak di vendor eksternal |
| Potongan biaya bersama | Tidak ada |

**Peringatan kapasitas**

Cakupan 26 modul dalam dokumen ini tidak dapat diselesaikan oleh 1 developer dalam 2 bulan. Bagian 10 memuat pemotongan cakupan yang direkomendasikan agar target Oktober tetap tercapai; modul di luar potongan tersebut dipindahkan ke pasca-event.

Sistem Point of Sales berbasis event untuk penjualan merchandise anime dan game, mendukung transaksi offline di venue event (Comifuro dan sejenisnya) maupun penjualan online, dengan pengelolaan multi-artist dan rekap hasil penjualan per artist per event.

Nilai inti yang ditawarkan:

1. Kasir tetap berfungsi tanpa internet — kondisi jaringan di venue event umumnya tidak stabil.
2. Setiap transaksi terikat ke event tertentu, sehingga rekap per event otomatis.
3. Hasil penjualan per artist terhitung otomatis dari data transaksi, menghilangkan rekap manual di akhir event.
4. Mencakup rantai penuh dari bahan baku dan produksi hingga penjualan, sehingga harga modal dan margin terlacak sejak awal.
5. Seluruh data dapat diekspor dan diimpor melalui Excel, memungkinkan input massal dan migrasi dari pencatatan spreadsheet yang sudah berjalan.

---

## 2. Latar belakang & masalah

Penjualan merchandise anime/game di Indonesia sebagian besar terjadi di event komunitas dengan karakteristik: durasi pendek (1–3 hari), volume transaksi padat di jam tertentu, koneksi internet tidak reliable, dan barang dititipkan dari banyak artist sekaligus.

Masalah yang ingin diselesaikan:

| # | Masalah | Dampak |
|---|---|---|
| P1 | Pencatatan penjualan manual (buku/spreadsheet) saat event | Rawan salah hitung, lambat saat antrean padat |
| P2 | Rekap hasil per artist dilakukan manual setelah event | Memakan waktu berhari-hari, rawan sengketa dengan artist |
| P3 | Stok tidak sinkron antara penjualan event dan online | Oversell, khususnya untuk item limited |
| P4 | Pre-order tidak tercatat sistematis | Buyer tidak terlacak, risiko lost order |
| P5 | Tidak ada visibilitas modal vs keuntungan per event | Sulit menentukan event mana yang layak diikuti lagi |

---

## 3. Tujuan & metrik sukses

**Tujuan produk**

| ID | Tujuan |
|---|---|
| G1 | Menyediakan kasir yang dapat beroperasi penuh tanpa koneksi internet |
| G2 | Mengotomatiskan rekap hasil penjualan per artist per event |
| G3 | Menyatukan data stok, produk, dan pelanggan lintas channel |
| G4 | Menyediakan laporan modal, keuntungan, dan penjualan yang dapat diaudit |

**Metrik sukses (target awal)**

ASSUMPTION — angka target di bawah adalah usulan awal dan belum berdasarkan data historis penjualan; perlu dikalibrasi setelah event pertama.

| Metrik | Target |
|---|---|
| Waktu proses satu transaksi di kasir | < 30 detik |
| Waktu rekap hasil artist setelah event ditutup | < 15 menit (dari sebelumnya berhari-hari) |
| Transaksi hilang/gagal tersimpan saat offline | 0 |
| Selisih stok fisik vs sistem setelah event | < 2% dari total unit |

---

## 4. Ruang lingkup

**Termasuk dalam scope**

- POS offline-first untuk penjualan di venue event
- Manajemen event, artist, produk, kategori, stok
- Sistem pre-order
- Manajemen pelanggan dan pembelian (procurement)
- Modul laporan: penjualan, modal & keuntungan, hasil per artist
- Dashboard performa per artist
- Vendor management untuk pencatatan pembelian dan produksi
- Open/close kasir (shift management)
- User management dan pengaturan sistem
- Ekspor dan impor Excel untuk seluruh modul CRUD
- Cetak struk dan cetak katalog produk
- QR code per produk untuk input cepat di kasir
- Kode produk yang digenerate otomatis
- Sistem flash sale
- Pembayaran tunai dan transfer bank manual beserta bukti pembayaran
- Pengiriman via kurir untuk pre-order
- Pencatatan bahan baku, produksi, dan penetapan harga jual berbasis markup

**Tidak termasuk dalam scope (v1)**

- Storefront online — belum dapat berjalan selama sistem berbasis localhost
- Integrasi otomatis ke marketplace (Shopee/Tokopedia) — dilakukan manual dulu
- Modul akuntansi penuh (jurnal, neraca, pajak)
- Program loyalti dan poin pelanggan
- Aplikasi mobile native di app store
- Multi-currency dan penjualan lintas negara

---

## 5. Pengguna & peran

| Peran | Deskripsi | Akses utama |
|---|---|---|| Owner / Admin | Pemilik bisnis | Seluruh modul termasuk laporan keuangan dan konfigurasi |
| Kasir / Staff event | Penjaga booth saat event | POS, lihat stok, buka/tutup kasir |
| Manajer inventori | Pengelola stok dan procurement | Produk, kategori, stok, purchase |
| Artist | Pemilik barang titipan | Lihat penjualan dan hasil miliknya sendiri (read-only) |

ASSUMPTION — akses portal mandiri untuk artist diasumsikan sebagai kebutuhan fase lanjutan, bukan v1; pada v1 laporan artist dikirim manual oleh admin.

---

## 6. Model data inti

Entitas utama dan relasinya:

- `EVENTS` — satu event = satu wadah transaksi (nama, tanggal mulai/selesai, lokasi, status)
- `ARTISTS` — artist pemilik barang (nama, kontak, catatan)
- `VENDORS` — pemasok atau jasa produksi tempat artist membeli/memproduksi barang (nama, jenis layanan, kontak, catatan)
- `CATEGORIES` — kategori produk, mendukung hierarki
- `PRODUCTS` — produk, wajib terikat ke satu `artist_id` dan satu `category_id`
- `PRODUCT_VARIANTS` — varian per produk (ukuran, warna, karakter), memegang SKU dan stok
- `CUSTOMERS` — data pelanggan
- `ORDERS` — transaksi, wajib membawa `event_id` dan `channel` (offline/online)
- `ORDER_ITEMS` — baris item per transaksi, menyimpan `cost_price` dan `sell_price` saat transaksi terjadi
- `PREORDERS` — pesanan barang belum tersedia, dengan status dan DP
- `PURCHASES` / `PURCHASE_ITEMS` — pencatatan pembelian/penerimaan barang dan modalnya
- `STOCK_MOVEMENTS` — riwayat semua pergerakan stok (masuk, keluar, penyesuaian)
- `ARTIST_SETTLEMENTS` — ringkasan hasil per artist per event dan status pembayarannya
- `CASHIER_SESSIONS` — sesi buka/tutup kasir beserta kas awal dan akhir
- `USERS`, `ROLES` — pengguna sistem dan hak aksesnya
- `SETTINGS` — konfigurasi sistem
- `MATERIALS` — bahan baku (nama, satuan, stok, harga per satuan)
- `MATERIAL_PURCHASES` — pembelian bahan baku beserta harga dan `vendor_id` pemasoknya
- `PRODUCTION_ORDERS` / `PRODUCTION_MATERIALS` — pencatatan produksi milik artist: `artist_id` sebagai pemilik hasil produksi, `vendor_id` sebagai pihak yang mengerjakan, bahan baku yang dipakai, jumlah output, dan harga modal hasil produksi
- `FLASH_SALES` / `FLASH_SALE_ITEMS` — periode flash sale, produk yang termasuk, harga khusus, dan kuota
- `BANK_ACCOUNTS` — daftar rekening tujuan transfer manual
- `PAYMENTS` — pembayaran per transaksi: metode, nominal, rekening tujuan bila transfer
- `PAYMENT_PROOFS` — berkas bukti pembayaran hasil foto webcam atau unggahan manual
- `SHIPMENTS` — pengiriman pre-order: kurir, ongkos, nomor resi, alamat, status
- `IMPORT_JOBS` — riwayat proses impor Excel beserta status dan catatan kesalahan per baris

**Keputusan desain penting**

1. `ORDER_ITEMS` menyimpan `cost_price` dan `sell_price` sebagai snapshot saat transaksi, bukan mengambil dari master produk. Alasannya: harga modal dan jual bisa berubah, dan laporan keuntungan historis harus tetap akurat.
2. `ARTIST_SETTLEMENTS` berisi hasil agregasi, bukan perhitungan komisi. Nilai `total_amount` dihitung dari `SUM(qty × sell_price)` seluruh `ORDER_ITEMS` yang produknya milik artist tersebut dalam satu event.
3. Stok dilekatkan pada `PRODUCT_VARIANTS`, bukan `PRODUCTS`, karena satu produk umumnya punya beberapa varian dengan stok terpisah.
4. Seluruh perubahan stok wajib melalui `STOCK_MOVEMENTS` agar dapat diaudit — tidak ada update langsung ke kolom stok tanpa jejak.
5. `product_code` bersifat permanen setelah dibuat. Kode tidak ikut berubah meskipun nama produk, artist, atau kategori diubah kemudian, karena kode sudah tercetak pada QR, katalog, dan struk yang beredar.
6. Harga jual disimpan sebagai nilai final, bukan formula markup. Markup hanya alat bantu saat pengisian harga; yang tersimpan dan dipakai di transaksi adalah nominal hasilnya, agar riwayat harga tidak berubah ketika aturan markup diubah.
7. `PAYMENT_PROOFS` disimpan sebagai berkas terpisah dengan akses terbatas, tidak diekspos sebagai URL publik. Bukti transfer memuat data pribadi pelanggan.
8. Harga flash sale tidak menimpa harga master produk. Sistem memilih harga berlaku saat transaksi dan menuliskannya ke `sell_price` di `ORDER_ITEMS`, sehingga laporan tetap akurat setelah periode flash sale berakhir.
9. `ARTISTS` dan `VENDORS` adalah dua tabel terpisah, bukan satu tabel dengan kolom tipe. Keduanya punya relasi yang sama sekali berbeda: artist terhubung ke produk dan penjualan, vendor terhubung ke pembelian bahan baku dan biaya produksi. Menyatukannya akan membuat laporan hasil artist tercampur dengan biaya produksi.
10. Satu artist dapat memakai banyak vendor, dan satu vendor dapat melayani banyak artist. Relasi ini terekam melalui `PRODUCTION_ORDERS`, bukan sebagai kolom tetap di tabel artist.

---

## 7. Kebutuhan fungsional

Prioritas menggunakan MoSCoW: **M** (Must have, wajib ada di v1), **S** (Should have), **C** (Could have).

### 7.1 Event management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F1.1 | Admin dapat membuat event dengan nama, tanggal, lokasi, dan catatan | M |
| F1.2 | Event memiliki status: draft, aktif, selesai, dibatalkan | M |
| F1.3 | Kasir memilih event aktif sebelum memulai transaksi | M |
| F1.4 | Seluruh transaksi otomatis terikat ke event yang sedang aktif | M |
| F1.5 | Sistem mencatat biaya event (sewa booth, transport) untuk perhitungan keuntungan bersih | S |
| F1.6 | Event dapat menetapkan daftar artist yang berpartisipasi | S |

**Kriteria penerimaan F1.4** — Ketika kasir menyelesaikan transaksi saat event "Comifuro 2026" aktif, record `ORDERS` yang tersimpan memiliki `event_id` yang merujuk ke event tersebut, dan transaksi ini muncul di laporan event tersebut tanpa input tambahan.

### 7.2 Pre-order management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F2.1 | Produk dapat ditandai sebagai item pre-order dengan estimasi tanggal ketersediaan | M |
| F2.2 | Sistem mencatat pre-order dengan data pelanggan, item, jumlah, dan DP | M |
| F2.3 | Status pre-order: dipesan, DP dibayar, barang tiba, lunas, diserahkan, dibatalkan | M |
| F2.4 | Kuota pre-order dapat dibatasi per produk | S |
| F2.5 | Batas maksimal pembelian per pelanggan untuk item limited | S |
| F2.6 | Notifikasi otomatis ke pelanggan saat barang tiba | C |
| F2.7 | Daftar tunggu (waitlist) saat kuota pre-order habis | C |

**Kriteria penerimaan F2.3** — Setiap perubahan status tercatat beserta waktu dan pengguna yang mengubahnya, dan pre-order tidak dapat berpindah ke status "diserahkan" sebelum berstatus "lunas".

### 7.3 Artist management

**Model lisensi (v1.6):** BoothPOS dijual dalam dua tingkat harga. **Pro** — satu artist saja (toko itu sendiri), tanpa konsinyasi. **Master** — multi-artist dengan rekap hasil dan bagi hasil per artist. Perbedaan ini ditegakkan lewat satu pengaturan (`multi_artist_enabled`), bukan build kode terpisah — satu basis kode untuk kedua tingkat harga.

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F3.1 | CRUD data artist: nama, kontak, catatan, status aktif | M |
| F3.2 | Setiap produk wajib terikat ke satu artist | M |
| F3.3 | Melihat daftar produk dan stok per artist | M |
| F3.4 | Melihat hasil penjualan per artist per event secara real-time | M |
| F3.5 | Artist dapat login untuk melihat penjualannya sendiri | C |
| F3.6 | Instalasi tersedia dalam dua tingkat: Pro (multi-artist nonaktif) dan Master (multi-artist aktif), diatur lewat pengaturan sistem | M |
| F3.7 | Saat Pro, sistem otomatis punya satu artist bawaan yang mewakili toko itu sendiri; endpoint buat artist baru ditolak | M |
| F3.8 | Artist bawaan tidak dapat dihapus, kode dan namanya dapat disunting | S |
| F3.9 | Peralihan Pro ke Master dapat dilakukan admin kapan saja, membuka kembali pembuatan artist baru | M |

**Kriteria penerimaan F3.7** — Pada instalasi Pro, permintaan `POST /artists` yang kedua (setelah artist bawaan ada) ditolak dengan 403 dan pesan yang menjelaskan bahwa fitur ini butuh upgrade ke Master.

**Kriteria penerimaan F3.9** — Peralihan Master ke Pro pada instalasi yang SUDAH punya lebih dari satu artist TIDAK menghapus atau menggabungkan data apa pun. Sistem hanya memblokir pembuatan artist baru; artist yang sudah ada dan riwayat transaksinya tetap utuh dan tetap bisa dilihat. Penyatuan data ke satu artist (bila diinginkan) adalah tindakan manual di luar sistem, bukan otomatis.

ASSUMPTION — harga pasti untuk tingkat Pro vs Master belum ditentukan di dokumen ini; ini keputusan pricing terpisah dari PM/founder, bukan keputusan teknis.

### 7.4 Stock management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F4.1 | Stok tercatat per varian produk | M |
| F4.2 | Stok berkurang otomatis saat transaksi selesai | M |
| F4.3 | Riwayat pergerakan stok tercatat lengkap dan tidak dapat dihapus | M |
| F4.4 | Penyesuaian stok manual (stock opname) dengan alasan wajib diisi | M |
| F4.5 | Peringatan saat stok mendekati habis | S |
| F4.6 | Alokasi stok per event (bawa sebagian stok ke venue) | S |
| F4.7 | Penanganan SKU blind box (isi acak per unit) | C |

**Kriteria penerimaan F4.2** — Setelah transaksi tersimpan, stok varian berkurang sesuai jumlah terjual, dan satu record `STOCK_MOVEMENTS` bertipe "penjualan" terbentuk merujuk ke `order_item_id` terkait.

### 7.5 Category management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F5.1 | CRUD kategori produk | M |
| F5.2 | Kategori mendukung sub-kategori (hierarki) | S |
| F5.3 | Produk dapat difilter berdasarkan kategori di POS | M |
| F5.4 | Kategori tidak dapat dihapus jika masih memiliki produk aktif | M |

### 7.6 Product management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F6.1 | CRUD produk: nama, deskripsi, artist, kategori, foto | M |
| F6.2 | Produk mendukung banyak varian dengan SKU unik | M |
| F6.3 | Harga modal dan harga jual per varian | M |
| F6.4 | Pencarian produk cepat di POS (nama atau SKU) | M |
| F6.5 | Dukungan pemindaian barcode | S |
| F6.6 | Bundling beberapa produk sebagai satu paket | C |

### 7.7 Customer management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F7.1 | Pencatatan pelanggan: nama, kontak, catatan | M |
| F7.2 | Transaksi dapat dikaitkan ke pelanggan (opsional untuk walk-in) | M |
| F7.3 | Melihat riwayat pembelian per pelanggan | S |
| F7.4 | Pencarian pelanggan cepat saat input pre-order | M |

Catatan kepatuhan: data kontak pelanggan adalah data pribadi. Sistem harus membatasi akses ke data ini hanya untuk peran yang membutuhkan, dan tidak menampilkannya di laporan yang dibagikan ke artist.

### 7.8 Purchase management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F8.1 | Pencatatan pembelian/penerimaan barang beserta harga modal | M |
| F8.2 | Penerimaan barang otomatis menambah stok | M |
| F8.3 | Pencatatan biaya tambahan (ongkir, bea impor) yang dibebankan ke modal | S |
| F8.4 | Status pembelian: dipesan, dalam perjalanan, diterima, dibatalkan | S |

### 7.9 Laporan modal & keuntungan

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F9.1 | Laporan total modal, total penjualan, dan laba kotor per event | M |
| F9.2 | Laporan keuntungan per produk dan per kategori | S |
| F9.3 | Laba bersih memperhitungkan biaya event | S |
| F9.4 | Perbandingan performa antar event | C |
| F9.5 | Laporan modal dan laba kotor per artist, dihitung terpisah dari biaya event | S |

**Kriteria penerimaan F9.1** — Laba kotor dihitung sebagai `SUM(qty × sell_price) - SUM(qty × cost_price)` dari seluruh `ORDER_ITEMS` pada event tersebut, menggunakan nilai snapshot, bukan harga master saat laporan dibuka.

**Kriteria penerimaan F9.5** — Untuk setiap artist, modal dihitung sebagai `SUM(qty × modal_per_unit)` dari seluruh `ORDER_ITEMS` produk milik artist tersebut pada event, dengan `modal_per_unit` memakai basis biaya yang tersedia untuk produk/varian bersangkutan (harga modal manual atau modal bahan dari BOM, sesuai yang tercatat pada produk itu — sistem tidak mewajibkan satu basis tertentu). Laba kotor per artist dihitung sebagai total penjualan artist tersebut dikurangi modal artist tersebut. Biaya event bersama (`event_cost`) TIDAK ikut dikurangkan pada angka ini; biaya tersebut sudah diperhitungkan secara terpisah pada laba bersih tingkat event (F9.3), agar tidak dua kali dikurangkan atau dialokasikan secara tidak adil antar artist.

**Catatan penambahan — 2026-09-02.** F9.5 adalah kapabilitas baru: saat ini laporan modal & keuntungan (7.9) hanya berskala event/produk/kategori, dan rekap artist (7.11) hanya berskala pendapatan tanpa modal maupun laba. F9.5 mengisi celah tersebut dengan pandangan modal & laba per artist. Ditempatkan di 7.9, bukan 7.11, karena secara fundamental ini adalah laporan modal & keuntungan (memakai basis biaya yang sama dengan F9.1/F9.2), hanya diiris per artist alih-alih per event atau per produk/kategori; 7.11 tetap fokus pada rekap pendapatan dan status pembayaran ke artist. Belum dibangun per tanggal catatan ini.

### 7.10 Laporan penjualan

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F10.1 | Laporan penjualan per event, per periode, per channel | M |
| F10.2 | Rincian penjualan per produk dan varian | M |
| F10.3 | Laporan per sesi kasir | M |
| F10.4 | Ekspor laporan ke CSV atau spreadsheet | M |
| F10.5 | Produk terlaris dan tren penjualan | S |
| F10.6 | Pencarian pada daftar transaksi berdasarkan nomor transaksi, nama pelanggan, atau nama kasir | S |

**Kriteria penerimaan F10.6** — Pada daftar transaksi di laporan penjualan, pengguna dapat mengetikkan kata kunci; sistem menyaring baris yang nomor transaksinya, nama pelanggannya, atau nama kasirnya cocok (mengandung kata kunci, tidak peka huruf besar/kecil) tanpa perlu memuat ulang seluruh laporan.

**Catatan penambahan — 2026-09-02.** F10.6 menambah kemampuan pencarian pada daftar transaksi yang sudah tersedia di laporan penjualan. Belum dibangun per tanggal catatan ini.

### 7.11 Laporan hasil per artist

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F11.1 | Rekap otomatis total penjualan per artist per event | M |
| F11.2 | Rincian item terjual per artist sebagai lampiran rekap | M |
| F11.3 | Penandaan status pembayaran ke artist: belum dibayar, sebagian, lunas | M |
| F11.4 | Pencatatan potongan biaya bersama sebelum pembayaran ke artist | C — tidak ada potongan biaya pada model bisnis saat ini |
| F11.5 | Ekspor rekap per artist untuk dikirim ke masing-masing artist | M |
| F11.6 | Detail transaksi (daftar order beserta rincian item) yang menyusun rekap seorang artist dapat dilihat langsung dari halaman Rekap Artist, dan turut disertakan pada berkas hasil ekspor rekap tersebut | M |

**Kriteria penerimaan F11.1** — Setelah event ditutup, sistem menampilkan daftar seluruh artist yang produknya terjual di event tersebut, dengan total nilai penjualan masing-masing, tanpa perlu perhitungan manual.

**Kriteria penerimaan F11.6** — Dari halaman Rekap Artist, memilih satu artist menampilkan daftar transaksi yang menyumbang ke total rekapnya (nomor transaksi, tanggal, item, qty, nilai); berkas ekspor rekap artist (F11.5) memuat detail transaksi yang sama, bukan hanya angka total.

**Catatan penambahan — 2026-09-02.** Rekap Artist saat ini hanya menampilkan total per artist; F11.2 (rincian item terjual) mencakup agregat per produk, bukan transaksi individual. F11.6 menambah kemampuan menelusuri (drill-down) ke transaksi yang menyusun total tersebut, baik di layar maupun di ekspor. Belum dibangun per tanggal catatan ini.

### 7.12 Open / close point of sales

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F12.1 | Kasir membuka sesi dengan mencatat kas awal | M |
| F12.2 | Kasir menutup sesi dengan mencatat kas akhir | M |
| F12.3 | Sistem menampilkan selisih antara kas seharusnya dan kas fisik | M |
| F12.4 | Transaksi hanya dapat dilakukan saat sesi terbuka | M |
| F12.5 | Ringkasan sesi mencakup jumlah transaksi dan rincian metode bayar | M |
| F12.6 | Ringkasan sesi dapat diekspor sebagai berkas untuk arsip harian | S |

### 7.13 User management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F13.1 | CRUD pengguna dengan peran (owner, admin, kasir, manajer inventori) | M |
| F13.2 | Hak akses per modul berdasarkan peran | M |
| F13.3 | Autentikasi login | M |
| F13.4 | Log aktivitas untuk tindakan sensitif (hapus data, penyesuaian stok, ubah harga) | M |
| F13.5 | Peran kustom yang dapat dikonfigurasi | C |

Catatan keamanan (area risiko: access control) — kasir tidak boleh memiliki akses ke laporan modal dan keuntungan. Pembatasan ini harus ditegakkan di sisi server, bukan hanya menyembunyikan menu di antarmuka.

**Catatan pasca-MVP — 2026-09-02 (manajemen pengguna & peran kustom)**

Fitur `001-user-store-settings` membangun F13.1 dan menaikkan F13.5 dari
stretch (Prioritas C) menjadi terbangun penuh:

- **F13.1 (CRUD pengguna dengan peran) — kini terbangun penuh**, termasuk
  foto profil, catatan akses terakhir (`last_access_at`), pencarian/filter,
  dan ekspor/impor massal lewat workbook master-data yang sama dengan
  vendor/bahan/BOM (lihat 7.15).
- **F13.5 (Peran kustom yang dapat dikonfigurasi) — dari stretch menjadi
  terbangun penuh.** Empat peran tetap (owner/admin/kasir/manajer inventori)
  digantikan oleh model `Role` dinamis dengan `menu_keys` yang dapat
  dikonfigurasi bebas per peran — bukan hanya menambah kolom di atas enum
  lama. Ini keputusan arsitektur yang disengaja (opsi C dari klarifikasi
  spec), bukan perluasan minor: satu primitif otorisasi baru,
  `User::canAccessMenu()`, menggantikan seluruh pemeriksaan berbasis role
  string di controller/policy/request lama.
- Catatan keamanan di atas (kasir dilarang akses laporan modal/keuntungan)
  tetap berlaku — kini ditegakkan lewat `menu_keys` peran Kasir default yang
  sengaja tidak menyertakan menu laporan tersebut, bukan lagi lewat
  pengecekan `role === 'kasir'` yang di-hardcode.

**Catatan pasca-MVP — 2026-09-03 (ganti bahasa antarmuka Indonesia/English)**

Fitur `002-language-toggle`, atas permintaan eksplisit pemilik produk.
**Bukan** kebangkitan butir manapun yang dicoret di §10.2 dan tidak
berkorespondensi dengan nomor F- manapun di dokumen ini — kapabilitas baru:

- Setelah login, setiap pengguna bisa mengganti bahasa antarmuka antara
  Bahasa Indonesia dan English lewat kontrol yang tersedia di seluruh
  layar (bukan hanya satu halaman pengaturan). Pilihan tersimpan per akun
  (`users.language`), bukan per perangkat/browser — kasir yang bergantian
  memakai perangkat yang sama masing-masing tetap melihat bahasa
  preferensinya sendiri.
- **Layar login dan struk transaksi SENGAJA dikecualikan total** — selalu
  Bahasa Indonesia, tidak ikut preferensi bahasa akun manapun. Layar login
  belum punya identitas akun untuk dijadikan acuan; struk dibaca pelanggan,
  bukan operator toko.
- Akun baru (dibuat manual maupun lewat impor massal) dan akun lama yang
  belum pernah mengatur preferensi sama-sama default ke English.
- **Konflik yang disengaja dengan konvensi kodebase ini**: proyek ini
  sebelumnya selalu berasumsi seluruh teks antarmuka berbahasa Indonesia
  (lihat `CLAUDE.md` — konvensi itu sekarang berlaku untuk kode
  sumber/komentar/commit message saja, bukan lagi untuk teks yang dilihat
  pengguna akhir). Ini dicatat eksplisit sebagai keputusan disengaja hasil
  proses klarifikasi spec, bukan penyimpangan yang tidak disadari.

### 7.14 Setting & config management

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F14.1 | Konfigurasi identitas toko (nama, logo, kontak) untuk struk | M |
| F14.2 | Konfigurasi metode pembayaran yang tersedia | M |
| F14.3 | Konfigurasi format struk dan penomoran transaksi | S |
| F14.4 | Konfigurasi pajak/biaya layanan bila diperlukan | C |
| F14.5 | Cadangkan dan pulihkan data | S |

### 7.15 Ekspor & impor Excel (lintas modul)

Berlaku untuk seluruh modul CRUD: produk, varian, kategori, artist, pelanggan, stok, pembelian, bahan baku, produksi, pre-order, dan transaksi penjualan.

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F15.1 | Setiap modul CRUD menyediakan ekspor data ke format `.xlsx` sesuai filter yang sedang aktif | M |
| F15.2 | Setiap modul CRUD menyediakan impor data dari `.xlsx` | M |
| F15.3 | Sistem menyediakan unduhan template Excel per modul, lengkap dengan nama kolom dan contoh baris | M |
| F15.4 | Impor dijalankan dua tahap: pratinjau validasi terlebih dahulu, baru konfirmasi simpan | M |
| F15.5 | Baris yang gagal validasi dilaporkan beserta nomor baris dan alasan kegagalan, tanpa membatalkan baris yang valid | M |
| F15.6 | Impor mendukung mode tambah baru dan mode perbarui data yang sudah ada berdasarkan kode unik | S |
| F15.7 | Riwayat impor tersimpan beserta pengguna, waktu, jumlah baris berhasil dan gagal | S |
| F15.8 | Impor stok menghasilkan record `STOCK_MOVEMENTS`, bukan menimpa nilai stok secara langsung | M |
| F15.9 | Impor transaksi penjualan digunakan untuk migrasi data historis dan rekonsiliasi penjualan luring | S |

**Kriteria penerimaan F15.5** — Saat mengimpor berkas berisi 100 baris dengan 3 baris bermasalah, sistem menyimpan 97 baris valid dan menampilkan laporan berisi nomor baris serta alasan untuk 3 baris yang gagal.

**Catatan implementasi — 2026-09-01.** Modul ini dibangun (lihat catatan perubahan cakupan di 10.2), dengan satu penyimpangan yang disengaja dari kriteria penerimaan F15.5 di atas: impor bersifat **semua-atau-tidak sama sekali**. Seluruh berkas divalidasi lebih dulu; bila ada satu baris pun yang gagal, tidak ada data yang berubah dan seluruh galat dilaporkan sekaligus beserta nama sheet, nomor baris, nama kolom, dan alasannya. Alasan penyimpangan: keempat sheet saling bergantung (produk menunjuk artist/kategori, stok menunjuk varian), sehingga "97 baris tersimpan, 3 gagal" bisa berarti produk tersimpan tanpa artistnya, atau harga separuh terbarui — kondisi yang lebih merusak daripada meminta pemilik toko memperbaiki berkasnya lalu mengulang impor, dan sejalan dengan mitigasi risiko "impor Excel merusak data massal" pada 9.6. Pratinjau F15.4 tersedia lewat parameter `dry_run` yang memakai jalur validasi yang sama persis. F15.6 (mode tambah/perbarui) diwujudkan sebagai upsert otomatis berdasarkan kolom unik masing-masing entitas, bukan sebagai dua mode terpisah. F15.8 dipenuhi: impor stok selalu lewat `StockService::applyMovement()`, tidak pernah menulis `current_stock` langsung.

Catatan keamanan (area risiko: input validation) — berkas impor adalah masukan tidak tepercaya. Sistem wajib memvalidasi tipe berkas, ukuran maksimum, dan setiap nilai sel di sisi server. Harga dan jumlah dari berkas impor tidak boleh dipercaya begitu saja.

Catatan perlindungan data — ekspor yang memuat data pelanggan hanya boleh diakses peran yang berwenang, dan berkas ekspor untuk artist tidak menyertakan kolom kontak pelanggan.

### 7.16 Struk

Printer thermal tidak dipakai. Struk ditampilkan di layar laptop kasir agar pelanggan dapat memotretnya sendiri, sehingga tidak ada kebutuhan perangkat keras tambahan maupun biaya kertas.

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F16.1 | Struk ditampilkan di layar setelah transaksi selesai, dalam tata letak yang jelas terbaca saat difoto pelanggan | M |
| F16.2 | Struk memuat identitas toko, nomor transaksi, nama event, waktu, rincian item, total, metode bayar, dan nama kasir | M |
| F16.3 | Tampilan struk berkontras tinggi, teks cukup besar, tanpa elemen yang terpotong di layar laptop | M |
| F16.4 | Struk dapat ditampilkan ulang dari riwayat transaksi | M |
| F16.5 | Struk dapat diunduh sebagai PDF bila pelanggan meminta salinan | S |
| F16.6 | Dukungan printer thermal | C |

### 7.17 QR code produk

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F17.1 | Sistem menghasilkan QR code unik untuk setiap varian produk, memuat kode produk | M |
| F17.2 | Kasir dapat memindai QR untuk menambahkan produk ke keranjang secara otomatis | M |
| F17.3 | Pemindaian berfungsi tanpa koneksi internet | M |
| F17.4 | QR dapat dicetak sebagai lembar label massal untuk ditempel pada barang | M |
| F17.5 | Pemindaian mendukung kamera perangkat maupun alat pemindai barcode eksternal | S |
| F17.6 | QR yang tidak dikenali menampilkan pesan jelas, bukan gagal diam-diam | M |

**Kriteria penerimaan F17.2** — Sekali pindai menambahkan varian yang benar ke keranjang dengan jumlah 1; pemindaian berulang atas QR yang sama menambah jumlah, bukan membuat baris baru.

### 7.18 Cetak katalog produk

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F18.1 | Sistem menghasilkan katalog produk siap cetak sebagai rujukan kasir di venue | M |
| F18.2 | Katalog memuat foto, nama produk, kode produk, harga, dan nama artist | M |
| F18.3 | Katalog dapat difilter per event, per artist, atau per kategori | M |
| F18.4 | Katalog dapat diunduh sebagai PDF | M |
| F18.5 | Tersedia varian tata letak ringkas untuk katalog referensi cepat dan tata letak bergambar besar untuk ditunjukkan ke pembeli | S |

### 7.19 Kode produk otomatis

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F19.1 | Kode produk digenerate otomatis dari komponen: kode artist, kode kategori, kode nama produk, dan penanda unik | M |
| F19.2 | Format kode dapat dikonfigurasi di pengaturan sistem | S |
| F19.3 | Sistem menjamin keunikan kode di tingkat basis data | M |
| F19.4 | Kode tetap permanen meskipun nama produk, artist, atau kategori diubah | M |
| F19.5 | Kode dapat disunting manual oleh admin sebelum produk memiliki transaksi | S |
| F19.6 | Setiap varian memiliki turunan kode tersendiri | M |

**Format kode: 12 karakter**

Batas 12 karakter tidak memungkinkan pemakaian pemisah tanda hubung bila keempat komponen tetap dipertahankan. Format yang ditetapkan:

| Segmen | Panjang | Isi | Contoh |
|---|---|---|---|
| Artist | 3 | Singkatan nama artist | `RYU` |
| Kategori | 2 | Singkatan kategori | `KY` (keychain) |
| Produk | 3 | Singkatan nama produk | `SAK` (Sakura) |
| Urutan | 4 | Nomor urut unik per kombinasi di atas | `0007` |

Hasil: `RYUKYSAK0007` — tepat 12 karakter, huruf kapital dan angka saja.

| ID | Kebutuhan tambahan | Prioritas |
|---|---|---|
| F19.7 | Singkatan artist dan kategori ditetapkan saat data dibuat dan tersimpan sebagai kolom tersendiri | M |
| F19.8 | Segmen produk dihasilkan otomatis dari nama produk, dapat disunting bila hasilnya tidak representatif | M |
| F19.9 | Sistem menolak singkatan artist atau kategori yang sudah dipakai entitas lain | M |

Konsekuensi yang perlu diterima: tanpa pemisah, kode lebih sulit dibaca manusia dibanding format bertanda hubung. Bila keterbacaan lebih diutamakan daripada kelengkapan komponen, alternatifnya adalah `RYU-KY-0007` (11 karakter) yang membuang segmen nama produk. Format 12 karakter di atas dipakai kecuali diputuskan lain.

### 7.20 Flash sale

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F20.1 | Admin dapat membuat flash sale dengan waktu mulai dan berakhir | M |
| F20.2 | Flash sale berisi daftar produk atau varian dengan harga khusus | M |
| F20.3 | Harga flash sale otomatis berlaku di kasir selama periode aktif | M |
| F20.4 | Kuota flash sale dapat dibatasi per produk maupun per pelanggan | S |
| F20.5 | Flash sale dapat dikaitkan ke event tertentu | S |
| F20.6 | Antarmuka kasir menampilkan penanda visual bahwa harga sedang diskon | M |
| F20.7 | Laporan penjualan memisahkan penjualan flash sale dan penjualan normal | S |
| F20.8 | Flash sale berfungsi saat perangkat offline berdasarkan konfigurasi yang sudah tersinkron sebelumnya | M |

**Kriteria penerimaan F20.3** — Transaksi pada pukul 10:00 saat flash sale berlaku 09:00–11:00 menggunakan harga diskon, dan transaksi pukul 11:01 kembali menggunakan harga normal, keduanya tercatat sebagai nominal final di `ORDER_ITEMS`.

### 7.21 Pembayaran & bukti pembayaran

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F21.1 | Mendukung metode pembayaran tunai | M |
| F21.2 | Mendukung transfer bank manual dengan menampilkan rekening tujuan yang dipilih kasir | M |
| F21.3 | Daftar rekening tujuan dikelola di pengaturan sistem; pada v1 terdapat dua rekening, BCA dan Mandiri | M |
| F21.11 | Mendukung pembayaran QR e-wallet dengan menampilkan QR statis milik toko di layar | M |
| F21.12 | Bukti pembayaran wajib diisi untuk seluruh pembayaran non-tunai; transaksi tidak dapat diselesaikan tanpa bukti | M |
| F21.13 | Nomor rekening ditampilkan besar dan jelas agar mudah dibaca pelanggan dari seberang meja | M |
| F21.4 | Kasir dapat mengambil foto bukti pembayaran melalui webcam perangkat | M |
| F21.5 | Kasir dapat mengunggah berkas bukti pembayaran secara manual | M |
| F21.6 | Bukti pembayaran tersimpan terkait transaksi dan dapat dilihat kembali | M |
| F21.7 | Transaksi transfer memiliki status verifikasi: menunggu, terverifikasi, ditolak | M |
| F21.8 | Pembayaran gabungan tunai dan non-tunai dalam satu transaksi | S |
| F21.9 | Bukti pembayaran yang diambil offline tersimpan lokal dan terunggah saat koneksi tersedia | M |
| F21.10 | Perhitungan kembalian untuk pembayaran tunai | M |

**Kriteria penerimaan F21.12** — Tombol penyelesaian transaksi tidak aktif untuk metode transfer maupun QR e-wallet sampai minimal satu berkas bukti terlampir. Aturan ini ditegakkan di sisi server, bukan hanya di antarmuka.

**Retensi bukti pembayaran**

Bukti pembayaran disimpan permanen selama berkas sudah terunggah ke sistem, tanpa penghapusan otomatis.

| Konsekuensi | Penanganan |
|---|---|
| Penyimpanan terus bertumbuh | Kompresi foto sebelum simpan; pantau kapasitas disk perangkat sebagai bagian rutinitas cadangan |
| Data pribadi tersimpan tanpa batas waktu | Akses dibatasi per peran; tinjau ulang kebijakan retensi setelah satu tahun operasional |
| Cadangan ikut membesar | Berkas bukti dicadangkan terpisah dari dump basis data agar proses cadangan tetap ringan |

Catatan keamanan (area risiko: access control dan perlindungan data) — nomor rekening dan bukti transfer termasuk data sensitif. Berkas bukti disimpan pada penyimpanan non-publik dengan akses melalui pemeriksaan izin, bukan tautan langsung yang dapat ditebak. Nomor rekening disimpan sebagai konfigurasi sistem dan tidak ikut disertakan pada berkas ekspor apa pun. Tidak ada verifikasi otomatis ke bank atau penyedia e-wallet pada v1; verifikasi dilakukan manual oleh kasir atau admin.

### 7.22 Pengiriman pre-order

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F22.1 | Pre-order dapat memilih metode penyerahan: ambil di event atau kirim via kurir | M |
| F22.2 | Pencatatan alamat pengiriman pelanggan | M |
| F22.3 | Pencatatan kurir, ongkos kirim, dan nomor resi | M |
| F22.4 | Status pengiriman: menunggu, dikemas, dikirim, diterima | M |
| F22.5 | Ongkos kirim masuk ke total tagihan pelanggan dan tidak terhitung sebagai pendapatan penjualan produk | M |
| F22.6 | Integrasi API ekspedisi untuk ongkir otomatis dan pelacakan resi | C |

ASSUMPTION — pada v1 ongkos kirim diasumsikan diinput manual oleh admin berdasarkan tarif kurir yang berlaku, tanpa integrasi API ekspedisi.

### 7.23 Bahan baku & harga modal

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F23.1 | Pencatatan bahan baku beserta satuan dan stok | M |
| F23.2 | Pencatatan pembelian bahan baku beserta harga dan pemasok | M |
| F23.3 | Perhitungan harga modal produk dari bahan baku yang dipakai | M |
| F23.4 | Penambahan biaya produksi lain di luar bahan baku, misalnya jasa cetak | S |
| F23.5 | Penetapan harga jual melalui markup persentase atau nominal terhadap harga modal | M |
| F23.6 | Harga jual hasil markup dapat disunting manual sebelum disimpan | M |
| F23.7 | Sistem menampilkan margin per produk saat penetapan harga | M |
| F23.8 | Peringatan saat harga jual berada di bawah harga modal | S |
| F23.9 | Stok bahan baku berkurang otomatis saat produksi dicatat | S |

### 7.24 Produksi & vendor

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F24.1 | Pencatatan order produksi: artist pemilik, vendor pengerja, produk, jumlah target, tanggal | M |
| F24.2 | Status produksi: direncanakan, berjalan, selesai, dibatalkan | M |
| F24.3 | Pencatatan bahan baku yang dikonsumsi per order produksi | S |
| F24.4 | Hasil produksi yang selesai otomatis menambah stok produk jadi | M |
| F24.5 | Harga modal per unit terhitung dari total biaya produksi dibagi jumlah output | M |
| F24.6 | Laporan biaya produksi per artist dan per periode | S |
| F24.7 | Pencatatan produk gagal atau cacat produksi | C |
| F24.8 | Laporan biaya produksi per vendor untuk membandingkan harga antar pemasok | S |

### 7.25 Vendor management

Modul untuk mencatat pihak eksternal tempat artist membeli atau memproduksi merchandise, misalnya percetakan, jasa akrilik, atau pemasok bahan baku.

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F25.1 | CRUD data vendor: nama, jenis layanan, kontak, catatan, status aktif | M |
| F25.2 | Pembelian bahan baku dapat dikaitkan ke vendor pemasoknya | M |
| F25.3 | Order produksi dapat dikaitkan ke vendor yang mengerjakan | M |
| F25.4 | Riwayat transaksi dan total pengeluaran per vendor | S |
| F25.5 | Pencatatan harga satuan layanan per vendor sebagai rujukan estimasi biaya | S |
| F25.6 | Perbandingan harga antar vendor untuk jenis layanan yang sama | C |
| F25.7 | Status pembayaran ke vendor: belum dibayar, sebagian, lunas | S |
| F25.8 | Ekspor dan impor data vendor via Excel | M |

**Kriteria penerimaan F25.3** — Order produksi tidak dapat disimpan tanpa artist pemilik hasil produksi; vendor pengerja bersifat opsional untuk mengakomodasi produksi yang dikerjakan sendiri.

Catatan: vendor tidak memiliki akun login pada sistem dan tidak menerima bagi hasil penjualan. Vendor hanya muncul di sisi biaya.

### 7.26 Dashboard per artist

Halaman ringkasan performa untuk satu artist, dapat dibuka admin maupun artist itu sendiri bila akses login sudah tersedia.

| ID | Kebutuhan | Prioritas |
|---|---|---|
| F26.1 | Ringkasan angka utama: total penjualan, jumlah unit terjual, jumlah transaksi, dan sisa stok | M |
| F26.2 | Filter dashboard berdasarkan event dan rentang tanggal | M |
| F26.3 | Daftar produk terlaris milik artist beserta jumlah dan nilai penjualannya | M |
| F26.4 | Perbandingan performa antar event untuk artist tersebut | S |
| F26.5 | Status penyerahan hasil: total penjualan, sudah dibayarkan, dan sisa yang belum dibayarkan | M |
| F26.6 | Ringkasan stok: stok tersisa, produk yang habis, dan produk yang mendekati habis | M |
| F26.7 | Ringkasan biaya produksi dan margin bila data produksi tersedia | S |
| F26.8 | Daftar pre-order aktif milik artist beserta statusnya | S |
| F26.9 | Tren penjualan harian selama event berlangsung | S |
| F26.10 | Ekspor isi dashboard ke Excel atau PDF untuk dikirim ke artist | M |

**Kriteria penerimaan F26.1** — Angka pada dashboard konsisten dengan laporan hasil artist pada modul 7.11 untuk periode dan event yang sama; tidak boleh ada selisih antara keduanya.

Catatan keamanan (area risiko: access control) — dashboard artist wajib memfilter data berdasarkan identitas artist di sisi server. Artist A tidak boleh dapat melihat data artist B dengan mengubah parameter identitas pada URL atau permintaan API.

Catatan perlindungan data — dashboard tidak menampilkan data kontak pelanggan. Untuk pre-order, cukup tampilkan status dan jumlah, bukan identitas pembelinya.

---

## 8. Kebutuhan non-fungsional

| Kategori | Kebutuhan |
|---|---|
| Offline capability | POS wajib dapat melakukan transaksi penuh tanpa koneksi; data tersimpan lokal dan tersinkron otomatis saat online kembali |
| Sinkronisasi | Konflik data diselesaikan dengan aturan yang jelas; transaksi bersifat append-only sehingga tidak saling menimpa |
| Performa | Pencarian produk menampilkan hasil < 300 ms; proses simpan transaksi < 1 detik |
| Perangkat | Berjalan di laptop dan tablet melalui browser modern; layar sentuh didukung |
| Keamanan (secrets management) | Kunci API dan kredensial disimpan sebagai environment variable, tidak pernah di dalam kode sumber atau penyimpanan sisi klien |
| Keamanan (input validation) | Seluruh input divalidasi di sisi server; harga dan jumlah tidak boleh dimanipulasi dari sisi klien |
| Perlindungan data pribadi | Data pelanggan dan artist diperlakukan sebagai data pribadi; akses dibatasi per peran dan tidak diekspor ke pihak yang tidak berkepentingan |
| Auditability | Perubahan stok, harga, dan penghapusan data tercatat dengan identitas pengguna dan waktu |
| Ketersediaan | Kegagalan koneksi ke server tidak boleh menghentikan operasi kasir |
| Cakupan offline | Seluruh fungsi wajib berjalan tanpa internet; terpenuhi secara inheren karena aplikasi berjalan di localhost |
| Portabilitas ke server | Konfigurasi disimpan sebagai environment variable dan jalur berkas dapat dikonfigurasi, agar migrasi ke server publik tidak memerlukan penulisan ulang |
| Cadangan | Dump basis data otomatis harian dan setiap penutupan sesi kasir, dengan salinan di media terpisah |
| Penanganan berkas besar | Impor Excel diproses sebagai antrean latar belakang bila melebihi ambang baris tertentu, agar tidak memblokir antarmuka |
| Keamanan berkas unggahan | Berkas unggahan divalidasi tipe dan ukurannya, disimpan dengan nama acak di luar direktori publik, dan tidak pernah dieksekusi oleh server |
| Kualitas media | Foto bukti pembayaran dikompresi sebelum disimpan agar hemat kuota dan tetap terbaca |
| Dokumentasi API | Kontrak API terdokumentasi dan versinya terkelola; perubahan yang memutus kompatibilitas dikomunikasikan lewat versi endpoint |

---

## 9. Arsitektur & tech stack

### 9.1 Stack yang ditetapkan

| Lapisan | Pilihan |
|---|---|
| Backend | Laravel (PHP) |
| Basis data | MySQL |
| Frontend | Vue.js |
| Pemrosesan Excel | Pustaka spreadsheet pada ekosistem Laravel |
| Pemrosesan latar belakang | Queue worker Laravel untuk impor besar, pembuatan katalog PDF, dan sinkronisasi |
| Penyimpanan berkas | Storage lokal atau object storage untuk foto produk, bukti bayar, dan berkas ekspor |

ASSUMPTION — nama pustaka spesifik untuk Excel, QR code, dan pencetakan PDF sengaja tidak dicantumkan karena versi dan status pemeliharaannya belum diverifikasi. Penetapan pustaka dilakukan pada tahap desain teknis dengan memeriksa versi terkini yang kompatibel.

### 9.2 Model deployment: localhost

Sistem dijalankan secara lokal di perangkat operasional, bukan di server cloud. Laravel, MySQL, dan hasil build Vue seluruhnya berjalan di mesin yang sama dan diakses melalui `localhost` pada peramban.

Keputusan ini **menyelesaikan kebutuhan offline secara langsung**: karena tidak ada permintaan jaringan keluar, aplikasi tetap berfungsi penuh tanpa internet. Tegangan arsitektur antara backend server-side dan kebutuhan offline yang dibahas pada versi sebelumnya dokumen ini menjadi tidak relevan.

| Aspek | Ketentuan |
|---|---|
| Runtime | PHP, MySQL, dan web server berjalan di perangkat operasional |
| Akses | Melalui peramban di alamat lokal |
| Frontend | Vue di-build menjadi berkas statis dan dilayani oleh Laravel |
| Instalasi | Paket lingkungan pengembangan lokal atau container, dengan skrip instalasi agar tidak perlu setup manual |
| Berkas | Foto produk dan bukti pembayaran tersimpan di penyimpanan lokal perangkat |

**Konsekuensi yang harus diterima**

| Konsekuensi | Penjelasan |
|---|---|
| Data terisolasi per perangkat | Setiap instalasi punya basis datanya sendiri; tidak ada data bersama otomatis antar perangkat |
| Multi-kasir menjadi rumit | Dua laptop kasir berarti dua basis data terpisah yang perlu digabungkan manual setelah event |
| Cadangan menjadi tanggung jawab manual | Tidak ada cadangan otomatis di cloud; kerusakan perangkat berisiko menghilangkan seluruh data |
| Pembaruan aplikasi per perangkat | Setiap rilis baru harus dipasang ulang di tiap mesin |
| Storefront online belum dapat berjalan | Penjualan online membutuhkan server yang dapat diakses publik, sehingga ditunda ke fase berikutnya |

**Mitigasi untuk kondisi multi-kasir**

Selama masih satu perangkat kasir, model localhost sudah memadai. Begitu kasir bertambah, tersedia dua jalur:

1. **Satu perangkat sebagai server lokal** — satu laptop menjalankan aplikasi, perangkat kasir lain mengaksesnya melalui jaringan lokal atau hotspot di venue, tanpa perlu internet. Ini pendekatan yang direkomendasikan karena data tetap terpusat dalam satu basis data.
2. **Instalasi terpisah dengan penggabungan data** — setiap perangkat berdiri sendiri, lalu data digabungkan lewat ekspor-impor Excel setelah event. Lebih sederhana secara teknis, tetapi rawan oversell dan salah rekap.

ASSUMPTION — jumlah kasir pada event pertama diasumsikan satu perangkat. Bila lebih, jalur 1 perlu diuji sebelum event karena melibatkan konfigurasi jaringan lokal.

**Jalur menuju online**

Karena Laravel, MySQL, dan Vue adalah stack standar, memindahkan aplikasi yang sama ke server publik di kemudian hari tidak memerlukan penulisan ulang. Yang perlu disiapkan sejak awal agar migrasi mulus: seluruh konfigurasi disimpan sebagai environment variable, jalur penyimpanan berkas dibuat dapat dikonfigurasi, dan tidak ada logika yang bergantung pada asumsi mesin tunggal.

### 9.3 Cadangan data

Karena tidak ada cadangan otomatis di cloud, ini menjadi kebutuhan operasional wajib, bukan opsional:

| Ketentuan | Rincian |
|---|---|
| Cadangan terjadwal | Dump basis data otomatis harian, dan setiap kali sesi kasir ditutup |
| Cadangan selama event | Ekspor Excel berkala selama event berlangsung sebagai lapisan kedua |
| Lokasi salinan | Salinan disimpan di media terpisah dari perangkat utama, misalnya flashdisk atau hard disk eksternal |
| Uji pemulihan | Proses pemulihan diuji minimal sekali sebelum event pertama, bukan diasumsikan berhasil |

Catatan keamanan (area risiko: secrets management dan perlindungan data) — berkas cadangan memuat data pribadi pelanggan dan artist. Cadangan tidak boleh disimpan di layanan berbagi berkas publik, dan perangkat operasional wajib memakai kata sandi sistem operasi.

### 9.4 Analisis dengan UML

Diagram UML berikut menjadi kelengkapan wajib pada tahap desain teknis, sebelum pengembangan dimulai:

| Diagram | Cakupan |
|---|---|
| Use case diagram | Interaksi seluruh peran terhadap 24 modul fungsional |
| Class diagram | Struktur entitas dan relasi, sejalan dengan bagian 6 dokumen ini |
| Sequence diagram | Alur transaksi kasir, impor Excel, produksi dari vendor hingga stok jadi, dan verifikasi pembayaran transfer |
| Activity diagram | Alur pre-order dari pemesanan hingga pengiriman, dan alur produksi dari bahan baku hingga stok jadi |
| State machine diagram | Perubahan status pre-order, pembayaran, pengiriman, produksi, dan sesi kasir |
| Deployment diagram | Penempatan runtime di perangkat operasional, opsi server lokal untuk multi-kasir, dan penyimpanan berkas |

### 9.5 Kontrak API

| Perangkat | Peran |
|---|---|
| Swagger / OpenAPI | Spesifikasi resmi seluruh endpoint, menjadi sumber kebenaran kontrak API |
| Bruno | Koleksi request yang disimpan bersama repositori kode dan ikut terkontrol versi |
| Postman | Koleksi untuk pengujian manual dan berbagi dengan anggota tim |

Ketentuan: spesifikasi OpenAPI wajib diperbarui bersamaan dengan perubahan endpoint dalam satu perubahan kode yang sama. Koleksi Bruno dan Postman diturunkan dari spesifikasi tersebut agar tidak terjadi tiga sumber kebenaran yang saling berbeda.

Catatan keamanan (area risiko: secrets management) — koleksi Bruno dan Postman yang disimpan di repositori tidak boleh memuat token, kata sandi, atau kunci API dalam bentuk nilai langsung. Gunakan variabel lingkungan yang tidak ikut dikomit.

---

## 10. Roadmap & rencana eksekusi

### 10.1 Realitas kapasitas

Dokumen ini memuat 26 modul. Dengan 1 developer dan waktu 2 bulan menuju event Oktober, seluruhnya tidak mungkin selesai. Pemotongan cakupan bukan pilihan, melainkan keharusan.

Yang meringankan: skalanya kecil. Lima artist dan puluhan SKU berarti beberapa fitur yang dirancang untuk skala besar tidak memberi manfaat nyata pada event pertama, sehingga dapat ditunda tanpa kerugian operasional.

### 10.2 Yang dipotong dari MVP dan alasannya

| Modul | Alasan penundaan |
|---|---|
| ~~Impor Excel~~ | ~~Puluhan SKU untuk 5 artist dapat diinput manual dalam hitungan jam. Membangun impor beserta validasi dan pratinjau memakan waktu berhari-hari untuk penghematan yang kecil~~ **Dibatalkan dari daftar potong pada 2026-09-01** atas permintaan eksplisit pemilik produk — lihat catatan di bawah tabel |
| QR code & pemindaian | Dengan puluhan SKU, pencarian pada grid produk berlayar sentuh sama cepatnya dan jauh lebih murah dibangun. Nilai QR baru muncul saat ratusan SKU |
| Flash sale | Diskon dapat diterapkan manual sebagai penyesuaian harga di kasir untuk event pertama |
| ~~Bahan baku, produksi, markup~~ | ~~Perhitungan modal cukup diinput sebagai harga modal per produk. Modul produksi penuh baru bernilai saat volume produksi meningkat~~ **Sebagian dibatalkan dari daftar potong pada 2026-09-01** — lihat catatan "Vendor, bahan baku, dan BOM" di bawah tabel. Modul produksi PENUH (penjadwalan produksi, kapasitas, dsb) tetap di luar cakupan |
| ~~Vendor management~~ | ~~Pengeluaran ke vendor eksternal untuk stiker dan keychain masih dapat dicatat di spreadsheet pada tahap ini~~ **Sebagian dibatalkan dari daftar potong pada 2026-09-01** — lihat catatan di bawah tabel. Manajemen PO/pembelian ke vendor tetap di luar cakupan, hanya master data vendor + harga bahan yang dibangun |
| ~~Pre-order & pengiriman kurir~~ | **Dibatalkan dari daftar potong.** Pre-order dijual di event Oktober, sehingga modul ini masuk MVP |
| Purchase management | Tumpang tindih dengan pencatatan harga modal sederhana |
| User management granular | Satu operator berarti cukup satu akun; peran berlapis belum dibutuhkan |
| Dashboard per artist | Rekap hasil artist pada modul 7.11 sudah menjawab kebutuhan inti; dashboard visual menyusul |

**Catatan perubahan cakupan — 2026-09-01 (impor Excel masuk kembali)**

Keputusan pemotongan di atas sengaja tidak dihapus; ia tetap benar untuk
konteks saat ditulis. Yang berubah adalah keputusannya, bukan alasannya.

Impor Excel diminta kembali secara eksplisit oleh pemilik produk dan sudah
dibangun, bersama ekspor `.xlsx` untuk produk, artist, kategori, dan stok.
Bentuk yang dibangun sedikit berbeda dari 7.15 dan perbedaannya disengaja:

- **Satu berkas gabungan berisi empat sheet**, bukan satu berkas per modul.
  Alasannya dependensi: produk menunjuk artist dan kategori, stok menunjuk
  varian. Empat berkas terpisah memaksa pemilik toko mengurutkan sendiri
  urutan unggahannya.
- **Semua-atau-tidak sama sekali**, menyimpang dari kriteria penerimaan
  F15.5 yang meminta baris valid tetap tersimpan. Karena sheet-sheet itu
  saling bergantung, penyimpanan sebagian meninggalkan master data setengah
  jadi — kondisi yang jauh lebih sulit dibereskan daripada memperbaiki
  berkasnya lalu mengulang impor. Pratinjau F15.4 tetap tersedia lewat
  `dry_run`, memakai jalur validasi yang sama persis.
- **Riwayat impor (F15.7)** belum berupa tabel `IMPORT_JOBS` tersendiri;
  untuk sekarang setiap impor menulis satu baris `activity_logs`
  (`action: imported`) berisi jumlah baris per sheet, dan berkas sumbernya
  disimpan di penyimpanan privat.
- **Impor transaksi penjualan (F15.9) TIDAK dibangun** — masih di luar
  cakupan.

Cakupan yang tetap dipotong: QR code, flash sale, purchase management
(manajemen PO ke vendor), user management granular, dan dashboard per
artist.

**Catatan penambahan pasca-MVP — 2026-09-01 (vendor, bahan baku, dan BOM)**

Atas permintaan eksplisit pemilik produk, dibangun modul baru: master data
vendor (pemasok bahan baku), master data bahan baku, harga bahan per vendor
(satu bahan boleh dijual banyak vendor pada harga berbeda), dan Bill of
Materials (BOM) per varian produk beserta perhitungan modal bahan
(`bom_cost`) yang diturunkan darinya.

Ini **BUKAN** kebangkitan salah satu butir yang dicoret di 10.2 di atas —
cakupannya dibangun sengaja lebih sempit dari keduanya dan tidak
berkorespondensi dengan nomor F- manapun di dokumen ini (kapabilitas baru,
bukan pemulihan kapabilitas lama):

- **Bukan "vendor management" penuh**: tidak ada purchase order, tidak ada
  pencatatan pembelian aktual, tidak ada riwayat transaksi dengan vendor.
  Hanya master data vendor + tabel harga per bahan.
- **Bukan "bahan baku, produksi, markup" penuh**: tidak ada penjadwalan
  produksi, tidak ada pelacakan konsumsi bahan aktual saat produksi
  berjalan, tidak ada laporan efisiensi produksi. Hanya BOM statis
  (resep: bahan apa + berapa banyak per unit) dan modal yang dihitung
  darinya.
- **`bom_cost` TIDAK menimpa `cost_price`.** `cost_price` sudah dipakai
  laporan laba dan settlement artist di seluruh kodebase; `bom_cost`
  disajikan terpisah, read-only, untuk dibandingkan manual oleh pemilik
  toko. Lihat dokblok `App\Services\BomCostCalculator`.
- **BOM diikat ke VARIAN**, bukan produk induk — varian ukuran/warna
  berbeda dari produk yang sama (mis. keychain kecil vs besar) bisa punya
  kebutuhan bahan yang berbeda.
- Diimpor/diekspor lewat workbook gabungan yang sama dengan impor master
  data lain (10.2), sebagai empat sheet tambahan: `vendors`, `materials`,
  `vendor_prices`, `bom`.

Cakupan yang tetap dipotong dari 10.2 setelah penambahan ini: purchase
management (PO ke vendor) dan modul produksi penuh.

### 10.3 Cakupan MVP Oktober

| Prioritas | Modul |
|---|---|
| Inti | Autentikasi sederhana, artist, kategori, produk & varian, kode produk otomatis |
| Inti | Stok masuk-keluar beserta riwayat pergerakan |
| Inti | Event management dan penandaan event pada transaksi |
| Inti | Kasir: keranjang, pencarian produk, penyelesaian transaksi |
| Inti | Pembayaran tunai, transfer, QR e-wallet, dengan bukti bayar wajib |
| Inti | Struk di layar |
| Inti | Buka dan tutup sesi kasir |
| Inti | Laporan penjualan per event dan rekap hasil per artist |
| Inti | Pre-order: pencatatan, DP, status, pilihan ambil di event atau kirim kurir |
| Inti | Ekspor Excel untuk laporan penjualan dan rekap artist |
| Inti | Cadangan basis data dan prosedur pemulihan |
| Menyusul bila waktu tersisa | Katalog PDF, laporan modal & keuntungan, dashboard artist |

### 10.4 Timeline

ASSUMPTION — perhitungan menggunakan asumsi event berlangsung akhir Oktober 2026, memberi sekitar delapan minggu kerja. Bila event jatuh pada awal Oktober, waktu tersedia hanya sekitar lima minggu dan cakupan pada 10.3 masih perlu dipangkas lagi. Tanggal pasti event perlu dikonfirmasi lebih dulu.

| Minggu | Fokus | Keluaran |
|---|---|---|
| 1 | Fondasi | Lingkungan localhost berjalan, skema basis data, autentikasi, kerangka Vue |
| 2 | Master data | CRUD artist, kategori, produk & varian, generator kode produk |
| 3 | Stok & event | Pergerakan stok, CRUD event, penandaan event pada transaksi |
| 4–5 | Kasir | Keranjang, pencarian produk, pembayaran tiga metode, unggah bukti, struk layar, buka-tutup sesi |
| 6 | Pre-order | Pencatatan pre-order, DP, status, alamat dan pengiriman kurir |
| 7 | Laporan & pengerasan | Laporan penjualan, rekap artist, ekspor Excel, cadangan dan uji pemulihan |
| 8 | Uji lapangan | Simulasi event penuh, input data produk sungguhan, pelatihan operator |

Minggu 8 tidak boleh dipakai untuk menambah fitur. Simulasi event dengan data sungguhan adalah pengaman terakhir sebelum dipakai di lapangan.

### 10.5 Setelah event Oktober

| Tahap | Cakupan |
|---|---|
| Pasca-event terdekat | Modul yang dipotong pada 10.2, diprioritaskan berdasarkan kendala nyata yang muncul saat event |
| Menengah | Pre-order beserta pengiriman kurir, produksi dan bahan baku, vendor management, dashboard artist |
| Lanjut | Migrasi dari localhost ke server publik, storefront online, portal artist, integrasi marketplace |

### 10.6 Aturan pengendalian cakupan

Permintaan fitur baru yang muncul sebelum Oktober masuk ke daftar pasca-event, bukan ke MVP. Satu developer tanpa cadangan berarti tidak ada ruang untuk mengejar keterlambatan; setiap penambahan cakupan langsung memotong waktu pengujian di minggu terakhir.

---

## 11. Asumsi

Poin berikut masih berstatus ASSUMPTION dan perlu dikonfirmasi:

1. Tanggal pasti event Oktober, karena selisih awal atau akhir bulan mengubah cakupan yang layak dikerjakan.
2. ~~Katalog PDF, laporan modal & keuntungan, dan ekspor Excel data master tidak dikerjakan sebelum Oktober, sebagai konsekuensi masuknya pre-order ke MVP.~~ **Diperbarui 2026-09-01:** laporan modal & keuntungan sudah dibangun, dan ekspor Excel data master (produk, artist, kategori, stok) beserta impornya juga sudah dibangun — lihat catatan perubahan cakupan di 10.2. Katalog PDF tetap di luar cakupan.
3. Laptop operasional memiliki kamera yang dapat diakses peramban untuk memotret bukti pembayaran.
4. QR e-wallet berupa QR statis milik toko yang dicetak atau ditampilkan, bukan QR dinamis per transaksi.
5. Kapasitas penyimpanan laptop memadai untuk foto bukti pembayaran yang disimpan permanen.
6. Developer tunggal tersedia penuh selama delapan minggu tanpa pekerjaan lain yang bersaing.
7. Data produk sungguhan siap diinput pada minggu ke-8 untuk keperluan uji lapangan.
8. Belum ada kebutuhan pelaporan pajak formal pada tahap ini.
9. Sistem hanya mencatat hasil dan biaya produksi, bukan mengelola alur kerja produksi di sisi vendor.

---

## 12. Risiko

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Konflik data saat dua kasir menjual item terakhir secara offline | Oversell pada item limited | Alokasi stok terpisah per perangkat kasir saat event, atau batasi satu kasir untuk item limited |
| Perangkat kasir rusak/hilang saat event | Kehilangan data transaksi yang belum tersinkron | Sinkronisasi otomatis setiap kali koneksi tersedia; ekspor cadangan berkala selama event |
| Perangkat operasional rusak tanpa cadangan terkini | Seluruh data penjualan hilang permanen | Cadangan terjadwal ke media terpisah; ekspor Excel berkala selama event |
| Sengketa hasil dengan artist | Kerusakan hubungan jangka panjang dengan artist | Rincian item terjual disertakan dalam setiap rekap; log perubahan stok dapat diaudit |
| Cakupan fitur meluas sebelum MVP selesai | Event pertama terlewat | Kunci cakupan Fase 1; permintaan baru masuk ke backlog Fase 2 |
| Kebutuhan kasir kedua muncul mendadak di venue | Data terpecah di dua basis data, rawan oversell | Uji skema satu perangkat sebagai server lokal sebelum event, bukan saat event berlangsung |
| Cakupan MVP tidak selesai tepat waktu | Event Oktober dijalankan dengan sistem setengah jadi | Kunci cakupan pada 10.3; siapkan pencatatan manual sebagai jalur cadangan hingga uji lapangan minggu ke-8 lulus |
| Masuknya pre-order menghapus minggu pengerasan | Sistem dipakai di event tanpa penyangga waktu untuk perbaikan bug | Gabungkan pengerasan ke minggu 7; bila event jatuh awal Oktober, pre-order dijalankan manual dan modulnya menyusul |
| Developer tunggal berhalangan | Pengembangan berhenti total tanpa pengganti | Simpan kode di repositori bersama sejak hari pertama; dokumentasikan cara menjalankan sistem agar orang lain dapat melanjutkan |
| Bukti pembayaran wajib memperlambat antrean | Antrean menumpuk pada jam ramai | Uji alur foto bukti saat simulasi minggu ke-8; sediakan opsi unggah menyusul untuk pelanggan yang sudah jelas membayar |
| Penyimpanan bukti pembayaran memenuhi disk laptop | Sistem berhenti di tengah event | Kompresi foto; periksa sisa kapasitas disk sebagai bagian persiapan pra-event |
| Istilah artist dan vendor tertukar saat implementasi | Laporan hasil artist tercampur biaya produksi | Nama tabel dan kolom mengikuti glosarium dokumen ini; ditinjau saat review skema basis data |
| Ketergantungan pada satu perangkat operasional | Operasional berhenti bila perangkat bermasalah | Siapkan perangkat cadangan dengan aplikasi terpasang dan cadangan data terkini sebelum event |
| Impor Excel merusak data massal | Stok atau harga kacau dalam jumlah besar | Wajibkan pratinjau validasi sebelum simpan; sediakan riwayat impor dan cadangan sebelum impor besar |
| Bukti pembayaran memuat data pribadi pelanggan | Risiko kebocoran data pribadi | Simpan di luar direktori publik, batasi akses per peran, dan tetapkan masa retensi berkas |
| Pemindaian QR gagal di kondisi cahaya venue | Antrean kasir melambat | Sediakan input kode produk manual sebagai jalur cadangan yang selalu tersedia |
| Foto bukti bayar menumpuk saat offline | Penyimpanan perangkat penuh di tengah event | Kompresi foto sebelum simpan; tampilkan indikator antrean unggah dan sisa penyimpanan |

---

## 13. Pertanyaan terbuka

1. Kapan tepatnya event Oktober berlangsung?
2. Apakah pre-order menerima pembayaran DP sebagian, atau harus lunas di muka?
3. Apakah developer tunggal ini bekerja penuh waktu untuk proyek ini?
4. Siapa yang mengoperasikan sistem saat event, dan apakah orangnya sama dengan developer?
5. Apakah tersedia laptop cadangan bila perangkat utama bermasalah di tengah event?
6. Apakah QR e-wallet yang dipakai berupa QR statis, dan atas nama akun apa?
