# Feature Specification: Pilihan Modifier Memakai Bahan Baku (Potong Stok Otomatis)

**Klasifikasi**: INTERNAL

**Feature Branch**: `087-modifier-material-deduction`

**Created**: 2026-10-31

**Status**: Draft

**Input**: User description: "Enhance the menu and inventory management system to support multiple modifiers per menu, with each modifier value optionally linked to a raw material (ingredient) for automatic stock deduction." (contoh: Indomie Kuah dengan grup Rasa Kuah / Level Pedas / Topping; pilihan SOTO memakai bahan "Indomie Soto", Telur memakai bahan "Telur", Extra Pedas memakai 2 unit "Cabai Bubuk"; satu bahan dipakai bersama oleh banyak menu dan banyak grup.)

Penilaian arsitektur yang diminta (kelayakan, model domain, skema, ERD, alur, API, perbandingan pendekatan, rekomendasi) ada di [`assessment.md`](./assessment.md). Dokumen ini hanya memuat APA dan MENGAPA.

## Latar

Hari ini menu sudah bisa punya banyak grup modifier, dan tiap grup punya banyak pilihan (feature 028/043/086). Bahan baku sudah punya stok per outlet dan riwayat pergerakan (feature 006/045). Resep dan BOM varian sudah ada, tetapi **hanya untuk menghitung HPP**: tidak ada satu pun penjualan yang mengurangi stok bahan baku. Akibatnya stok "Telur" di sistem hanya turun lewat koreksi manual, dan pemilik tidak tahu berapa telur yang benar-benar terpakai oleh penjualan.

Istilah yang dipakai di dokumen ini:

| Istilah | Arti | Contoh |
|---|---|---|
| **Menu / item** | Barang yang dijual di kasir | Indomie Kuah |
| **Varian** | Bentuk menu yang punya harga dan SKU sendiri; kasir memilih SATU | Kopi Susu — Hot / Iced |
| **Grup modifier** | Pertanyaan kustomisasi saat memesan; dipakai bersama banyak menu | Rasa Kuah, Level Pedas, Topping |
| **Pilihan modifier** | Jawaban dalam satu grup; bisa mengubah harga | SOTO, Sedang, Telur |
| **Bahan baku** | Barang inventori yang tidak dijual langsung; punya satuan dan stok per outlet | Indomie Soto (bungkus), Telur (butir), Cabai Bubuk (gram) |
| **Pemakaian bahan pilihan** | Bahan apa dan berapa banyak yang terpakai bila satu pilihan modifier dipesan satu kali | Telur → 1 butir Telur |
| **Resep** | Pemakaian bahan untuk menu/varian itu sendiri (tanpa melihat pilihan modifier); hari ini hanya untuk HPP | — |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Atur bahan yang dipakai sebuah pilihan modifier (Priority: P1)

Pengelola menu membuka grup modifier (mis. Topping) dan, untuk tiap pilihan, menentukan nol, satu, atau beberapa bahan baku beserta jumlah pemakaiannya per satu pesanan pilihan itu. Pilihan tanpa bahan (mis. Level Pedas "Sedang") dibiarkan kosong.

**Why this priority**: Tanpa pemetaan ini tidak ada yang bisa dipotong. Ini fondasi seluruh fitur dan sudah berguna sendiri (pemilik bisa melihat bahan apa yang dipakai menu mana).

**Independent Test**: Buka grup "Topping", petakan "Telur" → 1 butir bahan Telur, "Bakso" → 3 buah bahan Bakso; simpan; buka lagi: pemetaan tampil sama, termasuk satuan bahannya. Buka bahan "Telur": terlihat daftar pilihan modifier (dan menu yang memakainya) yang merujuk ke bahan ini.

**Acceptance Scenarios**:

1. **Given** grup "Topping" dengan pilihan Telur, Sosis, Bakso, Kornet, **When** pengelola memetakan Telur → bahan Telur, 1 butir, lalu menyimpan, **Then** pilihan Telur menampilkan pemakaian "1 butir Telur" dan pilihan lain tetap tanpa bahan.
2. **Given** pilihan "Extra Pedas", **When** pengelola memetakan bahan Cabai Bubuk 2 gram DAN Saus Sambal 15 ml, **Then** kedua baris tersimpan untuk pilihan itu.
3. **Given** pengelola memasukkan jumlah 0, negatif, atau bahan yang sama dua kali pada satu pilihan, **When** menyimpan, **Then** penyimpanan ditolak dengan pesan yang menyebut baris yang salah, dan tidak ada perubahan yang tersimpan.
4. **Given** bahan "Telur" dipetakan oleh pilihan di tiga grup berbeda, **When** pengelola membuka detail bahan Telur, **Then** ketiga pilihan (beserta grupnya) tercantum sebagai pemakai bahan ini.
5. **Given** bahan berstatus nonaktif atau terhapus, **When** pengelola mencari bahan untuk dipetakan, **Then** bahan itu tidak bisa dipilih; pemetaan lama yang merujuk bahan yang kemudian dinonaktifkan tetap tampil dengan penanda "nonaktif".

