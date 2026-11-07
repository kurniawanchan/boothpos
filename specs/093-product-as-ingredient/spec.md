# Feature Specification: Produk Sebagai Bahan Resep dengan Stok Bersama

**Klasifikasi**: INTERNAL

**Feature Branch**: `093-product-as-ingredient`

**Created**: 2026-11-07

**Status**: Draft

**Input**: User description: "Izinkan produk yang sudah ada dipakai juga sebagai bahan di resep produk lain, dengan SATU saldo stok bersama: penjualan langsung dan pemakaian sebagai bahan memotong saldo yang sama. Tambah penanda 'Gunakan sebagai bahan' di formulir produk, pilihan bahan di resep menampilkan produk bertanda itu, stok menu beresep dihitung dari semua bahannya, berlaku sama di POS Web dan Android, aman terhadap transaksi serentak dan kiriman ulang, dan resep rekursif harus didefinisikan (didukung atau dilarang)."

## Latar

Hari ini resep hanya bisa memakai **bahan baku** (`materials`): catatan terpisah dengan saldo sendiri (per outlet). Konsekuensinya, barang yang dijual langsung sekaligus dipakai sebagai bahan (mi instan, telur, roti) harus dicatat **dua kali**: sebagai produk (untuk dijual) dan sebagai bahan baku (untuk resep). Dua saldo itu tidak pernah tahu satu sama lain, sehingga:

1. Telur yang dijual langsung tidak mengurangi telur yang dianggap tersedia untuk resep (dan sebaliknya).
2. Pemilik harus menyesuaikan/menghitung dua saldo untuk satu barang fisik.
3. Stok menu beresep (092) bisa tampak tersedia padahal barangnya sudah habis dijual langsung.

Fitur ini menghapus duplikasi itu: sebuah produk adalah **satu entitas stok**; ia boleh dijual langsung, dipakai sebagai bahan, atau keduanya, dan setiap operasi membaca serta mengubah **saldo yang sama**.

Istilah:

| Istilah | Arti | Contoh |
|---|---|---|
| Produk bahan | Produk yang ditandai "Gunakan sebagai bahan" | Indomie Goreng, Telur |
| Menu beresep | Varian yang punya resep aktif (pengertian 092); stoknya diturunkan dari bahan | Indomie Kuah |
| Bahan baku | Catatan bahan lama (`materials`) dengan saldo terpisah; tetap ada dan tetap berfungsi | Gula curah, Kuah kaldu |
| Saldo bersama | Satu-satunya saldo stok per varian per outlet, dipakai penjualan langsung DAN pemakaian sebagai bahan | Telur: 200 pcs |
| Tahanan | Jumlah yang sudah "diambil" oleh pesanan terbuka tetapi belum dipotong dari saldo | 2 Indomie Kuah di tab terbuka menahan 2 Telur |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pemilik menandai produk sebagai bahan dan memakainya di resep (Priority: P1)

Pemilik membuka formulir produk "Telur" dan mengaktifkan **Gunakan sebagai bahan**. Lalu di resep "Indomie Kuah" ia memilih bahan dari daftar yang kini juga memuat produk bertanda itu, mengisi jumlah per porsi, dan menyimpan. Tidak ada catatan bahan baku baru dan tidak ada saldo baru yang dibuat.

**Why this priority**: Ini inti fitur. Tanpa penanda dan pilihan bahan, tidak ada yang bisa dipakai.

**Independent Test**: Tandai "Telur" (stok 200) dan "Indomie Goreng" (stok 100) sebagai bahan; buat resep "Indomie Kuah" berisi 1 Indomie Goreng + 1 Telur; resep tersimpan dan kedua produk muncul di pilihan bahan dengan nama, SKU, satuan, dan stok tersedia.

**Acceptance Scenarios**:

1. **Given** produk "Telur" belum bertanda bahan, **When** pemilik membuka pilihan bahan di resep, **Then** "Telur" tidak muncul.
2. **Given** pemilik mengaktifkan "Gunakan sebagai bahan" pada "Telur", **When** ia membuka pilihan bahan di resep, **Then** "Telur" muncul dengan nama, SKU, satuan, dan stok tersedia di outlet terpilih, dan dapat dipilih tanpa membuat catatan bahan baku.
3. **Given** resep "Indomie Kuah" memakai "Telur", **When** pemilik menyimpan resep, **Then** tidak ada saldo stok baru dibuat; resep merujuk produk "Telur" yang sudah ada.
4. **Given** sebuah produk, **When** pemilik menandainya sebagai bahan, **Then** produk itu tetap dapat dijual langsung di POS seperti sebelumnya.
5. **Given** satu resep, **When** pemilik menambah baris bahan, **Then** ia dapat mencampur bahan baku lama dan produk bahan dalam resep yang sama.

---

### User Story 2 - Penjualan menu beresep memotong saldo produk bahan (Priority: P1)

Kasir menjual satu "Indomie Kuah". Saat pesanan selesai, saldo "Indomie Goreng" berkurang 1 dan "Telur" berkurang 1, dan penjualan langsung "Telur" memakai saldo yang sama.

**Why this priority**: Nilai bisnis utamanya: satu barang fisik, satu saldo, tanpa pencatatan ganda.

**Independent Test**: Dengan Indomie Goreng 100, Telur 200: jual 1 Indomie Kuah → 99 dan 199. Jual 3 Telur langsung → 196. Satu angka Telur, dua jalur penjualan.

**Acceptance Scenarios**:

1. **Given** Indomie Goreng 100 dan Telur 200, **When** 1 Indomie Kuah terjual (pesanan selesai), **Then** Indomie Goreng menjadi 99 dan Telur menjadi 199.
2. **Given** Telur 199, **When** 3 Telur dijual langsung, **Then** Telur menjadi 196 dan angka itu yang dilihat resep serta POS.
3. **Given** satu pesanan memuat 2 Indomie Kuah dan 1 Telur langsung, **When** pesanan selesai, **Then** Telur berkurang 3 (2 dari resep + 1 langsung) dan tiap pengurangan tercatat dengan sumbernya masing-masing.
4. **Given** menu beresep dengan bahan produk dan bahan baku sekaligus, **When** pesanan selesai, **Then** produk bahan dipotong dari saldo bersama dan bahan baku dari saldo bahan baku, dalam satu kesatuan (semua berhasil atau tidak ada yang berubah).
5. **Given** pesanan yang sudah selesai, **When** pemilik mengubah resep atau mematikan penanda bahan, **Then** riwayat pesanan itu tidak berubah.

---

### User Story 3 - Stok menu beresep memperhitungkan saldo bersama dan tahanan (Priority: P1)

Kartu "Indomie Kuah" di POS Web dan Android menampilkan jumlah porsi maksimum dari bahan paling terbatas, dan angka itu ikut turun ketika produk bahan dijual langsung atau ditahan oleh tab terbuka.

**Why this priority**: Tanpa ini, stok menu menyesatkan dan kasir bisa menjual melebihi bahan.

**Independent Test**: Indomie Goreng 100, Telur 50, kebutuhan 1 dan 1 → kartu 50 (Telur penghambat). Jual 10 Telur langsung → kartu 40. Buka tab dengan 5 Indomie Kuah (belum selesai) → kartu 35 dan Telur di POS tampil tersedia 35 untuk penjualan langsung.

**Acceptance Scenarios**:

1. **Given** Indomie Goreng 100 dan Telur 50 dengan kebutuhan 1 per porsi, **When** kasir membuka POS, **Then** kartu Indomie Kuah menampilkan 50.
2. **Given** kartu menampilkan 50, **When** 10 Telur dijual langsung, **Then** kartu menampilkan 40 setelah POS menyegarkan data.
3. **Given** sebuah tab terbuka berisi 5 Indomie Kuah yang belum selesai, **When** kasir lain melihat POS, **Then** stok Indomie Kuah dan stok Telur untuk penjualan langsung sama-sama sudah dikurangi 5 tahanan.
4. **Given** menu beresep dengan bahan produk dan bahan baku, **When** stok dihitung, **Then** bahan penghambat boleh berupa produk bahan maupun bahan baku, dan stok menu adalah yang terendah di antara semuanya.
5. **Given** POS Web dan POS Android pada outlet dan waktu yang sama, **When** keduanya menampilkan kartu yang sama, **Then** angkanya identik.

---

### User Story 4 - Pesanan tidak boleh melebihi saldo bersama, termasuk saat serentak (Priority: P1)

Dua kasir (web dan tablet) menjual serentak barang yang berbagi saldo. Sistem tidak boleh menjual melebihi saldo, tidak boleh memotong dua kali saat kiriman diulang, dan tidak boleh menyisakan pemotongan setengah jadi.

**Why this priority**: Integritas stok adalah syarat mutlak; kegagalan di sini merusak angka semua laporan.

**Independent Test**: Telur tersisa 1; dua terminal serentak menjual 1 Indomie Kuah masing-masing. Tepat satu berhasil, satu ditolak 409; saldo Telur 0, tidak minus. Kirim ulang permintaan yang sukses (referensi sama) tidak memotong lagi.

**Acceptance Scenarios**:

1. **Given** Telur tersisa 1, **When** dua terminal serentak menyelesaikan masing-masing 1 Indomie Kuah, **Then** tepat satu berhasil, yang lain ditolak karena stok tidak cukup, dan saldo tidak pernah minus.
2. **Given** pesanan yang sudah berhasil, **When** klien mengirim ulang permintaan yang sama (retry atau sinkronisasi ulang), **Then** tidak ada pemotongan kedua.
3. **Given** menu beresep dengan dua bahan produk dan salah satunya kurang, **When** pesanan diselesaikan, **Then** ditolak seluruhnya dan kedua saldo tetap seperti semula.
4. **Given** produk bahan yang dijual langsung dan juga dipakai resep, **When** penjualan langsung serta penjualan menu beresep berebut sisa terakhir, **Then** hanya satu yang lolos.
5. **Given** produk yang diatur boleh minus (jika pengaturan seperti itu ada hari ini), **When** pemakaian sebagai bahan melebihi saldo, **Then** aturan minus yang sama berlaku seperti penjualan langsung produk itu, bukan aturan baru.

---

### User Story 5 - Semua operasi stok lain memakai saldo bersama dan terlacak (Priority: P2)

Penerimaan barang, koreksi manual, opname, retur pembelian, pembatalan/void, dan retur/refund penjualan pada produk bahan semua bekerja pada saldo yang sama, dan setiap pergerakan tercatat dengan sumbernya.

**Why this priority**: Menjamin satu sumber kebenaran, tetapi dapat diuji sesudah jalur jual (P1) berjalan.

**Independent Test**: Pada Telur: terima 100, koreksi −5, void satu Indomie Kuah, refund satu; seluruhnya terlihat di satu riwayat Telur dengan sumber berbeda (pembelian, koreksi, pembatalan, retur) dan saldo akhir sesuai.

**Acceptance Scenarios**:

1. **Given** Telur 100, **When** penerimaan 50 dicatat, **Then** saldo menjadi 150 dan riwayat mencatat sumber "pembelian/penerimaan".
2. **Given** koreksi manual atau opname pada Telur, **When** disimpan, **Then** saldo yang sama berubah dan riwayat mencatat sumber koreksi/opname.
3. **Given** pesanan berisi Indomie Kuah yang sudah selesai, **When** pesanan di-void, **Then** Indomie Goreng dan Telur dikembalikan persis sebesar yang dahulu dipotong.
4. **Given** retur/refund sebagian (mis. 1 dari 3 porsi), **When** disetujui, **Then** bahan dikembalikan sebanding 1 porsi menurut potret saat penjualan, bukan menurut resep saat ini.
5. **Given** retur pembelian, **When** dicatat, **Then** saldo produk bahan berkurang pada saldo yang sama.
6. **Given** riwayat pergerakan sebuah produk bahan, **When** pemilik membukanya, **Then** setiap baris menunjukkan sumbernya: penjualan langsung, pemakaian resep (beserta menu dan pesanan penyebab), pembelian, koreksi, retur, pembatalan.

---

### User Story 6 - Resep rekursif dan penyuntingan penanda dikelola aman (Priority: P2)

Sistem menolak susunan yang ambigu: menu beresep tidak boleh dipakai sebagai bahan, produk tidak boleh dipakai sebagai bahan bagi dirinya sendiri, dan mematikan penanda tidak merusak resep yang sudah memakainya.

**Why this priority**: Mencegah pemotongan ganda dan ketergantungan melingkar; bisa dikerjakan setelah jalur utama.

**Independent Test**: Coba pilih "Indomie Kuah" (beresep) sebagai bahan di resep lain: tidak ditawarkan / ditolak dengan alasan jelas. Matikan penanda "Telur" saat dipakai resep aktif: resep tetap utuh, tetapi "Telur" tidak muncul untuk resep baru.

**Acceptance Scenarios**:

1. **Given** "Indomie Kuah" punya resep aktif, **When** pemilik mencoba menandainya "Gunakan sebagai bahan", **Then** ditolak dengan penjelasan bahwa menu beresep tidak punya saldo sendiri.
2. **Given** produk bertanda bahan, **When** pemilik mencoba menambahkan resep aktif ke varian-variannya, **Then** ditolak dengan penjelasan bahwa produk bahan harus memiliki saldo sendiri.
3. **Given** resep sebuah produk, **When** pemilik memilih varian produk itu sendiri sebagai bahan, **Then** ditolak.
4. **Given** "Telur" dipakai resep aktif, **When** pemilik mematikan "Gunakan sebagai bahan", **Then** resep lama tetap utuh dan tetap memotong "Telur" saat dijual, tetapi "Telur" tidak muncul di pilihan bahan resep baru; formulir resep lama menandainya sebagai "tidak lagi tersedia untuk resep baru".
5. **Given** "Telur" dipakai resep aktif, **When** pemilik mencoba menghapus/menonaktifkan produk "Telur", **Then** sistem menolak (konflik) dengan menyebut resep yang memakainya, atau menjaga aturan penghapusan yang ada hari ini dengan pesan yang sama jelas.

---

### User Story 7 - Layar pengelolaan stok menampilkan peran produk bahan (Priority: P3)

Di layar produk, stok, riwayat pergerakan, serta koreksi/opname, pemilik melihat dengan jelas produk mana yang juga bahan, menu mana yang memakainya, dan stok yang tersedia untuk dijual langsung (sudah dikurangi tahanan).

**Why this priority**: Kejelasan operasional; fungsi inti sudah berjalan tanpanya.

**Independent Test**: Buka daftar produk: "Telur" memiliki lencana "Bahan" dan detailnya menyebut "dipakai di: Indomie Kuah". Buka riwayat stok Telur: baris pemakaian resep menyebut menu dan nomor pesanan.

**Acceptance Scenarios**:

1. **Given** produk bertanda bahan, **When** pemilik melihat daftar/detail produk, **Then** terlihat penandanya dan daftar menu beresep yang memakainya.
2. **Given** layar koreksi/opname produk bahan, **When** pemilik membukanya, **Then** angka yang dikoreksi adalah saldo bersama (bukan saldo terpisah) dengan keterangan bahwa produk itu juga dipakai resep.
3. **Given** POS dan layar stok, **When** produk bahan punya tahanan, **Then** tersedia dibedakan dari saldo fisik (saldo, tahanan, tersedia).

