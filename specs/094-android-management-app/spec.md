# Feature Specification: Aplikasi Android Manajemen (Ponsel & Tablet)

**Klasifikasi**: INTERNAL

**Feature Branch**: `094-android-management-app`

**Created**: 2026-11-25

**Status**: Draft

**Input**: User description: "create android app (support mobile phone and tablet) using latest UI/UX design android for management data use. so that manager can manage Restaurant (Floor & Tabs, Kitchen & Bar, Restaurant Setup), Inventory (Categories, Products, Stock) Purchase (Vendors, Materials, Purchase Orders), and see dashboard. Make it exactly the same data input as in the web. app can read server local and online set in setting. user login only for role Admin and Manager. before login user can choose outlet."

## Latar

Hari ini pengelolaan data (menu, stok, bahan, PO, pengaturan restoran) hanya bisa dilakukan di **web back-office**, yang nyaman di laptop tetapi tidak di lantai restoran. Tablet Android yang ada (`android-pos/`) adalah aplikasi **kasir**: login PIN staf, fokus transaksi, dan sengaja tidak memuat layar pengelolaan.

Fitur ini menambahkan **aplikasi Android kedua untuk manajemen**, dipakai pemilik/admin/manajer outlet di ponsel atau tablet untuk melihat dashboard dan mengelola data yang sama dengan web. Server, database, aturan bisnis, dan izin tetap satu; aplikasi ini hanyalah klien lain dengan formulir yang **isinya identik dengan web**.

Istilah:

| Istilah | Arti |
|---|---|
| Aplikasi Manajemen | Aplikasi Android baru dalam fitur ini (terpisah dari aplikasi kasir) |
| Aplikasi Kasir | Aplikasi Android yang sudah ada (`android-pos/`); tidak berubah |
| Server lokal | Server BoothPOS di jaringan toko (mis. laptop/server di outlet) |
| Server online | Server BoothPOS yang dapat dijangkau lewat internet (instalasi server bersama, 045) |
| Paritas formulir | Setiap formulir di aplikasi memuat field, pilihan, aturan wajib/opsional, batas, dan pesan galat yang sama dengan formulir web padanannya |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Menyambung ke server, memilih outlet, dan login (Priority: P1)

Manajer memasang aplikasi, membuka Pengaturan, memasukkan alamat server (lokal atau online), lalu memilih outlet dari daftar outlet server itu. Setelah outlet dipilih, ia login dengan akun pengguna yang perannya diizinkan. Peran lain ditolak dengan pesan yang jelas.

**Why this priority**: Tanpa sambungan, pemilihan outlet, dan login, tidak ada layar lain yang bisa dipakai. Ini juga gerbang keamanan utama.

**Independent Test**: Isi alamat server lokal → daftar outlet tampil → pilih outlet → login sebagai Admin berhasil masuk ke Dashboard; login sebagai Kasir ditolak; ganti ke alamat server online dan ulangi.

**Acceptance Scenarios**:

1. **Given** aplikasi baru dipasang, **When** pengguna membuka aplikasi, **Then** aplikasi meminta alamat server sebelum hal lain.
2. **Given** alamat server valid tersimpan, **When** layar awal dibuka, **Then** daftar outlet aktif server itu tampil (nama dan kode outlet) dan pengguna memilih satu sebelum layar login.
3. **Given** outlet dipilih, **When** pengguna dengan peran yang diizinkan login dengan username dan kata sandi yang benar, **Then** ia masuk ke Dashboard outlet terpilih.
4. **Given** outlet dipilih, **When** pengguna dengan peran yang tidak diizinkan (mis. Kasir, Waiter) login dengan kredensial benar, **Then** login ditolak dengan pesan "Akun ini tidak punya akses ke aplikasi manajemen" dan tidak ada sesi yang tertinggal.
5. **Given** pengguna yang diizinkan tetapi tidak ditugaskan ke outlet terpilih, **When** login, **Then** login ditolak dengan pesan bahwa akun tidak terdaftar di outlet itu.
6. **Given** alamat server salah atau tak terjangkau, **When** pengguna menyimpan pengaturan, **Then** aplikasi menampilkan bahwa server tidak dapat dihubungi dan tidak melanjutkan.
7. **Given** pengguna sudah login, **When** ia mengganti outlet dari menu, **Then** ia kembali ke pemilihan outlet dan harus login ulang bila akunnya tidak berlaku di outlet baru.

