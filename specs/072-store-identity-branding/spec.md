# Feature Specification: Identitas Toko di Judul Tab, Sidebar, Favicon, dan POS Selalu Terlihat

**Feature Branch**: `072-store-identity-branding`

**Created**: 2026-10-25

**Status**: Draft

**Input**: User description: "change `<title>BoothPOS</title>` to use value from store name combined with Application Name, change the sidebar brand (kotak 'B' + 'BoothPOS' + 'Master') to use store name and store logo. change the favicon to use store logo also. in POS, add scrollable to product list, so that the bottom information of the cart panel (customer, discount, subtotal, PPN, total, Pay) can be viewed with no scroll."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Sidebar menampilkan nama dan logo toko (Priority: P1)

Pemilik/admin sudah mengisi nama toko dan logo di Pengaturan > Identitas toko. Saat ini sidebar tetap menampilkan kotak huruf "B" dan nama aplikasi "BoothPOS", sehingga toko tidak terlihat sebagai miliknya sendiri. Sidebar harus menampilkan logo dan nama toko.

**Why this priority**: Ini perubahan yang paling terlihat setiap saat oleh semua peran, dan inti dari permintaan (identitas toko, bukan merek produk).

**Independent Test**: Isi nama toko dan unggah logo, buka aplikasi, lihat sudut kiri atas sidebar.

**Acceptance Scenarios**:

1. **Given** nama toko dan logo sudah diisi, **When** pengguna membuka aplikasi, **Then** sidebar menampilkan logo toko (menggantikan kotak huruf) dan nama toko (menggantikan nama aplikasi); tingkat lisensi ("Master") tetap tampil di bawah nama.
2. **Given** nama toko diisi tetapi logo belum diunggah, **When** sidebar tampil, **Then** kotak berisi huruf pertama nama toko ditampilkan sebagai pengganti logo.
3. **Given** nama toko dan logo belum diisi, **When** sidebar tampil, **Then** tampilan lama (huruf pertama + nama aplikasi) tetap dipakai.
4. **Given** sidebar dalam keadaan diciut, **When** tampil, **Then** hanya logo/kotak huruf yang terlihat, nama tetap tersembunyi seperti sekarang.
5. **Given** pemilik mengubah nama toko atau logo lalu menyimpan, **When** kembali ke halaman mana pun, **Then** sidebar langsung memakai nilai baru tanpa perlu keluar-masuk.

---

### User Story 2 - Judul tab browser memuat nama toko dan nama aplikasi (Priority: P1)

Judul tab browser kini selalu "BoothPOS". Judul harus menggabungkan nama toko dengan Nama Aplikasi (Pengaturan > Umum > Application name), sehingga kasir dengan banyak tab/jendela dapat membedakan toko.

**Why this priority**: Permintaan eksplisit; murah dan terlihat langsung.

**Independent Test**: Isi nama toko "Warkop WJM" dan nama aplikasi "Warkop WJM", muat ulang, baca judul tab.

**Acceptance Scenarios**:

1. **Given** nama toko dan nama aplikasi terisi, **When** halaman apa pun dibuka, **Then** judul tab berbentuk "{nama toko} – {nama aplikasi}".
2. **Given** nama toko kosong, **When** halaman dibuka, **Then** judul tab hanya nama aplikasi.
3. **Given** nama toko sama persis dengan nama aplikasi (mis. keduanya "Warkop WJM"), **When** halaman dibuka, **Then** judul tidak menggandakan nama (hanya muncul sekali).
4. **Given** pemilik menyimpan nama toko/aplikasi baru, **When** penyimpanan berhasil, **Then** judul tab ikut berubah tanpa muat ulang.
5. **Given** pengguna belum login, **When** halaman login tampil, **Then** judul tab tetap bermakna (nama aplikasi bawaan) dan tidak kosong atau "undefined".

---

### User Story 3 - Favicon memakai logo toko (Priority: P2)

Ikon tab browser memakai logo toko, bukan ikon bawaan.

**Why this priority**: Pelengkap identitas; tidak menghalangi pemakaian.

**Independent Test**: Unggah logo toko, muat ulang, lihat ikon di tab.

**Acceptance Scenarios**:

1. **Given** logo toko tersedia, **When** halaman dibuka, **Then** ikon tab menampilkan logo toko.
2. **Given** logo belum diunggah, **When** halaman dibuka, **Then** ikon bawaan lama dipakai.
3. **Given** logo diganti atau dihapus, **When** disimpan, **Then** ikon tab ikut berubah tanpa muat ulang (tanpa tertahan cache lama).

---

### User Story 4 - Daftar produk POS bisa digulir sehingga ringkasan keranjang selalu terlihat (Priority: P1)

Di layar POS, ringkasan bawah keranjang (pelanggan, diskon, subtotal, diskon, PPN, total, tombol Bayar, pesan sesi) kini bisa terpotong dan harus digulir untuk dilihat. Halaman POS harus pas satu layar: daftar produk menggulir sendiri, dan ringkasan bawah keranjang selalu terlihat penuh tanpa menggulir.

**Why this priority**: Total dan tombol Bayar adalah aksi kasir paling sering; tersembunyi memperlambat transaksi.

**Independent Test**: Buka POS pada layar laptop umum (tinggi ±768 px) dengan banyak produk; total dan tombol Bayar terlihat tanpa menggulir halaman atau panel.

**Acceptance Scenarios**:

