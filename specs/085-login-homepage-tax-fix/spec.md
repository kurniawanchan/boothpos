# Feature Specification: Beranda Login Dua Kolom & Perbaikan Hitungan Pajak

**Feature Branch**: `085-login-homepage-tax-fix`

**Created**: 2026-10-30

**Status**: Draft

**Input**: User description: "13. Homepage (Login / Outlet Selection Screen): two-column layout, outlet info section with background image, user card selection, 'Ganti outlet/terminal' as button, larger PIN area, remove gear icon. 14. Tax Calculation: fix tax so it follows Settings and is identical on Web POS and Android POS."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pajak sama persis di Web dan Android (Priority: P1)

Pemilik mengatur tarif pajak di Pengaturan. Kasir menjual pesanan yang sama di POS Web dan di tablet Android. Angka pajak, total, ringkasan pembayaran, dan struk harus identik di kedua aplikasi, dan selalu sesuai tarif yang tersimpan di Pengaturan.

**Why this priority**: Ini perbaikan kebenaran uang. Selisih pajak antar-aplikasi merusak laporan, struk, dan kepercayaan pelanggan. Lebih mendesak daripada tampilan.

**Independent Test**: Atur tarif tertentu di Pengaturan, susun pesanan yang sama (item sama, diskon sama) di Web dan Android, lalu bandingkan pajak, total, layar pembayaran, detail pesanan, dan struk. Semua angka harus sama.

**Acceptance Scenarios**:

1. **Given** tarif pajak 11% di Pengaturan, **When** kasir membuat pesanan dengan item dan diskon yang sama di Web dan Android, **Then** jumlah pajak dan total kedua pesanan sama persis.
2. **Given** pesanan sudah dibuat, **When** dilihat di keranjang, layar pembayaran, detail pesanan, dan struk (layar maupun cetak), **Then** angka pajak sama di semua tempat itu.
3. **Given** pemilik mengubah tarif di Pengaturan, **When** kasir membuat pesanan berikutnya, **Then** pesanan baru memakai tarif baru, sedangkan pesanan lama tidak berubah.
4. **Given** tarif 0% atau baris pajak disembunyikan, **When** pesanan dibuat, **Then** pajak tetap mengikuti aturan yang sudah berlaku (disembunyikan hanya menyembunyikan baris, tidak mengubah tagihan).
5. **Given** pesanan memuat modifier atau diskon, **When** pajak dihitung, **Then** dasar pengenaan pajak sama di kedua aplikasi.
6. **Given** tarif pajak diatur 100% (atau tarif bukan 10% lainnya, mis. 5% dan 2,5%), **When** kasir membuat pesanan di Web dan di Android, **Then** pajak sama dengan tarif yang diatur, bukan 10%, di keranjang, pembayaran, detail pesanan, dan struk.

---

### User Story 2 - Beranda login dua kolom dengan info outlet (Priority: P2)

Saat tablet dinyalakan atau shift berganti, kasir melihat beranda login yang jelas: kolom kiri memuat info outlet di atas foto latar dan kartu pengguna di bawahnya; kolom kanan memuat area PIN yang lebih besar.

**Why this priority**: Meningkatkan kecepatan dan kenyamanan login harian, tetapi tidak mengubah angka uang.

**Independent Test**: Buka beranda login pada tablet. Pastikan semua info outlet tampil terbaca di atas foto latar, jam berjalan tiap detik, kartu pengguna seragam, dan PIN bisa dimasukkan.

**Acceptance Scenarios**:

1. **Given** beranda login terbuka, **When** halaman tampil, **Then** ada dua kolom; kolom pertama terbagi atas-bawah menjadi bagian Info Outlet dan bagian Pilih Pengguna.
2. **Given** bagian Info Outlet, **When** tampil, **Then** terlihat logo, nama outlet, mode sistem, nama terminal, tanggal hari ini, jam berjalan, dan status online, semuanya terbaca jelas di atas foto latar.
3. **Given** jam ditampilkan, **When** satu menit berlalu, **Then** jam bertambah tiap detik tanpa muat ulang.
4. **Given** koneksi terputus atau pulih, **When** status berubah, **Then** penanda online/offline ikut berubah.
5. **Given** beberapa pengguna aktif, **When** bagian Pilih Pengguna tampil, **Then** tiap kartu berukuran dan berjarak sama; baris atas berisi foto/avatar, baris bawah berisi nama dan jabatan.
6. **Given** nama atau jabatan panjang, **When** kartu tampil, **Then** ukuran kartu tetap sama dan teks dipotong rapi, bukan melebar.
7. **Given** pengguna dipilih, **When** kasir mengetik PIN, **Then** area PIN lebih luas dan tombol angka lebih besar dari sebelumnya.

