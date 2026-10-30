# Feature Specification: Perbaikan UI/UX Kasir, Pesanan, Shift, dan Koneksi

**Feature Branch**: `084-ui-ux-kasir-pesanan-improvements`

**Created**: 2026-10-30

**Status**: Draft

**Input**: User description: "Warkop WJM – UI/UX Improvements, Functional Enhancements, and Bug Fixes" (navbar, istilah baku, menu navigasi, layar Meja, login, layar Kasir termasuk modifier/pilih meja/QRIS, layar Pembayaran, Pesanan, Koneksi, Shift, penutupan shift, cetak struk).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Kasir bekerja dari layar Kasir dengan modifier dan meja (Priority: P1)

Setelah login, kasir langsung masuk ke layar Kasir. Kasir memilih menu, memilih modifier item (sama seperti di aplikasi Android), memilih atau mengganti Meja untuk pesanan, lalu membayar. Modifier dan meja terlihat konsisten di keranjang, detail pesanan, struk, dan data Meja.

**Why this priority**: Ini alur transaksi utama. Modifier dan pilih meja di web adalah kemampuan baru yang paling berdampak; tanpanya kasir web tidak setara dengan kasir Android.

**Independent Test**: Login sebagai kasir, pastikan mendarat di Kasir, tambah item bermodifier, pilih Meja, bayar, lalu cek modifier dan meja muncul di struk, detail Pesanan, dan layar Meja.

**Acceptance Scenarios**:

1. **Given** kasir berhasil login, **When** halaman pertama tampil, **Then** layar yang terbuka adalah Kasir.
2. **Given** sebuah menu punya grup modifier, **When** kasir memilih menu itu, **Then** pilihan modifier tampil dan aturannya (wajib/opsional, jumlah pilihan) sama dengan di Android.
3. **Given** item dengan modifier ada di keranjang, **When** kasir melihat keranjang, ringkasan pesanan, detail pesanan, dan struk (cetak maupun digital), **Then** modifier tampil di bawah item di semua tempat itu.
4. **Given** pesanan belum dibayar, **When** kasir memilih atau mengganti Meja di layar Kasir, **Then** layar Meja menampilkan pesanan itu pada meja yang dipilih dan meja sebelumnya kembali bebas.
5. **Given** sebuah menu hanya punya satu varian bernama "Standard", **When** menu tampil di Kasir, keranjang, atau struk, **Then** label "Standard" tidak ditampilkan.
6. **Given** kasir memilih pembayaran QRIS, **When** pembayaran dicatat, **Then** pembayaran langsung berstatus Terverifikasi tanpa langkah verifikasi manual.

---

### User Story 2 - Pesanan: tinjau pesanan selesai dan ubah meja (Priority: P2)

Di layar Pesanan, pesanan berstatus Selesai menampilkan informasi lengkap (item, modifier, pembayaran, total, pajak, meja) agar transaksi bisa ditinjau. Pesanan Terbuka maupun Selesai punya pemilih Meja, dan perubahan meja langsung sinkron dengan layar Meja. Saat shift ditutup, pesanan shift itu hilang dari daftar Pesanan aktif.

**Why this priority**: Menutup celah peninjauan transaksi dan menjaga konsistensi data Meja dan Pesanan, tetapi tidak menghalangi transaksi baru.

**Independent Test**: Buka pesanan Selesai dan pastikan semua rincian terlihat; ubah meja pada pesanan Terbuka dan Selesai lalu cek layar Meja; tutup shift lalu cek daftar Pesanan.

**Acceptance Scenarios**:

1. **Given** ada pesanan berstatus Selesai, **When** pengguna membukanya, **Then** semua informasi transaksi tampil lengkap.
2. **Given** pesanan Terbuka atau Selesai, **When** pengguna mengubah Meja, **Then** perubahan tersimpan dan layar Meja mencerminkannya.
3. **Given** shift ditutup, **When** pengguna membuka Pesanan, **Then** pesanan milik shift yang sudah ditutup tidak ada di daftar aktif, sedangkan datanya tetap ada di Penjualan dan laporan.

