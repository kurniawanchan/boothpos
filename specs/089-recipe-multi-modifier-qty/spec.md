# Feature Specification: Resep Memotong Stok, Resep Ganda per Varian, dan Jumlah Pilihan Modifier

**Klasifikasi**: INTERNAL

**Feature Branch**: `089-recipe-multi-modifier-qty`

**Created**: 2026-11-02

**Status**: Draft

**Input**: User description: "Di halaman Resep: ubah label Yield menjadi Portions dan hapus label 'portions' di sebelah kolom; resep boleh lebih dari satu dan berlaku untuk satu varian; saat varian yang punya resep terjual, bahan resep dibaca lalu stok bahan dipotong. Di tambah/ubah modifier: satu bahan boleh dipakai di banyak pilihan; pilihan modifier boleh punya jumlah (quantity) dan pengaturan jumlah minimum dan maksimum. Terapkan untuk web dan aplikasi (Android)."

## Latar

Hari ini (fitur 087) pilihan modifier sudah bisa memotong stok bahan saat pesanan selesai. Resep menu/varian **belum**: resep hanya dipakai menghitung HPP, dan layarnya sendiri menyatakan "Menyimpan resep tidak memotong stok bahan". Akibatnya bahan utama sebuah menu (mis. mi, bumbu dasar) tidak berkurang saat menu terjual, padahal bahan dari pilihan modifier berkurang.

Di editor grup modifier, dua hal menghambat pemilik:

1. Memakai **bahan yang sama di dua pilihan berbeda** (mis. Cabe pada pilihan "1", "2", dan "4") ditolak dengan pesan "Bahan yang sama tidak boleh dipilih dua kali untuk satu pilihan", padahal tiap pilihan hanya memakai satu bahan itu. Pesan itu seharusnya hanya berlaku bila bahan yang sama ditulis dua kali **di dalam satu pilihan**.
2. Sebuah pilihan hanya bisa dipesan "ada atau tidak ada". Tidak ada cara memesan "Telur ×2" atau membatasi "paling banyak 3".

Istilah yang dipakai di dokumen ini:

| Istilah | Arti | Contoh |
|---|---|---|
| **Resep** | Daftar bahan dan jumlah untuk **satu porsi** sebuah varian | Pangsit Chillie Oil: 2 gr cabai kering, 10 ml minyak |
| **Porsi (Portions)** | Berapa porsi yang dihasilkan oleh jumlah bahan yang tertulis di resep (bekas label "Yield") | 1 |
| **Pilihan modifier** | Jawaban dalam grup modifier yang bisa mengubah harga dan memakai bahan | Level Pedas: 1, 2, 4 |
| **Jumlah pilihan** | Berapa kali satu pilihan dipesan pada satu item | Telur ×2 |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Menjual varian ber-resep memotong stok bahan (Priority: P1)

Pemilik sudah mengisi resep untuk sebuah varian. Saat kasir menyelesaikan pesanan yang memuat varian itu, sistem membaca bahan resepnya dan mengurangi stok bahan di outlet pesanan, sebanyak jumlah bahan per porsi dikali jumlah porsi yang terjual.

**Why this priority**: Ini inti permintaan dan satu-satunya bagian yang mengubah angka stok nyata. Tanpanya resep tetap hanya angka HPP dan stok bahan utama menu tidak pernah akurat.

**Independent Test**: Buat varian dengan resep "2 gr cabai kering per porsi"; stok cabai outlet 100. Jual 3 porsi lalu selesaikan pesanan: stok cabai menjadi 94, dan riwayat bahan mencatat pergerakan "penjualan" yang menaut ke baris pesanan itu.

**Acceptance Scenarios**:

1. **Given** varian punya resep aktif berisi cabai 2 gr dan minyak 10 ml per porsi, **When** pesanan 3 porsi varian itu selesai, **Then** stok cabai berkurang 6 gr dan minyak 30 ml di outlet pesanan.
2. **Given** varian ber-resep ada di tab yang masih terbuka, **When** item ditambah, dikurangi, atau dihapus selama tab terbuka, **Then** stok bahan tidak berubah sampai pesanan selesai (sama dengan aturan bahan pilihan modifier, fitur 087).
3. **Given** pesanan selesai yang sudah memotong bahan resep, **When** pesanan di-void atau di-refund, **Then** bahan yang dulu terpotong dikembalikan sesuai catatan pemotongan yang nyata, bukan sesuai resep saat ini.
4. **Given** bahan resep tidak cukup di outlet, **When** pesanan selesai, **Then** pesanan tetap berhasil, stok bahan boleh menjadi minus, dan kasir melihat peringatan yang menyebut bahan dan outlet-nya (sama dengan fitur 087).
5. **Given** varian ber-resep yang sama dipesan bersama pilihan modifier yang juga memakai bahan, **When** pesanan selesai, **Then** bahan dari resep dan bahan dari pilihan modifier sama-sama terpotong; bila bahannya sama, jumlahnya dijumlahkan menjadi satu pergerakan wajar.
6. **Given** varian tanpa resep, **When** pesanan selesai, **Then** tidak ada bahan resep yang terpotong dan perilaku lama tidak berubah.

---

### User Story 2 - Label "Portions" di layar Resep (Priority: P2)

Di layar Resep, label kolom "Yield" diganti "Portions", dan tulisan "portions" di sebelah kolom isian dihapus supaya tidak ada kata yang berulang.

**Why this priority**: Perubahan tampilan kecil, tetapi menghilangkan kebingungan istilah "yield" dan memperjelas bahwa angka itu adalah jumlah porsi yang dihasilkan.

**Independent Test**: Buka resep sebuah item: label di atas isian bertuliskan "Portions" (Bahasa Inggris) atau padanannya (Bahasa Indonesia), dan tidak ada tulisan satuan di sebelah kanan isian.

**Acceptance Scenarios**:

1. **Given** layar Resep terbuka, **When** dilihat dalam Bahasa Inggris, **Then** label bertuliskan "Portions" dan tidak ada teks "portions" di sebelah isian.
2. **Given** layar Resep terbuka, **When** dilihat dalam Bahasa Indonesia, **Then** label memakai istilah porsi yang konsisten dan tidak ada teks satuan di sebelah isian.
3. **Given** pesan galat angka porsi tidak valid, **When** ditampilkan, **Then** istilahnya juga memakai "Portions"/"porsi", bukan "yield".

---

### User Story 3 - Satu bahan boleh dipakai di banyak pilihan modifier (Priority: P2)

Pemilik membuka grup modifier (baru atau ubah) dan memakai bahan yang sama, mis. Cabe, di beberapa pilihan sekaligus (Level 1, 2, 4), masing-masing dengan jumlah sendiri. Hanya penulisan bahan yang sama dua kali di dalam satu pilihan yang ditolak.

**Why this priority**: Menghilangkan galat yang menghalangi pemakaian normal (tiap pilihan hanya punya satu bahan, tetapi tetap ditolak). Tanpanya pemetaan level pedas tidak bisa disimpan.

**Independent Test**: Buat grup "Level Pedas" dengan pilihan 1, 2, 4, masing-masing memakai Cabe (1, 2, 4 pcs); simpan tanpa galat; buka lagi: ketiga pemetaan tampil utuh.

**Acceptance Scenarios**:

1. **Given** tiga pilihan masing-masing memakai Cabe dengan jumlah berbeda, **When** grup disimpan, **Then** tersimpan tanpa galat dan galat "bahan yang sama" tidak muncul.
2. **Given** satu pilihan memuat Cabe dua kali, **When** disimpan, **Then** ditolak dengan pesan yang menunjuk pilihan tersebut saja, bukan pilihan lain.
3. **Given** Cabe dipakai di tiga pilihan, **When** salah satu pilihan dipesan, **Then** hanya jumlah milik pilihan itu yang dipotong dari saldo Cabe.
4. **Given** bahan dipakai di banyak pilihan, **When** pemilik menonaktifkan atau menghapus bahan, **Then** aturan yang sudah ada tetap berlaku (hapus bahan yang dipetakan pilihan aktif ditolak dengan 409).

---

