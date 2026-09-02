# Feature Specification: Pengaturan Pengguna dan Toko

**Feature Branch**: `001-user-store-settings`

**Created**: 2026-09-02

**Status**: Draft

**Input**: User description: "Pada pengaturan, tambahkan pengaturan untuk Pengguna dan Toko. Lengkap dengan export dan import data. Pengguna bisa mengatur akses untuk tiap menu, ada foto, ada last access, bisa search dan filter, crud lengkap. untuk data toko, ada nama alamat lengkap, logo, kontak person, telepon, email"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Kelola akun pengguna (Priority: P1)

Seorang owner/admin toko perlu menambah, mengubah, menonaktifkan, dan meninjau
akun staf (kasir, inventory, admin lain) langsung dari layar Pengaturan —
tanpa perlu mengakses database atau meminta bantuan teknis. Setiap akun
menampilkan foto profil (jika ada) dan kapan terakhir kali pengguna tersebut
login, sehingga owner bisa cepat melihat siapa yang aktif memakai sistem.

**Why this priority**: Ini mengisi kebutuhan yang sudah lama tercatat namun
belum pernah dibangun — saat ini akun staf baru hanya bisa dibuat lewat
seeder/database langsung, bukan lewat aplikasi. Tanpa ini, pemilik toko tidak
bisa mandiri mengelola stafnya sendiri.

**Independent Test**: Dapat diuji sepenuhnya dengan login sebagai owner,
membuat satu akun kasir baru lewat layar Pengaturan, lalu memverifikasi akun
itu bisa langsung dipakai untuk login — tanpa langkah manual di luar
aplikasi.

**Acceptance Scenarios**:

1. **Given** owner sedang berada di layar Pengaturan bagian Pengguna, **When**
   ia mengisi form akun baru (nama, username, password, peran, foto opsional)
   dan menyimpan, **Then** akun baru tersebut langsung muncul di daftar
   pengguna dan bisa dipakai untuk login.
2. **Given** ada lebih dari 50 akun pengguna terdaftar, **When** owner
   mengetik sebagian nama/username di kolom pencarian atau memilih filter
   peran/status aktif, **Then** daftar hanya menampilkan akun yang cocok.
3. **Given** owner sedang melihat detail akunnya sendiri yang sedang login,
   **When** ia mencoba menonaktifkan atau menghapus akun tersebut, **Then**
   sistem menolak aksi itu dan menjelaskan alasannya.
4. **Given** seorang pengguna berhasil login, **When** owner membuka kembali
   daftar pengguna, **Then** kolom "terakhir akses" pada baris pengguna itu
   menunjukkan waktu login yang baru saja terjadi.

---

### User Story 2 - Kelola peran dan akses menu (Priority: P1)

Seorang owner perlu membuat peran (role) baru dan menentukan menu/fitur apa
saja yang boleh diakses oleh peran tersebut — tidak terpatok pada 4 peran
tetap (owner/admin/kasir/inventory) yang berlaku hari ini — lalu menetapkan
peran itu ke akun pengguna tertentu.

**Why this priority**: Fitur "atur akses tiap menu" pada User Story 1 tidak
bisa berfungsi tanpa adanya peran yang benar-benar bisa dikonfigurasi — ini
adalah fondasi yang membuat penetapan peran di User Story 1 punya arti,
bukan sekadar memilih dari daftar tetap.

**Independent Test**: Dapat diuji sepenuhnya dengan login sebagai owner,
membuat satu peran baru dengan akses hanya ke sebagian menu (mis. hanya
Kasir dan Stok), menetapkan peran itu ke satu akun pengguna, lalu
memverifikasi pengguna tersebut login dan hanya melihat menu yang diizinkan.

**Acceptance Scenarios**:

1. **Given** owner berada di layar pengaturan Peran, **When** ia membuat
   peran baru dan memilih menu-menu tertentu yang boleh diakses, lalu
   menyimpan, **Then** peran baru tersebut tersedia untuk dipilih saat
   membuat/mengubah akun pengguna.
2. **Given** sebuah peran baru hanya diberi akses ke sebagian menu, **When**
   seorang pengguna dengan peran tersebut login, **Then** ia hanya melihat
   dan bisa membuka menu yang diizinkan untuk perannya.
3. **Given** sebuah peran masih dipakai oleh satu atau lebih akun pengguna
   aktif, **When** owner mencoba menghapus peran tersebut, **Then** sistem
   menolak penghapusan dan menjelaskan berapa pengguna yang masih memakainya.
