# Feature Specification: Hubungkan Aplikasi Android POS ke Data Server

**Feature Branch**: `048-wire-android-pos-api`

**Created**: 2026-10-15

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "start part 2: wire login, roster, menu and other data from database to android app with API. /goal make sure the android app is all working."

## Konteks

Aplikasi POS Android (fitur 047) sudah meniru seluruh tampilan desain, tetapi berjalan di atas data contoh di memori: nama staf, PIN, menu, meja, order, pembayaran, dan angka shift tidak berasal dari server. Fitur ini menyambungkan setiap layar ke data sungguhan milik outlet, dan melengkapi kemampuan server yang belum ada (daftar B1–B21 di `specs/047-android-pos-tablet/api-gap.md`; B1 dan B15 sudah selesai) supaya **setiap** layar bekerja dengan data asli. Tujuan akhirnya: seorang kasir dapat menjalankan satu hari kerja penuh hanya dengan aplikasi ini, dan hasilnya tampil benar di back office.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Masuk dengan PIN memakai staf dan menu asli (Priority: P1)

Seorang kasir menyalakan terminal outlet. Aplikasi menampilkan staf outlet itu yang sebenarnya (bukan nama contoh), kasir memilih namanya, memasukkan PIN 6 digit, lalu langsung melihat menu, kategori, harga, modifier, dan ketersediaan (item habis) milik outlet tersebut.

**Why this priority**: Tanpa login dan menu sungguhan, tidak ada alur lain yang bermakna. Ini irisan terkecil yang sudah memberi nilai: terminal tahu siapa penggunanya dan apa yang dijual.

**Independent Test**: Dengan satu outlet yang memiliki 3 staf dan sebuah menu, buka aplikasi di terminal baru: daftar staf dan menu di layar sama dengan yang ada di back office; PIN benar masuk, PIN salah ditolak.

**Acceptance Scenarios**:

1. **Given** terminal sudah disiapkan untuk sebuah outlet, **When** layar PIN dibuka, **Then** hanya staf aktif outlet itu yang tampil, lengkap dengan peran masing-masing.
2. **Given** seorang staf memilih namanya, **When** memasukkan PIN yang benar, **Then** ia masuk dan diarahkan sesuai perannya (kasir tanpa shift → buka shift; waiter → meja; supervisor/manajer → daftar order).
3. **Given** PIN salah lima kali berturut-turut, **When** mencoba lagi, **Then** login staf itu terkunci 15 menit dan pesan yang tampil menyebut sisa waktu atau kunci, sementara staf lain tidak terpengaruh.
4. **Given** harga atau ketersediaan sebuah menu diubah di back office, **When** menu di terminal disegarkan, **Then** perubahan itu tampil tanpa memasang ulang aplikasi.
5. **Given** sebuah item ditandai habis di back office, **When** kasir mengetuknya, **Then** ia tidak bisa ditambahkan dan alasannya jelas.

---

### User Story 2 - Satu order dari meja sampai lunas memakai data asli (Priority: P1)

Kasir atau waiter membuka order (meja atau counter), menambah item beserta pilihan dan catatan, mengirim ke dapur, lalu menerima pembayaran tunai. Total, pajak, dan sisa tagihan dihitung server; struk dan nomor antrean berasal dari server.

**Why this priority**: Ini jalur uang. Tanpa ini aplikasi belum bisa dipakai berjualan.

**Independent Test**: Buka order di sebuah meja, tambah 3 item termasuk satu dengan modifier wajib, kirim ke dapur, bayar tunai dengan uang lebih; periksa di back office bahwa order, baris, tiket dapur, pembayaran, dan stok tercatat benar, dan total sama persis dengan yang tampil di tablet.

**Acceptance Scenarios**:

1. **Given** meja kosong, **When** kasir memilih jumlah tamu, **Then** order terbuka di server dan meja tampil terisi di terminal lain yang melihat denah yang sama.
2. **Given** order dengan item, **When** koneksi putus sebentar lalu tombol diketuk ulang, **Then** tidak ada baris atau tiket dapur ganda.
3. **Given** item dengan grup modifier wajib, **When** kasir mencoba menambah tanpa memilih, **Then** ditolak dengan petunjuk grup mana yang kurang; server juga menolak jika permintaan dikirim tanpa pilihan itu.
4. **Given** order terkirim ke dapur, **When** pembayaran tunai diterima lebih dari total, **Then** kembalian dihitung dan order tertutup dengan nomor struk dari server.
5. **Given** dua terminal membuka meja yang sama hampir bersamaan, **When** keduanya mencoba membuat order, **Then** hanya satu yang berhasil dan yang lain diberi tahu meja sudah terisi.

