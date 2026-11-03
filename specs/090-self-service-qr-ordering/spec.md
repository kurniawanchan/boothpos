# Feature Specification: Pesan Mandiri via QR Meja (Self-Service), QR per Meja, dan Penanda Dine-In / Take Away

**Klasifikasi**: INTERNAL

**Feature Branch**: `090-self-service-qr-ordering`

**Created**: 2026-11-03

**Status**: Draft

**Input**: User description: "Halaman web sederhana, mobile-first, agar pelanggan membuat transaksi POS sendiri (self-service) dengan memindai QR yang sudah memuat nomor meja dan outlet. Pelanggan memilih menu seperti POS web yang ada tetapi tampilannya ramah ponsel, tanpa diskon, semua dalam bahasa Indonesia, pembayaran hanya QRIS (tampilkan gambar QRIS), tambahkan foto bukti bayar dari kamera atau unggah, lalu kirim transaksi. Tampilkan struk setelah kirim agar pelanggan bisa menyimpannya sebagai gambar atau PDF. Pelanggan mengisi data diri (nama, telepon, surel) atau lanjut sebagai tamu. Transaksi diberi penanda self-service. Di halaman Restaurant Setup web: tambah filter, paginasi, dan aksi membuat QR per meja yang memuat id meja, id outlet, dan URL POS self-service. Di halaman POS web dan aplikasi Android: tambah pilihan menandai transaksi sebagai Dine-In atau Take Away."

## Latar

Hari ini pelanggan harus antre ke kasir atau menunggu pelayan untuk memesan. POS yang ada (web dan Android) hanya dipakai staf. Fitur ini membuka satu pintu baru yang terbatas: **pelanggan memesan dan membayar sendiri dari ponselnya**, tanpa akun, tanpa memasang aplikasi, hanya dengan memindai QR yang ditempel di meja.

Tiga bagian yang saling bergantung, dikerjakan dalam satu fitur:

1. **Halaman pesan mandiri untuk pelanggan** (publik, tanpa login, mobile-first).
2. **QR per meja** yang dibuat staf dari halaman Restaurant Setup, beserta filter dan paginasi daftar meja.
3. **Penanda Dine-In / Take Away** pada POS web dan POS Android, karena pelanggan mandiri juga perlu memilihnya dan kasir perlu penanda yang sama.
4. **Notifikasi suara di aplikasi Android POS** saat pesanan mandiri masuk, agar staf segera memeriksanya.

Istilah yang dipakai di dokumen ini:

| Istilah | Arti |
|---|---|
| **Pesan mandiri / self-service** | Transaksi yang dibuat pelanggan sendiri lewat halaman publik, bukan oleh staf |
| **QR meja** | Kode QR milik satu meja; memuat alamat halaman pesan mandiri beserta identitas meja dan outlet |
| **Tamu (guest)** | Pelanggan yang memesan tanpa mengisi nama, telepon, atau surel |
| **Bukti bayar** | Foto layar atau struk transfer QRIS yang dilampirkan pelanggan |
| **Dine-In / Take Away** | Cara layanan pesanan: makan di tempat atau dibawa pulang |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pelanggan memesan dan membayar QRIS dari ponsel (Priority: P1)

Pelanggan duduk di meja, memindai QR di meja, dan halaman pesan mandiri terbuka di peramban ponselnya dengan nama outlet dan nomor meja sudah terisi. Ia menelusuri menu (cari dan kategori), menaruh item ke keranjang (termasuk memilih varian dan modifier bila ada), memilih Dine-In atau Take Away, lalu menekan bayar. Halaman menampilkan total, gambar QRIS outlet, dan kolom bukti bayar. Pelanggan membayar lewat aplikasi bank/e-wallet-nya, memotret atau mengunggah bukti bayar, dan mengirim pesanan.

**Why this priority**: Ini inti fitur dan satu-satunya bagian yang memberi nilai ke pelanggan. Tanpanya QR meja dan penanda layanan tidak berguna.

**Independent Test**: Buka alamat halaman pesan mandiri untuk satu meja di ukuran layar ponsel, pilih dua item, lampirkan foto bukti, kirim. Pesanan tercatat di outlet dan meja yang benar dengan penanda self-service dan total yang dihitung server.

**Acceptance Scenarios**:

1. **Given** QR meja yang sah untuk Meja 5 di Outlet A, **When** pelanggan memindainya, **Then** halaman terbuka dalam bahasa Indonesia, menampilkan nama outlet dan "Meja 5", dan hanya menu yang dijual di Outlet A.
2. **Given** keranjang berisi item, **When** pelanggan melihat ringkasan, **Then** tidak ada kolom atau baris diskon, dan pajak (bila diaktifkan di pengaturan toko) tampil sama seperti di POS.
3. **Given** pelanggan di langkah pembayaran, **When** halaman tampil, **Then** hanya QRIS yang tersedia sebagai metode, gambar QRIS terlihat jelas dan dapat diperbesar, dan nominal yang harus dibayar tertulis.
4. **Given** pelanggan belum melampirkan bukti bayar, **When** menekan kirim, **Then** pesanan tidak terkirim dan halaman menjelaskan bahwa bukti bayar wajib.
5. **Given** pelanggan memilih foto lewat kamera ponsel atau dari galeri, **When** foto terpilih, **Then** pratinjau tampil dan dapat diganti sebelum kirim.
6. **Given** pelanggan menekan kirim dua kali atau koneksi terputus lalu mengulang, **When** pesanan sama terkirim ulang, **Then** hanya satu pesanan yang tercatat.
7. **Given** sebuah item habis atau tidak dijual lagi saat pelanggan menekan kirim, **When** server memeriksa, **Then** pelanggan diberi tahu item mana yang bermasalah dan dapat memperbaiki keranjang tanpa mengisi ulang semuanya.