---

### Edge Cases

- **Banyak varian**: produk punya beberapa varian (stok per varian). Resep memilih **varian** tertentu sebagai bahan; penanda "Gunakan sebagai bahan" ada di tingkat produk dan membuat semua variannya dapat dipilih. Jika produk punya satu varian, pilihannya otomatis.
- **Produk tanpa pelacakan stok**: produk yang tidak melacak stok tidak dapat dijadikan bahan (tidak ada saldo bersama untuk dipotong); penanda ditolak dengan penjelasan.
- **Stok per outlet**: pemotongan, tahanan, dan ketersediaan selalu pada outlet tempat pesanan dibuat; saldo outlet lain tidak tersentuh dan tidak dipakai sebagai cadangan.
- **Produk bahan nonaktif/habis (86)**: bila produk bahan dinonaktifkan atau ditandai habis, menu beresep yang memakainya mengikuti aturan ketersediaan yang ada (tidak bisa dipesan bila bahan tidak tersedia).
- **Satuan**: jumlah bahan dalam resep dinyatakan dalam satuan hitung stok produk bahan itu; tidak ada konversi satuan otomatis (mis. tidak ada "1 lusin = 12").
- **Jumlah pecahan**: stok produk adalah bilangan bulat, sedangkan jumlah bahan resep boleh pecahan; pembulatan dan akumulasi pecahan harus tidak menghilangkan atau menggandakan stok (lihat Asumsi).
- **Resep lama pada bahan baku**: resep yang memakai bahan baku lama tidak diubah dan tetap berfungsi; tidak ada migrasi paksa.
- **Item yang dihapus**: varian produk bahan yang sudah punya riwayat tidak dapat dihapus permanen (aturan yang ada tetap).
- **Penanda dimatikan lalu dinyalakan lagi**: resep yang tetap memakainya kembali normal tanpa kehilangan baris.
- **Pesan mandiri QR**: pesanan dari pelanggan publik memakai aturan stok yang sama; kiriman yang melebihi saldo bersama ditolak sebelum pelanggan membayar.
- **Android tanpa koneksi**: bila aplikasi tidak dapat menjangkau server, ia tidak boleh mengarang potongan sendiri; kebenaran stok tetap di server (lihat Asumsi).

## Requirements *(mandatory)*

### Functional Requirements

**Penanda produk bahan**

- **FR-001**: Formulir produk MUST menyediakan penanda **Gunakan sebagai bahan** (bawaan: mati) yang dapat diubah oleh peran yang boleh mengelola master data.
- **FR-002**: Produk bertanda MUST muncul di pilihan bahan resep; produk tak bertanda MUST NOT muncul untuk resep baru.
- **FR-003**: Produk bertanda MUST tetap dapat dijual langsung di POS tanpa perubahan perilaku.
- **FR-004**: Mematikan penanda MUST NOT mengubah, menghapus, atau menonaktifkan resep yang sudah memakai produk itu; resep tersebut tetap memotong stok dan tetap dapat diedit/dihapus barisnya secara eksplisit.
- **FR-005**: Produk yang tidak melacak stok MUST ditolak saat ditandai sebagai bahan, dengan alasan jelas.

**Pemilihan bahan di resep**

- **FR-006**: Baris bahan resep MUST dapat merujuk **bahan baku** atau **varian produk bahan** (campuran dalam satu resep diizinkan); memilih produk bahan MUST NOT membuat catatan bahan baku atau saldo baru.
- **FR-007**: Pilihan bahan MUST menampilkan nama, SKU, satuan, dan stok tersedia di outlet terpilih untuk produk bahan; dan tetap menampilkan informasi yang sama seperti hari ini untuk bahan baku.
- **FR-008**: Jumlah per baris MUST mendukung nilai pecahan sebagaimana resep hari ini, dalam satuan hitung stok bahan yang dipilih.
- **FR-009**: Tidak boleh ada dua baris dengan bahan yang sama dalam satu blok resep (aturan yang ada diperluas ke produk bahan).