---

### User Story 2 - Melihat Dashboard (Priority: P1)

Manajer membuka Dashboard dan melihat ringkasan yang sama dengan Dashboard web untuk outlet terpilih: angka penjualan hari bisnis, statistik stok/bahan, dan peringatan (stok menipis, bahan minus). Angka rupiah sensitif hanya tampil bila perannya punya akses laporan, sama seperti web.

**Why this priority**: Alasan utama manajer membuka aplikasi di ponsel adalah memantau kondisi outlet; nilai paling cepat dirasakan.

**Independent Test**: Untuk outlet dan periode yang sama, angka di Dashboard aplikasi sama persis dengan Dashboard web.

**Acceptance Scenarios**:

1. **Given** login di outlet A, **When** Dashboard dibuka, **Then** semua angka sama dengan Dashboard web untuk outlet A dan periode yang sama.
2. **Given** peran tanpa akses laporan, **When** Dashboard dibuka, **Then** angka rupiah yang di web disembunyikan juga disembunyikan di aplikasi.
3. **Given** Dashboard terbuka, **When** pengguna menarik layar untuk menyegarkan, **Then** angka dimuat ulang dari server.
4. **Given** kartu peringatan stok/bahan, **When** diketuk, **Then** aplikasi membuka layar Stok atau Bahan dengan filter status yang sesuai.

---

### User Story 3 - Mengelola Inventori: Kategori, Produk, Stok (Priority: P1)

Manajer membuat dan mengubah kategori, produk (termasuk varian, harga per outlet, foto, resep, grup modifier, status Draft/Aktif, penanda "Gunakan sebagai bahan"), dan melakukan koreksi serta pindah stok, dengan formulir yang isinya sama dengan web.

**Why this priority**: Pengelolaan menu dan stok adalah pekerjaan manajemen yang paling sering dan paling sering dibutuhkan saat jauh dari laptop.

**Independent Test**: Buat produk dengan dua varian dari aplikasi; buka produk itu di web dan semua field tersimpan identik; lakukan koreksi stok dari aplikasi dan riwayat stok di web mencatatnya.

**Acceptance Scenarios**:

1. **Given** formulir Tambah Produk di aplikasi, **When** dibandingkan dengan formulir web, **Then** field, pilihan, field wajib, dan batas nilainya sama.
2. **Given** data tidak valid (mis. nama kosong, harga negatif), **When** disimpan, **Then** aplikasi menampilkan pesan galat per field yang sama dengan web, dan data tidak tersimpan.
3. **Given** koreksi stok dengan alasan, **When** disimpan, **Then** saldo outlet terpilih berubah, riwayat mencatat alasan dan pengguna, dan web menampilkan hasil yang sama.
4. **Given** server menolak aksi karena aturan bisnis (mis. hapus kategori yang masih dipakai), **When** aksi dijalankan, **Then** aplikasi menampilkan alasan penolakan dari server tanpa mengubah tampilan data.
5. **Given** layar Produk, **When** pengguna mencari, memfilter, dan menggulir, **Then** daftar memuat bertahap dan filter berperilaku sama dengan web.

---

### User Story 4 - Mengelola Pembelian: Vendor, Bahan, Purchase Order (Priority: P2)

Manajer mengelola vendor, bahan baku (termasuk satuan, biaya standar, koreksi dan riwayat stok bahan), serta membuat, mengubah, menerima, dan membatalkan purchase order, dengan formulir dan alur status yang sama dengan web.

**Why this priority**: Penting untuk operasional, tetapi lebih jarang dari pengelolaan menu/stok dan biasanya tidak mendesak di lapangan.

**Independent Test**: Buat PO dari aplikasi untuk satu vendor, terima sebagian barang dari aplikasi; web menampilkan status PO dan saldo bahan yang bertambah sesuai.

**Acceptance Scenarios**:

1. **Given** formulir Vendor/Bahan/PO di aplikasi, **When** dibandingkan dengan web, **Then** field dan aturannya sama.
2. **Given** PO berstatus draf, **When** manajer menerima barang, **Then** saldo bahan outlet terpilih bertambah dan status PO berubah sesuai aturan web.
3. **Given** transisi status yang tidak diizinkan (mis. menerima PO yang sudah dibatalkan), **When** dicoba, **Then** aplikasi menampilkan penolakan server.

---

### User Story 5 - Mengelola Restoran: Dapur & Bar dan Pengaturan Restoran (Priority: P2)

Manajer memantau dan memperbarui tiket Dapur & Bar, serta mengelola Pengaturan Restoran (area, meja, stasiun, QR meja, pindah outlet meja/stasiun) seperti di web.

Keputusan pemilik (2026-11-25): **Lantai & Tab TIDAK termasuk** aplikasi ini. Buka tab, tambah item, bayar, dan void tetap di aplikasi kasir dan web.

**Why this priority**: Pengaturan restoran jarang berubah, dan layar dapur sudah menangani operasional harian; bernilai tetapi tidak mendesak.

**Independent Test**: Tambah meja baru dari aplikasi → muncul di Pengaturan Restoran web dan di denah aplikasi kasir; buka Dapur & Bar → tiket dan statusnya sama dengan web.

**Acceptance Scenarios**:

1. **Given** layar Dapur & Bar, **When** dibuka, **Then** tiket per stasiun, status, dan catatan order sama dengan web untuk outlet terpilih dan diperbarui berkala.
2. **Given** tiket di Dapur & Bar, **When** manajer mengubah statusnya (mis. siap/selesai), **Then** perubahan mengikuti transisi yang sama dengan layar dapur web dan terlihat di web.
3. **Given** Pengaturan Restoran, **When** manajer menambah/mengubah meja atau stasiun, **Then** perubahan tersimpan dengan aturan yang sama dengan web (mis. meja dengan order terbuka tidak bisa dipindah outlet).

---

### User Story 6 - Tata letak ponsel dan tablet (Priority: P2)

Aplikasi menyesuaikan diri dengan ukuran layar: di ponsel memakai navigasi bawah dan layar satu kolom; di tablet memakai navigasi samping dan tampilan daftar-detail berdampingan, mengikuti pedoman desain Android terkini (termasuk mode gelap dan ukuran teks sistem).

**Why this priority**: Diminta eksplisit, tetapi setiap layar sudah bermanfaat di satu ukuran sebelum tata letak adaptif disempurnakan.

**Independent Test**: Jalankan alur US3 di ponsel (≈6 inci) dan tablet (≈10 inci), potret dan lanskap; semua field formulir terjangkau tanpa terpotong.

**Acceptance Scenarios**:

1. **Given** layar sempit, **When** aplikasi dibuka, **Then** navigasi ada di bawah dan daftar/detail tampil bergantian.
2. **Given** layar lebar, **When** aplikasi dibuka, **Then** navigasi ada di samping dan daftar serta detail tampil berdampingan.
3. **Given** perangkat diputar saat mengisi formulir, **When** orientasi berubah, **Then** isian yang belum disimpan tidak hilang.
4. **Given** mode gelap sistem atau ukuran teks diperbesar, **When** aplikasi dibuka, **Then** semua teks tetap terbaca dan tidak terpotong.

---

### Edge Cases

- Sesi kedaluwarsa atau akun dinonaktifkan saat aplikasi terbuka: aksi berikutnya mengarahkan ke login tanpa kehilangan pesan kenapa.
- Peran pengguna diubah di web saat ia login di aplikasi: menu yang tidak lagi diizinkan hilang pada pemuatan berikutnya, dan server menolak aksi yang tidak diizinkan.
- Penanda "Boleh memakai aplikasi manajemen" dimatikan di web saat pengguna sedang login: permintaan berikutnya ditolak dan aplikasi kembali ke login dengan pesan akses.
- Peran diizinkan masuk tetapi tidak memegang menu tertentu (mis. "Outlet Manager" bawaan tidak punya menu Vendor): menu itu tidak tampil, sama seperti di web.
- Mode DEMO/LIVE server berubah saat sesi berjalan: aplikasi memberi tahu dan memuat ulang data agar tidak menulis ke mode yang salah.
- Koneksi terputus saat menyimpan: aplikasi tidak menganggap simpan berhasil, menyimpan isian formulir, dan menawarkan coba lagi tanpa membuat data ganda.
- Dua pengguna mengubah data yang sama (web dan aplikasi): penyimpanan terakhir mengikuti aturan server; pesan konflik dari server ditampilkan apa adanya.
- Alamat server online memakai sambungan tidak aman: aplikasi memperingatkan pengguna sebelum mengirim kata sandi.
- Server lokal di luar jaringan (manajer di rumah): aplikasi menyarankan beralih ke alamat server online yang tersimpan.
- Outlet yang dipilih dinonaktifkan: daftar outlet diperbarui dan pengguna diminta memilih ulang.
- Unggah foto produk dari kamera berukuran besar: ukuran mengikuti batas yang sama dengan web; melebihi batas ditolak dengan pesan jelas.
- Server versi lama yang belum mendukung aplikasi ini: aplikasi menampilkan bahwa versi server tidak kompatibel.