4. **Given** hanya tersisa satu peran yang punya akses mengelola pengguna &
   peran, **When** owner mencoba menghapus atau mencabut akses menu
   pengelolaan itu dari peran tersebut, **Then** sistem menolak perubahan
   itu untuk mencegah toko kehilangan kemampuan mengelola aksesnya sendiri.

---

### User Story 3 - Lengkapi profil toko (Priority: P2)

Seorang owner perlu melengkapi identitas resmi tokonya — nama, alamat
lengkap, logo, nama kontak person, nomor telepon, dan email — satu kali di
Pengaturan, supaya identitas ini konsisten tercetak di struk dan muncul di
berkas-berkas yang diekspor dari sistem tanpa perlu diisi ulang setiap kali.

**Why this priority**: Saat ini hanya nama toko dan satu nomor kontak yang
bisa diisi; kebutuhan menampilkan identitas toko yang lengkap (untuk
keperluan resmi/kepercayaan pembeli) belum terpenuhi.

**Independent Test**: Dapat diuji sepenuhnya dengan login sebagai owner,
mengisi seluruh field profil toko termasuk mengunggah logo, menyimpan, lalu
memverifikasi data tersebut tersimpan dan tampil kembali saat halaman
dibuka ulang.

**Acceptance Scenarios**:

1. **Given** owner berada di layar Pengaturan bagian Toko, **When** ia
   mengisi nama, alamat lengkap, nama kontak person, telepon, email, dan
   mengunggah logo, lalu menyimpan, **Then** seluruh data tersimpan dan
   tampil kembali saat halaman dimuat ulang.
2. **Given** owner mengisi email dengan format yang tidak valid, **When** ia
   mencoba menyimpan, **Then** sistem menolak penyimpanan dan menunjukkan
   field mana yang bermasalah.
3. **Given** profil toko sudah lengkap terisi, **When** sebuah struk atau
   laporan diekspor, **Then** identitas toko (nama, logo, kontak) yang
   tercantum di dalamnya sesuai dengan yang dikonfigurasi di Pengaturan.

---

### User Story 4 - Ekspor dan impor massal data pengguna (Priority: P3)

Seorang owner yang perlu menyiapkan banyak akun staf sekaligus sebelum
sebuah event besar ingin mengekspor daftar pengguna yang ada sebagai berkas,
menyiapkan akun-akun baru di berkas yang sama, lalu mengimpornya kembali
sekaligus — tanpa mengisi form satu per satu untuk setiap staf.

**Why this priority**: Ini mempercepat provisioning staf dalam skala besar,
tapi bukan kebutuhan sehari-hari — toko yang stafnya sedikit tetap bisa
sepenuhnya memakai fitur ini lewat CRUD manual di User Story 1.

**Independent Test**: Dapat diuji sepenuhnya dengan mengekspor daftar
pengguna yang ada, menambahkan beberapa baris pengguna baru pada berkas
hasil ekspor, mengimpornya kembali, lalu memverifikasi akun-akun baru itu
muncul dan bisa dipakai login.

**Acceptance Scenarios**:

1. **Given** ada beberapa akun pengguna terdaftar, **When** owner mengekspor
   data pengguna, **Then** ia menerima satu berkas berisi seluruh akun
   beserta atributnya (kecuali kata sandi).
2. **Given** owner mengunggah berkas impor yang berisi kombinasi akun baru
   dan akun yang sudah ada (dikenali lewat username), **When** impor
   dijalankan, **Then** akun baru dibuat dan akun yang sudah ada diperbarui,
   tanpa duplikasi.
3. **Given** berkas impor memiliki satu baris dengan data tidak valid (mis.
   peran yang tidak dikenal), **When** impor dijalankan, **Then** seluruh
   proses impor dibatalkan dan sistem menunjukkan baris mana yang bermasalah
   serta alasannya — tidak ada akun yang berubah sebagian.

### Edge Cases

- Apa yang terjadi jika owner mencoba menonaktifkan/menghapus akunnya sendiri
  yang sedang login? Sistem harus menolak (lihat Acceptance Scenario 3, User
  Story 1) — mencegah toko kehilangan akses admin sepenuhnya.
- Apa yang terjadi jika owner yang login adalah satu-satunya akun berperan
  owner, dan ia mencoba mengubah perannya sendiri menjadi peran lain? Sistem
  harus mencegah ini juga, dengan alasan yang sama (mencegah toko kehilangan
  akses admin tertinggi).
- Apa yang terjadi jika foto profil atau logo yang diunggah bukan berkas
  gambar, atau ukurannya terlalu besar? Sistem menolak unggahan dengan pesan
  yang jelas, tanpa merusak data lain yang sedang disimpan bersamaan.
