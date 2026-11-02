# Feature Specification: Laporan Bahan dan Statistik Stok di Dashboard

**Feature Branch**: `088-material-stock-report`

**Created**: 2026-11-01

**Status**: Draft

**Input**: User description: "as an owner, I want a comprehensive report for material so that I can make executive decision to manage my product stock to always available to sell. also i want to view material and stock statistic in the dashboard."

**Klasifikasi**: INTERNAL

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Melihat bahan mana yang mengancam penjualan (Priority: P1)

Pemilik membuka laporan Bahan dan langsung melihat, per outlet, bahan yang saldonya minus, habis, atau akan habis dalam beberapa hari menurut laju pemakaian, beserta menu atau pilihan modifier yang ikut terancam tidak bisa dibuat (mis. "Indomie Ayam Bawang: sisa 3 bungkus, habis ±1 hari lagi → Indomie Kuah rasa Ayam Bawang").

**Why this priority**: Inilah inti permintaan: keputusan supaya produk selalu bisa dijual. Tanpa daftar risiko ini, pemilik baru tahu bahan habis setelah kasir menolak pesanan atau saldo sudah minus.

**Independent Test**: Isi saldo dan riwayat pemakaian beberapa bahan di dua outlet, buka laporan, dan pastikan urutan risiko, perkiraan hari tersisa, dan menu yang terdampak sesuai data.

**Acceptance Scenarios**:

1. **Given** bahan Telur di outlet A bersaldo 6 butir dan terpakai rata-rata 4 butir per hari selama 7 hari terakhir, **When** pemilik membuka laporan untuk outlet A, **Then** Telur tampil dengan status "akan habis" dan perkiraan sisa ±1,5 hari, beserta pilihan modifier yang memakainya.
2. **Given** bahan bersaldo minus, **When** laporan dibuka, **Then** bahan itu tampil paling atas dengan status "minus" dan jumlah kekurangannya.
3. **Given** bahan tidak pernah dipakai dalam periode acuan, **When** laporan dibuka, **Then** perkiraan hari tersisa tampil "tidak terpakai" (bukan nol atau tak hingga yang membingungkan).
4. **Given** pemilik memilih "semua outlet", **When** laporan dibuka, **Then** setiap bahan dinilai per outlet (satu baris per outlet yang punya saldo atau pemakaian), tidak dijumlah lintas outlet.

---

### User Story 2 - Saran pembelian bahan (Priority: P1)

Untuk bahan yang berisiko, laporan menyarankan berapa yang perlu dibeli supaya cukup untuk target hari persediaan, dengan vendor dan harga terakhir yang tercatat, sehingga pemilik bisa langsung memesan.

**Why this priority**: Mengubah informasi risiko menjadi tindakan; tanpa angka saran, pemilik tetap harus menghitung manual.

**Independent Test**: Dengan laju pemakaian dan saldo yang diketahui, pastikan jumlah saran = (target hari × laju harian) − saldo saat ini (minimal 0), dan vendor/harga yang tampil adalah vendor pilihan bahan itu.

**Acceptance Scenarios**:

1. **Given** target persediaan 7 hari, laju 4 butir/hari, saldo 6, **When** saran dihitung, **Then** jumlah saran = 22 butir dan perkiraan biaya = 22 × harga vendor pilihan.
2. **Given** bahan tanpa harga vendor, **When** saran ditampilkan, **Then** jumlah tetap tampil dan biaya ditandai "harga belum ada".
3. **Given** pemilik memilih beberapa saran dari dua vendor, **When** ia meminta pembuatan pesanan pembelian, **Then** sistem membuat satu draf PO per vendor untuk outlet terpilih, berisi bahan dan jumlah saran (yang bisa ia ubah sebelum menyimpan), dan tidak ada yang dipesan sebelum pemilik memprosesnya di halaman PO.
4. **Given** saran untuk bahan tanpa vendor, **When** pemilik memilihnya untuk dibuatkan PO, **Then** sistem menolak baris itu dengan alasan "belum ada vendor" dan tetap membuat draf untuk baris lain.

---

### User Story 3 - Statistik bahan dan stok di Dashboard (Priority: P2)

