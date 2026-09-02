# Feature Specification: Ganti Bahasa Antarmuka (Indonesia/English)

**Feature Branch**: `002-language-toggle`

**Created**: 2026-09-02

**Status**: Draft

**Input**: User description: "sebgai user, saya mau mengganti bahasa sebelum dan sesudah login dari bahasa indonesia ke english, dan sebaliknya. bahasa yang dipilih berlaku untuk masing-masing user. default english."

**Klarifikasi (2026-09-02)**:
- Cakupan penerjemahan: **seluruh aplikasi**, penuh sejak rilis pertama —
  bukan bertahap per layar (Q1: A).
- Struk pelanggan (`GET /orders/{id}/receipt`): **selalu Bahasa Indonesia**,
  tidak ikut preferensi bahasa kasir yang login — ini dokumen yang dibaca
  pelanggan, bukan operator toko (Q2: B).
- **Layar login selalu Bahasa Indonesia, tidak memiliki kontrol ganti
  bahasa sama sekali.** Toggle bahasa hanya tersedia SETELAH login,
  berlaku sebagai preferensi tersimpan per akun (Q3: custom — ini
  menggantikan asumsi awal bahwa layar login juga bisa diganti
  bahasanya; User Story terkait di bawah sudah disesuaikan).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Preferensi bahasa tersimpan per pengguna (Priority: P1)

Setelah login, seorang pengguna bisa mengganti bahasa tampilan seluruh
aplikasi antara Bahasa Indonesia dan English, dan pilihan itu diingat
sebagai preferensi akunnya — sehingga setiap kali dia login lagi (dari
perangkat mana pun di toko yang sama), aplikasi langsung tampil dalam
bahasa pilihannya, tanpa perlu mengatur ulang tiap kali.

**Why this priority**: Ini inti permintaan fitur — "bahasa yang dipilih
berlaku untuk masing-masing user" — preferensi harus melekat ke akun,
bukan ke perangkat/browser, karena satu toko sering memakai perangkat
bersama untuk beberapa kasir bergantian shift.

**Independent Test**: Login sebagai pengguna A, ganti bahasa ke Bahasa
Indonesia, logout. Login sebagai pengguna B di perangkat yang sama,
konfirmasi tampilan pengguna B TIDAK ikut berubah (masih default
English). Login ulang sebagai pengguna A, konfirmasi aplikasi langsung
tampil Bahasa Indonesia tanpa perlu memilih ulang.

**Acceptance Scenarios**:

1. **Given** pengguna sudah login dan aplikasi tampil default English,
   **When** pengguna mengganti bahasa ke Bahasa Indonesia lewat kontrol
   ganti bahasa yang tersedia di mana pun dalam aplikasi, **Then** seluruh
   layar yang sedang dan akan dibuka berganti ke Bahasa Indonesia
   seketika, dan pilihan ini tersimpan ke akunnya.
2. **Given** pengguna A memilih Bahasa Indonesia dan pengguna B tidak
   pernah mengganti apa pun, **When** kedua pengguna login bergantian di
   perangkat yang sama, **Then** masing-masing melihat aplikasi dalam
   bahasa preferensinya sendiri (A: Indonesia, B: English default),
   independen satu sama lain.
3. **Given** pengguna sudah mengganti bahasa, **When** pengguna logout lalu
   login kembali di lain waktu, **Then** aplikasi tampil dalam bahasa
   pilihan terakhirnya, bukan kembali ke default.
4. **Given** pengguna sedang menjelajah aplikasi dalam Bahasa Indonesia,
   **When** sesi mereka berakhir (logout manual atau token kedaluwarsa)
   dan mereka dilempar ke layar login, **Then** layar login tampil dalam
   Bahasa Indonesia (bahasa tetap layar login), terlepas dari preferensi
   bahasa akun yang baru saja logout.

---

### User Story 2 - Pengguna baru default berbahasa English (Priority: P2)

Akun pengguna yang baru dibuat (lewat CRUD pengguna atau impor massal)
belum pernah memilih bahasa apa pun, sehingga aplikasi tampil dalam
English baginya sampai dia sendiri menggantinya setelah login.