---

### User Story 3 - Shift, kas, dan laporan harian nyata (Priority: P2)

Kasir membuka shift dengan modal per pecahan, mencatat kas masuk/keluar dengan alasan, melihat X-report dari data shift sebenarnya, dan menutup shift dengan hitung buta; selisih terhadap angka sistem dan batch EDC ditentukan server.

**Why this priority**: Diperlukan untuk menutup hari dengan benar, tetapi penjualan (P1) bisa berjalan lebih dulu.

**Independent Test**: Buka shift dengan modal Rp 150.000, lakukan 2 penjualan tunai dan 1 kas keluar, hitung kas: angka "seharusnya" yang tampil setelah kirim hitungan sama dengan perhitungan manual (modal + tunai + kas masuk − kas keluar).

**Acceptance Scenarios**:

1. **Given** tidak ada shift terbuka, **When** kasir mencoba menerima pembayaran, **Then** ditolak sampai shift dibuka.
2. **Given** shift berjalan, **When** X-report dibuka, **Then** angkanya sama dengan data transaksi shift itu dan shift tetap terbuka.
3. **Given** ada pembayaran QRIS belum diverifikasi, **When** kasir menutup shift, **Then** ditolak dengan alasan.
4. **Given** selisih hitungan melebihi toleransi, **When** hitungan dikirim, **Then** diperlukan persetujuan atasan sebelum shift ditutup.

---

### User Story 4 - Persetujuan atasan dan metode bayar non-tunai (Priority: P2)

Aksi sensitif (void item terkirim, batal order, diskon di atas batas, kas keluar besar, cetak ulang, verifikasi QRIS, refund, tandai item habis) meminta PIN supervisor/manajer yang berbeda dari peminta. Pembayaran EDC memakai kode approval, QRIS dicatat tertunda lalu diverifikasi dengan referensi.

**Why this priority**: Aturan kontrol uang dan audit; tanpa ini aplikasi hanya setengah dipercaya, tetapi penjualan tunai tetap bisa jalan.

**Independent Test**: Kasir mencoba void item terkirim: dialog persetujuan muncul, PIN supervisor benar menjalankan aksi dan tercatat siapa menyetujui; PIN supervisor yang sama dengan peminta ditolak.

**Acceptance Scenarios**:

1. **Given** aksi yang membutuhkan persetujuan, **When** supervisor memasukkan PIN benar, **Then** persetujuan hanya berlaku untuk aksi itu dan tercatat di jejak audit.
2. **Given** persetujuan sudah dipakai atau kedaluwarsa, **When** dipakai lagi untuk aksi lain, **Then** ditolak.
3. **Given** pembayaran EDC dengan kode approval yang sama dan nominal yang sama dalam 2 menit, **When** dicatat lagi, **Then** pengguna diminta konfirmasi "mungkin ganda".
4. **Given** QRIS tertunda pada sebuah order, **When** kasir memilih metode non-tunai lain untuk order itu, **Then** ditolak sampai QRIS diverifikasi atau ditandai gagal.

---

### User Story 5 - Pajak, diskon, bagi bill, pra-bill, dan struk resmi (Priority: P3)

Total memuat pajak eksklusif dan diskon beralasan dengan batas per peran; bill bisa dibagi per item atau rata; pra-bill tercetak menandai meja "Bill tercetak"; struk bernomor per terminal dan cetak ulang tercatat sebagai "SALINAN n".

**Why this priority**: Melengkapi pengalaman desain; penjualan dasar tidak bergantung padanya.

**Independent Test**: Order Rp 60.000 dengan pajak 10% menghasilkan total Rp 66.000 di tablet, struk, dan laporan back office.

**Acceptance Scenarios**:

1. **Given** tarif pajak outlet diatur, **When** total dihitung, **Then** pajak dihitung setelah diskon dan tampil konsisten di layar, struk, dan laporan.
2. **Given** order tanpa pembayaran, **When** dibagi per item, **Then** terbentuk bill baru berisi item terpilih; jika sudah ada pembayaran, pembagian ditolak.
3. **Given** struk sudah dicetak sekali, **When** dicetak ulang, **Then** salinan pertama gratis dan berikutnya meminta persetujuan, dengan penomoran salinan.

---

### User Story 6 - Perangkat, koneksi, dan ketahanan (Priority: P3)

Terminal terdaftar sebagai perangkat outlet, melaporkan status (versi aplikasi, selisih jam, antrean), dan menampilkan kondisi koneksi dengan jujur. Permintaan yang gagal karena jaringan aman diulang tanpa dobel; sesi PIN yang habis diperpanjang atau meminta masuk ulang.

**Why this priority**: Kualitas operasional; tidak menghalangi penjualan.

**Independent Test**: Matikan jaringan di tengah pembayaran lalu nyalakan: pembayaran tercatat tepat satu kali dan layar menampilkan hasil akhir yang benar.

**Acceptance Scenarios**:

1. **Given** jaringan terputus saat pembayaran, **When** jaringan kembali, **Then** aplikasi menentukan apakah pembayaran sudah tercatat sebelum mengulang, dan tidak pernah mencatat dua kali.
2. **Given** token sesi kedaluwarsa, **When** kasir melanjutkan, **Then** sesi diperpanjang diam-diam bila masih sah, atau kasir diminta PIN lagi tanpa kehilangan order yang sedang dibuka.
3. **Given** terminal tidak dapat menjangkau server, **When** kasir membuka layar, **Then** status "offline" tampil jelas dan aksi yang butuh server dinonaktifkan dengan penjelasan, bukan gagal diam-diam.

---

### Edge Cases

- Staf dinonaktifkan atau dipindah outlet saat sesinya masih berjalan: permintaan berikutnya ditolak dan terminal kembali ke layar PIN.
- Menu kosong, kategori tanpa item, item tanpa foto, harga berubah di tengah order: order lama memakai harga saat dicatat; item baru memakai harga terbaru.
- Stok habis tepat saat item ditambahkan: server menolak dengan pesan yang jelas; tampilan tidak menunjukkan baris yang tidak tersimpan.
- Jam perangkat berbeda jauh dari server: peringatan ditampilkan; tanggal bisnis ditentukan server.
- Respons server tak terduga (kolom hilang, galat 5xx, skema database tertinggal): pesan ramah dengan opsi coba lagi, tanpa membocorkan detail teknis.
- Dua kasir mengubah order yang sama: perubahan terakhir tidak diam-diam menimpa; pengguna diberi tahu bila order berubah di server.
- Layar kecil (telepon) dan tablet 8"/13": seluruh alur tetap dapat dipakai.

## Requirements *(mandatory)*

### Functional Requirements

**Akses & identitas**
- **FR-001**: Terminal MUST dapat disiapkan untuk satu outlet oleh pemilik/admin sekali, dan sesudahnya menampilkan staf aktif outlet itu pada layar PIN.
- **FR-002**: Login PIN MUST memverifikasi PIN di server, menerapkan kunci 15 menit setelah 5 kesalahan, dan tidak pernah menyimpan atau mencatat PIN di perangkat.
- **FR-003**: Sesi PIN MUST berumur terbatas dan dapat diperpanjang sebelum habis; sesi yang tidak sah MUST mengembalikan pengguna ke layar PIN tanpa kehilangan order terbuka.
- **FR-004**: Peran (kasir, waiter, supervisor, manajer outlet) dan izin per aksi MUST ditentukan server; aplikasi hanya menyembunyikan kontrol, tidak menjadi penegak.

**Katalog & denah**
- **FR-005**: Menu, kategori, harga, grup modifier beserta aturan wajib/min/maks, dan ketersediaan (habis) MUST berasal dari server untuk outlet aktif dan dapat disegarkan.
- **FR-006**: Denah meja, area, status (tersedia/terisi/bill tercetak/dikelola perangkat lain), jumlah tamu, dan total berjalan MUST berasal dari server dan diperbarui berkala.

