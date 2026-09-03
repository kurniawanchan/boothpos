# Feature Specification: Seed Data Dummy & Mode DEMO/LIVE

**Feature Branch**: `003-seed-demo-live`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "create Seed data dummy: nama toko Demo Sakana Fridge, isi event dummy dan aktifkan, isi 3 artist dummy, isi 3 produk utk masing-masing 3 artist dummy dengan lengkap berikut 3 variant per produk dan isi stock, isi 3 kategori merchandise, isi data penjualan dummy, isi 3 data customer, isi 3 data vendor dari toko online 3 dari toko offline, isi bahan baku yg berhubungan dengan merchandise anime & game dari setiap vendor, isi data pre-order. Tambahkan option utk user bisa memilih menggunakan sistem dengan mode DEMO atau LIVE, tampilkan status DEMO/LIVE tersebut; jika DEMO tampil data dummy, jika LIVE data dummy tidak muncul, hanya data yg dibuat pada sesi LIVE yg muncul."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Toko baru mencoba sistem dengan data contoh lengkap (Priority: P1)

Seorang pemilik toko ("Demo Sakana Fridge") yang baru memasang BoothPOS ingin melihat bagaimana sistem bekerja dengan data yang realistis sebelum menggunakannya untuk transaksi sungguhan. Saat pertama kali masuk, ia menemukan sebuah event yang sudah aktif, tiga artist, produk lengkap dengan varian dan stok, kategori merchandise, beberapa customer, vendor, bahan baku, riwayat penjualan, dan pre-order — semuanya siap dijelajahi tanpa perlu diinput manual.

**Why this priority**: Tanpa data contoh, toko baru menghadapi layar kosong di semua modul (POS, laporan, master data) sehingga sulit menilai apakah sistem sesuai kebutuhan mereka. Ini adalah kebutuhan inti dari permintaan seed data.

**Independent Test**: Jalankan proses seeding, lalu login dan verifikasi setiap modul (Event, Artist, Produk, Kategori, Customer, Vendor, Bahan Baku, Penjualan, Pre-order) menampilkan data contoh yang lengkap dan saling terhubung secara valid (mis. produk terhubung ke artist yang benar, stok sesuai varian, bahan baku terhubung ke vendor yang benar).

**Acceptance Scenarios**:

1. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Event, **Then** ia melihat satu event dummy berstatus aktif.
2. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Artist, **Then** ia melihat 3 artist dummy.
3. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Produk untuk salah satu artist, **Then** ia melihat 3 produk milik artist tersebut, masing-masing dengan 3 varian dan stok terisi.
4. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Kategori, **Then** ia melihat 3 kategori merchandise.
5. **Given** sistem baru saja di-seed, **When** pemilik toko membuka riwayat Penjualan, **Then** ia melihat transaksi penjualan dummy dengan rincian item dan pembayaran yang valid.
6. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Customer, **Then** ia melihat 3 customer dummy.
7. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Vendor, **Then** ia melihat 3 vendor bertipe toko online dan 3 vendor bertipe toko offline.
8. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Bahan Baku, **Then** ia melihat bahan baku bertema merchandise anime & game, masing-masing terhubung ke harga dari vendor yang menjualnya.
9. **Given** sistem baru saja di-seed, **When** pemilik toko membuka daftar Pre-order, **Then** ia melihat setidaknya satu pre-order dummy dengan status dan pembayaran yang valid.

---

### User Story 2 - Berpindah antara mode DEMO dan LIVE (Priority: P1)

Pemilik atau admin toko ingin memilih apakah sistem sedang dipakai untuk eksplorasi/pelatihan (DEMO, menampilkan data contoh) atau untuk operasional toko sungguhan (LIVE, hanya menampilkan data yang benar-benar dibuat toko tersebut). Status mode yang sedang aktif harus selalu terlihat jelas agar tidak ada yang salah mengira data contoh sebagai data asli, atau sebaliknya.

**Why this priority**: Ini adalah mekanisme inti yang memisahkan data contoh dari data bisnis sungguhan — tanpa ini, seed data dummy berisiko bercampur dengan data transaksi nyata dan mengacaukan laporan keuangan toko.

**Independent Test**: Dari layar pengaturan, ubah mode dari LIVE ke DEMO dan sebaliknya, lalu verifikasi (a) indikator status mode berubah sesuai, dan (b) daftar data pada modul-modul utama berubah sesuai mode yang aktif.