**Why this priority**: Ini menetapkan perilaku default yang eksplisit
diminta ("default english"), memastikan tidak ada asumsi tersembunyi
lain (mis. ikut bahasa browser) yang bisa mengejutkan pengguna baru.

**Independent Test**: Buat pengguna baru lewat layar Kelola Pengguna
tanpa mengatur bahasa apa pun, login sebagai pengguna itu, konfirmasi
aplikasi (setelah login berhasil) tampil dalam English.

**Acceptance Scenarios**:

1. **Given** seorang admin membuat pengguna baru, **When** pengguna baru
   itu login untuk pertama kali, **Then** seluruh aplikasi tampil dalam
   English setelah login berhasil.
2. **Given** akun-akun yang sudah ada sebelum fitur ini dibangun (belum
   pernah memiliki preferensi bahasa tersimpan), **When** fitur ini
   dirilis dan pengguna itu login, **Then** aplikasi tampil dalam English
   baginya juga (perilaku default berlaku sama untuk akun lama maupun
   baru), sampai pengguna itu mengganti sendiri.

### Edge Cases

- Apa yang terjadi bila pengguna mengganti bahasa di tengah proses
  mengisi form panjang (mis. form Buat Produk)? Data yang sudah diisi
  di field tidak boleh hilang — hanya label/teks statis yang berganti
  bahasa, nilai input pengguna tetap.
- Apa yang terjadi pada teks yang berasal dari data, bukan dari kode UI
  (mis. nama produk "Kaos Polos", nama artist, catatan yang diketik
  pengguna sendiri)? Teks semacam ini TIDAK diterjemahkan — hanya label
  antarmuka (tombol, judul kolom, pesan sistem) yang mengikuti bahasa
  pilihan.
- Struk transaksi (dokumen yang dibaca pelanggan) TIDAK ikut berganti
  bahasa — selalu Bahasa Indonesia, berapa pun bahasa antarmuka kasir
  yang sedang login saat transaksi dibuat.
- Layar login TIDAK memiliki kontrol ganti bahasa dan selalu tampil
  Bahasa Indonesia, termasuk pesan galat login (username/password salah)
  — ini berlaku untuk semua orang, sebelum identitas akun diketahui.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Layar login MUST selalu tampil dalam Bahasa Indonesia dan
  MUST TIDAK menyediakan kontrol ganti bahasa apa pun — ini berlaku
  sama untuk semua orang, karena identitas akun (dan preferensi
  bahasanya) belum diketahui pada titik ini.
- **FR-002**: Setelah login berhasil, aplikasi MUST menampilkan seluruh
  antarmuka dalam bahasa yang tersimpan sebagai preferensi akun pengguna
  yang sedang login.
- **FR-003**: Sistem MUST menyediakan kontrol ganti bahasa yang dapat
  diakses dari mana pun dalam aplikasi setelah login (mis. header/navigasi
  global), tidak terbatas hanya di satu layar pengaturan.
- **FR-004**: Saat pengguna yang sudah login mengganti bahasa, sistem
  MUST menyimpan pilihan itu sebagai preferensi permanen akun tersebut,
  dan MUST menerapkannya ke seluruh layar aplikasi (yang sedang dan akan
  dibuka) seketika, tanpa perlu logout/login ulang.
- **FR-005**: Preferensi bahasa akun MUST melekat ke pengguna, bukan ke
  perangkat/browser — pengguna lain yang login di perangkat yang sama
  MUST melihat aplikasi dalam preferensi bahasanya sendiri, independen
  dari pengguna sebelumnya di perangkat itu.
- **FR-006**: Pengguna baru (dibuat lewat CRUD manual maupun impor massal)
  yang belum pernah mengatur preferensi bahasa MUST default ke English.
- **FR-007**: Akun yang sudah ada sebelum fitur ini dirilis dan belum
  pernah memiliki preferensi bahasa tersimpan MUST diperlakukan sama
  seperti pengguna baru — default English — sampai pengguna itu
  mengganti sendiri.
- **FR-008**: Cakupan penerjemahan MUST mencakup SELURUH layar aplikasi
  setelah login (dashboard, POS, master data, laporan, pengaturan, log
  aktivitas, dan seluruh layar lain yang ada) — bukan sebagian layar
  saja — sejak rilis pertama fitur ini.