### User Story 4 - Pilihan modifier punya jumlah dengan batas minimum dan maksimum (Priority: P2)

Pemilik menentukan, per pilihan, batas jumlah yang boleh dipesan (minimum dan maksimum; kosong berarti tanpa batas atas). Kasir memesan pilihan dengan jumlah tertentu (mis. Telur ×2). Selisih harga dan pemakaian bahan pilihan dikali jumlah yang dipesan.

**Why this priority**: Membuka kasus penting (topping tambahan, shot tambahan) tanpa membuat pilihan terpisah untuk tiap jumlah. Bergantung pada pemetaan bahan (Story 3) agar potongan stok benar.

**Independent Test**: Atur pilihan "Telur" dengan jumlah minimum 1 dan maksimum 3 serta pemakaian 1 butir Telur; di kasir pesan Telur ×2: harga naik 2 × selisih harga dan setelah selesai stok Telur turun 2 butir; coba ×4: ditolak.

**Acceptance Scenarios**:

1. **Given** pilihan dengan maksimum 3, **When** kasir memesan ×4, **Then** ditolak dengan pesan yang jelas (web maupun aplikasi) dan server juga menolak bila klien lama mengirim ×4.
2. **Given** pilihan dengan minimum 1 pada grup yang wajib dipilih, **When** kasir memilihnya, **Then** jumlah awal adalah 1 dan tidak bisa diturunkan di bawah 1 selain dengan melepas pilihan sepenuhnya (bila grup tidak mewajibkan).
3. **Given** pilihan ×2 dengan selisih harga Rp 3.000, **When** pesanan dibuat, **Then** harga baris naik Rp 6.000 dikali jumlah porsi item, dan struk serta KOT menampilkan jumlah pilihan.
4. **Given** pilihan ×2 memakai 1 butir Telur per satuan, **When** pesanan 2 porsi item selesai, **Then** Telur terpotong 4 butir; void/refund mengembalikan 4 butir.
5. **Given** pilihan lama yang tidak punya pengaturan jumlah, **When** dipakai setelah fitur ini rilis, **Then** berperilaku seperti sebelumnya (jumlah tetap 1, ada atau tidak ada).
6. **Given** grup memakai aturan "tiap pilihan = 1 porsi" (fitur 087), **When** pilihan dipesan dengan jumlah >1, **Then** aturan harga dasar tetap konsisten dan terdokumentasi di Asumsi; tidak ada penagihan ganda yang tidak disengaja.

---

### User Story 5 - Resep ganda untuk satu varian (Priority: P1)

Sebuah varian boleh memiliki lebih dari satu resep, dan satu resep boleh berlaku untuk satu atau beberapa varian. Pemilik membuat, mengubah, dan menghapus tiap resep tersendiri dari layar Resep. Saat varian terjual, semua resep aktifnya dijumlahkan. Resep tidak lagi punya versi: mengubah resep memperbaruinya di tempat.

**Why this priority**: Menentukan cara bahan dibaca saat penjualan (Story 1); harus ditetapkan bersamaan.

**Independent Test**: Beri satu varian dua resep (mis. "Dasar" dan "Topping standar"); layar Resep menampilkan keduanya; jual varian itu dan periksa pemotongan sesuai aturan gabungan yang dipilih pemilik.

**Acceptance Scenarios**:

1. **Given** varian sudah punya satu resep, **When** pemilik menambah resep kedua untuk varian yang sama, **Then** keduanya tersimpan dan tampil di layar Resep.
2. **Given** satu resep dipilih berlaku untuk dua varian, **When** salah satu varian terjual, **Then** hanya varian yang terjual yang memicu pemotongan.
3. **Given** varian punya BOM lengkap, **When** pemilik mencoba menautkan resep ke varian itu, **Then** aturan lama (konflik sumber biaya) tetap berlaku.
4. **Given** varian punya dua resep aktif ("Dasar": cabai 2 gr; "Topping standar": cabai 1 gr dan minyak 5 ml), **When** satu porsi varian itu terjual, **Then** SEMUA resep aktif dijumlahkan: cabai terpotong 3 gr dan minyak 5 ml. Kasir tidak diminta memilih resep.
5. **Given** pemilik mengubah sebuah resep, **When** disimpan, **Then** resep itu diperbarui di tempat (tidak ada nomor versi), berlaku untuk penjualan berikutnya, dan pesanan yang sudah selesai tidak berubah (potongan dan HPP-nya sudah tercatat).
6. **Given** pemilik menghapus satu dari dua resep sebuah varian, **When** varian itu terjual, **Then** hanya resep yang tersisa yang memotong stok; isi resep yang dihapus tersimpan di log aktivitas.