---

### User Story 2 - Staf membuat QR per meja dari Restaurant Setup (Priority: P1)

Di halaman Restaurant Setup web, staf berwenang membuka daftar meja dan memilih aksi "Buat QR" pada satu meja. Sistem menampilkan QR yang memuat id meja, id outlet, dan alamat halaman pesan mandiri, lengkap dengan nama outlet dan nomor meja di bawahnya, dan staf dapat mengunduh atau mencetaknya untuk ditempel di meja. Staf juga dapat membuat QR untuk banyak meja sekaligus.

**Why this priority**: Tanpa QR yang sah, pelanggan tidak punya pintu masuk. Bersama User Story 1 dan 6 ini membentuk MVP yang bisa dipakai di lapangan.

**Independent Test**: Buka Restaurant Setup, buat QR Meja 5, pindai dengan ponsel, dan pastikan halaman US1 terbuka untuk Meja 5 di outlet yang benar.

**Acceptance Scenarios**:

1. **Given** staf dengan hak Restaurant Setup, **When** menekan "Buat QR" pada sebuah meja, **Then** QR tampil dengan label outlet dan meja, dan dapat diunduh sebagai gambar atau dicetak.
2. **Given** beberapa meja terpilih, **When** staf membuat QR massal, **Then** satu berkas berisi QR semua meja terpilih dihasilkan, satu QR per meja dengan labelnya.
3. **Given** QR hasil sistem, **When** seseorang mengubah id meja atau id outlet di alamatnya, **Then** halaman menolaknya dan tidak menampilkan menu atau membuat pesanan untuk meja/outlet lain.
4. **Given** meja nonaktif atau sudah dihapus, **When** pelanggan memindai QR lamanya, **Then** halaman memberi pesan bahwa meja ini tidak melayani pesanan mandiri dan tidak bisa memesan.
5. **Given** pengguna tanpa hak Restaurant Setup, **When** mencoba membuat QR, **Then** aksi tidak tersedia/ditolak.

---

### User Story 3 - Struk setelah kirim, dapat disimpan sebagai gambar atau PDF (Priority: P2)

Setelah pesanan terkirim, pelanggan melihat struk: identitas outlet, nomor pesanan, meja, Dine-In/Take Away, daftar item beserta modifier, subtotal, pajak (bila ada), total, status pembayaran, dan waktu. Pelanggan menyimpannya sebagai gambar atau PDF dari halaman itu.

**Why this priority**: Pelanggan butuh tanda terima, tetapi pesanan sudah sah tanpa itu.

**Independent Test**: Selesaikan satu pesanan, tekan "Simpan sebagai gambar" dan "Simpan sebagai PDF", lalu pastikan isi berkas sama dengan layar dan semua gambar (logo) tampil benar.

**Acceptance Scenarios**:

1. **Given** pesanan baru terkirim, **When** halaman konfirmasi terbuka, **Then** struk lengkap tampil dan memuat penanda "Pesan Mandiri".
2. **Given** struk tampil, **When** pelanggan menekan simpan gambar atau simpan PDF, **Then** berkas terunduh dan isinya sama dengan struk di layar, termasuk logo toko.
3. **Given** bukti bayar belum diverifikasi staf, **When** struk tampil, **Then** status pembayaran tertulis menunggu verifikasi, **bukan** "Lunas".
4. **Given** pelanggan menutup halaman lalu membuka tautan struk yang sama dari perangkat yang sama, **When** halaman dimuat, **Then** struk yang sama tampil lagi.

---

### User Story 4 - Pelanggan mengisi data diri atau lanjut sebagai tamu (Priority: P2)

Sebelum membayar, pelanggan dapat mengisi nama, telepon, dan surel, atau memilih "Lanjut sebagai tamu" tanpa mengisi apa pun. Data yang diisi tersimpan sebagai data pelanggan outlet dan tertaut ke pesanan.

**Why this priority**: Berguna untuk pemilik (daftar pelanggan), tetapi tidak boleh menghalangi pesanan.

**Independent Test**: Kirim satu pesanan sebagai tamu dan satu pesanan dengan data diri; pesanan pertama tanpa data pelanggan, yang kedua tertaut ke pelanggan dengan data benar.

**Acceptance Scenarios**:

1. **Given** pelanggan memilih lanjut sebagai tamu, **When** mengirim pesanan, **Then** pesanan berhasil tanpa data pelanggan dan struk tidak memuat nama.
2. **Given** pelanggan mengisi data diri, **When** nama atau telepon kosong atau format telepon/surel tidak valid, **Then** kolom bersangkutan diberi pesan kesalahan berbahasa Indonesia dan pesanan tidak terkirim.
3. **Given** telepon yang sudah ada pada data pelanggan, **When** pelanggan memesan lagi dengan telepon itu, **Then** tidak muncul pelanggan kembar, dan pelanggan baru tidak dapat melihat data pelanggan lama (tidak ada pratinjau nama/surel yang sudah tersimpan).
4. **Given** halaman pesan mandiri, **When** pelanggan melihat bagian data diri, **Then** ada pemberitahuan singkat tentang untuk apa data dipakai.

---

### User Story 5 - Kasir menandai transaksi Dine-In atau Take Away di POS web dan Android (Priority: P2)

Pada halaman POS web dan POS Android, kasir memilih **Dine-In** atau **Take Away** untuk setiap transaksi baru sebelum membayar. Pilihan ini tercatat pada transaksi, tampil di struk, dan dapat dipakai di daftar penjualan dan laporan.

**Why this priority**: Dibutuhkan pelanggan mandiri juga (US1), tetapi untuk kasir ini perubahan terpisah yang berdiri sendiri.

**Independent Test**: Di POS web buat satu transaksi Dine-In dan satu Take Away; di Android lakukan hal sama; keempatnya tampil dengan penanda benar di daftar penjualan dan struk.

**Acceptance Scenarios**:

1. **Given** POS web atau Android dengan keranjang berisi item, **When** kasir melihat area pembayaran, **Then** terdapat pilihan Dine-In dan Take Away dengan satu pilihan aktif.
2. **Given** kasir mengganti pilihan menjadi Take Away lalu menyelesaikan transaksi, **When** membuka detail transaksi dan struk, **Then** keduanya menyebut Take Away.
3. **Given** transaksi lama yang dibuat sebelum fitur ini, **When** dilihat, **Then** tampil seperti semula tanpa kesalahan dan tidak diubah.
4. **Given** transaksi berbasis meja (tab) di Android, **When** diselesaikan, **Then** tetap Dine-In kecuali kasir mengubahnya secara eksplisit.

---

### User Story 6 - Staf memeriksa pesanan mandiri, memverifikasi pembayaran, lalu mengirim ke dapur (Priority: P1)

Pesanan mandiri **tidak langsung masuk dapur**. Ia muncul di daftar pesanan staf pada tab **Self-Service** (terpisah dari pesanan kasir), berstatus menunggu verifikasi. Staf membuka detail pesanan (item, modifier, meja, Dine-In/Take Away, data pelanggan bila ada) dan detail pembayaran (nominal, QRIS, foto bukti bayar), memverifikasi pembayaran, lalu menekan **Kirim ke dapur**. Hanya setelah itu pesanan muncul sebagai tiket di dapur.

**Why this priority**: Ini keputusan pemilik untuk mencegah pesanan palsu dan menjadi syarat fitur utuh: tanpa langkah ini pesanan mandiri tidak pernah sampai ke dapur.

**Independent Test**: Setelah US1 selesai, buka tab Self-Service sebagai kasir; pesanan tampil menunggu verifikasi, belum ada tiket dapur. Verifikasi pembayaran lalu kirim ke dapur; tiket muncul dengan meja dan Dine-In/Take Away yang benar.

**Acceptance Scenarios**:

1. **Given** pesanan mandiri baru, **When** staf membuka daftar pesanan, **Then** pesanan hanya tampil di tab **Self-Service** (dengan penanda self-service, meja, Dine-In/Take Away, waktu, total, status) dan tidak bercampur dengan tab pesanan kasir; tab menampilkan jumlah pesanan yang menunggu.
2. **Given** pesanan menunggu verifikasi, **When** staf memeriksa dapur, **Then** belum ada tiket dapur untuk pesanan itu.
3. **Given** pesanan mandiri, **When** staf membuka detailnya, **Then** detail pesanan dan detail pembayaran (termasuk foto bukti bayar yang dapat diperbesar) tampil; foto hanya dapat diakses staf berwenang.
4. **Given** bukti bayar valid, **When** staf memverifikasi pembayaran, **Then** status pembayaran menjadi terverifikasi mengikuti alur verifikasi yang ada, tombol **Kirim ke dapur** menjadi aktif, dan struk pelanggan yang dibuka ulang menunjukkan status terbaru.
5. **Given** pembayaran sudah terverifikasi, **When** staf menekan **Kirim ke dapur**, **Then** tiket dapur dibuat sekali (menekan ulang tidak menggandakan), memuat meja dan Dine-In/Take Away, dan pesanan berpindah status menjadi diproses.
6. **Given** pembayaran belum terverifikasi, **When** staf mencoba mengirim ke dapur, **Then** aksi ditolak dengan pesan yang menjelaskan alasannya.
7. **Given** bukti bayar tidak valid, **When** staf menolak pesanan dengan alasan, **Then** pesanan tidak masuk dapur, tidak dihitung sebagai pendapatan, stok tidak berubah, dan struk pelanggan menyatakan pesanan ditolak beserta alasannya.
8. **Given** daftar tab Self-Service, **When** staf menyaring berdasarkan status (menunggu verifikasi, terverifikasi, di dapur, ditolak) atau meja, **Then** hanya pesanan yang cocok tampil.

---

### User Story 7 - Filter dan paginasi daftar meja di Restaurant Setup (Priority: P3)