**Acceptance Scenarios**:

1. **Given** sistem sedang dalam mode LIVE, **When** pengguna melihat antarmuka, **Then** status "LIVE" tampil jelas di area yang selalu terlihat (mis. header).
2. **Given** pengguna berwenang mengubah mode, **When** ia memilih mode DEMO, **Then** sistem berpindah ke mode DEMO dan status berubah menjadi "DEMO".
3. **Given** sistem dalam mode DEMO, **When** pengguna membuka modul manapun yang memiliki data dummy, **Then** hanya data yang berasosiasi dengan mode DEMO yang tampil (termasuk data seed dan data lain yang dibuat selagi DEMO aktif).
4. **Given** sistem dalam mode LIVE dan sebelumnya pernah diaktifkan mode DEMO, **When** pengguna membuka modul manapun, **Then** data dummy/DEMO tidak muncul sama sekali; hanya data yang dibuat selagi mode LIVE aktif yang tampil.
5. **Given** pengguna berpindah mode bolak-balik, **When** ia kembali ke mode yang sebelumnya pernah aktif, **Then** data yang terkait mode tersebut utuh kembali (tidak hilang atau tertimpa oleh mode lain).

---

### User Story 3 - Mencegah data DEMO tercampur ke pelaporan bisnis nyata (Priority: P2)

Pemilik toko yang sudah beroperasi dengan mode LIVE ingin memastikan laporan keuangan, stok, dan settlement artist yang ia lihat murni berasal dari transaksi nyata, tidak pernah tercampur angka dari data dummy/DEMO, bahkan jika staf sempat mengaktifkan mode DEMO untuk latihan.

**Why this priority**: Ini melindungi integritas data bisnis — prioritas P2 karena bergantung pada mekanisme pemisahan mode di User Story 2, tapi penting untuk dipastikan eksplisit karena taruhannya adalah kepercayaan pada laporan keuangan.

**Independent Test**: Sambil dalam mode LIVE dengan beberapa transaksi nyata tercatat, aktifkan mode DEMO, lakukan aksi apapun, lalu kembali ke mode LIVE dan verifikasi laporan (penjualan, stok, settlement) hanya menghitung transaksi mode LIVE.

**Acceptance Scenarios**:

1. **Given** ada transaksi penjualan di mode LIVE dan transaksi dummy di mode DEMO, **When** pemilik toko membuka laporan penjualan dalam mode LIVE, **Then** total dan daftar transaksi hanya mencerminkan transaksi mode LIVE.
2. **Given** stok produk sudah disesuaikan lewat pergerakan stok dummy di mode DEMO, **When** pengguna memeriksa stok dalam mode LIVE, **Then** angka stok yang tampil hanya mencerminkan pergerakan stok mode LIVE.

---

### Edge Cases