Saat membuka Dashboard, pemilik melihat ringkasan kesehatan stok: jumlah bahan minus / habis / akan habis, jumlah menu yang terancam tidak bisa dibuat, nilai persediaan bahan, nilai bahan terbuang dalam periode, serta item produk dengan stok menipis yang sudah ada sekarang. Setiap angka bisa diklik menuju laporan yang sudah tersaring.

**Why this priority**: Dashboard adalah layar yang paling sering dibuka; tanda bahaya di sana membuat pemilik tidak perlu ingat membuka laporan.

**Independent Test**: Siapkan data dengan jumlah bahan berisiko yang diketahui, buka Dashboard, cocokkan setiap angka dengan laporan, lalu klik angka dan pastikan laporan terbuka dengan saringan yang sama.

**Acceptance Scenarios**:

1. **Given** 2 bahan minus dan 3 bahan akan habis di outlet terpilih, **When** Dashboard dibuka, **Then** kartu menampilkan 2 dan 3, sama dengan jumlah baris laporan pada saringan itu.
2. **Given** pengguna tanpa izin melihat biaya, **When** Dashboard dibuka, **Then** angka jumlah tetap tampil tetapi nilai rupiah (persediaan, terbuang) tidak tampil.
3. **Given** outlet di navbar diganti, **When** Dashboard dimuat ulang, **Then** statistik mengikuti outlet itu.

---

### User Story 4 - Pemakaian, pemborosan, dan selisih bahan (Priority: P2)

Pemilik melihat untuk periode tertentu: pemakaian bahan karena penjualan, bahan terbuang, koreksi stok (selisih stok opname), dan pembelian yang diterima, dalam jumlah dan rupiah, dengan tren per hari dan bahan teratas, serta menu/pilihan yang paling banyak memakai bahan.

**Why this priority**: Menjawab "ke mana bahan saya pergi" dan menunjukkan kebocoran (terbuang, selisih) yang langsung memengaruhi biaya dan ketersediaan.

**Independent Test**: Catat penjualan, buang, koreksi, dan penerimaan PO di periode tertentu; jumlah per jenis di laporan harus sama dengan buku besar bahan untuk periode dan outlet itu.

**Acceptance Scenarios**:

1. **Given** dalam periode ada penjualan memakai 10 Telur, 2 terbuang, dan koreksi −1, **When** laporan periode dibuka, **Then** pemakaian = 10, terbuang = 2, selisih = −1, masing-masing dengan nilai rupiahnya.
2. **Given** pesanan di-void/refund dan bahannya dikembalikan, **When** laporan dibuka, **Then** pemakaian bersih tidak menghitung bahan yang sudah dikembalikan.
3. **Given** pemilik memilih satu bahan, **When** rincian dibuka, **Then** tampil menu dan pilihan modifier yang memakainya beserta jumlah pemakaiannya dalam periode.

---

### User Story 5 - Ekspor laporan (Priority: P3)

Pemilik mengekspor laporan bahan (posisi stok, risiko, saran pembelian, pemakaian) ke Excel untuk dibagikan atau diarsipkan.

**Why this priority**: Pelengkap; pengambilan keputusan sudah bisa di layar.

**Independent Test**: Ekspor dengan saringan tertentu; isi berkas sama dengan yang tampil di layar dan aman dibuka (tanpa formula).

**Acceptance Scenarios**:

1. **Given** saringan outlet dan periode aktif, **When** pemilik mengekspor, **Then** berkas berisi baris dan angka yang sama dengan layar untuk saringan itu.

---

### Edge Cases

- Bahan baru tanpa riwayat pemakaian: perkiraan hari tersisa "tidak terpakai", tidak masuk daftar "akan habis" kecuali saldonya ≤ 0.
- Saldo minus: hari tersisa = 0, saran pembelian menutup kekurangan ditambah target hari.
- Bahan nonaktif atau terhapus: tidak masuk daftar risiko dan saran, tetapi tetap muncul di riwayat pemakaian periode lampau.
- Hari libur/outlet tutup membuat laju rata-rata rendah: laju dihitung dari hari yang punya penjualan di outlet itu (lihat Asumsi), supaya hari tutup tidak menurunkan laju.
- Menu bersumber stok varian (bukan bahan): ketersediaannya tetap dari stok varian; laporan bahan tidak menggandakannya, Dashboard menampilkan keduanya terpisah.
- Pemakaian dari resep/BOM: saat ini resep dan BOM hanya menghitung biaya dan TIDAK memotong stok bahan, sehingga bahan yang hanya dipakai lewat resep tidak punya laju pemakaian; laporan menandainya "pemakaian tidak tercatat", bukan "tidak terpakai".
- Mode DEMO/LIVE: laporan dan Dashboard hanya memakai data mode aktif.
- Pengguna yang hanya melihat sebagian outlet tidak pernah melihat angka outlet lain.

