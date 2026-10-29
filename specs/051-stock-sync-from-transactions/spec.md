# Feature Specification: Sinkronisasi Stok Riil dengan Transaksi yang Sudah Ada

**Feature Branch**: `082-stock-sync-from-transactions`

**Created**: 2026-10-30

**Status**: Draft (klarifikasi Q1 = A, Q2 = B sudah dijawab 2026-10-30)

**Input**: User description: "I just update the real stock for each product variant with live system mode. but before I update, there are pre-created preorder and sales transaction. the real stock should be taken for those transaction and recalculated but instead now is the total stock without taken by transaction. help me to sync the real stock for each variant by calculate it from preorder and sales transaction and preorder. dry-run first, ask for my approval to update in database and output the result in excel for my review."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Melihat hasil koreksi stok sebelum apa pun diubah (Priority: P1)

Pemilik sudah memasukkan stok riil (hasil hitung fisik) untuk tiap varian di mode LIVE, tetapi sebelum itu sudah ada transaksi penjualan dan pre-order yang dibuat. Stok yang tersimpan sekarang adalah angka yang diketik, tanpa dikurangi transaksi tersebut. Pemilik menjalankan koreksi dalam mode **uji coba (dry-run)**: sistem menghitung stok yang seharusnya untuk setiap varian, menampilkan ringkasan, dan menghasilkan berkas Excel untuk ditinjau. Tidak ada data yang berubah.

**Why this priority**: Ini menyangkut stok nyata dan uang; pemilik harus bisa memeriksa angka sebelum disentuh. Tanpa ini tidak ada yang aman dilakukan.

**Independent Test**: Jalankan dry-run pada data LIVE yang punya penjualan sebelum pembaruan stok; berkas Excel memuat stok sekarang, jumlah terjual yang dikurangkan, dan stok seharusnya per varian, sedangkan stok dan riwayat stok di sistem tetap persis sama.

**Acceptance Scenarios**:

1. **Given** varian dengan stok riil 50 yang diketik sesudah 8 unit terjual lewat transaksi, **When** pemilik menjalankan dry-run, **Then** Excel menampilkan stok sekarang 50, pengurang 8, stok seharusnya 42, dan stok di sistem tetap 50.
2. **Given** varian tanpa transaksi sebelum pembaruan stoknya, **When** dry-run, **Then** varian itu ditandai "tidak ada perubahan".
3. **Given** dry-run selesai, **When** pemilik memeriksa riwayat stok dan log aktivitas, **Then** tidak ada catatan baru.

---

### User Story 2 - Menerapkan koreksi setelah persetujuan eksplisit (Priority: P1)

Setelah meninjau Excel, pemilik menyetujui. Hanya setelah persetujuan itu sistem memperbarui stok tiap varian, dan setiap perubahan tercatat di riwayat stok sebagai koreksi yang bisa ditelusuri.

**Why this priority**: Inilah tujuan fitur; harus aman (tidak ada perubahan tanpa persetujuan) dan terlacak.

**Independent Test**: Setujui hasil dry-run; stok tiap varian berubah ke angka seharusnya, setiap perubahan punya catatan riwayat dengan alasan, dan menjalankan ulang tidak mengubah apa pun.

**Acceptance Scenarios**:

1. **Given** hasil dry-run yang sudah ditinjau, **When** pemilik menolak/tidak menyetujui, **Then** tidak ada data berubah.
2. **Given** pemilik menyetujui, **When** koreksi diterapkan, **Then** stok setiap varian sama dengan nilai "stok seharusnya" di Excel yang ditinjau, dan setiap varian yang berubah punya satu catatan riwayat stok beralasan jelas.
3. **Given** koreksi sudah diterapkan, **When** dry-run dijalankan lagi, **Then** semua varian ditandai "tidak ada perubahan" (tidak ada pengurangan ganda).
4. **Given** ada transaksi baru atau perubahan stok antara dry-run dan persetujuan, **When** pemilik menyetujui, **Then** sistem tidak menerapkan angka yang sudah kedaluwarsa: ia menghitung ulang dan meminta peninjauan ulang bila hasilnya berbeda.

---

### User Story 3 - Berkas Excel untuk ditinjau dan diarsipkan (Priority: P2)