- Apa yang terjadi jika proses seeding dijalankan lebih dari sekali (mis. ulang instalasi atau re-seed)? Sistem harus tidak menduplikasi data dummy yang sama.
- Bagaimana sistem menangani data yang sudah ada di database sebelum fitur mode DEMO/LIVE ini dipasang (mis. dari instalasi lama)? Lihat FR-014 dan Assumptions.
- Apa yang terjadi jika pengguna mencoba berpindah mode saat ada sesi kasir yang sedang terbuka (cash session aktif) dengan transaksi belum ditutup?
- Bagaimana tampilan modul yang menampilkan data gabungan lintas waktu (mis. laporan dengan rentang tanggal yang mencakup periode saat mode berbeda pernah aktif)?
- Apa yang terjadi pada nomor urut/kode yang deterministik (mis. kode SKU produk, nomor pre-order) antara data DEMO dan data LIVE — apakah keduanya berbagi ruang penomoran yang sama atau terpisah?

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Sistem MUST menyediakan satu proses seeding yang mengisi data contoh (dummy) untuk sebuah toko bernama "Demo Sakana Fridge", mencakup: 1 event aktif, 3 artist, 3 produk per artist (total 9 produk) masing-masing dengan 3 varian dan stok awal terisi, 3 kategori merchandise, riwayat penjualan dummy, 3 customer, 3 vendor toko online, 3 vendor toko offline, bahan baku bertema merchandise anime & game yang terhubung ke harga masing-masing vendor, dan data pre-order dummy.
- **FR-002**: Proses seeding MUST menghasilkan data yang lolos semua aturan bisnis dan invarian yang sudah berlaku di sistem (mis. pergerakan stok yang valid, harga dan pembayaran yang konsisten, keterhubungan artist–produk–kategori yang benar) — data dummy tidak boleh menjadi kasus khusus yang melanggar validasi normal.
- **FR-003**: Proses seeding MUST aman dijalankan berulang kali tanpa menghasilkan data duplikat atau merusak data yang sudah ada.
- **FR-004**: Sistem MUST menyediakan cara bagi pengguna berwenang untuk memilih mode operasi sistem: **DEMO** atau **LIVE**.
- **FR-005**: Sistem MUST menampilkan status mode yang sedang aktif (DEMO atau LIVE) secara jelas dan selalu terlihat selama pengguna menggunakan sistem.
- **FR-006**: Ketika mode **DEMO** aktif, sistem MUST menampilkan data yang berasosiasi dengan mode DEMO (termasuk seluruh data hasil seeding pada FR-001) di semua modul yang relevan (Event, Artist, Produk, Kategori, Customer, Vendor, Bahan Baku, Penjualan, Pre-order, Laporan, Stok).
- **FR-007**: Ketika mode **LIVE** aktif, sistem MUST menyembunyikan seluruh data yang berasosiasi dengan mode DEMO, dan hanya menampilkan data yang dibuat/berasosiasi dengan mode LIVE.
- **FR-008**: Setiap data baru yang dibuat pengguna (mis. produk baru, transaksi penjualan, customer, dsb.) MUST tercatat sebagai berasosiasi dengan mode yang aktif pada saat data tersebut dibuat, sehingga kemunculannya di kemudian hari konsisten dengan mode tersebut.
- **FR-009**: Perpindahan antar mode MUST bersifat non-destruktif — berpindah mode tidak boleh menghapus atau mengubah data milik mode manapun; data hanya disembunyikan/ditampilkan sesuai mode aktif.
- **FR-010**: Laporan dan ringkasan bisnis (penjualan, stok, settlement artist, dsb.) MUST hanya menghitung/menampilkan data dari mode yang sedang aktif — tidak pernah mencampur angka dari kedua mode.
- **FR-011**: Sistem MUST mencatat pada activity log setiap kali mode DEMO/LIVE diubah, termasuk siapa yang mengubah dan kapan.

- **FR-012**: Cakupan label mode DEMO/LIVE MUST mencakup seluruh data bisnis dan transaksi: event, artist, kategori, produk & varian, stok/pergerakan stok, customer, vendor, bahan baku (material & harga vendor), transaksi penjualan, pre-order, dan sesi kasir. Data administratif (akun pengguna, role/hak akses, pengaturan toko) tidak diberi label mode dan selalu terlihat apa adanya di kedua mode (lihat Assumptions).
- **FR-013**: Hanya role **owner** dan **admin** yang berwenang mengubah mode DEMO/LIVE, konsisten dengan pola penggerbangan pengaturan sensitif lain di sistem ini (mis. lisensi Pro/Master). Role cashier dan inventory dapat melihat status mode yang aktif tetapi tidak dapat mengubahnya.
- **FR-014**: Mode **DEMO** MUST berfungsi sebagai sandbox interaktif penuh — pengguna dapat membuat, mengubah, atau menghapus data apapun selagi mode DEMO aktif (mis. transaksi penjualan latihan, produk baru, customer baru) sama seperti mode LIVE, dan data tersebut otomatis berlabel DEMO mengikuti FR-008, tidak pernah tercampur ke data LIVE.

### Key Entities *(include if feature involves data)*