Daftar meja di Restaurant Setup web mendapat filter tambahan (pencarian nama/nomor meja, area, status aktif/nonaktif, dan outlet yang sudah mengikuti pola halaman lain) dan paginasi, supaya outlet dengan puluhan meja mudah dikelola dan dipilih untuk pembuatan QR massal.

**Why this priority**: Memudahkan pekerjaan staf, tetapi QR per meja tetap bisa dibuat tanpa ini pada outlet kecil.

**Independent Test**: Pada outlet dengan lebih dari satu halaman meja, saring berdasarkan area dan pencarian lalu pindah halaman; hasil, jumlah, dan pilihan tetap konsisten.

**Acceptance Scenarios**:

1. **Given** lebih meja daripada satu halaman, **When** staf membuka daftar, **Then** meja tampil per halaman dengan penunjuk halaman dan total.
2. **Given** filter area dan pencarian terisi, **When** daftar dimuat, **Then** hanya meja yang cocok yang tampil dan total ikut menyesuaikan.
3. **Given** staf memilih beberapa meja lalu pindah halaman atau mengubah filter, **When** membuat QR massal, **Then** perilaku pilihan jelas (hanya meja terlihat atau semua hasil filter) dan tidak ada meja yang terbuat QR-nya tanpa disadari.

---

### User Story 8 - Aplikasi Android POS membunyikan notifikasi saat pesanan mandiri masuk (Priority: P2)

Begitu pelanggan mengirim pesanan mandiri, aplikasi POS Android di outlet itu membunyikan notifikasi suara, misalnya "Pesanan dari meja 5", dan menampilkan pemberitahuan di layar, sehingga staf langsung tahu ada pesanan yang perlu diperiksa tanpa menatap layar.

**Why this priority**: Pesanan ditahan sampai staf memverifikasi (US6). Tanpa pemberitahuan aktif, pesanan bisa menunggu lama tanpa disadari dan pelanggan merasa diabaikan.

**Independent Test**: Dengan aplikasi Android terbuka di outlet yang sama, kirim satu pesanan mandiri dari meja 5; tablet berbunyi "Pesanan dari meja 5" dan menampilkan pemberitahuan dengan nomor pesanan dalam hitungan detik.

**Acceptance Scenarios**:

1. **Given** aplikasi Android POS aktif di Outlet A, **When** pelanggan mengirim pesanan mandiri dari Meja 5 Outlet A, **Then** perangkat membunyikan pengumuman suara berbahasa Indonesia "Pesanan dari meja 5" dan menampilkan pemberitahuan yang memuat meja, nomor pesanan, dan total.
2. **Given** perangkat di Outlet B, **When** pesanan mandiri masuk di Outlet A, **Then** perangkat Outlet B tidak berbunyi dan tidak menampilkan apa pun.
3. **Given** tiga pesanan masuk berurutan dalam beberapa detik, **When** notifikasi diputar, **Then** pengumuman dimainkan satu per satu tanpa saling menimpa dan tidak ada yang terlewat.
4. **Given** pemberitahuan di layar, **When** staf menutupnya, **Then** pemberitahuan hilang dan tidak muncul lagi untuk pesanan itu, sedangkan pesanan tetap ada di tab Self-Service sampai diproses.
5. **Given** perangkat sempat offline atau aplikasi baru dibuka, **When** tersambung kembali dan ada pesanan mandiri yang belum diproses, **Then** perangkat menampilkan ringkasan jumlah pesanan yang menunggu, bukan memutar ulang semua suara sekaligus.
6. **Given** staf mematikan suara notifikasi di pengaturan aplikasi, **When** pesanan mandiri masuk, **Then** hanya pemberitahuan visual yang muncul.

---

### Edge Cases

- Staf memverifikasi pembayaran tetapi lupa mengirim ke dapur: pesanan tetap terlihat di tab Self-Service berstatus terverifikasi, dan tab menunjukkan jumlah yang belum dikirim.
- Dua staf menekan verifikasi/kirim/tolak bersamaan pada pesanan yang sama: hanya satu yang berhasil, yang lain diberi tahu bahwa status sudah berubah.
- Beberapa tablet di outlet yang sama: semuanya berbunyi; staf yang pertama memproses pesanan menyelesaikannya dan staf lain melihat statusnya sudah berubah (lihat edge case aksi bersamaan).
- Tablet dalam mode hemat daya, layar mati, atau aplikasi di latar belakang: pemberitahuan tetap harus sampai sejauh yang diizinkan sistem; bila tidak mungkin, jumlah menunggu terlihat saat aplikasi dibuka (US8 skenario 5).
- Volume perangkat nol atau suara dimatikan sistem: pemberitahuan visual tetap muncul dan staf dapat melihat jumlah pesanan menunggu.
- QR rusak, kedaluwarsa, atau alamatnya diubah: halaman menolak dan menyuruh pelanggan meminta bantuan staf; tidak pernah menebak meja/outlet.
- Meja nonaktif, dihapus, atau outlet bukan lagi aktif; mode DEMO/LIVE berbeda dengan mode instalasi saat ini: pemesanan ditolak dengan pesan jelas.
- Outlet tidak punya kanal pembayaran QRIS aktif atau gambar QRIS belum diunggah: pesan mandiri tidak dapat dilanjutkan ke pembayaran dan pelanggan diarahkan ke staf.
- Meja yang sedang punya tab terbuka dari kasir: pesanan mandiri tetap diterima sebagai pesanan terpisah dan tidak mengubah tab itu (lihat Asumsi).
- Dua pelanggan di meja yang sama memesan bersamaan: dua pesanan terpisah, keduanya bernomor dan bertanda meja yang sama.
- Foto bukti bukan gambar, terlalu besar, atau rusak: ditolak dengan pesan yang menjelaskan jenis dan batas ukuran; foto kamera ponsel biasa harus lolos.
- Koneksi putus saat unggah atau kirim: keranjang dan data diri tidak hilang saat halaman dimuat ulang; kirim ulang tidak menggandakan pesanan.
- Item habis/dihentikan, harga berubah, atau jam jual berakhir saat pelanggan menyusun keranjang: server menjadi acuan dan pelanggan diberi tahu.
- Penyalahgunaan (memindai QR lalu memesan dari jauh, atau membanjiri pesanan): laju permintaan dibatasi, dan pesanan palsu dapat dikenali dari bukti bayar yang tidak valid.
- Pelanggan memilih jumlah item sangat besar atau memasukkan teks sangat panjang: dibatasi dan divalidasi di server.
- Ponsel lama dengan layar sempit (±360 px) dan koneksi lambat: halaman tetap dapat dipakai dan gambar menu tidak menghalangi pemesanan.