Berkas Excel berfungsi sebagai laporan tinjauan dan jejak: per varian memuat identitas (penjual, produk, varian, kode/SKU), stok sekarang, rincian pengurang (penjualan dan, sesuai keputusan, pre-order), stok seharusnya, selisih, dan status (akan diubah / tidak ada perubahan / perlu perhatian). Ada lembar ringkasan jumlah varian per status. Berkas dibuat juga setelah penerapan sebagai catatan hasil.

**Why this priority**: Mendukung peninjauan, tetapi koreksi inti tetap bisa berjalan tanpa tata letak yang sempurna.

**Independent Test**: Buka Excel hasil dry-run; setiap angka di dalamnya bisa dicocokkan dengan transaksi sumbernya dan dengan stok yang tampil di layar Stok.

**Acceptance Scenarios**:

1. **Given** hasil dry-run, **When** pemilik membuka Excel, **Then** terdapat lembar per varian dan lembar ringkasan, tanpa data kontak pelanggan.
2. **Given** varian yang hasilnya akan negatif, **When** dry-run, **Then** baris itu menampilkan stok seharusnya 0, ditandai "perlu perhatian", dan kekurangannya (unit) tertulis jelas, bukan disembunyikan.

---

### Edge Cases

- Hasil koreksi negatif (terjual lebih banyak daripada stok riil yang diketik): stok dibatasi ke 0, ditandai "perlu perhatian", dan kekurangannya (unit) dilaporkan di Excel dan di alasan riwayat; kekurangan itu tidak hilang dari catatan.
- Pre-order yang belum diserahkan: tidak mengurangi stok (sama seperti sekarang); diserahkan sesudah pembaruan stok: sudah mengurangi stok seperti biasa.
- Transaksi yang sudah dibatalkan (void) atau pre-order yang dibatalkan: tidak boleh mengurangi stok.
- Transaksi yang dibuat SESUDAH stok riil diketik: sudah mengurangi stok secara normal, tidak dihitung lagi.
- Varian yang stoknya diperbarui lebih dari sekali: sistem menggunakan pembaruan terakhir sebagai titik acuan dan menunjukkannya di Excel.
- Varian yang sudah dihapus (soft delete) namun punya riwayat: dilaporkan sebagai tidak diubah, tanpa galat.
- Data DEMO: tidak pernah ikut dihitung atau diubah; hanya data LIVE.
- Dijalankan dua kali berturut-turut setelah penerapan: tidak ada perubahan kedua (tidak ada pengurangan ganda).
- Transaksi terjadi di antara dry-run dan persetujuan: hasil dihitung ulang sebelum diterapkan.
- Sebagian varian gagal diterapkan: seluruh penerapan dibatalkan (semua atau tidak sama sekali), tidak ada stok setengah berubah.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Sistem MUST menyediakan mode uji coba (dry-run) yang menghitung stok seharusnya tiap varian LIVE tanpa mengubah stok, riwayat stok, atau log aktivitas.
- **FR-002**: Untuk tiap varian, stok seharusnya MUST dihitung dari stok riil yang terakhir diketik pemilik dikurangi kuantitas transaksi penjualan yang dibuat SEBELUM pembaruan itu dan belum tercermin di angka tersebut. Transaksi yang dibuat sesudahnya MUST tidak dihitung lagi.
- **FR-003**: Transaksi yang dibatalkan (void/cancel) MUST tidak mengurangi stok; pengembalian MUST ikut diperhitungkan.
- **FR-004**: Hanya pre-order yang sudah DISERAHKAN (handed over) yang mengurangi stok riil, sama seperti aturan stok yang berlaku; pre-order yang belum diserahkan MUST tidak mengurangi stok (keputusan pemilik: Q1 = A). Pre-order yang dibatalkan juga tidak dihitung.
- **FR-005**: Hasil dry-run MUST disajikan sebagai berkas Excel yang memuat, per varian: penjual, produk, varian, kode, stok sekarang, rincian pengurang, stok seharusnya, selisih, dan status; serta lembar ringkasan jumlah per status. Berkas MUST tidak memuat data kontak pelanggan.
- **FR-006**: Sistem MUST meminta persetujuan eksplisit pemilik sesudah dry-run dan sebelum mengubah data; tanpa persetujuan, tidak ada data yang berubah.
- **FR-007**: Saat disetujui, sistem MUST menerapkan koreksi sebagai pencatatan riwayat stok yang terlacak (beralasan, siapa yang menjalankan, kapan) dan MUST tidak menimpa stok langsung tanpa catatan riwayat.
- **FR-008**: Penerapan MUST semua-atau-tidak-sama-sekali: bila ada kegagalan, tidak ada perubahan yang tertinggal.
- **FR-009**: Sistem MUST mendeteksi bila data berubah antara dry-run dan persetujuan, dan MUST menghitung ulang serta meminta peninjauan ulang alih-alih menerapkan angka kedaluwarsa.
- **FR-010**: Menjalankan koreksi lagi sesudah diterapkan MUST tidak menghasilkan perubahan tambahan (idempoten).
- **FR-011**: Bila hasil perhitungan akan negatif, stok MUST dibatasi ke 0 (keputusan pemilik: Q2 = B), tidak pernah disimpan negatif. Varian itu MUST ditandai "perlu perhatian" di Excel dengan kekurangannya (selisih antara hasil hitung dan 0) ditampilkan terpisah, supaya pemilik tahu berapa unit yang terjual melebihi stok riil yang diketik; kekurangan itu juga tercatat di alasan riwayat stok.
- **FR-012**: Hanya data mode LIVE yang boleh dihitung dan diubah; data DEMO MUST tidak tersentuh.
- **FR-013**: Hanya pengguna dengan wewenang mengelola data master (owner/admin/inventory) yang boleh menjalankan dry-run maupun penerapan.
- **FR-014**: Berkas hasil MUST juga dibuat setelah penerapan sebagai catatan akhir: apa yang diubah dari berapa menjadi berapa.