- **Mode Sistem (DEMO/LIVE)**: Representasi status operasi sistem saat ini, disimpan di level toko/instalasi (bukan per pengguna, karena satu instalasi = satu toko). Menentukan label mana yang sedang "aktif" untuk keperluan penyaringan tampilan.
- **Label Mode pada Data**: Atribut yang melekat pada data bisnis (event, artist, produk, varian, customer, vendor, bahan baku, transaksi penjualan, pre-order, dsb.) yang menandai apakah data tersebut adalah data DEMO (contoh/dummy) atau data LIVE (nyata), ditentukan oleh mode yang aktif saat data dibuat.
- **Event Dummy**: Event contoh yang diaktifkan sebagai bagian dari data seed, tempat seluruh artist/produk/penjualan dummy bernaung.
- **Artist Dummy (3)**: Tiga artist contoh, masing-masing memiliki 3 produk.
- **Produk & Varian Dummy**: 9 produk contoh (3 per artist), masing-masing dengan 3 varian dan stok awal.
- **Kategori Merchandise Dummy (3)**: Tiga kategori contoh untuk mengelompokkan produk.
- **Customer Dummy (3)**: Tiga data pelanggan contoh.
- **Vendor Dummy (6)**: Tiga vendor bertipe toko online, tiga bertipe toko offline.
- **Bahan Baku Dummy**: Bahan baku bertema merchandise anime & game, masing-masing memiliki harga dari satu atau lebih vendor dummy di atas.
- **Transaksi Penjualan Dummy**: Riwayat penjualan contoh yang melibatkan produk, customer, dan metode pembayaran.
- **Pre-order Dummy**: Data pre-order contoh dengan status dan histori pembayaran yang valid.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Setelah seeding dijalankan sekali, seluruh 9 kategori data yang diminta (event, artist, produk+varian+stok, kategori, penjualan, customer, vendor online, vendor offline, bahan baku, pre-order) terisi lengkap dan dapat diverifikasi tanpa input manual tambahan.
- **SC-002**: Pengguna baru dapat mengenali mode sistem yang sedang aktif dalam waktu kurang dari 5 detik setelah membuka aplikasi, tanpa perlu membuka menu pengaturan.
- **SC-003**: 100% data yang dibuat selama mode LIVE aktif tidak pernah muncul saat mode DEMO aktif, dan sebaliknya — diverifikasi lewat pengujian di seluruh modul yang menampilkan data (Event, Artist, Produk, Kategori, Customer, Vendor, Bahan Baku, Penjualan, Pre-order, Laporan).
- **SC-004**: Berpindah mode tidak pernah menyebabkan kehilangan data — 100% data yang ada sebelum perpindahan tetap dapat diakses kembali saat mode aslinya diaktifkan lagi.
- **SC-005**: Angka pada laporan keuangan (penjualan, settlement artist) dalam mode LIVE tidak berubah nilainya akibat aktivitas apapun yang terjadi di mode DEMO.

## Follow-up Update (2026-09-03) — Store/User/Role Mode Separation & Sales Lookup

Requested after initial delivery: "bedakan nama toko, user dan role untuk
setiap mode" (differentiate store name, user, and role per mode) plus
Sales-screen lookup improvements. Folded into this same spec rather than a
separate one, since it directly extends/patches the DEMO/LIVE mechanism
above.

### User Story 5 - Nama toko tidak lagi tertimpa antar mode (Priority: P1)

Pemilik toko sudah mengisi nama toko sungguhan di mode LIVE. Menjalankan
seeder data contoh atau mengubah nama toko selagi mode DEMO aktif MUST NOT
mengubah nilai LIVE, dan sebaliknya.

**Why this priority**: Bug nyata dari implementasi awal — seeder menulis
satu baris `store_name` yang sama untuk kedua mode, sehingga menjalankan
seeder mengganti nama toko sungguhan pemilik menjadi "Demo Sakana Fridge"
tanpa disadari.

**Independent Test**: Set nama toko berbeda di masing-masing mode lewat
Settings, berpindah mode berkali-kali, pastikan tiap mode selalu
menampilkan nama toko miliknya sendiri, termasuk di struk transaksi.

**Acceptance Scenarios**:

1. **Given** nama toko LIVE adalah "Toko Asli", **When** menjalankan seeder
   data contoh (mode DEMO), **Then** nama toko LIVE tetap "Toko Asli".
2. **Given** sedang dalam mode DEMO, **When** pengguna mengubah nama toko
   lewat Settings, **Then** hanya nama toko DEMO yang berubah.
3. **Given** transaksi dibuat di mode LIVE, **When** struknya ditampilkan,
   **Then** struk menampilkan nama toko LIVE.

### User Story 6 - Daftar user tampil sesuai mode aktif (Priority: P3)

Owner/admin ingin daftar akun pengguna (Users) yang mereka lihat
mencerminkan mode yang sedang aktif, tanpa pernah mengganggu kemampuan
siapa pun untuk tetap login terlepas dari mode mana yang aktif.