---

### Edge Cases

- Pesanan memuat varian yang resepnya berisi bahan nonaktif atau terhapus: bahan itu dilewati dan tidak menggagalkan pesanan (sama dengan aturan bahan pilihan modifier).
- Resep dengan Portions lebih dari 1 (mis. resep menghasilkan 4 porsi): bahan per porsi dihitung dari jumlah bahan dibagi Portions; satu porsi terjual memotong seperempat jumlah tertulis.
- Penyusutan (shrinkage) pada baris resep: jumlah terpotong ikut memperhitungkan penyusutan, konsisten dengan perhitungan HPP.
- Resep dengan tanggal berlaku di masa depan: tidak ikut memotong stok sebelum tanggalnya.
- Dua resep aktif pada satu varian memakai bahan yang sama: jumlahnya digabung per baris menjadi satu pergerakan dan satu peringatan minus bila minus.
- Resep diubah atau dihapus setelah pesanan selesai: pesanan lama tidak berubah; void/refund memakai catatan pemotongan yang nyata.
- Pesanan lama yang selesai sebelum fitur ini rilis: tidak dipotong ulang dan tidak berubah; void/refund-nya hanya mengembalikan apa yang tercatat.
- Jumlah pilihan di luar batas dikirim klien lama atau diubah di tengah jalan: server menolak 422 dan tidak menyimpan pesanan setengah jadi.
- Minimum lebih besar dari maksimum pada pilihan: ditolak saat pemilik menyimpan.
- Pilihan dengan maksimum 1 (atau tanpa pengaturan) pada web/aplikasi lama: tetap berfungsi sebagai pilih/lepas tanpa pemilih jumlah.
- Bahan sama dari resep dan dari beberapa pilihan modifier dalam satu baris: satu saldo, jumlah gabungan, satu peringatan minus bila minus.
- Dua pesanan bersamaan memakai bahan yang sama: tidak saling menimpa saldo (aturan penguncian fitur 087 tetap berlaku).

## Requirements *(mandatory)*

### Functional Requirements

**Layar Resep**

- **FR-001**: Label kolom "Yield" di layar Resep MUST berubah menjadi "Portions" (Bahasa Indonesia: istilah "porsi" yang konsisten), termasuk pesan galat terkait.
- **FR-002**: Tulisan satuan "portions" di sebelah kanan isian Portions MUST dihapus.
- **FR-003**: Banner di layar Resep MUST tidak lagi menyatakan bahwa resep tidak memotong stok; teksnya diganti agar menjelaskan bahwa stok bahan dipotong saat pesanan selesai.

**Resep ganda dan potong stok**