### Key Entities

- **Varian produk**: memiliki stok saat ini dan riwayat pergerakan stok (append-only).
- **Pergerakan stok**: catatan perubahan stok (penjualan, penyesuaian manual, serah-terima pre-order, pengembalian, stok awal) dengan waktu dan stok sebelum/sesudah.
- **Transaksi penjualan POS dan pre-order**: sumber kuantitas yang mengurangi stok; punya status (selesai, batal/void, diserahkan, dan seterusnya).
- **Koreksi stok**: hasil perhitungan per varian (stok sekarang, pengurang, stok seharusnya, status) yang ditinjau lalu diterapkan.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Setelah koreksi diterapkan, 100% varian yang ditandai "akan diubah" memiliki stok sama dengan "stok seharusnya" di Excel yang ditinjau.
- **SC-002**: Dry-run tidak mengubah satu pun baris stok, riwayat stok, atau log aktivitas (selisih = 0).
- **SC-003**: Setiap angka pengurang di Excel dapat dicocokkan 100% dengan transaksi sumbernya oleh pemilik.
- **SC-004**: Menjalankan koreksi untuk kedua kalinya menghasilkan nol perubahan.
- **SC-005**: Pemilik dapat menyelesaikan siklus dry-run → tinjau Excel → setujui untuk seluruh katalog toko (hingga 2.000 varian) dalam satu sesi tanpa langkah manual per varian.
- **SC-006**: Tidak ada stok negatif yang tersimpan; 100% varian yang dibatasi ke 0 menampilkan kekurangannya di Excel dan riwayat stok.

## Assumptions

- "Stok riil" = stok yang diketik pemilik lewat pembaruan stok manual (Excel stok atau penyesuaian stok); titik acuan per varian adalah pembaruan terakhir itu.
- Hanya transaksi yang dibuat sebelum pembaruan terakhir itu yang dikoreksi; transaksi sesudahnya sudah mengurangi stok seperti biasa.
- Alat ini untuk koreksi sekali pakai oleh pemilik/pengembang (bukan layar harian); bentuk antarmukanya (perintah di terminal dengan konfirmasi, atau layar) diputuskan saat perencanaan, asalkan dry-run → persetujuan → penerapan terpenuhi.
- Penerapan memakai jalur penulisan stok yang sudah ada (riwayat stok bersifat append-only; tidak ada penulisan stok langsung).
- Data DEMO tidak ikut serta; Excel hasil tidak memuat data pribadi pelanggan.
- Keputusan pemilik: pre-order yang belum diserahkan tidak mengurangi stok (Q1 = A); hasil negatif dibatasi ke 0 dengan kekurangan dilaporkan (Q2 = B). Sistem tidak pernah menyimpan stok negatif; kekurangannya dicatat, bukan dibuang.