**Independent Test**: Buat user baru selagi DEMO aktif, berpindah ke LIVE,
pastikan user itu tidak muncul di daftar Users LIVE — dan pastikan sesi
login siapa pun (termasuk yang sedang menguji ini) tidak pernah terputus
oleh perpindahan mode.

**Acceptance Scenarios**:

1. **Given** owner sedang login, **When** mode sistem berpindah, **Then**
   sesi login owner tidak pernah terputus.
2. **Given** akun user baru dibuat selagi DEMO aktif, **When** melihat
   daftar Users di LIVE, **Then** akun itu tidak muncul di daftar itu.
3. **Given** lima akun dasar (owner/admin/kasir01/kasir02/inventory) yang
   sudah ada sebelum fitur ini, **When** dilihat di kedua mode, **Then**
   akun-akun itu tetap tampil sebagai akun LIVE.

### User Story 7 - Menelusuri detail transaksi penjualan (Priority: P1)

Kasir/owner di layar Sales ingin cepat menemukan satu transaksi tertentu
— lewat nomor transaksi, nama customer, atau nama artist — tanpa
menggulir seluruh daftar secara manual, dan melihat nama artist per baris
item baik di detail transaksi maupun di struk.

**Independent Test**: Ketik sebagian nomor transaksi, nama customer, atau
nama artist secara terpisah di layar Sales; pastikan daftar tersaring
dengan benar di tiap kasus, dan detail/struk transaksi menampilkan nama
artist per baris item.

**Acceptance Scenarios**:

1. **Given** beberapa transaksi ada di layar Sales, **When** mengetik
   nomor transaksi (sebagian), **Then** daftar tersaring ke yang cocok.
2. **Given** transaksi dari customer berbeda, **When** mengetik nama
   customer (sebagian), **Then** daftar tersaring ke customer yang cocok.
3. **Given** satu transaksi berisi produk dari artist tertentu, **When**
   mengetik nama artist tersebut, **Then** transaksi itu muncul di hasil.
4. **Given** transaksi berisi barang dari 2 artist berbeda, **When**
   melihat detail transaksi atau struknya, **Then** tiap baris item
   menampilkan nama artist yang benar.

### Additional Functional Requirements

- **FR-015**: Sistem MUST menyimpan nama toko terpisah untuk mode DEMO dan
  LIVE; mengubah satu MUST NOT mengubah yang lain.
- **FR-016**: Layar Settings MUST menampilkan/menyimpan nama toko milik
  mode yang sedang aktif. Struk MUST menampilkan nama toko sesuai mode
  tempat transaksinya dibuat. Seeder data contoh (003) MUST menulis nama
  tokonya ke penyimpanan DEMO saja.
- **FR-017**: Sistem MUST menandai setiap akun user baru dengan mode aktif
  saat dibuat; lima akun dasar yang sudah ada sebelum fitur ini
  (owner/admin/kasir01/kasir02/inventory) tetap ditandai LIVE. Daftar
  Users MUST hanya menampilkan akun sesuai mode aktif. Autentikasi/sesi
  login MUST NOT terpengaruh oleh mode aktif maupun perpindahannya — lihat
  Assumptions untuk penafsiran cakupan "role".
- **FR-018**: Layar Sales MUST menyediakan pencarian/penyaringan transaksi
  berdasarkan (a) nomor transaksi, (b) nama customer, (c) nama artist
  pemilik salah satu item transaksi — masing-masing independen.
- **FR-019**: Detail transaksi dan struk transaksi MUST menampilkan nama
  artist untuk setiap baris item di dalamnya.

### Additional Success Criteria

- **SC-006**: Menjalankan seeder data contoh tidak pernah mengubah nama
  toko LIVE yang sudah diisi sebelumnya.
- **SC-007**: Pengguna dapat menemukan satu transaksi tertentu di layar
  Sales dalam waktu kurang dari 10 detik memakai pencarian nomor
  transaksi/nama customer/nama artist.
- **SC-008**: Berpindah mode DEMO/LIVE tidak pernah memutus sesi login
  siapa pun, diverifikasi lewat pengujian eksplisit.

### Additional Assumptions

- "Role" pada permintaan ditafsirkan sebagai: definisi peran
  (Owner/Admin/Kasir/Inventory, menu_keys) tetap infrastruktur BERSAMA
  lintas mode (bukan didefinisikan ulang per mode) — yang benar-benar
  dipisah per mode adalah AKUN USER-nya (FR-017), bukan struktur izinnya.
