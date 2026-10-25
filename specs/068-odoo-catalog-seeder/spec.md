# Feature Specification: Pengisian awal katalog Odoo WJM ke mode LIVE dan DEMO

**Klasifikasi**: CONFIDENTIAL — merangkum katalog nyata milik pelanggan (Warkop WJM).

**Feature Branch**: `068-odoo-catalog-seeder`

**Created**: 2026-10-24

**Status**: Draft

**Input**: User description: "analyze these excel, then create a data seeder to live and demo environment to insert the data directly to the database of this warkop wjm system. category: `WJM 3/Point of Sale Category (pos.category).xlsx`; product attribute: `WJM 3/Product Attribute (product.attribute).xlsx`; product data: `WJM 3/Product Template (product.template).xlsx`"

**Analisis sumber**: [source-analysis.md](./source-analysis.md) — isi tiap berkas, celah data, dan catatan mutu.

**Tabel pemetaan**: [catalog-mapping.md](./catalog-mapping.md) — usulan kategori dan varian per item, menunggu persetujuan pemilik.

## Clarifications

### Session 2026-10-24

- Q: Ekspor produk tidak memuat kategori POS, atribut per item, maupun harga per varian. Dari mana penugasan ini diambil? → A: Dari tabel pemetaan yang disusun dari nama item, jumlah varian, dan nama BoM di sumber, lalu disetujui pemilik. Harga varian sama dengan harga item kecuali pemilik menetapkan lain. Enam foto yang terpotong di ekspor XLSX tetap kosong.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Outlet WJM 3 siap berjualan dengan menu Odoo-nya di mode LIVE (Priority: P1)

Warkop WJM 3 pindah dari Odoo ke BoothPOS. Operator (pemasang sistem) menjalankan pengisian katalog sekali di server toko. Setelah itu, di mode LIVE, outlet Warkop WJM 3 punya kategori dan item menu Odoo yang ditata sesuai tabel pemetaan yang disetujui pemilik, lengkap dengan varian, harga, dan foto. Kasir bisa langsung memesannya di POS tanpa entri manual.

**Why this priority**: Inilah inti permintaan. Tanpa ini pemilik harus mengetik ulang 79 item dan sekitar 111 varian sebelum bisa berjualan.

**Independent Test**: Pada instalasi yang mode LIVE-nya belum punya katalog, jalankan pengisian untuk LIVE saja, lalu buka POS outlet Warkop WJM 3 di LIVE. Semua item katalog tampil per kategori, dapat dipesan, dan berharga sesuai sumber.

**Acceptance Scenarios**:

1. **Given** mode LIVE belum punya katalog, **When** operator menjalankan pengisian untuk LIVE, **Then** 10 kategori dan semua item katalog (setelah pengecualian) ada di LIVE, aktif, dijual outlet Warkop WJM 3, dan dapat dipesan di POS dengan harga jual sumber.
2. **Given** item sumber punya atribut pembentuk varian (mis. "Jus": Jambu/Alpukat), **When** pengisian selesai, **Then** item itu punya satu varian per nilai, dan kasir memilih varian saat memesan.
3. **Given** item sumber punya foto yang sah, **When** pengisian selesai, **Then** kartu item di POS menampilkan foto itu.
4. **Given** pesanan berisi minuman dan makanan, **When** pesanan dikirim ke persiapan, **Then** item minuman masuk stasiun Bar dan item makanan masuk stasiun Dapur.
5. **Given** mode LIVE belum punya outlet sama sekali, **When** operator menjalankan pengisian, **Then** outlet "Warkop WJM 3" dibuat beserta stasiun Bar dan Dapur, lalu katalog diisi ke outlet itu.
6. **Given** instalasi baru, **When** penyiapan data bawaan dijalankan tanpa memanggil pengisian katalog WJM, **Then** katalog WJM tidak terisi di mode mana pun.

---

### User Story 2 - Mode DEMO memakai menu WJM yang asli (Priority: P2)

Pemilik dan kasir berlatih di mode DEMO. Saat ini outlet Warkop WJM 3 di DEMO berisi menu contoh (32 item rekaan). Setelah pengisian, DEMO memakai katalog WJM yang sama dengan LIVE, sehingga latihan dan demo memakai menu yang benar-benar dijual.