- **FR-004**: Sistem MUST mengizinkan sebuah varian memiliki lebih dari satu resep aktif, dan sebuah resep berlaku untuk satu atau beberapa varian produk yang sama.
- **FR-004a**: Resep MUST tidak lagi berversi: menyimpan perubahan memperbarui resep di tempat, dan banner "menyimpan membuat VERSI BARU" MUST dihapus dari layar. Resep dapat dihapus satu per satu.
- **FR-005**: Saat pesanan berisi varian ber-resep menjadi SELESAI, sistem MUST membaca bahan resep yang berlaku dan memotong stok bahan di outlet pesanan sebesar (jumlah bahan per porsi ÷ Portions, ditambah penyusutan) × jumlah porsi yang terjual.
- **FR-006**: Pemotongan resep MUST hanya terjadi pada titik pesanan menjadi selesai (sama dengan fitur 087); menambah/mengubah/menghapus item di tab terbuka MUST NOT menyentuh stok bahan.
- **FR-007**: Pemotongan MUST tetap lewat satu-satunya jalur tulis stok bahan, dengan jejak yang menaut ke baris pesanan, sehingga void/refund dapat mengembalikan jumlah yang benar-benar terpotong.
- **FR-008**: Bila stok bahan tidak cukup, pesanan MUST tetap berhasil dan stok boleh minus, dengan peringatan menyebut bahan dan outlet (aturan fitur 087).
- **FR-009**: Bahan dari resep dan bahan dari pilihan modifier pada baris yang sama MUST sama-sama dipotong; bahan yang sama digabung per baris.
- **FR-010**: Varian tanpa resep MUST berperilaku seperti sebelumnya (tanpa pemotongan resep).
- **FR-011**: Bila sebuah varian punya beberapa resep aktif, sistem MUST menjumlahkan bahan semuanya saat penjualan (keputusan pemilik 2026-11-02); kasir MUST NOT diminta memilih resep.
- **FR-012**: Biaya bahan resep MUST tetap tercermin pada HPP baris pesanan tanpa dihitung ganda dengan biaya bahan pilihan modifier.

**Editor grup modifier**

- **FR-013**: Sistem MUST mengizinkan bahan yang sama dipakai pada banyak pilihan dalam satu grup dan antar-grup.
- **FR-014**: Sistem MUST menolak bahan yang sama ditulis lebih dari sekali **di dalam satu pilihan**, dengan pesan yang menunjuk pilihan tersebut saja.
- **FR-015**: Tiap pilihan modifier MUST dapat diberi pengaturan jumlah minimum dan maksimum (maksimum kosong = tanpa batas atas); minimum tidak boleh melebihi maksimum.
- **FR-016**: Kasir (web dan aplikasi) MUST dapat memilih jumlah sebuah pilihan dalam batas minimum–maksimum, dan sistem MUST menolak jumlah di luar batas (422) di sisi server.
- **FR-017**: Selisih harga pilihan dan pemakaian bahan pilihan MUST dikali jumlah yang dipesan; total baris tetap dihitung server, tidak pernah dari nilai klien.
- **FR-018**: Struk, KOT, dan detail transaksi MUST menampilkan jumlah pilihan bila lebih dari 1 (mis. "Telur ×2").
- **FR-019**: Pilihan lama tanpa pengaturan jumlah MUST berperilaku seperti sebelumnya (jumlah 1, ada atau tidak ada) dan klien lama yang tidak mengirim jumlah MUST tetap berfungsi.
- **FR-020**: Void/refund pesanan yang memuat pilihan berjumlah >1 MUST mengembalikan bahan sebesar yang benar-benar terpotong.

**Web dan aplikasi**

- **FR-021**: Seluruh perubahan di atas MUST tersedia di web (layar Resep, editor grup modifier, kasir) dan di aplikasi Android (pemilih jumlah pilihan, harga berjalan, struk/KOT); aplikasi lama tetap berfungsi untuk pilihan yang tidak memakai jumlah.
- **FR-022**: Perubahan API MUST dicatat di spesifikasi API pada commit yang sama (aturan PRD §9.5), dan istilah baru di aplikasi tablet MUST lolos pemeriksaan terminologi yang ada.

### Key Entities