**Resep rekursif dan konsistensi (keputusan)**

- **FR-010**: Resep rekursif MUST **dilarang**: sebuah varian beresep MUST NOT dipakai sebagai bahan; sebuah varian produk bahan MUST NOT diberi resep aktif; sebuah produk MUST NOT dipakai sebagai bahan di resepnya sendiri. Larangan ditegakkan di kedua arah (saat menandai, saat menambah resep, dan saat memilih bahan) dengan pesan konflik yang menyebut penyebabnya.
- **FR-011**: Sistem MUST menolak menonaktifkan atau menghapus produk bahan yang masih dipakai resep aktif, menyebut resep yang memakainya, kecuali aturan penghapusan yang ada hari ini sudah mencakupnya dengan kejelasan setara.

**Saldo bersama dan pemotongan**

- **FR-012**: Setiap varian MUST memiliki tepat satu saldo per outlet; sistem MUST NOT membuat saldo terpisah, salinan, atau bayangan hanya karena produk dipakai sebagai bahan.
- **FR-013**: Saat pesanan menu beresep selesai, sistem MUST memotong saldo bersama setiap produk bahan sebesar kebutuhan per porsi × jumlah porsi, memakai rumus kebutuhan yang sama dengan bahan baku (susut dan jumlah porsi resep).
- **FR-014**: Pemotongan bahan produk dan bahan baku dalam satu pesanan MUST atomik: semua berhasil atau tidak ada yang berubah.
- **FR-015**: Seluruh perubahan saldo produk bahan MUST melewati satu mekanisme penulis stok yang sama dengan penjualan langsung; tidak boleh ada jalur penulisan kedua.
- **FR-016**: Pemotongan MUST idempoten: pengiriman ulang pesanan atau sinkronisasi ulang dengan referensi yang sama MUST NOT memotong lagi.
- **FR-017**: Pemotongan serentak atas saldo bersama MUST diserialkan sehingga saldo tidak pernah melampaui batas bawahnya (tidak ada overselling) dan setiap kegagalan mengembalikan semuanya.
- **FR-018**: Saat saldo bersama tidak cukup untuk kebutuhan resep, penyelesaian pesanan MUST ditolak dengan status konflik dan alasan yang menyebut produk bahan penghambat; aturan ini sejalan dengan 092 untuk kebutuhan resep. Saldo minus MUST hanya mungkin bila aturan minus yang berlaku hari ini untuk produk itu memang mengizinkan.

**Ketersediaan dan tahanan**

- **FR-019**: Stok menu beresep MUST dihitung sebagai kapasitas terendah di antara semua bahannya (produk bahan dan bahan baku), memakai saldo outlet dikurangi tahanan.
- **FR-020**: Tahanan oleh pesanan terbuka MUST juga mengurangi ketersediaan produk bahan untuk **penjualan langsung** dan untuk **menu beresep lain**, supaya dua pesanan terbuka tidak sama-sama lolos atas sisa yang sama.
- **FR-021**: Angka ketersediaan MUST dihitung di server dan dikirim ke POS Web dan POS Android; klien MUST NOT menghitung ulang, sehingga kedua POS menampilkan angka identik.
- **FR-022**: POS MUST menyegarkan ketersediaan setelah transaksi (selesai, tambah/ubah baris, void, refund) sehingga perubahan pada produk bahan tampak pada menu yang memakainya.

**Operasi stok lain**

- **FR-023**: Penerimaan barang, koreksi manual, opname, retur pembelian, dan pemindahan stok antar-outlet pada produk bahan MUST bekerja pada saldo bersama yang sama.
- **FR-024**: Void dan retur/refund MUST mengembalikan produk bahan sebesar yang tercatat dipotong untuk pesanan itu (dibaca dari catatan pemotongan, bukan dari resep saat ini); retur sebagian MUST mengembalikan sebanding.
- **FR-025**: Perubahan resep MUST berlaku hanya untuk pesanan yang selesai sesudahnya; riwayat dan potret pesanan lama MUST NOT berubah.