## Requirements *(mandatory)*

### Functional Requirements

**Halaman pesan mandiri (publik)**

- **FR-001**: Sistem MUST menyediakan halaman pesan mandiri yang dapat dibuka tanpa login, dirancang mobile-first, dan tetap dapat dipakai penuh pada lebar layar ±360 px.
- **FR-002**: Seluruh teks halaman, pesan kesalahan, dan struk pelanggan MUST berbahasa Indonesia.
- **FR-003**: Halaman MUST menentukan outlet dan meja hanya dari QR yang sah, menampilkan nama outlet dan nomor meja, dan pelanggan tidak dapat mengubahnya.
- **FR-004**: Halaman MUST menampilkan hanya menu yang boleh dipesan di outlet itu, memakai aturan ketersediaan, harga per outlet, dan jam jual yang sama dengan POS; item yang tidak dapat dipesan ditandai habis dan tidak dapat dimasukkan ke keranjang.
- **FR-005**: Pelanggan MUST dapat mencari menu, menyaring per kategori, memilih varian dan modifier (termasuk aturan jumlah/minimum/maksimum pilihan yang berlaku di POS), mengubah jumlah, dan menghapus item dari keranjang. Kartu menu menampilkan foto, nama, rentang harga, dan status habis seperti di POS web.
- **FR-006**: Halaman MUST NOT menyediakan diskon dalam bentuk apa pun; pajak mengikuti pengaturan pajak toko yang berlaku.
- **FR-007**: Pelanggan MUST memilih Dine-In atau Take Away sebelum mengirim; bawaan Dine-In.
- **FR-008**: Metode pembayaran MUST hanya QRIS. Halaman MUST menampilkan gambar QRIS kanal aktif outlet itu dan nominal total yang harus dibayar, dan gambar dapat diperbesar.
- **FR-009**: Pelanggan MUST melampirkan satu foto bukti bayar, baik dengan mengambil dari kamera ponsel maupun mengunggah dari galeri/berkas, dapat melihat pratinjau dan menggantinya; pesanan tidak dapat dikirim tanpa bukti bayar.
- **FR-010**: Pelanggan MUST dapat mengisi nama, telepon, dan surel, atau memilih lanjut sebagai tamu. Bila mengisi data diri, nama dan telepon wajib, surel opsional, dan format divalidasi.
- **FR-011**: Setelah kirim, sistem MUST menampilkan struk dan menyediakan aksi simpan sebagai gambar dan simpan sebagai PDF yang isinya sama dengan tampilan layar.
- **FR-012**: Struk MUST memuat identitas toko outlet transaksi, nomor pesanan, meja, Dine-In/Take Away, item beserta modifier, subtotal, pajak (hanya bila ada), total, metode QRIS, status pembayaran apa adanya (menunggu verifikasi selama belum diverifikasi), waktu, dan penanda pesan mandiri.
- **FR-013**: Draf keranjang dan data diri MUST bertahan bila halaman dimuat ulang pada perangkat yang sama sampai pesanan terkirim.

**Pencatatan pesanan**

