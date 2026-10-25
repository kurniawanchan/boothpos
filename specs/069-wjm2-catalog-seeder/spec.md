# Feature Specification: Pengisian katalog Odoo Warkop WJM 2 ke mode LIVE dan DEMO

**Klasifikasi**: CONFIDENTIAL — merangkum katalog nyata milik pelanggan (Warkop WJM).

**Feature Branch**: `069-wjm2-catalog-seeder`

**Created**: 2026-10-25

**Status**: Draft

**Input**: User description: "analyze these excel, then create a data seeder to live and demo environment to insert the data directly to the database of this warkop wjm system for outlet Warkop WJM 2, do not touch data for outlet Warkop WJM 3. category: `WJM 2/Kategori Point of Sale (pos.category).xlsx`; product attribute: `WJM 2/Atribut produk (product.attribute).xlsx`; product data: `WJM 2/Templete Produk (product.template).xlsx`. Update products and variants stock to 100 for Warkop WJM 2 outlet."

**Analisis sumber**: [source-analysis.md](./source-analysis.md) — isi tiap berkas, celah data, dan perbandingan dengan sistem saat ini.

## Clarifications

### Session 2026-10-25

- Q: Ekspor produk berhenti di 80 baris (A–M). Bangun dengan 80 item ini atau tunggu ekspor lengkap? → A: Bangun sekarang dengan 80 item ini. Item yang belum ada ditambahkan kemudian dengan menjalankan ulang pengisian memakai tabel pemetaan yang diperbarui.
- Q: Ekspor atribut tidak memuat nilai. Dari mana nama 141 varian diambil? → A: Dari tabel pemetaan. Nama yang dapat disimpulkan (Hot/Ice, rasa Indomie seperti WJM 3) diusulkan; sisanya diisi pemilik sebelum persetujuan.
- Q: Ke-79 item WJM 3 kini juga dijual di outlet WJM 2. Tarik dari menu WJM 2? → A: Ya. Hanya penanda "dijual di sini" untuk outlet WJM 2 yang berubah; item, harga, stok, dan penjualan WJM 3 di outlet WJM 3 tetap utuh.

**Dasar**: fitur ini memakai ulang pengisian katalog 068 (tabel pemetaan yang disetujui pemilik, pratinjau, satu transaksi per mode, jalan ulang aman, stok awal). Yang baru: outlet kedua di mode yang sama, dan larangan menyentuh data outlet pertama.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Outlet WJM 2 siap berjualan dengan menu Odoo-nya di mode LIVE (Priority: P1)

Warkop WJM 2 pindah dari Odoo ke sistem ini. Outletnya sudah dibuat di mode LIVE. Operator menjalankan pengisian katalog sekali. Setelah itu outlet Warkop WJM 2 punya kategori, item, varian, harga, dan foto dari Odoo (sesuai tabel pemetaan yang disetujui pemilik), dengan stok 100 per varian. Kasir WJM 2 bisa langsung memesannya.

**Why this priority**: Inilah inti permintaan. Tanpa ini pemilik harus mengetik ulang 80 item dan 141 varian.

**Independent Test**: Pada DB berisi outlet WJM 3 lengkap dan outlet WJM 2 kosong di LIVE, jalankan pengisian untuk WJM 2. Lalu periksa POS outlet WJM 2: kategori, item, varian, harga, foto, dan stok 100 sesuai tabel pemetaan. Pesanan dapat dibuat, dan tiketnya masuk ke stasiun milik WJM 2.

**Acceptance Scenarios**:

1. **Given** outlet "Warkop WJM 2" ada di LIVE tanpa item sendiri, **When** pengisian dijalankan, **Then** semua item dan varian dari tabel pemetaan yang disetujui tersedia di outlet itu, dengan harga sesuai tabel dan stok 100 per varian di outlet itu.
2. **Given** pengisian selesai, **When** kasir WJM 2 memesan satu minuman dan satu makanan, **Then** pesanan tersimpan, stok item itu berkurang, dan tiket masuk ke stasiun WJM 2, bukan stasiun WJM 3.
3. **Given** item WJM 2 punya nama yang juga dipakai item WJM 3 (mis. "BASRENG" dan "Basreng"), **When** pengisian dijalankan, **Then** item WJM 2 tetap dibuat sebagai milik WJM 2. Item WJM 3 dengan nama serupa tidak dipakai ulang, tidak diubah, dan tidak dianggap "sudah ada".