---

### User Story 2 - Penjualan memotong stok bahan dari pilihan yang dipesan (Priority: P1)

Kasir menjual Indomie Kuah dengan Rasa Kuah SOTO, Level Pedas Sedang, Topping Telur. Sistem otomatis mengurangi stok outlet tempat transaksi: Indomie Soto −1, Telur −1. Level Pedas "Sedang" tidak memotong apa pun karena tidak dipetakan. Setiap potongan tercatat di riwayat pergerakan bahan dan bisa dilacak ke baris pesanan asalnya.

**Why this priority**: Inilah tujuan utama permintaan. Bersama US1 ini sudah menjadi MVP.

**Independent Test**: Catat stok awal outlet A (Indomie Soto 50, Telur 100). Jual 2 porsi Indomie Kuah (SOTO, Sedang, Telur) di outlet A lewat POS web dan lewat tablet. Stok menjadi Indomie Soto 46, Telur 96; riwayat bahan menampilkan empat baris potongan yang masing-masing menunjuk ke nomor pesanan dan barisnya. Stok outlet B tidak berubah.

**Acceptance Scenarios**:

1. **Given** pemetaan SOTO → 1 Indomie Soto dan Telur → 1 Telur, **When** satu baris "Indomie Kuah ×1 (SOTO, Sedang, Telur)" terjual, **Then** stok Indomie Soto turun 1 dan Telur turun 1 di outlet transaksi, dan tidak ada pergerakan untuk bahan lain.
2. **Given** baris yang sama dengan jumlah 3, **When** terjual, **Then** potongan dikalikan jumlah baris: Indomie Soto −3, Telur −3.
3. **Given** Indomie Kuah (Topping Telur) dan Nasi Goreng (Topping Tambahan Telur) dalam SATU pesanan, **When** pesanan terjual, **Then** keduanya memotong dari SATU saldo bahan Telur outlet itu (total −2), bukan dari saldo terpisah per menu atau per grup.
4. **Given** pilihan Extra Pedas → 2 gram Cabai Bubuk, **When** dipesan pada baris berjumlah 2, **Then** Cabai Bubuk turun 4 gram.
5. **Given** pilihan tanpa pemetaan bahan, **When** dipesan, **Then** tidak ada pergerakan bahan untuk pilihan itu dan penjualan berjalan seperti sebelum fitur ini.
6. **Given** potongan sudah tercatat untuk sebuah baris, **When** pemetaan pilihan itu diubah kemudian, **Then** riwayat lama tidak berubah dan pengembalian stok untuk baris lama memakai jumlah yang benar-benar dipotong dulu, bukan pemetaan baru.
7. **Given** penjualan di mode DEMO, **When** terjual, **Then** potongan hanya memengaruhi stok bahan DEMO; stok LIVE tidak tersentuh.
8. **Given** tab meja terbuka berisi Indomie Kuah (SOTO, Telur) ×2 yang sudah dikirim ke dapur, **When** tab belum dibayar lunas, **Then** stok bahan belum berubah; **When** tab dilunasi dan pesanan selesai, **Then** Indomie Soto −2 dan Telur −2 tercatat sekaligus, dari isi tab saat selesai.
9. **Given** penjualan ritel di kasir (dibayar saat checkout), **When** checkout berhasil, **Then** pesanan langsung selesai dan bahan langsung terpotong.

---

### User Story 3 - Pembatalan, pengurangan, dan refund mengembalikan bahan dengan benar (Priority: P1)

Karena bahan baru dipotong saat pesanan **selesai** (FR-015), perubahan selama tab masih terbuka (kurangi jumlah, ganti pilihan, hapus baris) tidak menghasilkan pergerakan bahan apa pun: yang dipotong adalah isi tab saat selesai. Setelah pesanan selesai, void dan refund mengembalikan persis yang dulu dipotong. Satu-satunya pengecualian adalah baris yang sudah dimasak lalu dibatalkan dengan keputusan "buang" (waste) saat tab masih terbuka: bahannya benar-benar terpakai, jadi dicatat sebagai bahan terbuang saat itu juga.

**Why this priority**: Tanpa ini stok bahan melenceng setiap kali ada koreksi, dan pemilik akan berhenti mempercayai angkanya. Harus dikirim bersama US2.

**Independent Test**: Buka tab, tambah Indomie Kuah (SOTO, Telur) ×2, kurangi jadi ×1, ganti Topping ke Sosis: stok bahan tidak berubah sama sekali. Lunasi tab → hanya Indomie Soto −1 dan Sosis −1. Di tab lain, batalkan baris yang sudah dimasak dengan "buang" → bahannya tercatat terbuang saat itu. Refund pesanan pertama → Indomie Soto +1, Sosis +1.