**Order & dapur**
- **FR-007**: Membuka order (meja/counter), menambah/mengubah/menghapus baris, mengirim ke dapur, memindah/menggabung/membagi, dan membatalkan MUST dijalankan di server; total, pajak, diskon, dan sisa tagihan MUST dihitung server.
- **FR-008**: Setiap aksi pembuat atau pengubah order MUST aman diulang: pengulangan karena timeout tidak boleh membuat baris, tiket, atau pembayaran ganda.
- **FR-009**: Order MUST memuat nomor antrean (counter) dan nomor struk per terminal dari server.

**Pembayaran**
- **FR-010**: Pembayaran tunai, EDC (kode approval 6–12 karakter), dan QRIS statis (tertunda → verifikasi dengan referensi) MUST dicatat di server dengan aturan: tidak melebihi sisa untuk non-tunai, QRIS tertunda memblokir metode non-tunai lain dan penutupan shift, deteksi EDC ganda 2 menit.
- **FR-011**: Setelah pembayaran, status "tersimpan/tersinkron" dan "struk tercetak" MUST mencerminkan keadaan nyata, bukan nilai tetap.

**Shift & laporan**
- **FR-012**: Shift MUST dibuka dengan modal per pecahan dan hanya satu shift terbuka per kasir per terminal; pembayaran tanpa shift terbuka MUST ditolak.
- **FR-013**: Kas masuk/keluar MUST dicatat dengan alasan, dan kas keluar di atas ambang (Rp 200.000) MUST memerlukan persetujuan.
- **FR-014**: X-report MUST dihitung dari transaksi shift sebenarnya; penutupan shift MUST memakai hitungan buta per pecahan, total batch EDC, toleransi selisih Rp 5.000, dan persetujuan bila terlampaui; hasilnya (seharusnya/dihitung/selisih) berasal dari server.

**Kontrol & audit**
- **FR-015**: Aksi sensitif MUST memerlukan persetujuan atasan terikat pada satu aksi, oleh orang yang bukan peminta, dengan PIN-nya sendiri; setiap persetujuan MUST tercatat (siapa, kapan, apa, alasan).
- **FR-016**: Batas diskon per peran dan alasan wajib untuk void/batal/diskon MUST ditegakkan server.
- **FR-017**: Cetak ulang struk MUST dihitung (salinan pertama gratis) dan ditandai "SALINAN n".

**Perangkat & ketahanan**
- **FR-018**: Terminal MUST terdaftar dan melaporkan status berkala (versi aplikasi, selisih jam, antrean cetak/sinkron) yang tampil di layar Perangkat sebagai angka nyata.
- **FR-019**: Semua kegagalan jaringan/server MUST ditampilkan sebagai keadaan gagal dengan opsi coba lagi (bukan keadaan kosong), dengan pesan ramah dan tanpa detail teknis.
- **FR-020**: Aplikasi MUST tidak lagi memuat data contoh pada build rilis; data contoh hanya boleh ada pada mode pembandingan visual yang eksplisit.

**Dokumentasi & mutu**
- **FR-021**: Kontrak API MUST terdokumentasi lengkap (termasuk tab, denah, tiket, stasiun, meja, modifier, PIN, persetujuan, perangkat) dan diperbarui bersamaan dengan setiap perubahan rute.
- **FR-022**: Setiap kemampuan server baru MUST disertai uji otomatis yang membuktikan aturan uang, izin, dan keamanan pengulangan.

### Key Entities