## Requirements *(mandatory)*

### Functional Requirements

**Sambungan & sesi**

- **FR-001**: Aplikasi MUST menyediakan Pengaturan untuk menyimpan alamat server, dengan sedikitnya dua profil: lokal dan online, dan memilih profil yang aktif.
- **FR-002**: Aplikasi MUST memeriksa keterjangkauan dan kecocokan server sebelum menyimpan profil, dan menampilkan galat yang dapat dipahami bila gagal.
- **FR-003**: Aplikasi MUST menampilkan daftar outlet aktif server terpilih dan meminta pengguna memilih satu outlet SEBELUM layar login; daftar ini hanya memuat informasi non-sensitif (nama, kode, logo).
- **FR-004**: Pengguna MUST login dengan username dan kata sandi akun yang sama dengan web.
- **FR-005**: Hanya pengguna yang perannya memegang penanda baru **"Boleh memakai aplikasi manajemen"** yang MUST bisa masuk (keputusan pemilik 2026-11-25). Penanda diatur owner/admin di formulir Peran web. Bawaannya menyala untuk peran sistem Owner dan Admin dan peran bernama Outlet Manager hasil seed, dan mati untuk peran lain. Peran baru dibuat dengan penanda mati.
- **FR-005a**: Mengubah penanda di web MUST berlaku pada login berikutnya, dan pada permintaan berikutnya untuk sesi yang sedang berjalan (sesi pengguna yang penandanya dimatikan ditolak dan diarahkan ke login).
- **FR-006**: Penolakan peran MUST ditegakkan oleh server, bukan hanya oleh aplikasi; aplikasi menampilkan pesan penolakan dan tidak menyimpan sesi.
- **FR-007**: Pengguna MUST hanya bisa login ke outlet yang boleh ia akses (penugasan outlet atau peran "semua outlet"), dengan aturan yang sama dengan web.
- **FR-008**: Pengguna MUST bisa keluar dan mengganti outlet/server dari menu; mengganti server menghapus sesi lama.
- **FR-009**: Kredensial sesi MUST disimpan dengan penyimpanan aman perangkat dan tidak pernah dicatat di log; kata sandi tidak pernah disimpan.
- **FR-010**: Batas percobaan login MUST sama dengan web (dihitung per akun), dan pesan "terlalu banyak percobaan" menampilkan lama tunggu.

**Navigasi & izin**

- **FR-011**: Aplikasi MUST menampilkan menu: Dashboard; Restoran (Dapur & Bar, Pengaturan Restoran); Inventori (Kategori, Produk, Stok); Pembelian (Vendor, Bahan, Purchase Order).
- **FR-012**: Setiap menu MUST tampil hanya bila peran pengguna memegang kunci menu yang sama dengan web; server tetap menolak aksi tanpa izin (403) dan aplikasi menampilkan pesannya.
- **FR-013**: Semua data dan aksi MUST dibatasi pada outlet terpilih dengan aturan outlet yang sama dengan web; aplikasi tidak pernah mengirim outlet di luar yang diizinkan server.

**Paritas data**