---

### User Story 3 - Penutupan shift dan laporan X/Z yang benar (Priority: P2)

Kasir mengisi nominal uang saat menutup shift tanpa kursor melompat, lalu mencetak Laporan X dan Laporan Z dengan berhasil. Istilah di laporan memakai "EDC" dan "Tunai".

**Why this priority**: Cetak laporan dan penutupan shift adalah kewajiban harian; bug di sini menghambat tutup toko.

**Independent Test**: Ketik, ubah, dan hapus nominal lebih dari tiga digit pada form tutup shift; cetak Laporan X dan Z.

**Acceptance Scenarios**:

1. **Given** form tutup shift terbuka, **When** kasir mengetik nominal lebih dari tiga digit, **Then** kursor selalu tetap di akhir input dan pemisah ribuan tampil benar.
2. **Given** kasir menyunting atau menghapus digit, **When** format angka diterapkan ulang, **Then** kursor tidak melompat dan nilai tetap benar.
3. **Given** shift berjalan atau sudah ditutup, **When** kasir mencetak Laporan X atau Z, **Then** laporan tercetak lengkap tanpa galat.

---

### User Story 4 - Struk tercetak benar dan Koneksi printer jelas (Priority: P2)

Struk tercetak tanpa karakter "?" pengganti, tanpa label "Standard", dan dengan modifier. Di layar Koneksi, kasir melihat status printer: Menyambung (dengan indikator progres), Tersambung, Terputus, atau Gagal tersambung.

**Why this priority**: Struk yang salah terbaca atau printer yang statusnya tidak jelas langsung mengganggu pelanggan, tetapi punya jalan keluar manual.

**Independent Test**: Cetak struk berisi karakter non-ASCII, item Standard, dan item bermodifier; putuskan dan sambungkan printer sambil mengamati status.

**Acceptance Scenarios**:

1. **Given** struk berisi karakter di luar set dasar (mis. tanda baca khusus pada nama menu), **When** dicetak, **Then** karakter tercetak benar atau diganti padanan yang terbaca, tidak pernah "?".
2. **Given** printer sedang menyambung, **When** layar Koneksi dibuka, **Then** indikator progres dan status "Menyambung" terlihat.
3. **Given** koneksi printer berubah, **When** status berubah, **Then** layar menampilkan status yang sesuai: Menyambung, Tersambung, Terputus, atau Gagal tersambung.

---

### User Story 5 - Navbar, istilah baku, dan navigasi (Priority: P3)

Navbar menampilkan "Shift dibuka {jam}" di samping logo, nama pengguna yang sedang login (bukan "Online"), jam yang berdetak langsung, dan tombol hamburger yang menampilkan atau menyembunyikan sidebar. Menu berurutan: Kasir, Meja, Pesanan, Shift, Koneksi. Istilah dibakukan: Counter→Kasir, Order→Pesanan, Perangkat→Koneksi, Transfer→EDC, Tunai bersih→Tunai.

**Why this priority**: Perbaikan tampilan dan istilah; bernilai tetapi tidak mengubah alur transaksi.

**Independent Test**: Buka aplikasi dan periksa navbar, urutan menu, dan seluruh teks layar terhadap tabel istilah.

**Acceptance Scenarios**:

1. **Given** shift sedang dibuka, **When** navbar tampil, **Then** teks di samping logo adalah "Shift dibuka {jam buka}" dan teks nama toko ganda tidak muncul lagi.
2. **Given** pengguna login, **When** navbar tampil, **Then** nama pengguna tampil menggantikan "Online".
3. **Given** halaman terbuka lebih dari satu menit, **When** waktu berjalan, **Then** jam navbar ikut berubah tiap detik atau menit tanpa muat ulang.
4. **Given** sidebar tampil, **When** pengguna menekan hamburger, **Then** sidebar tersembunyi, dan menekannya lagi menampilkannya kembali.
5. **Given** menu navigasi, **When** dilihat, **Then** urutannya Kasir, Meja, Pesanan, Shift, Koneksi.
6. **Given** layar mana pun (label, tombol, pesan, struk, detail transaksi), **When** diperiksa, **Then** tidak ada lagi "Counter", "Order", "Perangkat", "Transfer", atau "Tunai bersih" sebagai istilah antarmuka.