**Acceptance Scenarios**:

1. **Given** tab terbuka, **When** jumlah baris dikurangi, pilihan diganti, atau baris belum-dimasak dihapus, **Then** tidak ada pergerakan bahan; **And** saat tab selesai, bahan dipotong dari isi akhir tab saja.
2. **Given** baris di tab terbuka yang dapurnya sudah mulai, **When** dibatalkan dengan keputusan "buang" (waste), **Then** bahan pilihan baris itu langsung dipotong dengan jenis "terbuang" (bukan "penjualan"), merujuk ke baris yang dibatalkan, dan baris itu tidak dipotong lagi saat tab selesai.
3. **Given** baris di tab terbuka yang dapurnya belum mulai, **When** dibatalkan dengan keputusan "kembalikan", **Then** tidak ada pergerakan bahan.
4. **Given** tab terbuka dibatalkan seluruhnya (void tab), **When** sebagian baris sudah dimasak dan diputuskan "buang", **Then** hanya bahan baris-baris itu yang tercatat terbuang; sisanya tidak menghasilkan pergerakan.
5. **Given** pesanan selesai, **When** di-void atau di-refund, **Then** semua bahan yang dipotong saat selesai dikembalikan sejumlah yang dulu dipotong, masing-masing tercatat sebagai pengembalian yang menunjuk ke baris asalnya.
6. **Given** sebuah pesanan sudah di-refund atau di-void, **When** dicoba lagi, **Then** ditolak seperti hari ini dan tidak ada pengembalian bahan kedua.

---

### User Story 4 - Stok bahan tidak cukup (Priority: P2)

Penjualan TIDAK PERNAH ditolak karena stok bahan. Bila bahan kurang, saldo bahan boleh menjadi minus, kasir diberi peringatan, dan kekurangan itu terlihat oleh pemilik sebagai tanda untuk stok opname atau pembelian.

**Why this priority**: Angka stok bahan di dapur sering tidak persis; menghentikan kasir karena sistem mengira telur habis lebih merugikan daripada saldo minus sementara.

**Independent Test**: Set stok Telur outlet A = 1. Selesaikan pesanan Indomie Kuah (Telur) ×2 di web dan di tablet. Pesanan berhasil; saldo Telur menjadi −1; kasir melihat peringatan yang menyebut Telur; Telur tampil sebagai minus di daftar bahan.

**Acceptance Scenarios**:

1. **Given** stok Telur 1 dan pesanan memerlukan 2, **When** pesanan selesai, **Then** pesanan tersimpan, saldo Telur −1, pergerakan mencatat saldo sebelum 1 dan sesudah −1, dan kasir menerima peringatan berisi nama bahan, saldo sebelum, dan kebutuhan.
2. **Given** saldo bahan minus, **When** pemilik membuka daftar bahan, **Then** saldo outlet itu ditandai minus, dan saringan "hanya yang minus" menampilkan bahan tersebut.
3. **Given** saldo bahan minus, **When** pembelian (penerimaan PO), koreksi, atau transfer masuk dicatat, **Then** saldo bertambah dari angka minus itu (−1 + 10 = 9).
4. **Given** koreksi manual atau transfer keluar akan membuat saldo minus, **When** dicatat, **Then** tetap ditolak seperti hari ini; hanya penjualan yang boleh membuat saldo minus.

---

### User Story 5 - Laporan pemakaian bahan dari penjualan (Priority: P3)

Pemilik melihat berapa banyak tiap bahan terpakai oleh penjualan dalam rentang tanggal bisnis, per outlet, terpisah dari pembelian, koreksi, dan transfer.

**Why this priority**: Memberi nilai analitik, tetapi data dasarnya (riwayat pergerakan dari US2/US3) sudah cukup untuk audit manual.

**Independent Test**: Setelah beberapa penjualan dan satu refund di hari bisnis yang sama, buka riwayat/ringkasan bahan Telur: pemakaian bersih = potongan penjualan dikurangi pengembalian.

**Acceptance Scenarios**:

1. **Given** penjualan dan refund pada rentang tanggal, **When** pemilik memfilter riwayat bahan menurut jenis "pemakaian penjualan", **Then** hanya pergerakan dari penjualan dan pengembaliannya yang tampil, masing-masing dengan nomor pesanan.

---

### User Story 6 - Pilih sumber stok per produk: varian atau modifier (Priority: P1)

*(Ditambahkan 2026-10-31 atas permintaan pemilik: "in product, add option to use stock from variant or modifier; apply the stock logic to all modules".)*

Di formulir produk, pengelola memilih dari mana stok produk itu berasal:

- **Varian**: stok dihitung pada varian produk itu sendiri (perilaku hari ini; contoh: Teh Botol, stok per botol).
- **Modifier**: produk itu sendiri tidak punya stok; yang dikurangi adalah bahan baku dari pilihan modifier yang dipesan (contoh: Indomie Kuah, stoknya ada di bungkus Indomie Soto / Ayam Bawang yang dipilih lewat Rasa Kuah).

Pilihan ini berlaku SAMA di semua modul yang membaca atau menulis stok (FR-030), sehingga satu produk tidak pernah dihitung dua kali dan tidak pernah tampil "habis" karena angka yang salah.

**Why this priority**: Tanpa pilihan ini, produk seperti Indomie Kuah tetap punya stok varian palsu (dihitung terpisah dari bungkus Indomie yang sebenarnya), sehingga stok ganda atau kasir melihat "habis" padahal bahan masih ada. Pemilik sudah memutuskan menghapus varian Indomie dan memakai modifier (catatan 2026-10-31), jadi produk itu membutuhkan sumber stok "modifier".

**Independent Test**: Set Indomie Kuah = sumber "Modifier", Teh Botol = sumber "Varian". Jual keduanya di POS web dan tablet, lalu void/refund dan transfer stok. Indomie Kuah: stok varian tidak pernah berubah dan tidak pernah tampil "habis"; bahan pilihannya berkurang/kembali. Teh Botol: stok varian berkurang/kembali seperti hari ini. Halaman Produk, laporan stok, dan grid tablet menampilkan stok masing-masing sesuai sumbernya.

**Acceptance Scenarios**:

1. **Given** produk baru atau produk lama, **When** formulir produk dibuka, **Then** ada pilihan "Sumber stok: Varian / Modifier"; produk lama bernilai "Varian" sampai diubah, sehingga tidak ada perilaku yang berubah diam-diam.
2. **Given** Indomie Kuah bersumber "Modifier", **When** dijual, di-void, di-refund, atau dibatalkan di modul mana pun (POS web ritel, tab/meja, tablet Android, split), **Then** stok varian Indomie Kuah tidak pernah bergerak, dan bahan pilihan modifier-nya bergerak sesuai FR-008–FR-020.
3. **Given** Teh Botol bersumber "Varian", **When** dijual atau dibatalkan, **Then** stok variannya bergerak seperti hari ini, DAN bahan pilihan modifier-nya (mis. "Extra Shot") tetap dipotong (FR-025).
4. **Given** pengelola mengubah sumber Indomie Kuah dari "Varian" ke "Modifier", **When** menyimpan, **Then** sistem memperingatkan bila varian masih punya saldo stok (saldo itu tidak lagi dipakai), perubahan tercatat di log aktivitas, dan pesanan yang sudah ada tidak diubah.
5. **Given** produk bersumber "Modifier", **When** ditampilkan di kartu POS web, grid tablet, halaman Produk, dan laporan stok, **Then** tampilannya mengikuti FR-031 dan tidak pernah memakai angka stok varian.
6. **Given** produk bersumber "Modifier" tetapi tidak satu pun pilihannya memetakan bahan, **When** pengelola menyimpan, **Then** sistem memperingatkan bahwa produk ini tidak akan mengurangi stok apa pun (tidak ditolak).

---

### Edge Cases