- **Resep**: kumpulan bahan per porsi untuk satu atau beberapa varian satu produk; memuat nama/label, Portions (jumlah porsi yang dihasilkan), tanggal berlaku, catatan, dan baris bahan (bahan, jumlah, penyusutan). Diedit di tempat, tanpa versi.
- **Penautan resep–varian**: menyatakan resep mana berlaku untuk varian mana; sebuah varian boleh punya lebih dari satu.
- **Pemakaian bahan pilihan modifier**: bahan dan jumlah per satu satuan sebuah pilihan; bahan yang sama boleh muncul di banyak pilihan, tetapi tidak dua kali dalam satu pilihan.
- **Pengaturan jumlah pilihan**: batas minimum dan maksimum jumlah pemesanan per pilihan modifier.
- **Jumlah pilihan pada baris pesanan**: berapa kali sebuah pilihan dipesan pada satu baris; tersimpan sebagai potret di baris pesanan sehingga perubahan pengaturan kelak tidak mengubah pesanan lama.
- **Pergerakan stok bahan**: jejak potongan penjualan, pengembalian, dan buang yang menaut ke baris pesanan.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Untuk 100% pesanan selesai yang memuat varian ber-resep, stok bahan resep berkurang tepat sesuai resep × porsi, terbukti dari selisih saldo sebelum/sesudah di riwayat bahan.
- **SC-002**: Pemilik dapat menyimpan grup modifier yang memakai satu bahan di sepuluh pilihan berbeda tanpa galat palsu, dan galat hanya muncul pada pilihan yang benar-benar menulis bahan sama dua kali.
- **SC-003**: Kasir dapat memesan pilihan berjumlah >1 dan melihat harga baris yang benar dalam satu langkah tambahan atau kurang dibandingkan memilih pilihan itu sekarang, baik di web maupun di tablet.
- **SC-004**: Void/refund pesanan yang memotong bahan resep dan bahan pilihan mengembalikan saldo ke nilai semula (selisih 0) pada 100% kasus uji.
- **SC-005**: Pesanan yang dibuat dengan aplikasi atau klien lama tanpa jumlah pilihan tetap berhasil dengan hasil harga dan stok yang sama seperti sebelum fitur ini.
- **SC-006**: Tidak ada kata "Yield" tersisa di layar Resep dan pesan galatnya dalam Bahasa Inggris maupun Indonesia.

## Assumptions

- Pemotongan resep mengikuti aturan fitur 087: terjadi saat pesanan menjadi selesai, boleh membuat saldo bahan minus dengan peringatan, dan dikembalikan dari buku besar pergerakan.
- Jumlah bahan resep ditulis **per porsi** (judul bagian layar "Ingredients per portion"); Portions membagi jumlah itu bila resep menghasilkan lebih dari satu porsi. **ASSUMPTION** karena layar menulis keduanya; perlu dikonfirmasi saat perencanaan bila rumus tidak cocok dengan perhitungan HPP yang ada.
- "Jumlah pilihan" berarti kasir memilih berapa kali sebuah pilihan dipesan (mis. Telur ×2), dan min/max membatasi jumlah itu per pilihan. Batas min/max grup (berapa pilihan boleh dipilih) tetap ada dan terpisah. **ASSUMPTION** dari gambar permintaan.
- Minimum dan maksimum jumlah berlaku SAAT pilihan dipilih (jumlah 0 = pilihan tidak dipilih). Pengaturan bawaan sebuah pilihan adalah minimum 1 dan maksimum 1, sehingga data lama tidak berubah perilaku.
- Aturan "tiap pilihan = 1 porsi" (fitur 087) dihitung per SATUAN jumlah: SOTO ×2 sama dengan dua porsi (dua bungkus), jadi menagih harga dasar sekali lagi untuk tiap satuan di atas yang pertama dalam grup itu. Diputuskan di perencanaan (research D5).
- Data resep lama berversi dimigrasikan tanpa kehilangan riwayat: untuk tiap varian, versi yang aktif hari ini menjadi satu resep aktif, dan versi lama disimpan sebagai arsip baca-saja (tidak memotong stok). **ASSUMPTION**; cara tepatnya diputuskan di perencanaan.
- Tanggal berlaku dan catatan perubahan tetap ada sebagai isian biasa pada resep (tanggal = mulai berlaku; catatan = keterangan bebas). **ASSUMPTION** karena versi dihapus tetapi layar masih memuat keduanya.
- Resep yang tertaut ke varian ber-BOM lengkap tetap ditolak (aturan konflik sumber biaya yang ada).
- Pesanan yang sudah selesai sebelum rilis tidak dipotong ulang; tidak ada pengisian ulang data lama.
- Fitur ini tidak menambah pembelian bahan, produksi, atau jadwal produksi (di luar lingkup).
- Verifikasi di peramban dan perangkat tablet nyata dilakukan manusia setelah implementasi; sebelum itu status "belum diverifikasi" dicatat di bukti.