---

### User Story 2 - Data WJM 3 tidak tersentuh (Priority: P1)

Pemilik sudah menyetujui dan menyunting katalog WJM 3 (harga, nama varian, foto, stok 100). Pengisian WJM 2 tidak boleh mengubah satu pun data itu.

**Why this priority**: Larangan eksplisit dari pengguna, dan kesalahan di sini merusak outlet yang sudah berjalan.

**Independent Test**: Ambil potret seluruh data milik WJM 3 (item, varian, harga, riwayat harga, foto, stok per outlet, gerakan stok, kategori dan stasiunnya, penanda menu di outlet WJM 3, pengaturan outlet WJM 3) sebelum pengisian. Satu-satunya perubahan yang diizinkan adalah penanda "dijual di outlet WJM 2" untuk item WJM 3 (FR-019). Bandingkan dengan potret sesudahnya: harus identik.

**Acceptance Scenarios**:

1. **Given** katalog WJM 3 lengkap di LIVE dan DEMO, **When** pengisian WJM 2 dijalankan di kedua mode, **Then** potret data WJM 3 sebelum dan sesudah identik.
2. **Given** kategori bernama sama dipakai kedua outlet (mis. "COFFEE"/"Coffee"), **When** pengisian dijalankan, **Then** nama, urutan, dan stasiun kategori WJM 3 tidak berubah, dan tiket item WJM 2 tetap masuk ke stasiun WJM 2.
3. **Given** item WJM 3 saat ini juga dijual di outlet WJM 2, **When** pengisian dijalankan, **Then** item-item itu tidak lagi dijual di outlet WJM 2, tetap dijual di outlet WJM 3, dan semua data lain milik WJM 3 tidak berubah.

---

### User Story 3 - Katalog yang sama di mode DEMO (Priority: P2)

Untuk pelatihan kasir WJM 2, mode DEMO juga punya outlet Warkop WJM 2 dengan katalog yang sama dan stok 100.

**Why this priority**: Berguna untuk pelatihan, tetapi outlet LIVE lebih penting.

**Independent Test**: Jalankan pengisian mode DEMO. Outlet Warkop WJM 2 dibuat di DEMO bila belum ada, berisi katalog yang sama dengan LIVE, dan outlet DEMO lain (WJM 3) tidak berubah.

**Acceptance Scenarios**:

1. **Given** DEMO belum punya outlet Warkop WJM 2, **When** pengisian mode DEMO dijalankan, **Then** outlet itu dibuat beserta stasiunnya, lalu diisi katalog yang sama dengan LIVE dan stok 100 per varian.
2. **Given** pengisian berhasil di LIVE tetapi gagal di DEMO, **When** operator memeriksa hasilnya, **Then** LIVE tetap terisi dan DEMO kembali utuh seperti sebelum dijalankan.

---

### User Story 4 - Pratinjau dan jalan ulang yang aman (Priority: P2)

Operator dapat melihat apa yang akan dibuat sebelum menyimpan, dan dapat menjalankan ulang tanpa menggandakan data atau stok.

**Why this priority**: Pengisian menulis langsung ke DB toko, jadi operator butuh kepastian sebelum dan sesudah.

**Independent Test**: Jalankan pratinjau: laporan muncul dan DB tidak berubah. Jalankan sungguhan dua kali: jalan kedua tidak membuat apa pun dan tidak menambah stok.

**Acceptance Scenarios**:

1. **Given** katalog yang disetujui, **When** operator menjalankan pratinjau, **Then** laporan per mode menyebut jumlah kategori, stasiun, item, varian, foto, dan stok yang akan dibuat, serta item berharga 0, dan DB tidak berubah.
2. **Given** pengisian sudah berjalan, **When** dijalankan lagi, **Then** semua item WJM 2 dilaporkan "sudah ada", stoknya tetap, dan suntingan pemilik sesudah pengisian (harga, foto, stok) bertahan.
3. **Given** tabel pemetaan masih berstatus draf, **When** operator menjalankan pengisian sungguhan, **Then** pengisian ditolak tanpa perubahan apa pun. Pratinjau tetap boleh.

### Edge Cases

