# Feature Specification: Stok Menu Berbasis Resep (Web dan Android)

**Klasifikasi**: INTERNAL

**Feature Branch**: `092-recipe-based-menu-stock`

**Created**: 2026-11-06

**Status**: Draft

**Input**: User description: "Terapkan mekanisme stok berbasis resep untuk item menu di POS Web dan POS Android: stok tersedia sebuah menu berasal dari bahan baku di resepnya (bahan dengan kapasitas terendah menentukan), pemotongan stok saat penjualan selesai memotong bahan sesuai resep dan bukan stok varian, kedua aplikasi menampilkan angka yang sama dan menolak pesanan melebihi stok, menu tanpa resep tidak berubah, aman dari penjualan berlebih saat banyak terminal, tidak ada pemotongan ganda saat percobaan ulang."

## Latar

Hari ini (fitur 087 dan 089) bahan baku **sudah dipotong** saat pesanan selesai, dari dua sumber: resep aktif varian dan bahan pilihan modifier. Yang **belum** ada, dan menjadi isi fitur ini:

1. **Angka stok menu tidak berasal dari bahan.** Kartu menu di POS hanya menampilkan stok varian, atau (untuk produk bersumber `modifier`) jumlah porsi dari pilihan modifier. Menu yang punya resep tidak pernah menampilkan "bisa dibuat berapa porsi" dari bahan resepnya.
2. **Menu beresep tidak otomatis lepas dari stok varian.** Sumber stok adalah pengaturan manual per produk (`variant` atau `modifier`). Menu yang sudah punya resep aktif tetapi masih bersumber `variant` memotong DUA hal: stok varian dan bahan resep.
3. **Pesanan tidak ditahan oleh kapasitas bahan.** Saldo bahan boleh minus saat penjualan; peringatan hanya muncul sesudahnya. Fitur ini membalik aturan itu untuk menu beresep (FR-013).

Istilah:

| Istilah | Arti | Contoh |
|---|---|---|
| Menu beresep | Varian yang punya setidaknya satu resep aktif (tidak diarsipkan, sudah berlaku) | Indomie Kuah |
| Kebutuhan per porsi | Jumlah bahan untuk SATU porsi menu, setelah memperhitungkan susut dan jumlah porsi resep | 50 gr sayur |
| Kapasitas bahan | Saldo bahan di outlet ÷ kebutuhan per porsi, dibulatkan ke bawah | 500 gr ÷ 50 gr = 10 |
| Stok menu (berbasis resep) | Kapasitas bahan TERENDAH di antara semua bahan resep (bahan penghambat) | min(20, 10, 10, 10) = 10 |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Kasir melihat stok menu dari bahan resep (Priority: P1)

Kasir membuka POS (web atau tablet) di sebuah outlet. Kartu menu yang punya resep menampilkan jumlah porsi yang masih bisa dibuat dari saldo bahan **outlet itu**, bukan angka stok varian.

**Why this priority**: Inilah nilai utama fitur: pemilik cukup menjaga saldo bahan, dan menu otomatis tahu kapan habis.

**Independent Test**: Buat menu dengan empat bahan (Indomie 20 pcs, Telur 10 pcs, Sayur 500 gr, Kuah 2.000 ml; kebutuhan per porsi 1 pcs, 1 pcs, 50 gr, 200 ml). Buka POS; kartu harus menampilkan 10 porsi.

**Acceptance Scenarios**:

1. **Given** resep Indomie Kuah dengan saldo bahan di atas, **When** kasir membuka POS, **Then** stok menu tampil 10 dan bahan penghambat adalah Telur, Sayur, atau Kuah.
2. **Given** salah satu bahan resep bersaldo 0, **When** POS memuat menu, **Then** menu tampil habis dan tidak bisa ditambahkan ke keranjang.
3. **Given** saldo Telur menjadi 3 (bahan lain tetap), **When** daftar menu dimuat ulang, **Then** stok menu tampil 3.
4. **Given** menu beresep yang stok varian-nya tercatat 999, **When** POS menampilkan menu, **Then** angka 999 diabaikan; hanya bahan yang menentukan.
5. **Given** menu tanpa resep, **When** POS menampilkan menu, **Then** perilaku stok varian tidak berubah.