---

### User Story 6 - Perapian teks layar Kasir, Meja, dan Pembayaran (Priority: P3)

Layar Kasir menampilkan nomor saja (bukan "Counter {nomor}"), tanpa teks "Counter · panggil dengan nomor antrean · {nama}", dengan petunjuk "Pilih menu untuk menambah item" dan "Pilih dan tahan menu untuk menandai habis.". Layar Meja tidak lagi menampilkan "denah dari server · diperbarui tiap 3 dtk". Di Pembayaran, "Terima Tunai" pindah ke kolom pertama; logo EDC dan label "EDC" sebaris; petunjuk EDC berbunyi "Jangan lupa untuk cek bukti transaksi di aplikasi QRIS".

**Why this priority**: Perubahan kosmetik murni.

**Independent Test**: Bandingkan setiap layar dengan teks dan tata letak yang diminta.

**Acceptance Scenarios**:

1. **Given** layar Kasir, **When** dibuka, **Then** teks lama dihapus atau diganti sesuai daftar.
2. **Given** layar Meja, **When** dibuka, **Then** teks informasi denah tidak tampil.
3. **Given** layar Pembayaran tunai, **When** dibuka, **Then** "Terima Tunai" ada di kolom pertama.
4. **Given** layar Pembayaran EDC, **When** dibuka, **Then** logo EDC dan label "EDC" sebaris, dengan instruksi baru.

---

### Edge Cases

- Modifier wajib belum dipilih: item tidak bisa masuk keranjang dan alasannya jelas.
- Meja yang dipilih sedang dipakai pesanan lain atau tidak aktif: perubahan ditolak dengan pesan yang jelas, atau mengikuti aturan meja yang sudah ada.
- Pesanan Selesai diubah mejanya: hanya penempatan meja yang berubah, nominal dan pembayaran tidak ikut berubah.
- Shift ditutup saat masih ada pesanan Terbuka: perilaku mengikuti aturan tutup shift yang ada (lihat Asumsi).
- Menu bervarian lebih dari satu, termasuk salah satunya "Standard": label varian tetap tampil untuk membedakan.
- Printer tidak merespons: status Gagal tersambung muncul setelah batas waktu, indikator progres berhenti.
- Nominal tutup shift ditempel (paste) atau dihapus semua: kursor dan nilai tetap benar.
- Struk lama atau pesanan tanpa modifier: tetap tercetak seperti semula, tanpa baris modifier kosong.

## Requirements *(mandatory)*

### Functional Requirements

**Navbar dan navigasi**

- **FR-001**: Navbar MUST menampilkan "Shift dibuka {jam}" (jam shift dibuka) di samping logo, menggantikan teks nama toko yang terduplikasi.
- **FR-002**: Navbar MUST menampilkan nama pengguna yang sedang login menggantikan label "Online".
- **FR-003**: Tombol hamburger di kiri atas MUST menampilkan atau menyembunyikan sidebar navigasi.
- **FR-004**: Jam navbar MUST berjalan langsung tanpa muat ulang halaman.
- **FR-005**: Menu navigasi MUST berurutan: Kasir, Meja, Pesanan, Shift, Koneksi.
- **FR-006**: Setelah login berhasil, pengguna MUST diarahkan ke layar Kasir.

**Istilah baku**

- **FR-007**: Aplikasi MUST memakai istilah Kasir (bukan Counter), Pesanan (bukan Order), Koneksi (bukan Perangkat), EDC (bukan Transfer), dan Tunai (bukan Tunai bersih) di semua layar, label, tombol, pesan, struk, laporan, dan detail transaksi, baik web maupun Android.

**Layar Meja**