---

### User Story 3 - Ganti outlet/terminal sebagai tombol jelas (Priority: P3)

Aksi "Ganti outlet / terminal" tampil sebagai tombol yang jelas, dan ikon roda gigi dihapus dari beranda sehingga hanya ada satu jalan menuju aksi itu.

**Why this priority**: Perapian kecil; aksinya jarang dipakai.

**Independent Test**: Buka beranda login; pastikan tombol terlihat jelas, ikon roda gigi tidak ada, dan tombol membuka layar penyiapan outlet/terminal.

**Acceptance Scenarios**:

1. **Given** beranda login, **When** tampil, **Then** "Ganti outlet / terminal" berbentuk tombol yang jelas terlihat, bukan teks biasa.
2. **Given** beranda login, **When** tampil, **Then** tidak ada ikon roda gigi/pengaturan.
3. **Given** tombol ditekan, **When** layar penyiapan terbuka, **Then** perilakunya sama seperti sebelumnya.

---

### Edge Cases

- Tarif pajak bukan bilangan bulat (mis. 2,5%) atau menghasilkan pecahan rupiah: pembulatan harus sama di Web dan Android.
- Pesanan dengan diskon yang menurunkan dasar pajak ke 0: pajak 0, tanpa nilai negatif.
- Tarif diubah saat pesanan masih terbuka (tab belum dibayar): [lihat Asumsi].
- Tablet lama (APK lama) yang tidak mengenal pengaturan pajak terbaru tetap menampilkan angka dari server.
- Tidak ada foto outlet: bagian Info Outlet memakai foto bawaan, teks tetap terbaca.
- Hanya satu pengguna, atau sangat banyak pengguna: kartu tetap seragam; daftar bisa digulir.
- Tablet orientasi potret atau layar sempit: dua kolom tetap dapat dipakai (susunan menyesuaikan, PIN tetap besar).
- Pengguna tanpa foto: avatar inisial tampil dengan ukuran sama.
- Perangkat offline: jam dan tanggal tetap berjalan, status menunjukkan offline.

## Requirements *(mandatory)*

### Functional Requirements

**Beranda login**

- **FR-001**: Beranda login MUST berupa tata letak dua kolom.
- **FR-002**: Kolom pertama MUST terbagi menjadi dua bagian bertumpuk atas-bawah: Info Outlet (atas) dan Pilih Pengguna (bawah).
- **FR-003**: Bagian Info Outlet MUST menampilkan logo outlet, nama outlet, mode sistem, nama terminal, tanggal saat ini, jam berjalan, dan status online.
- **FR-004**: Bagian Info Outlet MUST memakai gambar outlet sebagai latar, dan semua teks/ikon di atasnya MUST tetap terbaca (kontras memadai).
- **FR-005**: Jam MUST diperbarui terus-menerus tanpa tindakan pengguna.
- **FR-006**: Status online MUST mengikuti kondisi koneksi saat itu.
- **FR-007**: Bagian Pilih Pengguna MUST menampilkan pengguna yang tersedia sebagai kartu; tiap kartu terdiri dari dua baris bertumpuk: avatar (atas), nama dan jabatan (bawah).
- **FR-008**: Semua kartu pengguna MUST berukuran, berjarak, dan sejajar sama.
- **FR-009**: Aksi "Ganti outlet / terminal" MUST tampil sebagai tombol yang jelas terlihat.
- **FR-010**: Area input PIN MUST lebih luas dan lebih mencolok dari sebelumnya.
- **FR-011**: Ikon roda gigi/pengaturan MUST dihapus dari beranda login.
- **FR-012**: Alur login (pilih pengguna, ketik PIN, penguncian PIN, mode DEMO/LIVE, pembatasan akses tanpa token) MUST tidak berubah.

**Pajak**

- **FR-013**: Jumlah pajak MUST dihitung dari tarif yang tersimpan di Pengaturan pada saat pesanan dibuat.
- **FR-014**: Web dan Android MUST menghasilkan jumlah pajak dan total yang identik untuk pesanan yang setara (item, diskon, modifier, tarif sama).
- **FR-015**: Angka pajak MUST konsisten di keranjang, ringkasan pembayaran, detail pesanan, struk layar, dan struk cetak.
- **FR-016**: Perubahan tarif di Pengaturan MUST berlaku pada pesanan berikutnya dan MUST NOT mengubah pesanan yang sudah tercatat.
- **FR-017**: Tidak boleh ada tarif pajak atau aturan hitung yang ditulis tetap di kode dan mengalahkan Pengaturan, termasuk cadangan, label bawaan bertarif, dan pratinjau di sisi klien.
- **FR-018**: Aturan "baris pajak disembunyikan tetap ditagih" dan snapshot pajak per pesanan MUST tetap berlaku.
- **FR-019**: Pembulatan pajak MUST satu aturan yang sama untuk semua aplikasi dan semua tempat tampil.
- **FR-020**: Untuk SETIAP tarif yang sah di Pengaturan (0–100%, termasuk 100% dan tarif berdesimal), pajak yang dihitung MUST sama dengan tarif itu. Gejala yang dilaporkan: tarif diatur 100% tetapi pajak tetap dihitung 10%. Penyebab MUST ditemukan dengan mereproduksi gejala lebih dulu (di Web, di Android, dan di server) sebelum perbaikan diterapkan.

