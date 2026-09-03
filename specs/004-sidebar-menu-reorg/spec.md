# Feature Specification: Penataan Ulang Menu Sidebar

**Feature Branch**: `004-sidebar-menu-reorg`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "update Menu: menu utama inventaris, submenu: kategori, produk, stok; Sales di bawah cashier session; Purchase di bawah Sales; Inventaris di bawah purchase; pre-orders di bawah inventaris."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Menu inti transaksi berurutan secara alur kerja (Priority: P1)

Seorang kasir/owner membuka aplikasi dan menyusuri sidebar dari atas ke
bawah mengikuti alur kerja hariannya: buka sesi kasir, transaksi
penjualan, lalu urusan barang (pembelian bahan/stok, produk, pre-order)
— bukan urutan sekarang yang mencampur menu transaksi dengan menu master
data secara acak.

**Why this priority**: Ini inti permintaan pengguna — urutan menu adalah
satu-satunya perubahan yang diminta secara eksplisit dan berdampak
langsung ke kecepatan navigasi harian setiap peran yang login.

**Independent Test**: Login sebagai owner, buka sidebar, verifikasi
urutan menu dari atas ke bawah persis mengikuti urutan yang diminta,
tanpa mengubah halaman/otorisasi apa pun di baliknya.

**Acceptance Scenarios**:

1. **Given** pengguna sudah login, **When** melihat sidebar, **Then**
   menu "Sales" muncul tepat setelah "Cashier Session".
2. **Given** pengguna sudah login, **When** melihat sidebar, **Then**
   menu "Purchase" muncul tepat setelah "Sales".
3. **Given** pengguna sudah login, **When** melihat sidebar, **Then**
   menu "Inventaris" muncul tepat setelah "Purchase".
4. **Given** pengguna sudah login, **When** melihat sidebar, **Then**
   menu "Pre-orders" muncul tepat setelah "Inventaris".

---

### User Story 2 - Kategori/Produk/Stok dikelompokkan sebagai "Inventaris" (Priority: P1)

Owner/admin/inventory yang mengelola barang ingin tiga layar yang saling
berkaitan erat (Kategori, Produk, Stok) tampil sebagai satu grup menu
"Inventaris" yang bisa dibuka/tutup, bukan tiga item terpisah yang
berjejer di sidebar utama — mengikuti pola yang sudah ada untuk grup
"Pengaturan" (Settings/Users/Roles).

**Why this priority**: Bagian eksplisit dari permintaan pengguna dan
prasyarat langsung bagi User Story 1 (urutan "Inventaris" tidak bisa
diuji tanpa grup ini ada lebih dulu).

**Independent Test**: Klik grup "Inventaris" di sidebar, verifikasi ia
mengembang menampilkan tiga submenu (Kategori, Produk, Stok), masing-masing
menuju halaman yang sama persis seperti sebelumnya, dengan hak akses per
peran yang sama seperti sebelumnya.

**Acceptance Scenarios**:

1. **Given** sidebar ditampilkan, **When** pengguna melihat grup baru
   "Inventaris", **Then** ia berisi tepat tiga submenu: Kategori, Produk,
   Stok — dalam urutan itu.
2. **Given** seorang pengguna yang sebelumnya tidak berhak mengakses salah
   satu dari Kategori/Produk/Stok, **When** melihat grup "Inventaris",
   **Then** submenu yang tidak berhak diaksesnya tetap tersembunyi, sama
   seperti perilaku sebelum perubahan ini (hak akses tidak berubah, hanya
   tampilannya yang dikelompokkan).
3. **Given** grup "Inventaris" hanya berisi submenu yang TIDAK berhak
   diakses pengguna, **When** melihat sidebar, **Then** grup "Inventaris"
   itu sendiri tersembunyi seluruhnya (sejalan dengan perilaku grup
   "Pengaturan" yang sudah ada).

---

### User Story 3 - Vendor/Bahan Baku dikelompokkan sebagai "Purchase" (Priority: P2)

Owner/admin/inventory yang mengurus pembelian bahan baku ingin layar
Vendor dan Bahan Baku (yang sama-sama tentang dari mana dan berapa harga
bahan dibeli) tampil sebagai satu grup menu "Purchase", konsisten dengan
pola pengelompokan "Inventaris" di User Story 2.

**Why this priority**: "Purchase" secara eksplisit diminta di urutan
menu, tapi belum ada satu pun layar bernama "Purchase" di aplikasi ini
saat ini — prioritas P2 (bukan P1) karena bergantung pada keputusan
penafsiran cakupan (lihat Assumptions) yang sedikit lebih berisiko
salah tafsir dibanding pengelompokan Inventaris yang sudah jelas
komponennya.

**Independent Test**: Klik grup "Purchase" di sidebar, verifikasi ia
mengembang menampilkan submenu Vendor dan Bahan Baku, masing-masing
menuju halaman yang sama persis seperti sebelumnya.

**Acceptance Scenarios**:

1. **Given** sidebar ditampilkan, **When** pengguna melihat grup baru
   "Purchase", **Then** ia berisi submenu Vendor dan Bahan Baku.