- **FR-008**: Layar Meja MUST tidak lagi menampilkan teks "denah dari server · diperbarui tiap 3 dtk".

**Layar Kasir**

- **FR-009**: Judul kasir MUST menampilkan nomornya saja, dan teks "Counter · panggil dengan nomor antrean · {nama pengguna}" MUST dihapus.
- **FR-010**: Petunjuk MUST berbunyi "Pilih menu untuk menambah item" dan "Pilih dan tahan menu untuk menandai habis.".
- **FR-011**: Label varian "Standard" MUST disembunyikan bila menu hanya punya satu varian bernama Standard, di Kasir, keranjang, pesanan, dan struk.
- **FR-012**: Web POS MUST mendukung modifier item dengan perilaku yang sama dengan aplikasi Android (grup, wajib/opsional, batas pilihan, harga tambahan bila ada).
- **FR-013**: Modifier MUST tampil konsisten di struk (cetak dan digital), detail pesanan, ringkasan pesanan, dan catatan transaksi lain yang memuat item.
- **FR-014**: Layar Kasir MUST menyediakan pemilih Meja yang memungkinkan memilih atau mengganti meja pesanan, dan pilihan itu MUST tercermin di data layar Meja.
- **FR-015**: Pembayaran QRIS di alur POS MUST langsung berstatus Terverifikasi tanpa validasi atau verifikasi manual.

**Layar Pembayaran**

- **FR-016**: "Terima Tunai" MUST berada di kolom pertama.
- **FR-017**: Logo EDC dan label "EDC" MUST sebaris.
- **FR-018**: Instruksi EDC MUST berbunyi "Jangan lupa untuk cek bukti transaksi di aplikasi QRIS".

**Pesanan**

- **FR-019**: Pesanan berstatus Selesai MUST menampilkan informasi lengkap transaksi.
- **FR-020**: Pesanan Terbuka dan Selesai MUST punya pemilih Meja yang dapat diubah, dan perubahannya MUST sinkron dengan layar Meja.
- **FR-021**: Saat shift ditutup, pesanan shift itu MUST keluar dari daftar Pesanan aktif tanpa menghapus catatan penjualan, pembayaran, dan laporan.

**Koneksi**

- **FR-022**: Layar Koneksi MUST menampilkan indikator progres yang terlihat selama printer masih menyambung.
- **FR-023**: Layar Koneksi MUST menampilkan salah satu status: Menyambung, Tersambung, Terputus, Gagal tersambung.

**Shift**

- **FR-024**: Cetak Laporan X MUST berfungsi dengan benar.
- **FR-025**: Cetak Laporan Z MUST berfungsi dengan benar.
- **FR-026**: Form tutup shift MUST menjaga kursor di posisi yang benar saat mengetik, menyunting, menghapus, dan menempel nominal, termasuk saat pemisah ribuan diterapkan.

**Struk**

- **FR-027**: Struk tercetak MUST tidak menampilkan "?" untuk karakter yang tidak didukung printer; karakter dicetak benar atau diganti padanan yang terbaca.
- **FR-028**: Struk MUST menyembunyikan varian "Standard" tunggal dan menampilkan modifier.

**Persyaratan lintas fitur**

- **FR-029**: Alur transaksi, pembayaran, cetak, dan shift yang ada MUST tetap berfungsi tanpa regresi.
- **FR-030**: Data MUST konsisten antara Kasir, Meja, Pesanan, dan Shift.

### Key Entities