## Requirements *(mandatory)*

### Functional Requirements

**Posisi dan risiko**

- **FR-001**: Sistem MUST menampilkan laporan Bahan yang berisi, per bahan per outlet: saldo saat ini, satuan, jenis bahan, laju pemakaian harian, perkiraan hari tersisa, status (minus / habis / akan habis / aman / tidak terpakai), dan nilai persediaan.
- **FR-002**: Laju pemakaian harian MUST dihitung dari pemakaian bersih (penjualan dikurangi pengembalian, ditambah terbuang) dalam periode acuan yang bisa dipilih (bawaan 7 hari terakhir) dibagi jumlah hari yang punya penjualan di outlet itu dalam periode tersebut.
- **FR-003** *(diputuskan 2026-11-01: otomatis)*: Status "akan habis" MUST ditentukan otomatis: perkiraan hari tersisa < N hari, dengan N bisa diubah di layar laporan (bawaan 3). Tidak ada batas minimum manual per bahan. Bahan tanpa pemakaian tercatat tidak pernah "akan habis"; ia baru berisiko saat saldonya ≤ 0.
- **FR-004**: Untuk setiap bahan berisiko, laporan MUST menampilkan menu dan pilihan modifier yang memakainya dan perkiraan porsi yang masih bisa dibuat dari saldo saat ini.
- **FR-005**: Daftar MUST bisa disaring menurut outlet (mengikuti navbar), jenis bahan, dan status, serta diurutkan dengan yang paling berisiko di atas (minus, lalu hari tersisa paling sedikit).

**Saran pembelian**

- **FR-006**: Laporan MUST menghitung saran jumlah beli = maks(0, target hari × laju harian − saldo saat ini), dengan target hari yang bisa diubah pemilik (bawaan 7 hari).
- **FR-007**: Saran MUST menampilkan vendor pilihan dan harga terakhir yang tercatat beserta perkiraan biaya; bahan tanpa harga ditandai jelas.
- **FR-008** *(diputuskan 2026-11-01: draf PO)*: Pemilik MUST bisa memilih saran dan membuat draf PO, satu per vendor pilihan, untuk outlet terpilih, dengan jumlah saran yang bisa diubah sebelum disimpan. Draf mengikuti aturan PO yang ada (status draf, belum menambah stok sampai diterima) dan hanya bisa dibuat pengguna yang boleh membuat PO. Baris tanpa vendor ditolak dengan alasan dan tidak menggagalkan baris lain.

**Pemakaian, pemborosan, selisih**

- **FR-009**: Laporan MUST menampilkan, untuk periode tanggal bisnis yang dipilih, total per jenis pergerakan bahan (pemakaian penjualan bersih, terbuang, koreksi +/−, pembelian diterima, pindah masuk/keluar) dalam jumlah dan rupiah.
- **FR-010**: Laporan MUST menampilkan tren harian pemakaian dan terbuang, serta 10 bahan teratas menurut nilai pemakaian dan menurut nilai terbuang.
- **FR-011**: Rincian satu bahan MUST menampilkan menu dan pilihan modifier yang memakainya dalam periode, dengan jumlah per menu/pilihan.
- **FR-012**: Angka laporan MUST cocok dengan buku besar bahan untuk outlet dan periode yang sama (satu sumber kebenaran; tidak ada angka yang dihitung terpisah dari buku besar).

**Dashboard**

- **FR-013**: Dashboard MUST menampilkan statistik stok untuk outlet terpilih: jumlah bahan minus, habis, dan akan habis; jumlah menu/pilihan yang terancam; nilai persediaan bahan; nilai bahan terbuang dalam periode Dashboard; dan jumlah item produk stok menipis yang sudah ada.
- **FR-014**: Setiap angka statistik di Dashboard MUST bisa diklik dan membuka laporan Bahan dengan saringan yang menghasilkan angka itu.
- **FR-015**: Angka Dashboard MUST sama dengan angka laporan untuk saringan yang sama.