2. **Given** seorang pengguna yang tidak berhak mengakses Vendor maupun
   Bahan Baku, **When** melihat sidebar, **Then** grup "Purchase"
   tersembunyi seluruhnya.

---

### Edge Cases

- Menu yang TIDAK disebutkan permintaan pengguna (Dashboard, POS, Events,
  Artists, Customers, Reports, grup Pengaturan) tetap ada di sidebar,
  mempertahankan urutan relatifnya masing-masing, ditempatkan setelah
  urutan baru di atas.
- Pengelompokan ini murni tampilan navigasi — URL/route setiap halaman,
  hak akses per peran (`menu_keys`), dan seluruh perilaku halaman itu
  sendiri MUST NOT berubah sama sekali.
- Pengguna yang sedang berada di salah satu halaman yang dipindah
  posisinya (mis. sedang di halaman Stok) MUST NOT ter-logout atau
  kehilangan state saat sidebar ditata ulang.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Sidebar MUST menampilkan menu dengan urutan: (menu yang
  sudah ada sebelum Sesi Kasir, tidak berubah) → Sesi Kasir → Sales →
  Purchase (grup) → Inventaris (grup) → Pre-orders → (sisa menu yang
  tidak disebutkan permintaan pengguna, dalam urutan relatif yang sama
  seperti sebelumnya).
- **FR-002**: Sidebar MUST mengelompokkan Kategori, Produk, dan Stok
  sebagai submenu di bawah satu menu induk yang dapat dibuka/tutup
  bernama "Inventaris", dalam urutan itu (Kategori, Produk, Stok).
- **FR-003**: Sidebar MUST mengelompokkan Vendor dan Bahan Baku sebagai
  submenu di bawah satu menu induk yang dapat dibuka/tutup bernama
  "Purchase".
- **FR-004**: Pengelompokan menu MUST NOT mengubah hak akses (`menu_keys`)
  atau route/URL halaman manapun — submenu yang sebelumnya tidak terlihat
  bagi suatu peran tetap tidak terlihat, dan setiap grup yang seluruh
  submenu-nya tidak dapat diakses peran yang login MUST tersembunyi
  seluruhnya, sejalan dengan perilaku grup "Pengaturan" yang sudah ada.
- **FR-005**: Perubahan ini MUST tersedia dalam kedua bahasa yang didukung
  aplikasi (Indonesia/Inggris), sejalan dengan cakupan i18n yang sudah
  berlaku di seluruh sidebar.

### Key Entities *(include if feature involves data)*

- **Grup Menu**: Kumpulan tampilan (bukan entitas data) yang mengelompokkan
  beberapa menu_key yang sudah ada di bawah satu label dan ikon induk yang
  bisa dibuka/tutup — pola yang sudah ada (grup "Pengaturan") diperluas
  untuk dua grup baru: "Inventaris" dan "Purchase".

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% pengguna yang login melihat urutan sidebar baru persis
  seperti FR-001, diverifikasi lewat pengujian otomatis untuk setiap
  peran.
- **SC-002**: 100% hak akses menu per peran tetap identik sebelum dan
  sesudah perubahan ini — tidak ada peran yang mendapat akses baru atau
  kehilangan akses akibat penataan ulang.
- **SC-003**: Waktu yang dibutuhkan pengguna baru untuk menemukan menu
  Stok/Produk/Kategori atau Vendor/Bahan Baku berkurang karena
  letaknya berdekatan secara logis dalam satu grup, bukan tersebar.

## Follow-up Update (2026-09-03) — Gambar Produk & Filter Klik

Ditambahkan: "Product: tampilkan image di table dan di POS; add filter
clickable, all artist and all categories to show all product."

### User Story 4 - Gambar produk terlihat di daftar Produk dan di POS (Priority: P2)

Owner/admin yang mengelola produk dan kasir yang mencari barang di POS
sama-sama ingin melihat foto produk langsung di daftar/grid, bukan hanya
saat membuka form edit — memudahkan mengenali barang secara visual,
terutama saat banyak varian bernama mirip.

**Why this priority**: Peningkatan visual, bukan blocker alur kerja inti
(kasir masih bisa transaksi tanpa gambar) — P2 setelah US1/US2 penataan
menu, tapi berdiri sendiri dari sisi implementasi.

**Independent Test**: Buka halaman Produk, verifikasi setiap baris tabel
menampilkan thumbnail produk (atau placeholder bila produk belum punya
gambar). Buka POS, verifikasi setiap kartu produk di grid menampilkan
gambar yang sama.

**Acceptance Scenarios**:

1. **Given** sebuah produk sudah punya gambar, **When** membuka daftar
   Produk, **Then** baris produk itu menampilkan thumbnail gambarnya.
2. **Given** sebuah produk belum punya gambar, **When** membuka daftar
   Produk atau grid POS, **Then** baris/kartu itu menampilkan placeholder
   yang jelas (bukan kosong/rusak).
3. **Given** sebuah produk sudah punya gambar, **When** kasir menyusuri
   grid POS, **Then** kartu produk itu menampilkan gambarnya.