- **Satu pilihan, banyak bahan; banyak pilihan, satu bahan**: Extra Pedas memakai dua bahan; Telur dan "Double Telur" sama-sama memakai bahan Telur. Dalam satu baris, pemakaian per bahan dijumlahkan sebelum dipotong, sehingga satu bahan = satu pergerakan per baris.
- **Grup dipakai banyak menu**: pemetaan melekat pada pilihan, jadi berlaku sama untuk semua menu yang memakai grup itu. Menu yang butuh jumlah berbeda (mis. Nasi Goreng memakai 2 telur) memakai grup/pilihan tersendiri (lihat Asumsi).
- **Grup warisan kategori yang dilepas (086)**: pilihan dari grup yang dilepas tidak bisa dipesan, jadi tidak memotong apa pun.
- **Produk yang stoknya tidak dilacak** (mis. menu masak, varian `track_stock` mati): diatur oleh sumber stok produk (US6). Sumber "Modifier" = bahan pilihan dipotong, varian tidak; sumber "Varian" dengan pelacakan mati = tidak ada stok varian yang bergerak, dan bahan pilihan mengikuti FR-025.
- **Sumber stok diubah saat ada tab terbuka berisi produk itu**: yang berlaku adalah sumber pada saat baris DITAMBAHKAN untuk stok varian (sudah dipotong saat item masuk tab) dan sumber pada saat pesanan SELESAI untuk bahan; plan harus memastikan tidak ada potongan ganda atau yang terlewat (lihat FR-028).
- **Transfer stok antar-outlet** untuk varian produk bersumber "Modifier": ditolak, karena varian itu tidak punya stok; yang ditransfer adalah bahannya.
- **Pemetaan diubah atau dihapus setelah penjualan**: pengembalian memakai jumlah yang tercatat saat penjualan.
- **Bahan dinonaktifkan/dihapus setelah dipetakan**: penjualan baru tidak memotong bahan nonaktif dan pengelola diberi peringatan di grup modifier; pengembalian untuk penjualan lama tetap ke bahan itu.
- **Jumlah pecahan**: 0,5 sdt garam × 3 porsi = 1,5; pembulatan hanya pada presisi penyimpanan stok bahan, bukan per porsi.
- **Kirim ulang / retry jaringan** dari tablet atau web dengan kunci idempotensi yang sama: tidak ada potongan kedua.
- **Dua kasir menjual bahan yang sama bersamaan** di outlet yang sama: kedua potongan tercatat, saldo akhir benar, tidak ada yang hilang.
- **Split / pindah meja / gabung tab** selagi terbuka: tidak ada pergerakan bahan; tiap pesanan hasil split memotong bahannya sendiri saat ia selesai. Pindah meja pesanan yang sudah selesai (084) tidak mengubah stok.
- **Tab dibiarkan terbuka melewati tutup hari / tutup shift**: bahan belum terpotong sampai tab selesai, jadi stok hari itu tampak lebih tinggi dari pemakaian fisik. Ini konsekuensi yang diterima dari FR-015.
- **Baris "buang" lalu tab selesai**: baris yang dibatalkan sudah tidak ada di tab, jadi tidak terpotong dua kali.
- **Pesanan di outlet A untuk item milik outlet B**: bahan dipotong dari outlet TRANSAKSI, sama seperti stok produk.
- **Bahan yang tidak punya saldo di outlet itu sama sekali**: diperlakukan sebagai saldo 0, lalu boleh menjadi minus (FR-014).
- **Penyelesaian tab dicoba dua kali** (pelunasan ganda, retry): pesanan hanya bisa berubah menjadi selesai sekali, jadi bahan hanya terpotong sekali.

## Requirements *(mandatory)*

### Functional Requirements

**Pemetaan pemakaian bahan**

- **FR-001**: Pengelola menu (peran yang sudah boleh mengelola grup modifier) MUST dapat menentukan untuk setiap pilihan modifier nol atau lebih baris pemakaian bahan, masing-masing berisi satu bahan baku dan jumlah pemakaian per satu pesanan pilihan itu.
- **FR-002**: Jumlah pemakaian MUST lebih besar dari nol, boleh pecahan (sekurangnya tiga angka desimal), dan dinyatakan dalam satuan bahan itu sendiri. Satu bahan MUST NOT muncul dua kali pada pilihan yang sama.
- **FR-003**: Hanya bahan aktif pada mode data yang sama (DEMO/LIVE) yang MUST dapat dipilih untuk dipetakan.
- **FR-004**: Satu bahan baku MUST dapat dirujuk oleh sejumlah pilihan modifier mana pun, dari grup mana pun, yang dipakai menu mana pun; sistem MUST NOT membuat saldo stok terpisah untuk bahan yang sama karena dipakai menu, grup, atau pilihan yang berbeda. Saldo bahan tetap satu per bahan per outlet.
- **FR-005**: Detail bahan baku MUST menampilkan daftar pilihan modifier (dengan nama grupnya) yang memakai bahan itu.
- **FR-006**: Perubahan pemetaan MUST tercatat di log aktivitas (siapa, kapan, pilihan mana, sebelum/sesudah).
- **FR-007**: Pemetaan MUST ikut ekspor/impor master data bila grup modifier sudah termasuk di sana; bila belum, cukup terlihat di layar (lihat Asumsi).

**Pemotongan saat penjualan**

- **FR-008**: Saat pesanan selesai (FR-015), sistem MUST memotong stok setiap bahan yang dipetakan oleh pilihan modifier pada tiap baris pesanan sebesar (jumlah pemakaian × jumlah baris), dari saldo outlet transaksi.
- **FR-009**: Bila beberapa pilihan pada satu baris memakai bahan yang sama, sistem MUST menjumlahkannya menjadi satu pergerakan per bahan per baris.
- **FR-010**: Pilihan tanpa pemetaan MUST NOT menghasilkan pergerakan bahan apa pun.
- **FR-011**: Pemotongan bahan dan perubahan status pesanan menjadi selesai (beserta pembayaran yang menyelesaikannya) MUST berhasil atau gagal bersama; tidak boleh ada pesanan selesai tanpa potongan bahannya, atau potongan bahan untuk pesanan yang gagal selesai.
- **FR-012**: Setiap pergerakan bahan dari penjualan MUST mencatat bahan, outlet, jenis (pemakaian penjualan / pengembalian / terbuang), jumlah, saldo sebelum dan sesudah, pengguna, waktu, dan rujukan ke baris pesanan asalnya, sehingga dari pergerakan bisa ditelusuri ke pesanan dan dari pesanan ke pergerakan.
- **FR-013**: Kirim ulang permintaan yang sama (kunci idempotensi pesanan/tab yang sama) MUST NOT menghasilkan potongan kedua; dua transaksi bersamaan atas bahan yang sama MUST menghasilkan saldo akhir yang benar.