**Why this priority**: Diminta secara eksplisit ("live and demo"), dan latihan dengan menu rekaan menyesatkan. Prioritasnya di bawah LIVE karena DEMO tidak menghasilkan penjualan nyata.

**Independent Test**: Pada instalasi yang punya menu contoh di DEMO, jalankan pengisian untuk DEMO saja. POS outlet Warkop WJM 3 di DEMO hanya menampilkan katalog WJM, dan data LIVE tidak berubah.

**Acceptance Scenarios**:

1. **Given** outlet Warkop WJM 3 di DEMO menjual menu contoh, **When** pengisian DEMO selesai, **Then** POS outlet itu menampilkan katalog WJM dan tidak lagi menampilkan item contoh.
2. **Given** pesanan demo lama memuat item contoh, **When** pengisian DEMO selesai, **Then** pesanan lama itu tetap utuh dan terbaca, karena item contoh hanya ditarik dari menu, tidak dihapus.
3. **Given** katalog sudah diisi di kedua mode, **When** pemilik mengubah harga satu item di DEMO, **Then** harga item yang sama di LIVE tidak berubah.
4. **Given** DEMO punya outlet lain selain Warkop WJM 3, **When** pengisian DEMO selesai, **Then** POS outlet lain itu tidak menampilkan item WJM, dan menunya tidak berubah.

---

### User Story 3 - Jalan ulang aman, dengan laporan untuk setiap baris sumber (Priority: P2)

Operator dapat menjalankan pengisian lagi, misalnya setelah gagal di tengah jalan atau pada instalasi yang sebagian sudah terisi, tanpa duplikat dan tanpa menimpa perubahan pemilik. Setiap jalan menghasilkan laporan yang menjelaskan nasib setiap baris sumber.

**Why this priority**: Data LIVE adalah data produksi. Proses yang bisa menggandakan atau menimpa data pemilik tidak aman dipakai.

**Independent Test**: Jalankan pengisian dua kali berturut-turut. Jalan kedua tidak membuat dan tidak mengubah apa pun, dan laporannya menandai semua item "sudah ada".

**Acceptance Scenarios**:

1. **Given** katalog sudah diisi, **When** operator menjalankan pengisian lagi, **Then** tidak ada kategori, item, varian, atau grup pilihan baru, dan tidak ada data yang berubah.
2. **Given** pemilik sudah mengubah harga atau foto salah satu item hasil pengisian, **When** pengisian dijalankan lagi, **Then** perubahan pemilik tetap.
3. **Given** pengisian satu mode gagal di tengah, **When** operator memeriksa mode itu, **Then** tidak ada potongan katalog yang tertinggal di mode itu.
4. **Given** pengisian selesai, **When** operator membaca laporan, **Then** setiap baris sumber tercatat sebagai dibuat, sudah ada, atau dikecualikan beserta alasannya.
5. **Given** pengisian satu mode berhasil, **When** pemilik membuka log aktivitas mode itu, **Then** ada tepat satu entri pengisian katalog yang menyebut outlet dan jumlah yang dibuat, dilewati, dan dikecualikan. Mode yang gagal tidak meninggalkan entri.

---

### User Story 4 - Pratinjau tanpa menyimpan (Priority: P3)

Sebelum menyentuh mode LIVE di server pelanggan, operator menjalankan pratinjau yang menghasilkan laporan yang sama tanpa menyimpan apa pun.

**Why this priority**: Pengaman tambahan untuk data produksi. Berguna, tetapi tidak wajib untuk MVP.

**Independent Test**: Jalankan pratinjau untuk LIVE. Laporan terisi, dan isi LIVE sebelum dan sesudah pratinjau identik.

**Acceptance Scenarios**:

1. **Given** mode LIVE dalam keadaan apa pun, **When** operator menjalankan pratinjau, **Then** laporan menampilkan apa yang akan dibuat, dilewati, dan dikecualikan, dan tidak ada data yang berubah.

---

### Edge Cases