- Apa yang terjadi jika pencarian/filter pengguna tidak menemukan hasil?
  Sistem menampilkan status kosong yang jelas, bukan tabel kosong tanpa
  penjelasan.
- Apa yang terjadi jika berkas impor mereferensikan username yang sama pada
  dua baris berbeda dalam satu berkas yang sama? Sistem menolak seluruh
  impor dan menjelaskan baris mana yang bentrok.
- Apa yang terjadi jika owner mencoba menghapus sebuah peran yang masih
  dipakai oleh satu atau lebih pengguna aktif? Sistem menolak (lihat
  FR-014) dan menjelaskan berapa pengguna yang masih memakai peran itu.
- Apa yang terjadi jika owner mengubah/menghapus peran sedemikian rupa
  sehingga tidak ada satu pun peran tersisa yang bisa mengelola pengguna
  dan peran? Sistem menolak perubahan tersebut (lihat FR-013) — toko tidak
  boleh pernah kehilangan kemampuan mengelola aksesnya sendiri.
- Apa yang terjadi pada 4 peran yang berlaku hari ini (owner, admin, kasir,
  inventory) saat fitur ini pertama kali aktif? Peran-peran itu menjadi
  peran bawaan yang sudah dikonfigurasi meniru akses yang berlaku saat ini
  (lihat Assumptions) — perilaku sistem tidak berubah sampai owner secara
  aktif mengubah konfigurasinya.

## Requirements *(mandatory)*

### Functional Requirements

**Pengguna (User Management)**

- **FR-001**: Sistem MUST memungkinkan owner/admin membuat, melihat,
  mengubah, dan menonaktifkan akun pengguna (CRUD lengkap).
- **FR-002**: Sistem MUST memungkinkan pengelolaan foto profil untuk setiap
  akun pengguna.
- **FR-003**: Sistem MUST mencatat dan menampilkan waktu login terakhir
  ("terakhir akses") setiap akun pengguna.
- **FR-004**: Sistem MUST memungkinkan pencarian daftar pengguna berdasarkan
  nama atau username.
- **FR-005**: Sistem MUST memungkinkan penyaringan (filter) daftar pengguna
  berdasarkan peran dan status aktif/nonaktif.
- **FR-006**: Sistem MUST mencegah pengguna menonaktifkan, menghapus, atau
  mengubah perannya sendiri yang sedang login (mencegah kehilangan akses
  admin toko sepenuhnya).
- **FR-007**: Sistem MUST memungkinkan ekspor data pengguna (kecuali kata
  sandi) sebagai satu berkas yang dapat diunduh.
- **FR-008**: Sistem MUST memungkinkan impor data pengguna dari berkas yang
  diunggah — baris yang cocok dengan akun yang sudah ada (berdasarkan
  username) diperbarui, baris baru membuat akun baru.
- **FR-009**: Sistem MUST memvalidasi seluruh data impor sebelum menerapkan
  perubahan apa pun; jika ada baris tidak valid, seluruh impor dibatalkan
  dan sistem menjelaskan baris serta alasannya (tidak ada perubahan
  sebagian).
- **FR-010**: Sistem MUST memungkinkan owner mendefinisikan dan mengubah
  peran (role) secara bebas, termasuk membuat peran baru dan mengganti nama
  peran yang sudah ada — bukan terbatas pada 4 peran tetap yang berlaku
  saat ini.
- **FR-011**: Sistem MUST memungkinkan owner menentukan menu/fitur apa saja
  yang dapat diakses oleh tiap peran (izin per menu yang bisa dikonfigurasi
  bebas, bukan satu paket akses yang sudah ditentukan dari awal).
- **FR-012**: Sistem MUST memungkinkan owner menetapkan satu peran ke
  setiap akun pengguna.
- **FR-013**: Sistem MUST mencegah perubahan atau penghapusan peran yang
  mengakibatkan tidak ada satu pun peran tersisa yang memiliki akses untuk
  mengelola pengguna dan peran itu sendiri (mencegah toko kehilangan
  kemampuan mengelola aksesnya sendiri).
- **FR-014**: Sistem MUST mencegah penghapusan sebuah peran yang masih
  dipakai oleh satu atau lebih akun pengguna aktif.

**Toko (Store Profile)**

- **FR-015**: Sistem MUST memungkinkan owner/admin mengonfigurasi nama
  toko, alamat lengkap, nama kontak person, nomor telepon, dan alamat
  email toko.
- **FR-016**: Sistem MUST memungkinkan pengunggahan logo toko.
- **FR-017**: Profil toko MUST disajikan sebagai satu profil per instalasi
  (bukan daftar banyak toko), konsisten dengan sistem ini yang memang
  dipasang satu per toko/lokasi.