1. **Given** katalog berisi puluhan produk, **When** kasir membuka POS, **Then** hanya daftar produk yang menggulir; halaman tidak menggulir dan seluruh ringkasan bawah keranjang terlihat.
2. **Given** keranjang berisi banyak baris, **When** baris bertambah, **Then** daftar baris keranjang menggulir sendiri sementara ringkasan bawah tetap terlihat.
3. **Given** kasir menggulir daftar produk, **When** menggulir ke bawah dan kembali, **Then** kotak pencarian/filter di atas daftar tetap terlihat dan keranjang tidak bergeser.
4. **Given** jendela dipersempit hingga tinggi kecil, **When** ruang tidak cukup, **Then** tombol Bayar dan Total tetap diprioritaskan terlihat.

---

### Edge Cases

- Nama toko sangat panjang: dipotong dengan elipsis di sidebar; judul tab tetap utuh (dipotong browser).
- Berkas logo gagal dimuat (tautan rusak): sidebar jatuh ke kotak huruf, favicon ke ikon bawaan; tidak ada ikon rusak.
- Mode DEMO/LIVE: nama toko berbeda per mode; judul dan sidebar mengikuti mode aktif dan berubah saat mode berganti.
- Nama toko berisi karakter khusus atau markup: tampil sebagai teks biasa, tidak ditafsirkan.
- Multi-outlet: identitas yang dipakai adalah identitas toko/organisasi di Pengaturan, bukan nama outlet terpilih.
- Layar sentuh/tablet pada POS: gulir daftar produk dengan sentuhan berfungsi dan tidak bentrok dengan gulir keranjang.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Sidebar MUST menampilkan nama toko sebagai judul merek menggantikan nama aplikasi, dan tingkat lisensi tetap di bawahnya.
- **FR-002**: Sidebar MUST menampilkan logo toko pada slot merek; bila tidak ada logo, MUST menampilkan kotak huruf pertama nama toko.
- **FR-003**: Bila nama toko kosong, sidebar MUST memakai perilaku lama (huruf pertama nama aplikasi dan nama aplikasi).
- **FR-004**: Judul tab MUST berbentuk "{nama toko} – {nama aplikasi}"; bila nama toko kosong hanya nama aplikasi; bila keduanya sama, hanya satu kali.
- **FR-005**: Favicon MUST memakai logo toko bila ada, selain itu ikon bawaan.
- **FR-006**: Perubahan nama toko, nama aplikasi, atau logo yang disimpan MUST langsung tercermin pada sidebar, judul tab, dan favicon tanpa muat ulang.
- **FR-007**: Judul tab dan favicon MUST bermakna sebelum login (halaman login) memakai nilai bawaan bila identitas toko belum dapat dibaca.
- **FR-008**: Layar POS MUST pas satu tinggi layar tanpa gulir halaman: daftar produk menggulir secara internal dan ringkasan bawah keranjang (pelanggan, diskon, subtotal, PPN, total, tombol Bayar, pesan status) MUST selalu terlihat penuh.
- **FR-009**: Daftar baris keranjang MUST menggulir sendiri bila panjang, tanpa mendorong ringkasan bawah keluar layar.
- **FR-010**: Teks nama toko/aplikasi MUST ditampilkan sebagai teks biasa (tidak menjalankan markup).
- **FR-011**: Nama aplikasi tetap dipakai pada teks "Powered by …" di dokumen; perubahan ini tidak mengubah dokumen.

### Key Entities

- **Identitas toko**: nama toko (berbeda per mode DEMO/LIVE) dan logo toko; sudah ada di Pengaturan > Identitas toko.
- **Nama aplikasi**: merek produk satu untuk seluruh instalasi; sudah ada di Pengaturan > Umum.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Setelah nama toko dan logo diisi, 100% halaman setelah login menampilkan logo dan nama toko di sidebar dan judul tab berisi nama toko.
- **SC-002**: Perubahan identitas tampak di sidebar, judul, dan ikon tab dalam 2 detik setelah menyimpan, tanpa muat ulang.
- **SC-003**: Pada layar tinggi 768 px dan lebar 1366 px, Total dan tombol Bayar POS terlihat tanpa menggulir pada 100% pembukaan POS, dengan katalog sebesar apa pun.
- **SC-004**: Kasir dapat mencapai produk mana pun di katalog 200 produk lewat gulir daftar tanpa kotak pencarian/filter hilang dari layar.
- **SC-005**: Tidak ada kasus judul kosong, "undefined", atau ikon rusak pada skenario nama/logo kosong atau gagal dimuat.

## Assumptions

- **ASSUMPTION**: Pemisah judul tab adalah " – " dengan urutan toko lebih dulu, mengikuti kata "store name combined with Application Name".
- **ASSUMPTION**: Sebelum login, identitas toko tidak terbaca (pengaturan butuh login); halaman login memakai nama aplikasi bawaan dan ikon bawaan. Menampilkan identitas toko di halaman login di luar cakupan.
- **ASSUMPTION**: "Bottom information" pada gambar ke-3 adalah ringkasan bawah panel keranjang POS yang kini terpotong; solusi adalah tata letak satu layar dengan daftar produk menggulir internal.
- **ASSUMPTION**: Logo toko yang sudah ada (unggahan di Identitas toko) dipakai apa adanya untuk favicon; tidak ada pembuatan ukuran ikon khusus.
- **ASSUMPTION**: Tingkat lisensi ("Master") tetap tampil di sidebar.
- Aplikasi Android POS dan dokumen/struk di luar cakupan.