- **FR-014**: Sistem MUST menghitung harga, pajak, dan total sepenuhnya di server; jumlah, harga, atau total yang dikirim dari halaman publik tidak pernah dipercaya.
- **FR-015**: Setiap pesanan hasil halaman ini MUST diberi penanda **self-service** yang tersimpan permanen pada pesanan, tidak dapat diisi oleh klien POS staf, dan tidak dapat dipalsukan dari halaman publik lain.
- **FR-016**: Pesanan MUST tercatat pada outlet, meja, dan mode data (DEMO/LIVE) yang berasal dari QR, memakai tanggal bisnis dan penomoran yang sama dengan pesanan POS.
- **FR-017**: Pengiriman ulang pesanan yang sama (klik ganda, koneksi putus) MUST tidak menghasilkan pesanan ganda.
- **FR-018**: Bukti bayar MUST disimpan privat, hanya dapat dibuka lewat jalur yang memeriksa hak akses staf, tertaut ke pembayaran QRIS pesanan, dan pembayaran dicatat berstatus menunggu verifikasi (bukan terverifikasi otomatis).
- **FR-019**: Pesanan mandiri MUST dibuat berstatus menunggu verifikasi dan MUST NOT menghasilkan tiket dapur maupun memotong stok/bahan sampai staf memverifikasi pembayaran **dan** mengirimnya ke dapur. Pemotongan stok memakai aturan dan titik yang sama seperti pesanan POS pada saat itu, tanpa jalur penulisan stok baru; pesanan ditolak tidak pernah memotong stok.
- **FR-020**: Data pelanggan yang diisi MUST dikaitkan ke data pelanggan yang ada tanpa membuat duplikat untuk telepon yang sama, dan halaman publik MUST NOT mengembalikan data pelanggan yang sudah tersimpan kepada siapa pun.
- **FR-021**: Pelanggan MUST mendapat tautan struk tak-tertebak untuk membuka ulang struknya; tautan itu hanya mengungkap satu pesanan dan tidak dapat dipakai menelusuri pesanan lain.

**QR meja dan keamanan**

- **FR-022**: Sistem MUST dapat membuat QR per meja yang memuat id meja, id outlet, dan alamat halaman pesan mandiri, dengan cara yang tidak dapat dipalsukan: perubahan id pada alamat MUST membuat QR/alamat tidak sah.
- **FR-023**: Alamat di dalam QR MUST mengarah ke alamat server yang dapat dijangkau pelanggan, bukan `localhost`. Bila alamat publik belum diatur, staf diberi tahu sebelum QR dibuat. (Lihat Asumsi tentang jaringan.)
- **FR-024**: Endpoint publik MUST dibatasi lajunya dan hanya membuka data minimal yang dibutuhkan (menu, kanal QRIS, identitas toko); tidak ada data staf, pelanggan lain, biaya/HPP, atau stok mentah yang keluar.
- **FR-025**: Halaman publik MUST menolak pesanan bila meja tidak aktif, outlet tidak aktif, mode tidak cocok, atau kanal QRIS aktif tidak ada, dengan pesan jelas.
- **FR-026**: Pesan mandiri MUST hanya dapat dilayani oleh instalasi yang lisensinya aktif, sama seperti bagian sistem lain.

**Restaurant Setup**

- **FR-027**: Halaman Restaurant Setup web MUST menyediakan aksi "Buat QR" pada setiap meja yang menampilkan QR berlabel outlet dan meja, dan dapat diunduh atau dicetak; aksi hanya untuk peran yang berhak mengelola Restaurant Setup.
- **FR-028**: Staf MUST dapat memilih banyak meja dan membuat QR massal dalam satu berkas yang siap cetak.
- **FR-029**: Daftar meja MUST mendukung filter (pencarian nama/nomor, area, status aktif, dan outlet seperti pola halaman lain) dan paginasi dengan total, mengikuti pola filter dan paginasi halaman lain di aplikasi.

**Dine-In / Take Away di POS**

- **FR-030**: POS web dan POS Android MUST menyediakan pilihan Dine-In atau Take Away pada setiap transaksi baru, tercatat pada transaksi, dan tampil di detail transaksi dan struk.
- **FR-031**: Transaksi sebelum fitur ini MUST tetap valid dan tampil tanpa perubahan data; laporan dan filter yang sudah memakai jenis layanan MUST tetap benar.
- **FR-032**: Penanda layanan dan penanda self-service MUST tampil di sisi staf (daftar pesanan, detail, tiket dapur) sehingga staf dapat mengenali dan menyaringnya.
- **FR-034**: Daftar pesanan staf MUST memiliki tab **Self-Service** yang memuat semua pesanan mandiri, dengan filter status dan meja serta paginasi; pesanan mandiri MUST NOT tampil di tab pesanan kasir sebelum dikirim ke dapur.
- **FR-035**: Detail pesanan mandiri MUST menampilkan detail pesanan dan detail pembayaran termasuk foto bukti bayar, dan menyediakan aksi **Verifikasi pembayaran**, **Tolak** (dengan alasan wajib), dan **Kirim ke dapur** (aktif hanya setelah terverifikasi), masing-masing hanya untuk peran yang berhak dan masing-masing aman dari klik ganda.
- **FR-036**: Struk pelanggan yang dibuka ulang MUST mencerminkan status terbaru: menunggu verifikasi, terverifikasi, diproses di dapur, atau ditolak (beserta alasan).

**Pelacakan**

- **FR-033**: Pembuatan QR meja dan penerimaan pesanan mandiri MUST tercatat di log aktivitas yang ada, tanpa memuat data pribadi pelanggan.

**Notifikasi di Android POS**