- **Terminal/Perangkat**: satu tablet atau telepon milik sebuah outlet; punya nama (mis. T01), versi aplikasi, status heartbeat.
- **Staf & Peran**: pengguna outlet dengan PIN; peran menentukan izin, batas diskon, dan hak menyetujui.
- **Sesi PIN**: bukti masuk berumur terbatas milik satu staf pada satu terminal.
- **Persetujuan**: izin sekali pakai yang menyatakan atasan X menyetujui aksi Y dengan alasan Z; terikat pada ringkasan aksi.
- **Menu/Item/Modifier/Ketersediaan**: apa yang dijual outlet, aturan pilihan, dan penanda habis.
- **Meja & Area**: denah outlet dengan status dan pemilik order terbuka.
- **Order & Baris**: pesanan dengan baris, catatan, diskon, status kirim dapur, pajak, nomor antrean dan struk.
- **Pembayaran**: tunai/EDC/QRIS dengan status (lunas/tertunda/gagal), referensi, dan shift tempat uang diterima.
- **Shift & Pergerakan Kas**: periode kerja kasir dengan modal per pecahan, kas masuk/keluar, X-report, hitungan tutup, dan selisih.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Seorang kasir dapat menyelesaikan siklus "masuk PIN → order 3 item → kirim dapur → bayar tunai → struk" dalam waktu kurang dari 90 detik pada jaringan outlet normal.
- **SC-002**: 100% layar yang ada di desain menampilkan data dari server (tidak ada angka, nama, atau menu contoh) pada build yang dipakai di outlet.
- **SC-003**: Untuk 50 order uji berisi diskon, modifier, dan pembayaran campuran, total di tablet, struk, dan laporan back office identik sampai ke rupiah.
- **SC-004**: Dalam uji gangguan jaringan (putus di tengah tiap langkah tulis), 0 baris/tiket/pembayaran ganda dan 0 order hilang.
- **SC-005**: PIN salah 5 kali mengunci tepat staf itu selama 15 menit; 0 percobaan PIN ke-6 yang diproses selama terkunci.
- **SC-006**: Seluruh aksi sensitif di daftar FR-015 tidak dapat dijalankan tanpa persetujuan sah, dibuktikan oleh uji otomatis untuk setiap aksi.
- **SC-007**: Tutup shift menghasilkan angka "seharusnya" yang cocok dengan hitungan manual pada 10 skenario uji (modal + tunai + kas masuk − kas keluar − refund tunai).
- **SC-008**: Perubahan harga atau ketersediaan di back office tampil di terminal dalam 10 detik setelah penyegaran otomatis berikutnya.
- **SC-009**: Aplikasi tetap dapat dipakai (tanpa tampilan rusak atau crash) pada tablet 8", 10", 13" dan telepon untuk seluruh alur P1–P3.
- **SC-010**: Setiap rute API yang ditambah/diubah muncul di dokumen kontrak pada commit yang sama, diperiksa oleh uji otomatis.

## Assumptions

- **ASSUMPTION — Online-first**: terminal memerlukan koneksi ke server outlet untuk menulis data; mode offline penuh (antrean lokal, sinkron batch — B16) di luar cakupan fitur ini. Saat offline, aplikasi menampilkan keadaan jujur dan menonaktifkan aksi tulis.
- **ASSUMPTION — Penyiapan terminal**: pemilik/admin masuk dengan username+kata sandi sekali untuk menyiapkan terminal bagi satu outlet; token itu dipakai hanya untuk mengambil daftar staf dan memulai sesi PIN. Pendaftaran perangkat resmi (B14) menggantikan mekanisme ini bila selesai.
- **ASSUMPTION — Satu server per organisasi**: alamat server dikonfigurasi saat penyiapan terminal; tidak ada layanan cloud.
- **ASSUMPTION — Nilai contoh desain** (batas diskon per peran 10/0/50/100%, ambang kas keluar Rp 200.000, toleransi selisih Rp 5.000, pajak 10% eksklusif) dipakai sebagai nilai awal yang dapat diatur per outlet; angka final ditetapkan pemilik produk.
- **ASSUMPTION — Peran**: Kasir/Waiter/Supervisor/Manajer Outlet dibuat sebagai peran kustom pada sistem peran yang ada, bukan struktur peran baru.
- Kemampuan server yang sudah ada dipakai apa adanya; yang belum ada (B2–B14, B17–B19, dokumentasi B21) dibangun dalam fitur ini sesuai prioritas cerita pengguna. Refund penuh (B19) dan bagi rata (B18) berprioritas terendah.
- Pelaporan antrean cetak ke server (B20) dan sinkron offline (B16) ditunda.
- Cetak fisik (printer LAN 80 mm) di luar cakupan; aplikasi menampilkan status cetak yang berasal dari antrean cetak nyata hanya setelah modul printer terpasang — sebelum itu, status cetak MUST ditampilkan sebagai "belum tersedia", bukan sukses palsu.
- Data demo (outlet "Warkop WJM 3", staf contoh) disediakan sebagai seed lokal untuk pengujian, bukan sebagai bagian build rilis.