### Key Entities

- **Pengaturan Pajak**: tarif (persen), label, dan tombol tampil per instalasi, terpisah untuk mode DEMO dan LIVE.
- **Pesanan**: menyimpan jumlah pajak dan tarif saat dibuat; tidak berubah bila pengaturan berganti.
- **Outlet**: nama, logo, dan foto latar yang tampil di beranda login.
- **Terminal**: nama perangkat yang tampil di bagian Info Outlet.
- **Pengguna (staf)**: nama, jabatan, dan foto yang tampil di kartu pengguna.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Untuk himpunan pesanan uji berisi minimal 20 kombinasi (tarif bulat dan pecahan, dengan/tanpa diskon, dengan/tanpa modifier), selisih pajak dan total antara Web dan Android adalah Rp0 pada 100% kasus.
- **SC-002**: Pada 100% pesanan uji, angka pajak di keranjang, pembayaran, detail pesanan, dan struk sama dengan angka yang tercatat.
- **SC-003**: Setelah tarif diubah, 100% pesanan berikutnya memakai tarif baru dan 0 pesanan lama berubah.
- **SC-004**: Pemeriksaan kode tidak menemukan tarif pajak yang ditulis tetap yang memengaruhi tagihan.
- **SC-005**: Kasir dapat memilih pengguna dan menyelesaikan login dengan PIN tanpa bantuan pada percobaan pertama.
- **SC-006**: Semua informasi Info Outlet terbaca jelas pada tablet uji dalam orientasi lanskap dan potret.
- **SC-007**: Jam di beranda tidak tertinggal lebih dari 1 detik dari waktu perangkat.
- **SC-008**: Dengan 1 sampai 12 pengguna, semua kartu pengguna berukuran sama.

## Assumptions

- "Homepage (Login / Outlet Selection)" adalah layar PIN/pilih pengguna di aplikasi Android POS; login web berada di luar cakupan.
- Dua kolom: kolom pertama berisi Info Outlet dan Pilih Pengguna; kolom kedua berisi area PIN.
- Foto pada `outlet-background-reference.png` dipakai sebagai latar bawaan; tidak ada pengelolaan unggah foto outlet baru dalam fitur ini.
- Jabatan pengguna diambil dari peran yang sudah ada (owner/admin tidak tampil, sesuai aturan daftar staf tanpa token).
- Pajak tetap berupa satu tarif per instalasi per mode, dihitung setelah diskon, dibulatkan ke rupiah penuh per pesanan (aturan sekarang). Tidak ada pengaturan "metode hitung" baru: pemilik menegaskan masalahnya adalah tarif, bukan metode.
- ASSUMPTION (belum terbukti, harus direproduksi pada tahap rencana): pembacaan kode statis tidak menemukan angka 10% yang hidup di jalur server maupun Web. Kandidat penyebab: (a) APK lama yang masih membawa cadangan 10% (fitur 083 sudah menghapusnya dari kode, tetapi perangkat bisa belum diperbarui); (b) [gugur saat implementasi: pajak satu nilai untuk DEMO dan LIVE, jadi tarif tidak bisa "tersimpan di mode lain"]; (c) nilai tarif lama tertahan di cache setelah disimpan; (d) pratinjau sisi klien memakai nilai usang (terbukti mungkin: AppShell memuat pengaturan sekali, lihat research H2). Ini hipotesis, bukan temuan.
- Tab terbuka yang belum dibayar memakai tarif saat total dihitung ulang oleh server; pesanan yang sudah dibayar tidak berubah.
- APK lama tetap bekerja; mereka menampilkan angka dari server dan tidak wajib berubah.
- Perubahan antarmuka memakai istilah baku Kasir/Pesanan/Koneksi/Tunai dari fitur 084.
- Verifikasi di perangkat fisik dilakukan terpisah; spec ini tidak mengklaim sudah terverifikasi di tablet.