---

### User Story 2 - Penjualan memotong bahan, tidak memotong stok varian (Priority: P1)

Saat pesanan menu beresep selesai, tiap bahan dipotong sebesar (jumlah porsi terjual × kebutuhan per porsi). Stok varian menu beresep tidak pernah bergerak, di jalur mana pun (POS langsung, tab, pecah tagihan, pesan mandiri QR).

**Why this priority**: Tanpa ini angka pada Story 1 menyimpang dari kenyataan dan stok varian menghitung dobel.

**Independent Test**: Jual 2 porsi Indomie Kuah. Saldo harus berkurang: Indomie 2 pcs, Telur 2 pcs, Sayur 100 gr, Kuah 400 ml; stok varian tidak berubah.

**Acceptance Scenarios**:

1. **Given** saldo bahan Story 1, **When** 2 porsi terjual dan pesanan selesai, **Then** keempat bahan berkurang sesuai contoh dan tiap pengurangan tercatat di riwayat bahan dengan rujukan ke baris pesanan.
2. **Given** menu beresep, **When** pesanan selesai lewat jalur apa pun, **Then** tidak ada pergerakan stok varian untuk baris itu.
3. **Given** pesanan yang sama diproses ulang karena percobaan ulang klien atau jaringan, **When** permintaan kedua tiba, **Then** bahan tidak dipotong dua kali.
4. **Given** pesanan selesai lalu di-void atau di-refund, **When** pembatalan diproses, **Then** bahan dikembalikan sesuai aturan pembalikan yang berlaku saat ini (dibaca dari buku besar, bukan dihitung ulang dari resep yang mungkin sudah berubah).
5. **Given** menu tanpa resep, **When** pesanan selesai, **Then** stok varian dipotong seperti sekarang.

---

### User Story 3 - Pesanan melebihi stok bahan ditolak (Priority: P1)

Kasir tidak bisa menambahkan jumlah melebihi stok menu berbasis resep, baik di keranjang POS maupun saat server memeriksa pesanan.

**Why this priority**: Mencegah janji yang tak bisa dipenuhi dan penjualan berlebih ketika beberapa terminal aktif bersamaan.

**Independent Test**: Dengan stok menu 10, coba pesan 11 di satu terminal; ditolak. Dua terminal masing-masing memesan 6 hampir bersamaan; hanya satu yang berhasil.

**Acceptance Scenarios**:

1. **Given** stok menu 10, **When** kasir menaikkan jumlah ke 11 di keranjang, **Then** tombol tambah berhenti di 10 dan pesan jelas menyebut bahan penghambat.
2. **Given** dua terminal membaca stok 10 lalu masing-masing mengirim 6 porsi, **When** kedua pesanan diproses, **Then** salah satunya ditolak dengan galat bisnis (409) dan saldo bahan tidak pernah minus.
3. **Given** satu pesanan memuat dua baris menu yang berbagi bahan yang sama (mis. telur), **When** server memeriksa, **Then** kebutuhan bahan DIJUMLAHKAN lintas baris sebelum dibandingkan dengan saldo.
4. **Given** pesanan ditolak karena stok, **When** ditolak, **Then** tidak ada bahan terpotong dan tidak ada pesanan setengah jadi.

---

### User Story 4 - Web dan Android menampilkan angka yang sama (Priority: P2)

Untuk menu, outlet, dan saldo bahan yang sama, POS web dan POS Android menampilkan stok menu yang sama persis. Server satu-satunya penghitung; kedua klien hanya menampilkan.

**Why this priority**: Pemilik tidak boleh melihat angka berbeda di dua layar; tetapi nilai dasarnya (Story 1–3) sudah berdiri tanpa ini.

**Independent Test**: Bandingkan kartu menu yang sama di web dan tablet pada outlet yang sama; angkanya identik, dan keduanya ikut berubah setelah satu penjualan.

**Acceptance Scenarios**:

1. **Given** outlet dan saldo yang sama, **When** web dan tablet memuat menu, **Then** stok menu sama.
2. **Given** sebuah penjualan selesai di salah satu klien, **When** klien itu dan klien lain menyegarkan daftar (otomatis atau tarik-untuk-segarkan), **Then** keduanya menampilkan stok baru.
3. **Given** APK lama yang belum mengenal bidang baru, **When** memuat menu beresep, **Then** aplikasi tetap bekerja (bidang tak dikenal diabaikan) dan server tetap menolak pesanan yang melebihi bahan.