- **FR-014**: Setiap formulir buat/ubah di aplikasi MUST memiliki paritas formulir dengan padanannya di web: field, nilai bawaan, pilihan, field wajib, batas nilai, dan pesan galat.
- **FR-015**: Aplikasi MUST tidak menghitung sendiri harga, total, stok, porsi, atau pajak; semua angka berasal dari server, dan aplikasi hanya menampilkannya.
- **FR-016**: Aplikasi MUST menampilkan galat validasi (422) per field, konflik aturan bisnis (409) sebagai pesan dari server, dan penolakan izin (403) sebagai pesan akses, sama dengan konvensi web.
- **FR-017**: Uang MUST ditampilkan dalam format rupiah yang sama dengan web; teks antarmuka berbahasa Indonesia.

**Layar per modul**

- **FR-018**: Dashboard MUST menampilkan kartu dan angka yang sama dengan Dashboard web untuk outlet dan periode terpilih, termasuk statistik bahan, dan menyembunyikan rupiah bagi peran tanpa menu laporan.
- **FR-019**: Kategori MUST mendukung daftar, cari, buat, ubah, nonaktifkan/hapus, dan tautan grup modifier, sesuai web.
- **FR-020**: Produk MUST mendukung daftar dengan filter dan tab yang sama dengan web, buat/ubah produk dan varian, harga per outlet, foto dari kamera atau galeri, status Draft/Aktif, penanda "Gunakan sebagai bahan", sumber stok, grup modifier (termasuk melepas grup warisan), dan resep (blok dasar dan bermodifier).
- **FR-021**: Stok MUST mendukung melihat saldo per outlet, koreksi dengan alasan, pindah stok antar-outlet, dan riwayat pergerakan, sesuai web.
- **FR-022**: Vendor MUST mendukung daftar, cari, buat, ubah, dan nonaktifkan, sesuai web.
- **FR-023**: Bahan MUST mendukung daftar dengan saldo outlet terpilih (tanpa jatuh ke total), buat/ubah (satuan, jenis, biaya standar), koreksi stok bahan dengan alasan, dan riwayat pergerakan bahan, sesuai web.
- **FR-024**: Purchase Order MUST mendukung daftar dengan filter status, buat/ubah draf, penerimaan barang (penuh/sebagian), pembatalan, dan unduh/bagikan dokumen PO, dengan transisi status yang sama dengan web.
- **FR-025**: Aplikasi MUST TIDAK menyediakan Lantai & Tab (denah meja, buka tab, tambah item, bayar, void); tidak ada aksi yang membutuhkan shift kasir.
- **FR-026**: Dapur & Bar MUST menampilkan tiket per stasiun dengan status dan catatan order, diperbarui berkala, dan mendukung perubahan status tiket dengan transisi yang sama dengan layar dapur web.
- **FR-027**: Pengaturan Restoran MUST mendukung area, meja, stasiun, buat ulang QR meja, dan pindah outlet meja/stasiun dengan penjaga yang sama dengan web.

**Pengalaman pengguna**

- **FR-028**: Aplikasi MUST adaptif ponsel/tablet: navigasi bawah dan satu panel di layar sempit, navigasi samping dan daftar-detail di layar lebar.
- **FR-029**: Aplikasi MUST mendukung mode gelap, ukuran teks sistem, dan orientasi potret/lanskap tanpa kehilangan isian formulir.
- **FR-030**: Daftar MUST memuat bertahap, mendukung tarik-untuk-segarkan, dan menampilkan keadaan kosong, memuat, dan galat yang jelas.
- **FR-031**: Saat tidak ada koneksi, aplikasi MUST menampilkan status luring, tetap menampilkan data terakhir yang dimuat sebagai baca-saja, dan memblokir penyimpanan sampai terhubung kembali.
- **FR-032**: Penyimpanan ulang setelah koneksi terputus MUST tidak membuat data ganda.

**Mode & audit**

- **FR-033**: Aplikasi MUST menampilkan mode DEMO/LIVE server secara jelas di setiap layar dan memberi tahu bila mode berubah selama sesi.
- **FR-034**: Setiap perubahan dari aplikasi MUST tercatat di log aktivitas dengan cara yang sama dengan perubahan dari web (pengguna, aksi, waktu).

### Key Entities