- **Tabel pemetaan belum disetujui**: pengisian menolak berjalan tanpa perubahan.
- **Tabel pemetaan tidak cocok dengan sumber** (item tanpa baris pemetaan, kategori atau nilai atribut yang tidak ada di sumber, atau jumlah varian berbeda dari Odoo tanpa penyimpangan yang dinyatakan): pengisian berhenti tanpa perubahan dan menyebut baris yang bermasalah.
- **Mode punya beberapa outlet aktif, tetapi tidak ada yang bernama "Warkop WJM 3"**, dan operator tidak memilih outlet: pengisian mode itu berhenti tanpa perubahan dan menyebut outlet yang tersedia.
- **Outlet pilihan operator tidak ada atau nonaktif** di mode itu: pengisian mode itu berhenti tanpa perubahan.
- **Item bernama sama dalam kategori bernama sama sudah ada** di mode itu, dibuat pemilik atau oleh jalan sebelumnya: item dilewati tanpa diubah dan dicatat "sudah ada".
- **Item hasil pengisian yang kemudian diganti nama oleh pemilik**: jalan ulang menganggapnya belum ada dan membuatnya lagi dengan nama sumber. Pratinjau menampilkannya sebagai "akan dibuat", sehingga operator bisa membatalkan atau membatasi jalan ke mode lain.
- **Grup pilihan bernama sama dengan opsi berbeda sudah ada** (di DEMO ada grup contoh "Rasa": Cokelat/Keju/Srikaya/Kacang): grup lama tidak diubah, grup hasil pengisian diberi nama pembeda, dan hal itu dicatat.
- **Beberapa item berawalan nama sama** (7 "Roti Bakar …", 4 dimsum, 3 pangsit): tiap item tetap mendapat kode berbeda tanpa campur tangan operator.
- **Item berharga 0** ("Nasi Putih"): diisi apa adanya dan ditandai di laporan untuk ditinjau pemilik.
- **Produk teknis Odoo** ("Partial Payment", referensi `nf_partial_payment`): dikecualikan.
- **Nilai atribut uji** ("Paket Hemat: test"): dikecualikan.
- **Sel foto berisi pesan batas ukuran Odoo** (6 item) atau kosong (12 item): item diisi tanpa foto dan didaftar di laporan.
- **Satu foto dipakai beberapa item** (4 pasang): tiap item mendapat foto itu.
- **Kuantitas stok Odoo, termasuk yang negatif** (−123, −41): tidak dibawa; item tidak melacak stok.
- **Item kit/BoM Odoo** (6 kit, 7 BoM): dijual sebagai item biasa dengan harganya sendiri. Komponen BoM tidak ada di ekspor, tidak dibawa, dan item-item ini didaftar di laporan.
- **Item dengan dua atribut pembentuk varian** (paket × ayam): satu varian per kombinasi, dinamai gabungan nilainya.
- **Pengisian DEMO berhasil tetapi LIVE gagal, atau sebaliknya**: mode yang berhasil tetap tersimpan, dan laporan menyebut status tiap mode.

## Requirements *(mandatory)*

### Functional Requirements

**Menjalankan dan cakupan**

- **FR-001**: Pengisian katalog WJM MUST hanya berjalan bila dipanggil secara eksplisit oleh operator berwenang di server. Pengisian tidak ikut penyiapan bawaan instalasi mana pun dan tidak tersedia dari antarmuka web atau POS.
- **FR-002**: Secara bawaan, pengisian MUST mengisi mode LIVE dan DEMO dalam satu kali jalan, dengan pilihan untuk mengisi satu mode saja. Salinan tiap mode berdiri sendiri: perubahan di satu mode tidak memengaruhi mode lain.
- **FR-003**: Per mode, outlet tujuan MUST ditentukan dengan urutan berikut: (1) outlet pilihan operator; (2) bila tidak ada pilihan, outlet bernama "Warkop WJM 3"; (3) bila outlet itu tidak ada, satu-satunya outlet aktif di mode itu; (4) bila mode belum punya outlet sama sekali, outlet "Warkop WJM 3" dibuat. Bila tidak ada aturan yang berlaku, pengisian mode itu berhenti tanpa perubahan dan menyebut outlet yang tersedia.
  - *Catatan bertanggal 2026-10-25 (fitur 069):* kebijakan penentuan outlet kini dibawa katalog (`outlet.create_when`). Katalog WJM 3 memakai `mode_empty` dan berperilaku seperti di atas; katalog WJM 2 (`demo_missing`) tidak pernah memakai outlet yang namanya lain. Dua outlet aktif bernama sama kini **ditolak** (pilih dengan `--outlet`), bukan dipilih yang pertama.