---

### User Story 5 - Menu tanpa resep tidak berubah (Priority: P2)

Semua menu tanpa resep aktif memakai stok varian persis seperti sebelumnya: tampilan, batas qty, pemotongan, pengembalian, laporan stok.

**Why this priority**: Kompatibilitas mundur; sebagian besar katalog tidak beresep.

**Independent Test**: Jalankan seluruh rangkaian tes stok dan POS yang ada; semuanya tetap lulus tanpa perubahan harapan.

**Acceptance Scenarios**:

1. **Given** menu tanpa resep dengan stok varian 5, **When** kasir memesan 6, **Then** ditolak seperti sekarang.
2. **Given** resep sebuah menu diarsipkan atau dihapus sehingga tidak ada resep aktif, **When** POS memuat menu, **Then** menu kembali memakai stok varian.

---

### Edge Cases

- **Resep belum berlaku** (`effective_from` di masa depan) atau **diarsipkan**: tidak dihitung; menu memakai stok varian.
- **Bahan resep tidak punya baris saldo di outlet itu**: dianggap 0, sehingga menu habis di outlet itu.
- **Saldo bahan minus** (warisan dari sebelum fitur ini): kapasitas dihitung 0, tidak negatif.
- **Kebutuhan per porsi 0 atau kosong**: baris itu tidak membatasi kapasitas.
- **Satuan berbeda** (pcs, gr, ml): setiap bahan dibandingkan dalam satuan dasarnya sendiri; tidak ada konversi lintas satuan di dalam satu bahan.
- **Jumlah pecahan** (mis. 0,5 butir): kapasitas dihitung dari angka pecahan penuh lalu dibulatkan ke bawah hanya di hasil akhir; saldo disimpan dengan tiga desimal.
- **Susut (waste %)** dan **jumlah porsi resep** (Portions): kebutuhan per porsi mengikuti rumus yang sama dengan pemotongan, supaya angka tampil dan angka terpotong tidak berbeda.
- **Beberapa resep aktif pada satu varian**: kebutuhannya dijumlahkan (aturan 089); bahan yang sama di dua resep dijumlahkan sebelum dibagi.
- **Menu beresep banyak varian** yang berbagi bahan: tiap varian dihitung sendiri; kartu produk menampilkan stok gabungan secara konsisten dengan aturan kartu yang berlaku.
- **Bahan dipakai pilihan modifier** yang dipilih pada baris: dipotong seperti sekarang (087/089); lihat Asumsi untuk batas pemeriksaan stoknya.
- **Pesanan ber-tab** (terbuka lama): bahan tetap dipotong saat pesanan selesai, bukan saat baris masuk tab (aturan 087 dipertahankan); pemeriksaan kapasitas tetap dilakukan saat baris ditambahkan dan saat pesanan diselesaikan.
- **Percobaan ulang** (`local_ref` sama, atau tombol bayar ditekan dua kali): satu pemotongan.
- **Menu ditandai 86 (habis manual)**: tetap tidak bisa dipesan, terlepas dari saldo bahan.
- **Mode DEMO dan LIVE**: saldo bahan dan hitungan terpisah per mode, seperti sekarang.

## Requirements *(mandatory)*

### Functional Requirements

**Stok menu berbasis resep**