**Jejak audit**

- **FR-026**: Setiap pergerakan saldo MUST mencatat sumbernya dan bedakan: penjualan langsung, pemakaian resep (dengan menu dan pesanan penyebab), pembelian/penerimaan, koreksi, opname, retur pembelian, pembatalan, retur penjualan, dan pemindahan.
- **FR-027**: Riwayat pergerakan sebuah produk bahan MUST menampilkan semua sumber di atas dalam satu daftar berurutan waktu.

**Antarmuka dan kompatibilitas**

- **FR-028**: Formulir produk, formulir/laci resep, pilihan bahan, informasi stok produk, riwayat pergerakan, koreksi/opname, POS Web, dan POS Android MUST menampilkan peran produk bahan secara konsisten dengan pola desain yang ada, termasuk pembedaan saldo, tahanan, dan tersedia.
- **FR-029**: Data lama MUST tetap utuh: produk, resep berbahan baku, riwayat pesanan, dan potret lama tetap valid dan tetap menghasilkan angka yang sama; tidak ada migrasi data yang mengubah angka.
- **FR-030**: Klien lama (APK lama) MUST tetap benar karena ketersediaan datang dari server lewat bidang yang sudah dibaca.
- **FR-031**: Pesanan mandiri via QR MUST memakai aturan stok bersama yang sama, dan kiriman yang melebihi saldo bersama MUST ditolak sebelum pembayaran.

### Key Entities

- **Produk bahan (penanda)**: atribut tingkat produk yang menyatakan varian-variannya boleh dipilih sebagai bahan resep baru. Tidak menciptakan saldo.
- **Saldo bersama**: saldo per varian per outlet yang sudah ada; satu-satunya angka untuk penjualan langsung, pemakaian resep, pembelian, koreksi, opname, retur, dan void.
- **Baris bahan resep**: kini merujuk satu dari dua jenis bahan (bahan baku atau varian produk bahan) dengan jumlah per porsi; potret saat penjualan menyimpan jenis, rujukan, dan jumlah yang dipotong.
- **Pergerakan stok**: catatan tambah-saja dengan jenis sumber (termasuk "pemakaian resep") dan rujukan ke pesanan serta menu penyebab.
- **Tahanan**: kebutuhan bahan dari pesanan terbuka yang belum dipotong; dihitung dari baris pesanan, bukan tabel saldo kedua.
- **Menu beresep**: varian dengan resep aktif; stoknya diturunkan, bukan disimpan.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pemilik dapat menandai sebuah produk sebagai bahan dan memakainya di resep dalam kurang dari 2 menit tanpa membuat catatan bahan baku tambahan.
- **SC-002**: Untuk barang yang dijual langsung sekaligus dipakai sebagai bahan, **0** saldo ganda: setiap produk bahan memiliki tepat satu saldo per outlet, dan seluruh jalur (jual, resep, beli, koreksi, void, retur) menghasilkan angka yang sama pada rekonsiliasi.
- **SC-003**: Pada skenario contoh (Indomie Goreng 100, Telur 200/50), stok Indomie Kuah ditampilkan benar (bahan penghambat menentukan) di POS Web dan Android dengan selisih 0.
- **SC-004**: Dalam uji serentak (minimal 20 pesanan paralel atas sisa 1 unit bahan bersama), tepat 1 berhasil, saldo tidak pernah minus, dan 0 pemotongan ganda terjadi.
- **SC-005**: 100% pengiriman ulang atas pesanan yang sama menghasilkan 0 pemotongan tambahan.
- **SC-006**: Void atau refund penuh atas pesanan beresep mengembalikan saldo semua bahan ke angka sebelum penjualan (selisih 0), termasuk bila resep sudah diubah setelah penjualan.
- **SC-007**: 100% pergerakan stok produk bahan menampilkan sumbernya; untuk pemakaian resep, menu dan pesanan penyebab dapat ditelusuri dari satu baris riwayat.
- **SC-008**: Ketersediaan di POS menurun dalam satu siklus penyegaran setelah penjualan langsung, tab terbuka, atau selesai pesanan pada produk bahan.
- **SC-009**: Seluruh data lama (resep berbahan baku, riwayat pesanan) menghasilkan angka identik sebelum dan sesudah fitur ini aktif; 0 regresi pada rangkaian tes yang ada.
- **SC-010**: Upaya membuat resep rekursif atau melingkar ditolak 100% dengan penjelasan penyebab.