- **FR-004**: Item hasil pengisian MUST dijual hanya oleh outlet tujuan; outlet lain di mode yang sama tidak mulai menjualnya.
  - *Catatan bertanggal 2026-10-25 (fitur 069):* stasiun kini dikenali menurut peran lewat daftar nama katalog (`stations`), setiap item baru mendapat `station_id` outletnya sendiri, dan saldo nol varian baru dibukukan di outlet tujuan (sebelumnya di outlet aktif pertama mode itu, yang bisa berupa outlet lain).

**Isi katalog**

- **FR-005**: Pengisian MUST membuat 10 kategori POS sumber dengan nama persis dan urutan tampil sesuai urutan sumber. Pesanan dari kategori Coffee, Non Coffee, Signature Coffee, dan Tea diarahkan ke stasiun "Bar" outlet tujuan; kategori lain ke stasiun "Dapur". Stasiun yang belum ada di outlet tujuan dibuat.
- **FR-006**: Pengisian MUST membuat setiap item menu sumber, kecuali yang dikecualikan FR-011, sebagai item aktif yang langsung dapat dipesan, dengan nama persis seperti di sumber dan harga jual dari kolom "Sales Price".
- **FR-007**: Penugasan kategori, atribut pembentuk varian, nama varian, harga varian, dan grup pilihan per item MUST berasal dari tabel pemetaan katalog ([catalog-mapping.md](./catalog-mapping.md)). Tabel itu disusun dari nama item, jumlah varian, dan nama BoM di sumber, lalu disetujui pemilik. Pengisian MUST menolak berjalan, tanpa perubahan apa pun, selama tabel belum berstatus disetujui.
- **FR-008**: Item dengan atribut pembentuk varian MUST mendapat satu varian per nilai atribut, atau satu varian per kombinasi bila atributnya lebih dari satu, dengan nama varian sesuai tabel pemetaan (mis. "Penyet Hemat"). Item tanpa atribut seperti itu mendapat satu varian standar. Harga setiap varian sama dengan harga item, kecuali tabel pemetaan menyebut harga lain.
- **FR-009**: Atribut "opsi saja", yaitu yang tidak membentuk varian ("Rasa": Hazelnut, Vanilla, Gula Aren), MUST menjadi grup pilihan pada pesanan: satu pilihan, tanpa tambahan harga, dan ditautkan ke item sesuai tabel pemetaan. Grup tetap dibuat walaupun belum ditautkan ke item mana pun.
- **FR-010**: Foto item MUST diambil dari sumber bila selnya berisi gambar yang sah. Sel kosong, atau sel berisi pesan batas ukuran Odoo, menghasilkan item tanpa foto.
- **FR-011**: Pengisian MUST mengecualikan produk teknis Odoo ("Partial Payment"), nilai atribut uji ("test"), dan atribut pembentuk varian yang tidak dipakai item mana pun. Setiap pengecualian tercatat di laporan beserta alasannya.
- **FR-012**: Pengisian MUST NOT membawa dari sumber: nama staf (kolom "Responsible"), kuantitas stok dan perkiraannya, struktur kit/BoM, satuan, dan kolom internal Odoo lainnya. Harga pokok tidak tersedia di sumber (semuanya 0), sehingga item tercatat dengan harga pokok 0.
- **FR-013**: Item hasil pengisian MUST tidak melacak stok, sehingga dapat dipesan tanpa saldo stok. Pemilik dapat menyalakan pelacakan stok per item kemudian.
  - *Catatan bertanggal 2026-10-25 (permintaan pengembang):* `catalog.json` kini membawa `initial_stock` (dari baris `**Stok awal**` di `catalog-mapping.md`). Bila `null`, perilaku di atas tetap berlaku. Bila berisi angka, setiap varian baru melacak stok dan mendapat gerakan `initial` sebesar angka itu di outlet tujuan, lewat `StockService`. Katalog WJM memakai 100.
- **FR-014**: Setiap kategori, item, dan varian hasil pengisian MUST mendapat kode sesuai format kode sistem: unik di seluruh sistem (kedua mode), permanen, dan diturunkan dengan aturan tetap. Item yang namanya berawalan sama tetap mendapat kode berbeda tanpa campur tangan operator. Kode yang sudah ada tidak pernah diubah.

**Keamanan data dan jalan ulang**