- Outlet bernama "Warkop WJM 2" tidak ada, atau ada lebih dari satu di sebuah mode: LIVE menolak dengan pesan jelas. DEMO membuatnya bila tidak ada.
- Nama item sama dalam satu kategori WJM 2: ditolak sebagai masalah tabel pemetaan sebelum ada penulisan.
- Nama sumber berspasi ganda atau berakhiran spasi ("MARTABAK TELUR  KORNET MINI", atribut `Mentai `): dirapikan secara konsisten, dan perapian dicatat di laporan.
- Item berharga 0 (3 item): diisi bila disetujui pemilik, dan selalu muncul di laporan "harga 0, tinjau pemilik".
- Foto yang terpotong di ekspor: item tetap dibuat tanpa foto, kecuali tabel pemetaan memberi foto pengganti.
- Kategori sumber tanpa item (mis. NASI, TEA, SIGNATURE COFFEE karena ekspor terpotong): tidak dibuat. Tabel pemetaan menyatakannya secara eksplisit, dan kategori itu dibuat kelak bila jalan ulang membawa itemnya.
- Nama varian masih kosong di tabel pemetaan: katalog tidak dapat dibangkitkan, dan masalahnya disebut per item.
- Item WJM 2 dihapus atau diganti nama oleh pemilik sesudah pengisian: jalan ulang tidak membuatnya kembali atau membuat salinannya. Perilakunya sama dengan 068.
- Satu mode gagal di tengah: mode itu dibatalkan seluruhnya; mode lain tidak terpengaruh.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Pengisian MUST membaca katalog WJM 2 dari berkas katalog yang dibangkitkan dari tabel pemetaan WJM 2 yang disetujui pemilik, terpisah dari katalog WJM 3. Ekspor XLSX mentah tidak boleh masuk repo.
- **FR-002**: Cakupan item MUST berupa 80 produk dalam ekspor saat ini. Kategori sumber tanpa item tidak dibuat. Item tambahan dari ekspor yang lebih lengkap MUST dapat ditambahkan kemudian dengan jalan ulang, tanpa mengubah item WJM 2 yang sudah ada.
- **FR-003**: Nama varian MUST diambil dari tabel pemetaan. Jumlah varian per item MUST sama dengan `# Varian Produk` di sumber, kecuali pemilik menyatakan penyimpangan beserta alasannya. Tabel yang masih punya nama varian kosong tidak boleh disetujui, dan pembangkitan katalog menolaknya.
- **FR-004**: Kategori dan item MUST ditetapkan per item lewat tabel pemetaan yang disetujui pemilik, karena ekspor produk tidak memuat kategori.
- **FR-005**: Setiap item dan varian hasil pengisian MUST milik outlet Warkop WJM 2 dan hanya dijual di outlet itu dalam mode yang sama.
- **FR-006**: Pengisian MUST tidak mengubah, menghapus, atau memakai ulang data milik outlet Warkop WJM 3: item, varian, harga, riwayat harga, foto, stok, gerakan stok, penanda menu di outlet WJM 3, stasiun, meja, perangkat, dan kategori (nama, urutan, stasiun). Satu-satunya pengecualian ada di FR-019.
- **FR-007**: Identitas "item sudah ada" MUST dinilai di dalam outlet WJM 2 saja. Item outlet lain dengan nama atau kategori serupa tidak pernah dianggap sama.
- **FR-008**: Tiket dapur item WJM 2 MUST masuk ke stasiun milik outlet WJM 2. Pengisian membuat stasiun WJM 2 yang dibutuhkan bila belum ada.
- **FR-009**: Kategori yang namanya sudah ada di mode itu (huruf besar-kecil diabaikan) MAY dipakai bersama, asalkan FR-006 dan FR-008 terpenuhi. Kategori baru dibuat dengan nama dari tabel pemetaan.
- **FR-010**: Setiap varian WJM 2 MUST melacak stok dan mulai dengan stok 100 di outlet WJM 2. Stok ini tercatat sebagai gerakan stok awal, bukan angka yang ditimpa.
- **FR-011**: Pengisian MUST berjalan untuk LIVE dan DEMO. Tiap mode adalah satu satuan semua-atau-tidak-sama-sekali, dan kegagalan satu mode tidak membatalkan mode lain.
- **FR-012**: Di LIVE, pengisian MUST memakai outlet "Warkop WJM 2" yang sudah ada dan tidak membuat outlet baru. Di DEMO, outlet itu dibuat bila belum ada.
- **FR-013**: Pengisian MUST punya mode pratinjau yang melaporkan semua yang akan dibuat tanpa menyimpan apa pun.
- **FR-014**: Jalan ulang MUST tidak membuat duplikat, tidak menambah stok, dan tidak menimpa suntingan pemilik.
- **FR-015**: Katalog berstatus draf MUST ditolak untuk pengisian sungguhan.
- **FR-016**: Setiap mode yang berhasil MUST mencatat satu entri log aktivitas berisi mode, outlet, sumber, dan jumlah yang dibuat, dilewati, dan dikecualikan.
- **FR-017**: Nama staf (kolom "Penanggung Jawab") dan data pribadi lain MUST tidak masuk katalog, laporan, maupun log.
- **FR-018**: Laporan MUST mempertanggungjawabkan setiap baris sumber (80 produk, 13 kategori, 14 atribut): dibuat, sudah ada, atau dikecualikan beserta alasannya.
- **FR-019**: Item yang bukan milik outlet WJM 2 (termasuk ke-79 item WJM 3) MUST ditarik dari menu outlet WJM 2 di mode yang diisi, dengan mengubah hanya penanda "dijual di outlet WJM 2". Item itu tidak dihapus, dan tetap dijual di outlet pemiliknya. Laporan MUST menyebut item yang ditarik.