- **FR-018**: Sistem MUST memvalidasi format email toko dan format nomor
  telepon sebelum data disimpan.

### Key Entities

- **Akun Pengguna**: Merepresentasikan seseorang yang dapat login ke
  sistem. Atribut kunci: nama, username, kata sandi, peran (mengacu ke satu
  Peran yang dikonfigurasi), status aktif/nonaktif, foto profil, waktu
  login terakhir.
- **Peran (Role)**: Merepresentasikan sekumpulan hak akses yang diberi nama
  dan dapat dikonfigurasi bebas oleh owner. Atribut kunci: nama peran,
  daftar menu/fitur yang dapat diakses oleh peran tersebut.
- **Profil Toko**: Merepresentasikan identitas resmi toko sebagaimana
  tercetak di struk dan muncul pada berkas yang diekspor. Atribut kunci:
  nama toko, alamat lengkap, logo, nama kontak person, telepon, email.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Owner/admin dapat membuat akun staf baru yang langsung bisa
  dipakai login dalam waktu kurang dari 2 menit, tanpa memerlukan akses
  database/teknis (dibandingkan proses saat ini yang mewajibkan akses
  langsung ke database).
- **SC-002**: Owner/admin dapat menemukan satu akun tertentu dari daftar
  berisi 50+ akun dalam waktu kurang dari 10 detik lewat pencarian atau
  filter.
- **SC-003**: Owner/admin dapat menyiapkan 20 akun staf sekaligus lewat satu
  kali impor massal dalam waktu kurang dari 5 menit, dibandingkan membuat
  satu per satu secara manual.
- **SC-004**: 100% struk dan laporan yang diekspor menampilkan nama, logo,
  dan kontak toko sesuai yang dikonfigurasi, tanpa perlu diisi ulang per
  dokumen.
- **SC-005**: Tidak ada satu pun pengguna yang bisa mengunci dirinya sendiri
  keluar dari sistem dengan menonaktifkan atau mengubah peran akunnya
  sendiri yang sedang login.
- **SC-006**: Owner dapat membuat sebuah peran baru dengan kombinasi akses
  menu yang berbeda dari 4 peran bawaan, menetapkannya ke seorang pengguna,
  dan memverifikasi pengguna itu hanya bisa mengakses menu yang diizinkan —
  seluruhnya tanpa bantuan teknis/developer.

## Assumptions

- Ekspor/impor data pengguna mengikuti pola berkas massal yang sudah
  dipakai untuk data induk (master data) lain di sistem ini — satu berkas,
  divalidasi penuh sebelum ada perubahan diterapkan, dicocokkan ke akun
  yang sudah ada lewat username.
- Kata sandi akun baru diatur/direset langsung oleh owner/admin yang
  mengelola akun tersebut (bukan lewat alur undangan berbasis email),
  konsisten dengan sistem ini yang berjalan offline di satu mesin lokal
  tanpa kapasitas pengiriman email yang andal.
- Menonaktifkan/menghapus akun pengguna mengikuti pola soft-delete
  (nonaktifkan, bukan hapus permanen) yang sudah dipakai untuk data induk
  lain di sistem ini, supaya riwayat transaksi lama (mis. "dijual oleh
  siapa") tetap bisa dirujuk.
- Profil toko adalah satu data per instalasi; dukungan multi-lokasi/multi-
  toko di luar cakupan fitur ini.
- Hanya peran dengan akses pengelolaan pengguna & peran (setara owner/admin
  hari ini) yang dapat membuka Pengaturan Pengguna, Pengaturan Peran, dan
  Pengaturan Toko.
- 4 peran yang berlaku saat ini (owner, admin, kasir, inventory) menjadi
  peran bawaan yang sudah dikonfigurasi meniru akses yang berlaku hari ini
  ketika fitur ini pertama kali aktif, sehingga perilaku sistem tidak
  berubah sampai owner secara aktif mengubah konfigurasinya.
- Perubahan konfigurasi akses sebuah peran berlaku paling lambat pada login
  berikutnya pengguna yang memakai peran tersebut — tidak wajib berlaku
  seketika terhadap sesi yang sedang berjalan.
- Granularitas "menu/fitur" mengikuti struktur menu utama yang sudah ada di
  aplikasi (mis. Kasir, Produk, Stok, Laporan, Pengaturan, dst.) — izin per
  menu adalah dapat-diakses/tidak, bukan izin per-aksi yang lebih detail di
  dalam satu menu (mis. boleh lihat tapi tidak boleh hapus). Izin per-aksi
  di luar cakupan fitur ini kecuali dinyatakan lain di kemudian hari.