**Akses, ekspor, dan aturan umum**

- **FR-016**: Laporan Bahan MUST hanya bisa dibuka pengguna dengan menu Laporan; nilai rupiah (persediaan, pemakaian, terbuang, saran biaya) MUST disembunyikan dari pengguna tanpa izin melihat biaya, di laporan maupun Dashboard.
- **FR-017**: Semua angka MUST dibatasi outlet yang boleh dilihat pengguna dan mode data (DEMO/LIVE) yang aktif.
- **FR-018**: Pemilik MUST bisa mengekspor laporan (posisi & risiko, saran pembelian, pemakaian periode) ke Excel dengan saringan aktif; teks di berkas tidak boleh terbaca sebagai formula.
- **FR-019**: Nilai rupiah bahan MUST memakai biaya per satuan yang sama dengan yang dipakai biaya resep (harga vendor pilihan; bahan setengah jadi memakai biaya standar), supaya laporan dan biaya menu tidak berbeda.

### Key Entities *(include if feature involves data)*

- **Bahan per outlet**: bahan, outlet, saldo, satuan, jenis, status aktif; sumber posisi stok.
- **Pergerakan bahan (buku besar)**: jenis (penjualan, pengembalian, terbuang, koreksi, pembelian, pindah), jumlah, saldo sebelum/sesudah, outlet, waktu, rujukan pesanan/PO; sumber semua angka periode.
- **Pemetaan pilihan → bahan**: pilihan modifier, bahan, jumlah per porsi; menghubungkan bahan ke menu yang terancam.
- **Harga bahan**: vendor pilihan dan harga terakhir, atau biaya standar bahan setengah jadi; sumber nilai rupiah dan saran biaya.
- **Ambang & target persediaan**: ambang hari "akan habis" (bawaan 3) dan target hari persediaan (bawaan 7); keduanya pilihan di layar laporan, diingat per pengguna.
- **Draf PO dari saran**: vendor, outlet, baris bahan + jumlah; memakai PO yang sudah ada.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pemilik bisa menyebut tiga bahan yang paling berisiko habis di outletnya dalam waktu kurang dari 30 detik setelah membuka Dashboard.
- **SC-002**: 100% bahan bersaldo minus atau habis muncul di daftar risiko laporan dan di hitungan Dashboard (diuji dengan data yang diketahui).
- **SC-003**: Angka pemakaian, terbuang, dan koreksi per periode sama persis dengan buku besar bahan untuk outlet dan periode yang sama.
- **SC-004**: Setiap angka statistik di Dashboard sama dengan jumlah baris laporan pada saringan tujuan kliknya.
- **SC-005**: Laporan untuk 300 bahan dan 3 outlet tampil dalam waktu kurang dari 3 detik.
- **SC-006**: Dalam satu bulan pemakaian, jumlah kejadian bahan minus di outlet yang memakai laporan menurun dibanding bulan sebelumnya (diukur dari buku besar).

## Assumptions

- Laporan ditambahkan sebagai tab baru di halaman Laporan yang sudah ada; statistik Dashboard ditambahkan ke Dashboard yang sudah ada, tanpa menghapus kartu yang ada.
- Pemakaian bahan hanya diketahui dari buku besar bahan (penjualan lewat pilihan modifier sejak 087, terbuang, koreksi, pembelian, pindah). Resep dan BOM tidak memotong stok, jadi tidak menyumbang laju pemakaian; menambahkan pemotongan berbasis resep di luar lingkup fitur ini.
- Hari dengan penjualan dihitung per outlet menurut tanggal bisnis, bukan tanggal kalender UTC.
- Ambang "akan habis" dan target hari persediaan berlaku untuk semua bahan dan bisa diubah di layar laporan; nilai terakhir diingat per pengguna.
- Draf PO memakai harga vendor pilihan terakhir sebagai harga satuan awal; pemilik bisa mengubahnya di halaman PO.
- Statistik stok produk (varian) di Dashboard memakai aturan stok menipis yang sudah ada; fitur ini tidak mengubah aturannya.
- Tablet Android tidak berubah pada fitur ini.