**Stok tidak cukup**

- **FR-014** *(diputuskan 2026-10-31: izinkan minus)*: Bila saldo bahan di outlet transaksi tidak cukup, penyelesaian pesanan MUST tetap berhasil; saldo bahan boleh menjadi minus; pergerakan mencatat saldo sebelum dan sesudah apa adanya; kasir (web dan tablet) MUST menerima peringatan yang menyebut nama bahan, saldo sebelum, dan kebutuhan. Peringatan tidak menghalangi transaksi.
- **FR-014a**: Bahan bersaldo minus MUST ditandai di daftar bahan (per outlet) dan bisa disaring "hanya yang minus". (Catatan plan 2026-10-31: laporan stok rendah hari ini hanya untuk produk; bahan tidak punya laporan itu, jadi tanda minus hidup di daftar bahan.) Pembelian, koreksi, dan transfer masuk MUST menambah saldo dari angka minus itu.
- **FR-014b**: Hanya pemotongan karena penjualan dan bahan terbuang yang boleh membuat saldo minus; koreksi manual dan transfer keluar MUST tetap ditolak bila hasilnya minus, seperti hari ini.

**Saat pemotongan, pembatalan, refund**

- **FR-015** *(diputuskan 2026-10-31: saat pesanan selesai)*: Bahan MUST dipotong hanya pada saat pesanan berubah menjadi **selesai**: penjualan ritel saat checkout berhasil (pesanan langsung selesai), tab saat dilunasi dan ditutup. Yang dipotong adalah isi pesanan pada saat itu. Selama pesanan masih terbuka, tidak ada pergerakan bahan karena penjualan. (Ini berbeda dari stok produk, yang tetap dipotong saat item ditambahkan ke tab; perilaku stok produk tidak diubah.)
- **FR-016**: Menambah/mengurangi jumlah, mengganti pilihan modifier, menghapus baris, split, gabung, dan pindah meja selama pesanan terbuka MUST NOT menghasilkan pergerakan bahan.
- **FR-017**: Pesanan hanya bisa menjadi selesai satu kali; percobaan penyelesaian kedua (pelunasan ganda, retry) MUST NOT memotong bahan lagi.
- **FR-018**: Membatalkan baris yang dapurnya sudah mulai dengan keputusan "buang" (waste) selagi pesanan terbuka MUST langsung memotong bahan pilihan baris itu dengan jenis "terbuang" yang merujuk ke baris itu. Keputusan "kembalikan" (dapur belum mulai) MUST NOT menghasilkan pergerakan bahan. Void seluruh tab terbuka mengikuti aturan yang sama per baris.
- **FR-019**: Void pesanan selesai dan refund MUST mengembalikan setiap bahan sebesar yang benar-benar dipotong untuk baris-baris pesanan itu (bersih dari pengembalian sebelumnya), bukan dihitung ulang dari pemetaan saat ini.
- **FR-020**: Pengembalian bahan MUST NOT melebihi potongan bersih baris itu; refund atau void yang ditolak (sudah di-refund, sudah di-void) MUST NOT mengembalikan bahan. Bahan terbuang (FR-018) tidak pernah dikembalikan.

**Mode, outlet, dan klien**

- **FR-021**: Semua pemetaan dan pergerakan MUST terpisah antara mode DEMO dan LIVE, dan antara outlet, mengikuti aturan yang sudah berlaku untuk stok bahan.
- **FR-022**: POS web dan tablet Android MUST menghasilkan potongan yang identik untuk pesanan yang sama; perhitungan potongan MUST dilakukan oleh server, tidak pernah dari jumlah yang dikirim klien.

**Sumber stok per produk** *(ditambahkan 2026-10-31, US6)*