### User Story 5 - Filter artist & kategori berupa chip yang bisa diklik (Priority: P2)

Pengguna yang menyaring daftar produk (di halaman Produk maupun di POS)
ingin cukup mengklik nama artist atau kategori untuk menyaring, dan
mengklik "Semua Artist"/"Semua Kategori" untuk menampilkan kembali
seluruh produk — pola yang sudah ada untuk filter kategori di POS,
diperluas mencakup filter artist juga, dan dibuat konsisten di kedua
layar.

**Why this priority**: Perluasan pola yang sudah terbukti (filter
kategori POS sudah begini) ke satu sumbu filter baru (artist) dan satu
layar lagi (Produk) — P2, tidak mengubah data/otorisasi apa pun.

**Independent Test**: Di POS dan di halaman Produk, klik chip nama
artist tertentu — verifikasi daftar tersaring hanya produk artist itu.
Klik "Semua Artist" — verifikasi seluruh produk (lintas artist) muncul
kembali. Ulangi untuk kategori.

**Acceptance Scenarios**:

1. **Given** daftar/grid produk ditampilkan, **When** pengguna mengklik
   chip nama artist tertentu, **Then** hanya produk milik artist itu yang
   tampil.
2. **Given** filter artist sedang aktif, **When** pengguna mengklik chip
   "Semua Artist", **Then** produk dari semua artist tampil kembali.
3. **Given** daftar/grid produk ditampilkan, **When** pengguna mengklik
   chip nama kategori tertentu, **Then** hanya produk kategori itu yang
   tampil.
4. **Given** filter kategori sedang aktif, **When** pengguna mengklik chip
   "Semua Kategori", **Then** produk dari semua kategori tampil kembali.
5. **Given** filter artist DAN kategori aktif bersamaan, **When** melihat
   daftar, **Then** hanya produk yang cocok KEDUA kriteria yang tampil
   (filter bersifat gabungan/AND, bukan saling menggantikan).

### Additional Functional Requirements

- **FR-006**: Daftar Produk (halaman manajemen) dan grid produk di POS
  MUST menampilkan gambar produk per baris/kartu, dengan placeholder yang
  jelas untuk produk tanpa gambar.
- **FR-007**: Daftar Produk dan grid POS MUST menyediakan filter artist
  dan filter kategori dalam bentuk chip yang bisa diklik langsung
  (bukan hanya dropdown), masing-masing menyertakan pilihan "Semua
  Artist"/"Semua Kategori" yang menghapus filter itu dan menampilkan
  seluruh produk kembali.
- **FR-008**: Filter artist dan filter kategori MUST dapat dipakai
  bersamaan (gabungan/AND), konsisten dengan perilaku filter yang sudah
  ada di halaman Produk.

### Additional Success Criteria

- **SC-004**: 100% produk yang memiliki gambar menampilkannya di kedua
  layar (Produk, POS); 100% produk tanpa gambar menampilkan placeholder
  yang konsisten, bukan ruang kosong/ikon rusak.
- **SC-005**: Pengguna dapat menyaring ke satu artist atau kategori
  tertentu, atau kembali ke "semua", hanya dengan satu klik — tanpa
  membuka dropdown atau mengetik.

### Additional Assumptions

- "Filter clickable" diterapkan pada KEDUA layar (halaman Produk dan
  POS) untuk konsistensi, meskipun permintaan pengguna tidak menyebut
  layar secara eksplisit — POS sudah punya pola chip kategori yang sama
  persis, jadi memperluasnya ke kedua layar/kedua sumbu filter lebih
  konsisten daripada hanya menambah di salah satu.
- Gambar produk (FR-006) memakai `image_path`/URL yang sudah ada pada
  data produk (dipakai form edit saat ini) — fitur ini tidak menambah
  kemampuan unggah gambar baru, hanya menampilkan yang sudah tersimpan.

## Assumptions

- **"Purchase" belum punya halaman sendiri di aplikasi ini** (manajemen
  pembelian/PO ke vendor sengaja dipotong dari MVP — lihat CLAUDE.md
  "Scope discipline"). Permintaan ini ditafsirkan sebagai PENATAAN ULANG
  navigasi atas dua halaman yang SUDAH ADA dan paling relevan dengan
  makna "pembelian" (Vendor dan Bahan Baku/harga bahan per vendor), BUKAN
  permintaan membangun modul purchase order baru. Tidak ada halaman/route/
  backend baru yang dibuat oleh fitur ini.
- Menu yang tidak disebutkan eksplisit oleh pengguna (Dashboard, POS,
  Events, Artists, Customers, Reports, grup Pengaturan) tetap di
  posisinya masing-masing secara relatif, ditempatkan setelah rangkaian
  urutan baru (Sesi Kasir → Sales → Purchase → Inventaris → Pre-orders).
- Ikon untuk dua grup baru ("Inventaris", "Purchase") dipilih mengikuti
  konvensi ikon yang sudah dipakai sub-menunya masing-masing (Phosphor
  Icons duotone, sejalan dengan CLAUDE.md), bukan permintaan desain
  spesifik dari pengguna.