- **Profil Server**: alamat server yang disimpan di perangkat (lokal/online), label, dan penanda aktif. Hanya di perangkat.
- **Outlet Terpilih**: outlet yang dipilih sebelum login; menentukan lingkup semua data.
- **Sesi Manajemen**: sesi login pengguna yang diizinkan, terikat pada satu server dan satu outlet.
- **Peran & Menu**: peran pengguna, kunci menu yang dipegangnya (menentukan menu yang tampil), dan penanda baru "Boleh memakai aplikasi manajemen" (menentukan boleh login). Sumber: server; diatur di web.
- **Entitas data yang dikelola** (semua sudah ada di server, tidak ada entitas bisnis baru): Kategori, Produk/Varian, Grup Modifier, Resep, Saldo & Pergerakan Stok, Vendor, Bahan & Pergerakan Bahan, Purchase Order, Area, Meja, Stasiun, Tiket Dapur.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Manajer baru bisa menyambungkan aplikasi ke server, memilih outlet, dan masuk ke Dashboard dalam waktu kurang dari 2 menit tanpa bantuan.
- **SC-002**: 100% formulir dalam cakupan lolos pemeriksaan paritas dengan web (field, wajib, batas, pesan galat), diverifikasi dengan daftar periksa per formulir.
- **SC-003**: 100% percobaan login oleh peran yang tidak diizinkan ditolak, termasuk saat aplikasi dimodifikasi atau dipanggil langsung ke server.
- **SC-004**: Angka Dashboard aplikasi sama dengan Dashboard web untuk outlet dan periode yang sama pada 100% kartu yang diuji.
- **SC-005**: Manajer bisa membuat produk dengan dua varian dan harga di ponsel dalam waktu kurang dari 3 menit.
- **SC-006**: Layar daftar utama tampil dengan data dalam kurang dari 2 detik di jaringan toko biasa.
- **SC-007**: Semua alur US1–US4 bisa diselesaikan di ponsel ≈6 inci dan tablet ≈10 inci, potret dan lanskap, tanpa field terpotong atau tak terjangkau.
- **SC-008**: Tidak ada data ganda yang tercipta dalam pengujian simpan ulang setelah koneksi terputus.

## Assumptions

- ASUMSI: Aplikasi Manajemen adalah aplikasi Android **terpisah** dari aplikasi kasir (`android-pos/`); aplikasi kasir dan aplikasi WebView lama (`android/`) tidak berubah.
- ASUMSI: Aplikasi selalu membaca dan menulis langsung ke server (online-first); data luring hanya cache tampilan baca-saja, tanpa antrean tulis luring.
- ASUMSI: Login memakai username + kata sandi akun web, bukan PIN; PIN tetap khusus aplikasi kasir.
- ASUMSI: "Server lokal" dan "server online" adalah dua alamat ke server/database yang SAMA milik organisasi (atau dua instalasi yang dipilih pengguna); aplikasi tidak menyinkronkan data antar-server.
- ASUMSI: Menu di luar cakupan (POS/kasir, Shift, Penjualan, Laporan, Pelanggan, Pengguna, Peran, Pengaturan toko, Outlet, Terminal, Lisensi, Impor/Ekspor Excel) tidak termasuk; manajer memakai web untuk itu.
- ASUMSI: Impor/ekspor Excel master data tidak termasuk di aplikasi.
- ASUMSI: Daftar outlet sebelum login memakai pola tanpa token yang sudah ada untuk aplikasi kasir (hanya nama/kode/logo/mode), sebagai pengecualian Konstitusi IV yang sudah tercatat.
- ASUMSI: Versi Android minimum mengikuti aplikasi kasir yang ada; distribusi berupa APK yang dipasang manual (bukan Play Store).
- Dependensi: aturan bisnis, validasi, izin per menu, cakupan outlet, mode DEMO/LIVE, dan log aktivitas yang sudah ada di server; bila ada formulir web yang validasinya hanya di sisi klien, aturan itu perlu dipastikan juga ada di server agar paritas terjaga.
- Dependensi: server perlu menyimpan penanda peran FR-005, menampilkannya di formulir Peran web, dan menegakkannya pada login serta setiap permintaan sesi aplikasi manajemen (perubahan server + web, dirinci saat perencanaan).
- ASUMSI: Aksi Dapur & Bar di aplikasi tidak membutuhkan shift terbuka, sama dengan layar dapur web.