## Assumptions

- **ASSUMPTION — rujukan berupa varian**: stok disimpan per varian per outlet, jadi baris resep merujuk **varian** dari produk bahan; penanda berada di tingkat produk. Produk satu-varian dipilih otomatis.
- **ASSUMPTION — rekursi dilarang** (keputusan spesifikasi sesuai saran permintaan): menu beresep tidak punya saldo sendiri (aturan 092), sehingga ia tidak dapat menjadi bahan; ini juga menutup kemungkinan siklus tanpa perlu deteksi siklus bertingkat.
- **ASSUMPTION — satuan**: varian hari ini tidak punya satuan. Fitur ini menambahkan label satuan tampilan opsional untuk produk bahan (mis. "pcs", "bungkus") yang menjadi satuan jumlah resep; tidak ada konversi satuan otomatis. Jika label kosong, ditampilkan "unit".
- **ASSUMPTION — kegagalan stok**: kebutuhan resep atas produk bahan ditolak 409 saat tidak cukup, selaras dengan 092; bahan baku pilihan modifier tetap memakai peringatan (087). Bila produk bahan memang mengizinkan minus hari ini, aturan itu dipakai, bukan aturan baru.
- **ASSUMPTION — waktu pemotongan**: penjualan langsung mengikuti perilaku yang ada (stok produk dipotong saat item masuk tab), sedangkan kebutuhan resep dipotong saat pesanan selesai (087/092); celah di antaranya ditutup oleh tahanan (FR-020), bukan dengan mengubah waktu pemotongan yang sudah ada.
- **ASSUMPTION — pecahan**: jumlah bahan resep boleh pecahan sementara stok produk bilangan bulat; penanganan sisa pecahan (mis. 0,5 telur per porsi) mengikuti keputusan perencanaan; spesifikasi hanya mensyaratkan tidak ada stok hilang atau ganda secara akumulatif. Hal ini perlu dipastikan di tahap plan.
- **ASSUMPTION — Android offline**: kebenaran stok tetap di server; tablet tidak membukukan potongan sendiri. Bila arsitektur POS Android hari ini memang tidak mendukung antrean offline, fitur ini tidak menambahkannya; bila ada, kiriman ulangnya idempoten (FR-016).
- **ASSUMPTION — impor/ekspor Excel**: kolom/lembar impor resep dan master data untuk produk bahan di luar cakupan versi ini; lembar yang ada tetap berfungsi tanpa perubahan.
- **ASSUMPTION — hak akses**: penanda dan resep dikelola oleh peran yang sama yang mengelola master data/resep hari ini; tidak ada peran baru.
- **Ketergantungan**: bergantung pada mekanisme stok menu berbasis resep dan penjaga kapasitas (092), pemotongan bahan saat pesanan selesai (087/089), saldo per outlet (045), dan jejak pergerakan stok yang ada.
- **Di luar cakupan**: konversi satuan, resep bertingkat (rekursif), perubahan waktu pemotongan stok penjualan langsung, laporan baru, dan migrasi otomatis bahan baku lama menjadi produk bahan.