- **FR-009**: Struk transaksi (`GET /orders/{id}/receipt` dan tampilan
  cetak/unduhnya) MUST selalu ditampilkan dalam Bahasa Indonesia,
  terlepas dari preferensi bahasa kasir yang sedang login — ini dokumen
  yang dibaca pelanggan, bukan bagian dari antarmuka operator yang
  di-toggle fitur ini.
- **FR-010**: Sistem MUST menampilkan pesan galat dari server (mis.
  validasi 422, konflik bisnis 409) yang terjadi SETELAH login sesuai
  preferensi bahasa pengguna yang sedang login. Pesan galat yang terjadi
  di layar login (SEBELUM login, mis. username/password salah) selalu
  dalam Bahasa Indonesia, mengikuti FR-001.
- **FR-011**: Mengganti bahasa MUST TIDAK menghapus atau mengubah data
  apa pun yang sudah diisi pengguna di form yang sedang terbuka pada saat
  pergantian bahasa terjadi.
- **FR-012**: Teks yang berasal dari data yang dimasukkan pengguna sendiri
  (nama produk, nama artist, catatan bebas, dll) MUST TIDAK diterjemahkan
  otomatis oleh fitur ini — hanya label dan teks tetap antarmuka yang
  mengikuti bahasa pilihan.

### Key Entities

- **Preferensi Bahasa Pengguna**: atribut yang melekat ke setiap akun
  pengguna, menyimpan pilihan bahasa antarmuka (Bahasa Indonesia atau
  English) yang berlaku setiap kali akun itu login, default English bila
  belum pernah diatur. Hanya berlaku untuk layar SETELAH login — tidak
  ada representasi bahasa untuk sesi anonim, karena layar login memiliki
  bahasa tetap (lihat FR-001).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pengguna yang sudah login dapat mengganti bahasa tampilan
  dan melihat perubahan bahasa di seluruh teks antarmuka yang berlaku
  dalam waktu kurang dari 1 detik, tanpa memuat ulang halaman secara
  manual.
- **SC-002**: 100% pengguna yang login ulang setelah pernah mengganti
  bahasa melihat aplikasi langsung tampil dalam bahasa pilihan
  terakhirnya, tanpa perlu mengatur ulang.
- **SC-003**: Dua pengguna berbeda yang login bergantian di perangkat
  yang sama masing-masing selalu melihat bahasa preferensi akunnya
  sendiri — nol kasus bahasa "tertular" dari pengguna sebelumnya di
  perangkat yang sama.
- **SC-004**: Seluruh pesan galat sistem yang dilihat pengguna setelah
  login tampil dalam bahasa preferensinya, bukan campuran bahasa yang
  membingungkan.
- **SC-005**: 100% struk transaksi yang dicetak/diunduh tetap Bahasa
  Indonesia, tidak peduli preferensi bahasa kasir yang membuatnya.

## Assumptions

- Hanya dua bahasa yang didukung pada rilis pertama fitur ini: Bahasa
  Indonesia dan English — tidak ada bahasa ketiga di cakupan ini.
- Kontrol ganti bahasa (setelah login) berbentuk pilihan sederhana (mis.
  dropdown/toggle dua opsi), bukan deteksi otomatis dari lokasi/browser —
  pengguna selalu memilih secara eksplisit, dan default eksplisit adalah
  English sesuai permintaan fitur.
- Perubahan bahasa berlaku seketika di sisi klien (reaktif, tanpa reload
  paksa) untuk teks antarmuka yang sudah dimuat; teks yang datang dari
  respons API berikutnya (pesan galat baru, dll.) otomatis memakai bahasa
  yang sedang aktif saat permintaan itu dikirim.
- Layar login secara sengaja DIKECUALIKAN dari toggle bahasa fitur ini —
  tetap Bahasa Indonesia permanen, konsisten dengan bagaimana produk ini
  selama ini selalu berbahasa Indonesia untuk pengguna yang belum
  diketahui identitasnya.
- Fitur ini tidak mengubah bahasa penulisan kode, komentar kode, nama
  commit, atau dokumentasi internal proyek (`docs/`, `CLAUDE.md`) — itu
  tetap Bahasa Indonesia sesuai konvensi proyek yang sudah ada; yang
  berubah hanya teks yang dilihat pengguna akhir di layar SETELAH login.