### Key Entities *(include if feature involves data)*

- **Outlet Warkop WJM 2**: outlet tujuan, satu per mode. Di LIVE sudah ada (kode WJD); di DEMO dibuat oleh pengisian.
- **Stasiun WJM 2**: tempat tiket dapur/bar outlet WJM 2 diarahkan; milik outlet WJM 2.
- **Kategori**: pengelompokan menu yang berlaku untuk seluruh mode; bisa dipakai bersama kedua outlet.
- **Item dan varian WJM 2**: menu milik outlet WJM 2, dengan harga, foto opsional, dan stok per outlet.
- **Tabel pemetaan WJM 2**: keputusan pemilik per item (kategori, stasiun, varian, harga khusus, foto pengganti, stok awal) beserta status persetujuan.
- **Laporan pengisian**: ringkasan per mode untuk operator dan log aktivitas.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% item dan varian dalam tabel pemetaan yang disetujui tersedia di POS outlet WJM 2 pada kedua mode, dengan harga yang sama persis dengan tabel.
- **SC-002**: 0 perubahan pada data WJM 3 selain penanda "dijual di outlet WJM 2" (FR-019), dibuktikan dengan potret sebelum dan sesudah di kedua mode.
- **SC-008**: POS outlet WJM 2 hanya menampilkan item milik WJM 2 (0 item WJM 3).
- **SC-003**: Setiap varian WJM 2 menunjukkan stok 100 di outlet WJM 2 segera sesudah pengisian.
- **SC-004**: Jalan kedua membuat 0 baris dan 0 gerakan stok.
- **SC-005**: Pengisian kedua mode selesai dalam waktu kurang dari 2 menit.
- **SC-006**: Pesanan uji di outlet WJM 2 masuk ke stasiun WJM 2 pada 100% percobaan.
- **SC-007**: Setiap baris sumber muncul di laporan dengan status yang jelas (0 baris tak tercatat).

## Assumptions

- Kategori setiap item, stasiun tiap kategori (minuman ke Bar, makanan ke Dapur), dan pengelompokan varian disusun sebagai usulan di tabel pemetaan WJM 2, lalu disetujui pemilik sebelum pengisian sungguhan (sama dengan 068).
- Atribut `Varian` dengan 2 varian diusulkan sebagai Hot/Ice dengan harga Ice sama dengan Hot, mengikuti WJM 3, kecuali pemilik menetapkan lain.
- Nama item dipakai seperti di sumber (huruf kapital); spasi ganda dirapikan. Pemilik dapat mengubah nama di tabel pemetaan.
- Harga varian sama dengan harga item kecuali tercantum di tabel harga khusus.
- Foto pakai 11 JPEG utuh dari sumber. Item lain tanpa foto, kecuali tabel pemetaan memberi foto pengganti. Foto WJM 3 tidak disalin otomatis.
- Pengaturan outlet WJM 2 (alamat, meja, perangkat, kasir) di luar cakupan; pemilik mengaturnya lewat layar yang ada.
- Pengisian ke server pelanggan memerlukan otorisasi eksplisit pengembang, sama dengan 068 (T043).
- Berkas `Order Point of Sale (pos.order).xlsx` tidak dipakai kecuali pengguna mengizinkan dan data pelanggan di dalamnya dimasking dulu.