- **FR-024**: Setiap produk MUST punya pengaturan **sumber stok** dengan nilai `Varian` atau `Modifier`, diatur di formulir produk (buat dan ubah) oleh peran yang sudah boleh mengelola produk. Produk yang sudah ada MUST bernilai `Varian` setelah fitur ini dipasang.
- **FR-025** *(diputuskan 2026-10-31: gabungan)*: Untuk produk bersumber `Varian`, stok varian MUST bergerak seperti hari ini DAN bahan dari pilihan modifier-nya MUST tetap dipotong sesuai FR-008–FR-020 (mis. Kopi Susu botol + bahan "Extra Shot"). Jadi bahan pilihan modifier dipotong untuk SEMUA produk; sumber stok hanya menentukan apakah stok VARIAN ikut bergerak.
- **FR-026**: Untuk produk bersumber `Modifier`, stok varian MUST NOT bergerak di jalur mana pun (jual, kurangi, hapus baris, void, refund, buang, transfer), dan bahan dari pilihan modifier yang dipesan MUST dipotong dan dikembalikan sesuai FR-008–FR-020.
- **FR-027**: Mengubah sumber stok MUST tercatat di log aktivitas dalam transaksi yang sama (sebelum/sesudah). Bila varian masih punya saldo stok saat diubah ke `Modifier`, sistem MUST memperingatkan; saldo itu dibiarkan (tidak dihapus, tidak dipindah).
- **FR-028**: Sumber stok yang dipakai MUST ditetapkan sekali per baris pesanan dan disimpan bersama baris itu, sehingga pengembalian (kurangi, hapus, void, refund) memakai sumber yang sama dengan saat stok dikurangi, walaupun pengaturan produk diubah di tengah jalan.
- **FR-029**: Produk bersumber `Modifier` yang tidak punya satu pun pilihan berpemetaan bahan MUST diberi peringatan saat disimpan (tidak ditolak).
- **FR-030**: Aturan sumber stok MUST diterapkan di SEMUA modul yang membaca atau menulis stok produk, tanpa pengecualian, termasuk sekurangnya: penjualan ritel POS web; tab/meja (tambah, ubah, hapus, buang, pindah, gabung, split, lunas, void); tablet Android (grid, keranjang, tab, pelunasan); void dan refund pesanan selesai; transfer stok antar-outlet; koreksi stok; halaman Produk (daftar, detail, formulir); laporan stok (stok per outlet, stok rendah, riwayat pergerakan); ekspor/impor master data produk; dan pengisi katalog WJM. Satu aturan di server menentukan sumber stok sebuah baris; klien tidak pernah memutuskannya.
- **FR-031** *(diputuskan 2026-10-31: seperti menu masak)*: Produk bersumber `Modifier` MUST tampil TANPA angka stok dan MUST NOT pernah tampil "habis" karena stok di kartu POS web, grid tablet, halaman Produk, dan laporan stok (stok per outlet, stok rendah), sama seperti varian yang pelacakan stoknya mati hari ini. Penanda habis manual (86) tetap berlaku. Kekurangan bahan terlihat di daftar bahan (FR-014a), bukan di produk.

**Biaya**

- **FR-023**: Biaya bahan dari pilihan modifier yang dipesan MUST ditambahkan ke biaya pokok baris pesanan saat pesanan selesai, memakai sumber harga bahan yang sama dengan perhitungan HPP resep/BOM, sehingga laporan margin mencerminkan topping yang dipakai; pesanan lama tidak berubah.

### Key Entities *(include if feature involves data)*

- **Grup Modifier** (sudah ada): pertanyaan kustomisasi, dipakai bersama banyak menu dan kategori.
- **Pilihan Modifier** (sudah ada): jawaban dalam grup, dengan selisih harga. Kini punya nol atau lebih Pemakaian Bahan Pilihan.
- **Pemakaian Bahan Pilihan** (baru): pasangan (pilihan modifier, bahan baku, jumlah per pesanan pilihan). Unik per (pilihan, bahan).
- **Bahan Baku** (sudah ada): barang inventori dengan satu satuan; satu saldo per outlet per mode. Dirujuk oleh banyak Pemakaian Bahan Pilihan.
- **Saldo Bahan per Outlet** (sudah ada): satu-satunya tempat stok bahan disimpan; tidak pernah per menu/pilihan.
- **Pergerakan Stok Bahan** (sudah ada, diperluas): riwayat append-only; kini juga jenis "pemakaian penjualan", "pengembalian penjualan", dan "terbuang", dengan rujukan ke baris pesanan. Saldo bahan kini boleh minus, hanya karena penjualan/terbuang.
- **Baris Pesanan** (sudah ada): menyimpan salinan pilihan modifier yang dipesan; menjadi rujukan pergerakan bahan.
- **Resep / BOM Varian** (sudah ada, tidak diubah): pemakaian bahan menu itu sendiri; tetap hanya untuk HPP di fitur ini.
- **Sumber Stok Produk** (baru, US6): pengaturan per produk, `Varian` atau `Modifier`; menentukan stok apa yang bergerak untuk baris pesanan produk itu di semua modul. Disalin ke baris pesanan saat stok dikurangi (FR-028).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Untuk setiap pesanan yang memuat pilihan berpemetaan, 100% bahan terpotong tepat sebesar Σ(pemakaian × jumlah baris), diverifikasi dengan membandingkan saldo sebelum/sesudah pada skenario uji Indomie Kuah, Nasi Goreng, dan Extra Pedas.
- **SC-002**: Untuk tab yang diubah (tambah, kurangi, ganti pilihan) lalu diselesaikan, potongan bahan = isi akhir tab (selisih 0); setelah refund, saldo setiap bahan kembali tepat ke saldo awal (selisih 0). Tidak ada pergerakan bahan selama tab terbuka kecuali baris "buang".
- **SC-002a**: Pesanan yang bahannya kurang tetap selesai 100% di web dan tablet, dengan peringatan tampil ke kasir dan bahan minus tampil di daftar bahan (saringan "hanya yang minus").
- **SC-003**: 0 potongan ganda pada uji kirim ulang (permintaan sama dikirim 2× atau lebih) dan 0 selisih saldo pada uji 20 penjualan bersamaan atas bahan yang sama.
- **SC-004**: Setiap pergerakan bahan dari penjualan dapat ditelusuri ke nomor pesanan dan baris asalnya dalam satu langkah dari layar riwayat bahan.
- **SC-005**: Pengelola dapat memetakan bahan untuk satu grup berisi 4 pilihan dalam waktu kurang dari 2 menit.
- **SC-006**: Waktu kasir menyelesaikan pesanan tidak bertambah yang terasa (pesanan 10 baris dengan 3 pilihan berpemetaan per baris selesai sama cepatnya dengan sebelum fitur ini, dalam toleransi 10%).
- **SC-007**: POS web dan tablet menghasilkan riwayat pergerakan bahan yang identik untuk pesanan yang sama.
- **SC-008**: Untuk produk bersumber `Modifier`, 0 pergerakan stok varian di seluruh skenario uji US2, US3, dan US6 (jual, tab, split, void, refund, buang, transfer); untuk produk bersumber `Varian`, pergerakan stok varian identik dengan sebelum fitur ini.
- **SC-009**: Setiap modul dalam daftar FR-030 punya sekurangnya satu uji yang membuktikan ia mengikuti sumber stok produk.