- **FR-037**: Aplikasi Android POS yang aktif pada outlet pesanan MUST membunyikan pengumuman suara berbahasa Indonesia dengan format "Pesanan dari meja {nama/nomor meja}" segera setelah pesanan mandiri berhasil dikirim pelanggan, dan hanya satu kali per pesanan per perangkat.
- **FR-038**: Aplikasi MUST menampilkan pemberitahuan visual yang memuat meja, nomor pesanan, dan total, dapat ditutup staf, dan tidak muncul lagi untuk pesanan yang sama setelah ditutup.
- **FR-039**: Notifikasi MUST hanya diterima perangkat pada outlet yang sama dengan pesanan, dan hanya dalam mode data (DEMO/LIVE) yang sama; tidak ada data pelanggan (nama, telepon, surel) di dalam pengumuman suara.
- **FR-040**: Banyak pesanan dalam waktu berdekatan MUST dimainkan berurutan tanpa saling menimpa; setelah offline atau baru dibuka, aplikasi MUST menampilkan jumlah pesanan mandiri yang menunggu, tidak memutar ulang semua suara.
- **FR-041**: Staf MUST dapat mematikan suara notifikasi di pengaturan aplikasi (pemberitahuan visual tetap muncul), dan pengaturan itu diingat per perangkat.

### Key Entities *(include if feature involves data)*

- **Pesanan mandiri**: pesanan biasa yang membawa penanda self-service, outlet, meja, cara layanan (Dine-In/Take Away), dan satu pembayaran QRIS berstatus menunggu verifikasi. Siklusnya: menunggu verifikasi → terverifikasi → dikirim ke dapur, atau ditolak.
- **QR meja**: representasi satu meja untuk dipindai; membawa alamat halaman, identitas meja dan outlet, serta tanda keaslian yang membuat perubahan isi dapat dikenali.
- **Meja (DiningTable)**: sudah ada; mendapat filter dan paginasi daftar, serta aksi pembuatan QR. Status aktif menentukan apakah QR-nya masih dapat dipakai.
- **Cara layanan (service type)**: nilai pada pesanan yang sudah mengenal Dine-In dan Take Away; kini dipilih kasir pada POS dan oleh pelanggan mandiri.
- **Bukti bayar (PaymentProof)**: sudah ada; kini juga dapat diunggah oleh pelanggan publik dan hanya dibaca staf berwenang.
- **Pelanggan (Customer)**: sudah ada; dapat terbentuk atau terpakai ulang dari data diri halaman publik. Pesanan tamu tidak punya pelanggan.
- **Kanal pembayaran QRIS**: sudah ada; sumber gambar QRIS yang ditampilkan.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pelanggan baru dapat menyelesaikan pesanan 2 item (dari memindai QR hingga struk tampil) dalam waktu kurang dari 3 menit di ponsel dengan koneksi seluler biasa, tidak termasuk waktu membayar di aplikasi bank.
- **SC-002**: Minimal 90% pelanggan uji menyelesaikan pesanan pada percobaan pertama tanpa bantuan staf.
- **SC-003**: Daftar menu pertama terlihat dan dapat dipakai dalam 3 detik setelah QR dipindai pada koneksi seluler biasa.
- **SC-004**: Halaman pesan mandiri dapat dipakai penuh (tanpa gulir mendatar dan tanpa kontrol terpotong) pada lebar layar 360 px sampai 430 px.
- **SC-005**: 100% pesanan hasil halaman ini memiliki penanda self-service, outlet, meja, cara layanan, dan bukti bayar; 0% pesanan dapat dibuat untuk meja/outlet yang tidak ada pada QR yang dipindai.
- **SC-006**: 0 pesanan ganda dari kirim ulang atau klik ganda pada uji 50 pengiriman berulang.
- **SC-007**: Staf dapat membuat QR satu meja dalam kurang dari 30 detik dan QR seluruh meja satu outlet (sampai 50 meja) dalam satu langkah massal.
- **SC-008**: Staf menemukan satu meja di daftar berisi 100 meja dalam kurang dari 15 detik memakai pencarian atau filter.
- **SC-009**: 100% transaksi baru di POS web dan Android memiliki cara layanan Dine-In atau Take Away; transaksi lama tetap tampil tanpa perubahan angka laporan.
- **SC-010**: Tidak ada data pribadi pelanggan, biaya/HPP, atau data staf yang dapat diambil dari halaman publik tanpa QR/tautan struk yang sah, diuji dengan percobaan akses tanpa kredensial.
- **SC-011**: 0 tiket dapur tercipta dari pesanan mandiri yang pembayarannya belum diverifikasi, dan 0 stok terpotong dari pesanan yang ditolak.
- **SC-012**: Pada koneksi normal, pengumuman suara "Pesanan dari meja X" terdengar di tablet outlet dalam 10 detik setelah pelanggan menekan kirim, pada 95% pesanan uji; 0 pengumuman terdengar di outlet lain.

## Assumptions