- **FR-015**: Pengisian MUST aman dijalankan ulang. Kategori dianggap sudah ada bila mode yang sama punya kategori bernama sama. Item dianggap sudah ada bila mode yang sama punya item bernama sama dalam kategori bernama sama. Yang sudah ada dilewati tanpa diubah.
  - *Catatan bertanggal 2026-10-25 (fitur 069):* identitas "item sudah ada" dinilai hanya di antara item **milik outlet tujuan**; item outlet lain dengan nama dan kategori sama tidak pernah cocok. Untuk katalog WJM 3 tidak ada perubahan perilaku.
- **FR-016**: Pengisian MUST NOT mengubah atau menghapus data yang sudah ada sebelum dijalankan, kecuali penarikan menu contoh pada FR-017. Grup pilihan yang sudah ada tidak diubah; bila grup bernama sama dengan opsi berbeda sudah ada, grup hasil pengisian diberi nama pembeda.
- **FR-017**: Hanya di mode DEMO, item yang dijual outlet tujuan tetapi bukan bagian katalog WJM (menu contoh saat ini) MUST ditarik dari menu outlet itu, tanpa dihapus, sehingga riwayat pesanan demo tetap utuh. Di mode LIVE tidak ada item yang ditarik.
  - *Catatan bertanggal 2026-10-25 (fitur 069):* penarikan item kini mengikuti `withdraw_others` per mode di katalog. WJM 3: DEMO ya, LIVE tidak (seperti di atas). WJM 2: keduanya ya.
- **FR-018**: Pengisian setiap mode MUST bersifat semua-atau-tidak-sama-sekali: bila gagal, tidak ada perubahan dari mode itu yang tersimpan. Mode lain yang sudah berhasil tetap tersimpan.
- **FR-019**: Setiap mode yang berhasil MUST mencatat satu entri log aktivitas di dalam satuan semua-atau-tidak-sama-sekali yang sama, berisi mode, outlet, sumber katalog, serta jumlah yang dibuat, dilewati, dan dikecualikan.
- **FR-020**: Data katalog yang dibawa pengisian MUST berupa salinan bersih tanpa nama staf dan tanpa kolom internal Odoo. Berkas ekspor mentah tidak disimpan bersama kode sistem.

**Laporan dan pratinjau**

- **FR-021**: Setiap jalan MUST menampilkan laporan per mode yang berisi: outlet tujuan; jumlah dibuat dan sudah ada per jenis (kategori, item, varian, grup pilihan, foto); baris yang dikecualikan beserta alasannya; item tanpa foto; item berharga 0; grup yang diberi nama pembeda; item kit/BoM yang dijual sebagai item biasa; dan item contoh yang ditarik (DEMO). Laporan MUST mencakup semua baris sumber.
- **FR-022**: Operator MUST dapat menjalankan pratinjau yang menghasilkan laporan FR-021 tanpa menyimpan perubahan apa pun.

**Tabel pemetaan**

- **FR-023**: Sebelum menyimpan apa pun, pengisian MUST mencocokkan tabel pemetaan dengan sumber. Setiap item sumber yang tidak dikecualikan harus punya tepat satu baris pemetaan. Setiap kategori dan nilai atribut yang dirujuk harus ada di sumber. Jumlah varian tiap item harus sama dengan kolom "# Product Variants" di sumber, kecuali baris itu menyatakan penyimpangan secara eksplisit. Ketidakcocokan menghentikan pengisian tanpa perubahan dan didaftar di laporan.

### Key Entities