- **Pesanan**: transaksi yang berisi item, modifier, meja, pembayaran, status (Terbuka/Selesai), dan shift asal.
- **Modifier item**: pilihan tambahan atau variasi pada item pesanan (grup dan opsi) yang sudah ada di Android.
- **Meja**: penempatan fisik pesanan; satu pesanan punya paling banyak satu meja, dipakai bersama oleh Kasir, Pesanan, dan Meja.
- **Shift**: periode kerja kasir dengan jam buka, laporan X/Z, dan penutupan.
- **Koneksi printer**: status sambungan perangkat cetak (Menyambung, Tersambung, Terputus, Gagal tersambung).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% teks antarmuka (web, Android, struk, laporan) bebas dari lima istilah lama; diverifikasi lewat penelusuran teks dan pemeriksaan layar.
- **SC-002**: Kasir dapat menambah item bermodifier, memilih meja, dan membayar dalam satu alur tanpa berpindah layar, dan modifier serta meja muncul di 100% keluaran terkait (keranjang, detail pesanan, struk, layar Meja).
- **SC-003**: 100% struk uji yang memuat karakter non-ASCII tercetak tanpa "?".
- **SC-004**: Laporan X dan Z berhasil tercetak pada 100% percobaan uji (shift berjalan dan sudah ditutup).
- **SC-005**: Pada 20 percobaan mengetik/menyunting/menghapus nominal lebih dari tiga digit, kursor tetap benar pada semuanya.
- **SC-006**: Setelah tutup shift, 0 pesanan shift itu tersisa di daftar Pesanan aktif, dan total di Penjualan serta laporan tidak berubah.
- **SC-007**: Status printer di layar Koneksi berubah mengikuti kondisi nyata dalam 2 detik, dan indikator progres terlihat sepanjang proses menyambung.
- **SC-008**: Suite pengujian backend, frontend, dan alur pembayaran/cetak/shift yang ada tetap lulus.

## Assumptions

- **Cakupan (dikoreksi 2026-10-30 saat perencanaan):** hampir semua butir (navbar, istilah, menu, Kasir, Meja, Pembayaran, Pesanan, Koneksi, Shift, tutup shift, struk) adalah perubahan **aplikasi Android POS**, tempat teks-teks itu berada. Pengecualian atas keputusan pemilik: **modifier dibangun di POS web (`PosView`)** mengikuti perilaku Android yang sudah ada dan memakai data modifier yang sama; Android tidak diubah untuk modifier selain memastikan tampilannya. Kata "web" pada butir lain di atas dibaca sebagai "aplikasi POS".
- Cetak Laporan X dan Z saat ini belum tersedia di Android (tombol hanya menampilkan "Cetak belum tersedia"); "memperbaiki" berarti membangunnya.
- Hamburger menyembunyikan sidebar hanya pada layar lebar; layar sempit memakai navigasi bawah tanpa sidebar.
- Jam yang statis hanya ada di layar PIN; header aplikasi belum punya jam, sehingga jam hidup ditambahkan di header dan dipakai ulang di layar PIN.
- Kata benda "perangkat" yang berarti "tablet ini" dalam kalimat ditulis ulang, bukan diganti mentah menjadi "Koneksi"; "Koneksi" dipakai untuk nama menu dan layar.
- Pembayaran QRIS lama yang masih menunggu verifikasi tetap dapat diverifikasi; hanya pembayaran baru yang otomatis Terverifikasi.
- "Clear order data" saat tutup shift berarti pesanan hilang dari daftar Pesanan aktif, bukan dihapus; penjualan, pembayaran, dan laporan tetap utuh (konsisten dengan aturan bahwa catatan penjualan tidak dihapus).
- Pesanan yang masih Terbuka saat shift ditutup mengikuti aturan tutup shift yang sudah ada; spesifikasi ini tidak mengubah aturan itu.
- QRIS otomatis Terverifikasi hanya untuk alur POS; metode lain tidak berubah.
- Status QRIS lama tetap terbaca; data pembayaran QRIS yang sudah ada tidak dimigrasi ulang.
- Perubahan Meja pada pesanan Selesai hanya mengubah penempatan, tidak mengubah nominal, pembayaran, atau stok.
- Karakter yang tidak didukung printer diganti padanan terdekat (mis. tanda baca tipografis ke ASCII) bila tidak bisa dicetak langsung.
- Jam navbar memakai zona waktu bisnis yang sudah dipakai aplikasi (WIB).
- Pajak, harga, dan total tidak berubah oleh fitur ini.