- Pesan mandiri berlaku **per outlet dan per meja**; satu instalasi tetap satu server dan satu database per organisasi (konstitusi 2.0.0). Tidak ada akun pelanggan dan tidak ada riwayat lintas perangkat.
- Pelanggan memakai peramban ponsel biasa dan harus dapat menjangkau server toko. Untuk instalasi lokal (laptop toko), ini berarti ponsel pelanggan berada di jaringan Wi-Fi yang sama atau server punya alamat publik yang dapat dijangkau; alamat itu diatur sekali oleh pemilik. Mengatur jaringan/domain itu sendiri **di luar cakupan** fitur ini; fitur hanya mewajibkan alamat QR bukan `localhost`.
- Keputusan pemilik (2026-11-03): pesanan mandiri ditahan sampai staf memverifikasi bukti bayar, lalu staf mengirim ke dapur; tab **Self-Service** di daftar pesanan staf adalah tempat kerjanya. Verifikasi dan kirim ke dapur adalah dua aksi terpisah. ASUMSI: tab ini ada di daftar pesanan staf web; Android tetap hanya mendapat pilihan Dine-In/Take Away, kecuali Anda ingin tab yang sama di Android.
- ASUMSI: pesanan yang ditolak dibatalkan dengan alasan wajib dan tidak dapat dibuka lagi; pelanggan yang salah tolak harus memesan ulang.
- Struk pelanggan **tidak** menyatakan lunas sebelum staf memverifikasi bukti bayar, karena bukti dari pelanggan belum tentu valid. Verifikasi memakai alur pembayaran QRIS tertunda yang sudah ada.
- Pesanan mandiri adalah pesanan terpisah dan **tidak** digabung ke tab meja yang mungkin sedang terbuka dari kasir; ia juga tidak membuat tab baru. Penggabungan atau pembayaran bersama di luar cakupan.
- Cara layanan bawaan: **Dine-In** pada halaman pelanggan dan pada POS; kasir cukup satu ketukan untuk mengganti. Transaksi berbasis meja di Android tetap Dine-In. Nilai ini sudah dikenal sistem sehingga laporan yang ada tidak berubah. ASUMSI: kasir tidak dipaksa memilih bila bawaan sudah benar.
- Bila pelanggan mengisi data diri: nama dan telepon wajib, surel opsional. Pencocokan pelanggan lama memakai telepon; aturan rincinya ditetapkan saat perencanaan.
- Foto bukti bayar: gambar umum (JPEG/PNG/HEIC dari ponsel) dengan batas ukuran wajar yang ditetapkan saat perencanaan; foto kamera ponsel standar harus lolos.
- Bahasa selalu Indonesia; tidak ada pilihan bahasa di halaman pelanggan.
- Kebijakan keamanan: QR ditandatangani/tak-tertebak, endpoint publik dibatasi lajunya (pola pembatas bernama yang sudah ada untuk rute tanpa token), dan pengecualian Konstitusi IV untuk rute tanpa token dicatat seperti pada 073. Rincian teknis ditetapkan di rencana.
- Android: mendapat pilihan Dine-In/Take Away dan notifikasi suara pesanan mandiri (US8); Android tidak menjadi tempat pesan mandiri dan, sesuai asumsi di atas, tidak mendapat tab Self-Service. ASUMSI: pemberitahuan di Android hanya memberi tahu; memeriksa, memverifikasi, dan mengirim ke dapur tetap dilakukan di web staf. Bila pemberitahuan harus membuka detail pesanan di Android, itu menambah cakupan.
- ASUMSI: pengumuman suara memakai suara sintesis perangkat berbahasa Indonesia; bila perangkat tidak punya suara Indonesia, dipakai nada pendek sebagai cadangan. Pemberitahuan berlaku untuk aplikasi yang sedang terbuka pada outlet itu, termasuk saat layar PIN; terminal yang belum disiapkan ke outlet tidak menerimanya.
- Cara pesanan sampai ke tablet (pengecekan berkala atau dorongan langsung) ditetapkan di rencana; spec hanya mensyaratkan batas waktu SC-012. Verifikasi di tablet/peramban nyata dilakukan manusia dan dicatat di bukti fitur.
- **Asumsi tambahan dari perencanaan dan implementasi (2026-11-03):** (1) yang menekan "Kirim ke dapur" wajib punya **shift terbuka di outlet pesanan** (order butuh shift); (2) struk memakai **nomor mandiri** `SS-YYMMDD-NNN` sejak kirim dan nomor order POS menyusul setelah dikirim ke dapur (menggantikan "penomoran yang sama" di FR-016 demi tak membuat celah urutan resmi saat pesanan ditolak); (3) bila harga atau pajak berubah antara kirim dan dispatch, dispatch **ditolak** (`total_changed`) dan staf menolak pesanan; pelanggan tak pernah ditagih selisih; (4) **hanya pemegang izin verifikasi QRIS** yang dapat memverifikasi/menolak (peran Kasir bawaan tidak memegangnya; jalur persetujuan penyelia tidak ikut); (5) tab Self-Service hanya di web; Android hanya memberi tahu; (6) Dine-In/Take Away pada tablet dipilih di editor pesanan (tab yang sudah ada), bukan sebelum tab dibuka.
- Di luar cakupan: diskon, metode bayar selain QRIS, pembayaran terverifikasi otomatis oleh payment gateway, melihat status/riwayat pesanan lain, pesan ulang dari struk, notifikasi surel/WhatsApp, dan penggabungan dengan tab meja.
- Bergantung pada: data meja dan outlet (028, 045), kanal pembayaran QRIS dan bukti bayar (alur 084), identitas toko per outlet (079), pajak (083), ketersediaan menu dan modifier (043, 086, 089), serta utilitas unduh dokumen (`utils/pdfCapture.js`, 029).