- **Ekspor Odoo WJM 3**: tiga berkas sumber, yaitu kategori POS (10), atribut produk (11 atribut, 26 nilai), dan template produk (80 baris: 79 item menu + 1 produk teknis).
- **Katalog WJM (salinan bersih)**: data yang dibawa pengisian. Per item: nama, kategori, harga, varian (nama, harga), foto, dan grup pilihan. Tidak memuat nama staf atau kolom internal Odoo.
- **Tabel pemetaan katalog**: penugasan per item (kategori, atribut dan nama varian, harga varian, grup pilihan) yang diusulkan dari sumber dan disetujui pemilik. Statusnya draf atau disetujui.
- **Kategori**: kelompok menu di POS; menentukan stasiun persiapan (Bar/Dapur) dan urutan tampil.
- **Item menu dan varian**: yang dipesan kasir. Setiap item punya minimal satu varian dengan harga sendiri, dan tidak melacak stok.
- **Grup pilihan**: pilihan pada pesanan yang tidak membentuk varian (mis. "Rasa").
- **Outlet tujuan**: outlet Warkop WJM 3 di tiap mode, satu-satunya outlet yang menjual item hasil pengisian.
- **Mode data (LIVE/DEMO)**: dua salinan katalog yang terpisah. Kode di kedua salinan berbeda karena kode unik di seluruh sistem.
- **Laporan pengisian**: ringkasan per mode yang menjelaskan nasib setiap baris sumber.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Setelah satu kali jalan untuk LIVE, 100% item katalog WJM (setelah pengecualian) dapat dipesan di POS outlet Warkop WJM 3 dengan harga sumber, dan tidak satu pun item yang dikecualikan muncul di POS.
- **SC-002**: Setelah satu kali jalan untuk DEMO, POS outlet Warkop WJM 3 di DEMO menampilkan katalog WJM tanpa satu pun item contoh, dan 100% pesanan demo lama masih terbaca.
- **SC-003**: Jalan kedua berturut-turut membuat 0 data baru dan mengubah 0 data yang ada.
- **SC-004**: 0 data yang sudah ada sebelum jalan berubah atau terhapus, selain penarikan item contoh di DEMO.
- **SC-005**: Laporan mencakup 100% baris dari ketiga berkas sumber: setiap baris dibuat, sudah ada, atau dikecualikan dengan alasan.
- **SC-006**: 0 nama staf dari sumber tersimpan di sistem atau di data yang dibawa pengisian.
- **SC-007**: Setiap item yang punya foto sah di sumber (61 item) menampilkan foto itu di POS.
- **SC-008**: Pengisian kedua mode selesai dalam kurang dari 2 menit di mesin toko.
- **SC-009**: Jumlah varian setiap item hasil pengisian 100% sama dengan tabel pemetaan yang disetujui (111 varian bila usulan disetujui tanpa perubahan jumlah).

## Assumptions

- **ASSUMPTION**: "live and demo environment" berarti mode data LIVE dan DEMO dalam satu instalasi (fitur 003), bukan dua server terpisah.
- **ASSUMPTION**: Ekspor berasal dari Odoo outlet "Warkop WJM 3" (nama folder "WJM 3"), outlet yang di DEMO saat ini memakai menu contoh. Katalog ini menggantikan menu contoh itu di DEMO; item contoh hanya ditarik dari menu, tidak dihapus.
- **ASSUMPTION**: Persetujuan pemilik dicatat di tabel pemetaan sebagai status "Disetujui" beserta tanggal dan peran penyetuju. Jawaban atas pertanyaan di tabel itu dimasukkan sebelum disetujui.
- **ASSUMPTION**: Pembagian stasiun (minuman ke Bar, lainnya ke Dapur) mengikuti pola menu contoh. Pemilik dapat mengubahnya per kategori atau per item kemudian.
- **ASSUMPTION**: Stok tidak dilacak karena menu warkop dibuat saat dipesan, sama seperti menu contoh. Kuantitas Odoo tidak konsisten (ada yang negatif), sehingga tidak layak menjadi saldo awal.
- **ASSUMPTION**: Kit/BoM Odoo tidak dipetakan karena komponennya tidak ada di ekspor, dan BoM di sistem ini menautkan bahan baku, bukan item menu lain.
- **ASSUMPTION**: Dengan harga pokok 0, margin item-item ini di laporan tampak 100% sampai pemilik mengisi harga pokok atau melengkapi BoM.
- **ASSUMPTION**: Nama item dipakai persis seperti di sumber, termasuk huruf kapital dan ejaannya ("Cappuchino", "ES JERUK"). Merapikan nama adalah keputusan pemilik.
- **ASSUMPTION**: Kategori tampil dalam urutan sumber (alfabetis) karena kolom "Sequence" di sumber bernilai 0 semua.
- **ASSUMPTION**: Katalog bersih hanya disimpan di salinan sistem khusus WJM dan tidak pernah dibawa ke instalasi pelanggan lain. Isinya menu dan harga yang memang tampil ke pembeli; nama staf tidak ikut.
- **ASSUMPTION**: Penugasan staf ke outlet, meja, metode pembayaran, dan pengaturan struk di LIVE tetap diurus pemilik seperti biasa, di luar pengisian ini.
- Berkas keempat di folder yang sama, "Point of Sale Orders (pos.order).xlsx", berada di luar cakupan: tidak diminta, dan impor transaksi penjualan memang dipotong dari cakupan (PRD F15.9).