- **FR-001**: Sistem MUST menghitung stok menu beresep sebagai jumlah porsi utuh terbesar yang bisa dibuat dari saldo bahan di OUTLET pemesanan, yaitu kapasitas terendah di antara semua bahan resep dasar varian itu.
- **FR-002**: Kebutuhan per porsi MUST memakai rumus yang SAMA dengan rumus pemotongan stok saat penjualan selesai (jumlah bahan, susut, dan jumlah porsi resep), supaya angka tampil dan angka terpotong tidak pernah berbeda.
- **FR-003**: Bila ada bahan resep dengan saldo 0 (atau minus, atau tanpa baris saldo di outlet itu), stok menu MUST 0 dan menu MUST tidak bisa dipesan.
- **FR-004**: Stok varian MUST NOT menjadi sumber kebenaran ketersediaan menu beresep; angka stok varian diabaikan untuk keperluan tampil, batas qty, dan pemeriksaan pesanan.
- **FR-005**: Menu beresep MUST dikenali otomatis dari adanya resep aktif; pemilik tidak perlu mengubah pengaturan sumber stok produk secara manual Keputusan pemilik (2026-11-06): SEMUA produk yang sudah punya resep aktif beralih otomatis ke stok bahan saat fitur ini aktif, termasuk yang bersumber `variant` hari ini; stok varian-nya berhenti bergerak sejak saat itu dan angka lamanya dibiarkan apa adanya (tidak ditulis ulang).
- **FR-006**: Menu tanpa resep aktif MUST tetap memakai mekanisme stok varian dan perilaku yang ada tanpa perubahan apa pun (tampilan, batas qty, potong, kembalikan, laporan).

**Pemotongan dan pembalikan**

- **FR-007**: Saat pesanan menjadi selesai, sistem MUST memotong tiap bahan sebesar jumlah porsi terjual × kebutuhan per porsi, satu pergerakan per bahan, tercatat dengan rujukan ke baris pesanan.
- **FR-008**: Untuk baris menu beresep, sistem MUST NOT membuat pergerakan stok varian di jalur mana pun (kasir langsung, tab, pecah tagihan, pesan mandiri QR, tablet).
- **FR-009**: Pemotongan MUST terjadi tepat sekali per pesanan selesai, termasuk saat permintaan yang sama dikirim ulang.
- **FR-010**: Void, refund, dan pembatalan baris MUST memulihkan bahan lewat mekanisme pembalikan yang sudah ada (membaca buku besar `sale − return` per baris), bukan menghitung ulang dari resep saat ini.
- **FR-011**: Seluruh pemeriksaan kapasitas dan pemotongan dalam satu pesanan MUST atomik: gagal di titik mana pun membatalkan semuanya, tanpa pesanan setengah jadi dan tanpa bahan terpotong sebagian.

**Pembatasan pesanan**

- **FR-012**: POS MUST mencegah jumlah di keranjang melebihi stok menu beresep, dan menampilkan alasan yang menyebut bahan penghambat.
- **FR-013**: Server MUST menolak pesanan yang kebutuhan bahannya melebihi saldo (galat bisnis 409 dengan bahan penghambat), dengan kebutuhan DIJUMLAHKAN lintas semua baris pesanan sebelum dibandingkan Keputusan pemilik (2026-11-06): penolakan berlaku KERAS untuk semua menu beresep, tanpa pengaturan per menu. Ini MEMBALIK keputusan 087 (saldo bahan boleh minus saat penjualan dengan peringatan saja) khusus untuk menu beresep; pengurangan non-penjualan dan jalur tanpa resep tidak berubah.
- **FR-014**: Dua terminal yang memesan bahan yang sama hampir bersamaan MUST tidak pernah membuat saldo bahan minus akibat penjualan berlebih; pemeriksaan dan pemotongan dilakukan di bawah kunci yang sama sehingga hanya pesanan yang muat yang berhasil.

**Konsistensi Web dan Android**

- **FR-015**: Stok menu beresep MUST dihitung SATU kali di server dan dikirim ke kedua klien; klien MUST NOT menghitung ulang dari bahan.
- **FR-016**: Payload menu POS MUST membawa stok menu efektif beserta bahan penghambat dengan bentuk yang sama untuk web dan Android; bidang baru bersifat tambahan sehingga APK lama tetap bekerja.
- **FR-017**: Stok menu MUST diperbarui di kedua klien setelah penjualan berhasil dan pada penyegaran daftar/ketersediaan berkala yang sudah ada.
- **FR-018**: Daftar menu MUST dimuat tanpa kueri per kartu: seluruh saldo bahan dan resep untuk satu halaman menu dimuat dalam jumlah kueri tetap.

**Keterlacakan**

- **FR-019**: Pemilik MUST dapat melihat dari riwayat bahan bahwa sebuah pengurangan berasal dari penjualan menu tertentu, dengan jumlah per bahan yang cocok dengan contoh perhitungan resep.