- Pencarian transaksi (FR-018) beroperasi atas data yang sudah diambil
  sesuai filter event/tanggal yang berlaku di layar Sales, bukan pencarian
  bebas lintas seluruh riwayat tanpa batas.
- Nama artist di struk (FR-019) muncul per baris item (satu transaksi bisa
  berisi barang dari beberapa artist), bukan satu nama tunggal di kop
  struk.

## Follow-up Update 2 (2026-09-03) — Demo Users, Clickable Sales Lookup, Real Receipt Footer

Requested: (1) seed dummy user accounts linked to roles for DEMO mode, (2)
make the Sales screen's transaction number / customer name / artist name
directly clickable to reveal detail (rather than requiring the separate
search box from the first follow-up), (3) the receipt's footer contact
block currently shows placeholder-looking values ("Budi Santoso /
0812-3456-7890 · toko@contoh.com") that read as fake — it MUST show the
real data for that specific transaction instead.

### Additional Functional Requirements

- **FR-020**: The demo seeder MUST create at least 2 sample user accounts
  tagged to DEMO mode, each linked to one of the existing shared roles
  (e.g. a demo Kasir, a demo Admin) — demonstrating FR-017's per-mode user
  list with real, usable demo logins (documented credentials, same
  convention as the base seeded accounts).
- **FR-021**: Clicking a transaction's number in the Sales list MUST open
  that transaction's detail (the existing receipt view satisfies this —
  no separate screen required).
- **FR-022**: Clicking a customer's name in the Sales list MUST show that
  customer's detail (name, phone, email) without navigating away from the
  Sales screen.
- **FR-023**: Clicking an artist's name in the Sales list MUST narrow the
  transaction list to that artist (reusing the existing search/filter
  behavior from Follow-up Update 1 is sufficient — a click is a shortcut
  for typing the name).
- **FR-024**: The receipt's footer contact block MUST show the actual
  customer of that transaction (name + phone/email) when one is attached,
  and MUST show nothing in that block for a walk-in (no customer) order —
  replacing the previous behavior of always showing the store's generic
  contact-person setting, which read as fake/placeholder data on a
  customer-facing document.

### Additional Assumptions

- "Link role untuk mode demo" (FR-020) reuses the EXISTING shared Role
  rows (Owner/Admin/Kasir/Inventory) — consistent with Follow-up Update
  1's assumption that role definitions are not duplicated per mode; only
  the demo *user accounts* are new and mode-tagged.
- FR-022's customer detail is a lightweight inline reveal (e.g. a small
  popover/modal) using data already available from the Sales report
  response — not a new full customer-management screen (that already
  exists separately as the Customers page).

## Assumptions

- Seeding data dummy dijalankan sebagai proses administratif satu kali (mis. skrip/perintah) yang dijalankan oleh pemilik/pengelola instalasi, bukan tindakan yang dipicu otomatis dari antarmuka pengguna setiap saat.
- Data yang sudah ada di database sebelum fitur mode DEMO/LIVE ini dipasang (instalasi lama yang di-upgrade) diperlakukan sebagai data mode **LIVE**, agar riwayat transaksi nyata yang sudah ada tidak pernah tersembunyi atau dianggap dummy.
- Karena BoothPOS adalah instalasi satu-toko-satu-mesin tanpa multi-tenancy (lihat konteks proyek), mode DEMO/LIVE berlaku global untuk seluruh instalasi tersebut, bukan per pengguna/kasir individual — semua pengguna yang login melihat mode yang sama pada satu waktu.
- Data konfigurasi/administratif seperti akun pengguna, role & hak akses, serta pengaturan toko (nama toko, dsb.) tidak ikut berubah/disembunyikan oleh mode DEMO/LIVE — hanya data bisnis/operasional yang terpengaruh.
- Kuantitas data dummy mengikuti persis yang diminta pengguna (3 artist, 3 produk/artist, 3 varian/produk, 3 kategori, 3 customer, 3+3 vendor); jumlah transaksi penjualan dan pre-order dummy dibuat secukupnya untuk mendemonstrasikan alur kerja utama (tidak dispesifikasikan jumlah persis oleh pengguna).
- Bahasa antarmuka dan seluruh data dummy (nama produk, deskripsi, dsb.) mengikuti konvensi Bahasa Indonesia yang sudah berlaku di codebase ini.