## Assumptions

- **Pemetaan pada tingkat pilihan, bukan per menu.** Karena grup modifier dipakai bersama, pemakaian bahan sebuah pilihan sama untuk semua menu yang memakai grupnya. Penimpaan per menu (mis. Telur di Nasi Goreng = 2 butir) di luar cakupan; caranya: buat grup/pilihan tersendiri. Rancangan tidak menutup kemungkinan penimpaan per menu di kemudian hari.
- **Satuan tunggal per bahan, tanpa konversi.** Jumlah pemakaian dinyatakan dalam satuan bahan (gram, butir, ml, bungkus). Konversi antarsatuan (kg ↔ g, karton ↔ butir) di luar cakupan.
- **Resep/BOM menu tetap hanya untuk HPP.** Memotong bahan resep menu itu sendiri (mis. mangkuk, kemasan) adalah fitur lanjutan; rancangan dibuat agar keduanya bisa memakai jalur potong yang sama nanti.
- **Peran**: siapa pun yang hari ini boleh mengubah grup modifier boleh mengubah pemetaannya; tidak ada peran baru.
- **Saldo bahan per outlet** (045) dipertahankan: "satu catatan inventori terpusat" berarti satu saldo per bahan per outlet, bukan per menu atau pilihan. Outlet yang berbeda tetap punya saldo fisik masing-masing.
- **Android**: tidak perlu menghitung apa pun; hanya perlu menampilkan pesan dari server (penolakan/peringatan stok bahan) dengan baik. APK lama tetap berfungsi karena semua perhitungan di server.
- **Ekspor/impor master data**: bila grup modifier belum termasuk lembar master data, pemetaan bahan juga tidak ditambahkan ke sana di fitur ini.
- **Sumber stok bawaan `Varian`** untuk semua produk lama: tidak ada stok yang berubah perilaku sampai pemilik mengubah produk tertentu. ASUMSI: pengaturan per varian `track_stock` (sudah ada) tetap berlaku di dalam sumber `Varian`; sumber `Modifier` mengabaikannya.
- **Pembatalan baris yang sudah dimasak** tetap memakai keputusan stok yang sudah ada (084); fitur ini tidak menambah pilihan "kembalikan sebagian bahan".
- **Bahan terbuang dicatat saat pembatalan "buang"** (ASUMSI, diturunkan dari jawaban Q2=B): karena bahan baru dipotong saat selesai, baris yang dimasak lalu dibuang tidak akan pernah memotong bahan bila tidak dicatat terpisah, dan stok akan lebih tinggi dari fisiknya. Bila pemilik lebih suka mengabaikan bahan terbuang, FR-018 cukup dihapus.
- **Waktu potong bahan berbeda dari stok produk** (keputusan 2026-10-31): stok produk tetap dipotong saat item masuk tab, bahan saat pesanan selesai. Tab yang lama terbuka membuat stok bahan sementara tampak lebih tinggi dari pemakaian fisik.