### Key Entities

- **Menu beresep**: varian dengan setidaknya satu resep aktif; stoknya diturunkan dari bahan, bukan disimpan.
- **Resep, blok resep, baris resep**: sudah ada (089); baris menyatakan bahan, jumlah, dan susut; blok dasar milik varian menentukan kapasitas menu.
- **Bahan baku dan saldo bahan per outlet**: sumber kebenaran tunggal stok menu beresep; riwayat pergerakan append-only.
- **Stok menu (turunan)**: angka porsi plus bahan penghambat; tidak disimpan sebagai kolom stok baru, dihitung dari saldo bahan saat daftar dimuat.
- **Pergerakan bahan**: satu baris per bahan per baris pesanan, dengan rujukan ke baris pesanan, dipakai juga untuk pembalikan.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Untuk contoh Indomie Kuah, kartu menu menampilkan tepat 10 porsi di web dan di tablet; setelah penjualan 2 porsi, kedua layar menampilkan 8 setelah penyegaran.
- **SC-002**: Penjualan 2 porsi mengurangi tepat Indomie 2 pcs, Telur 2 pcs, Sayur 100 gr, Kuah 400 ml, dan tidak mengubah stok varian sama sekali.
- **SC-003**: Dalam uji dua terminal yang memesan bahan yang sama bersamaan dengan 100 percobaan, saldo bahan tidak pernah menjadi minus akibat penjualan berlebih dan total porsi terjual tidak pernah melebihi stok awal.
- **SC-004**: Pesanan yang sama dikirim 5 kali berturut-turut (percobaan ulang) memotong bahan tepat satu kali.
- **SC-005**: Void atau refund pesanan menu beresep mengembalikan setiap bahan ke saldo semula, termasuk bila resep sudah diubah setelah penjualan.
- **SC-006**: Semua tes stok, pesanan, dan POS untuk menu tanpa resep yang ada sebelum fitur ini tetap lulus tanpa perubahan harapan.
- **SC-007**: Memuat daftar menu beresep untuk satu halaman (hingga 100 menu) tidak lebih lambat secara terasa bagi kasir dibanding sekarang dan memakai jumlah kueri yang tidak bertambah seiring jumlah menu.
- **SC-008**: Menu dengan salah satu bahan bersaldo 0 tampil habis dan tidak dapat dipesan di web maupun tablet pada pemuatan berikutnya.

## Assumptions

- **Pemotongan tetap saat pesanan selesai.** Aturan 087/089 (bahan tidak bergerak selama tab terbuka; dipotong saat selesai) dipertahankan; fitur ini menambah angka stok turunan dan pemeriksaan kapasitas, bukan mengubah waktu pemotongan.
- **Kapasitas menu dihitung dari blok resep DASAR varian.** Blok yang terikat pilihan modifier tidak ikut menentukan stok kartu, karena pilihannya baru diketahui saat dipesan; bahan pilihan modifier tetap diperlakukan sesuai aturan 087 (porsi per opsi ditampilkan; tidak menurunkan stok kartu menu).
- **Outlet pemesanan** menentukan saldo yang dipakai (saldo bahan per outlet), sama seperti stok varian per outlet hari ini.
- **Satu satuan per bahan.** Tidak ada konversi satuan lintas bahan; "satuan berbeda" berarti tiap bahan dibandingkan dalam satuan dasarnya sendiri.
- **Pembulatan:** stok menu dibulatkan KE BAWAH ke bilangan bulat; kebutuhan dan saldo dihitung dengan presisi tiga desimal.
- **Satu penghitung server** untuk web dan Android; Android tidak mendapat logika baru selain menampilkan bidang yang dikirim server dan menahan tombol tambah di batas.
- **Cakupan jalur pesanan:** POS web, tab, pecah tagihan, tablet, dan pesan mandiri QR; seluruhnya lewat pintu pesanan yang sudah ada.
- **Di luar cakupan:** pembelian ke vendor, penjadwalan produksi, konversi satuan lintas bahan, perubahan editor resep, dan penulisan ulang pergerakan stok historis.
- **Kontrak API** (`docs/openapi-pos-mvp.yaml`) bergerak bersama perubahan respons di commit yang sama.
