**Klasifikasi**: INTERNAL

# Feature Specification: Sel Satu Baris di Daftar Pre-order (Nomor PO, Tanggal Dispatch di Tooltip, Total)

**Feature Branch**: `084-preorder-list-oneline-cells`

**Created**: 2026-10-31

**Status**: Draft

**Input**: User description: "update to make PO Number in oneline, Invoice/shipping date in tootip next to the status, total in oneline"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Nomor PO dan Total tidak terpotong ke baris kedua (Priority: P1)

Pemilik/admin/kasir memindai daftar Pre-order untuk mencari pesanan tertentu atau membandingkan nilai pesanan. Saat ini, ketika tabel sempit (banyak kolom, layar laptop di booth), nomor pre-order (mis. `PO-20260927-0010`) dan nilai Total (mis. `Rp 1.250.000`) bisa patah menjadi dua baris. Nomor yang terpotong sulit dibaca dan dicocokkan dengan invoice, dan nominal yang terpotong (mis. `Rp` di satu baris, `1.250.000` di baris berikutnya) mudah salah baca. Setelah fitur ini, keduanya selalu tampil utuh dalam satu baris.

**Why this priority**: Nomor dan total adalah dua informasi yang paling sering dibaca di daftar; salah baca nominal berisiko pada penagihan.

**Independent Test**: Buka daftar Pre-order pada lebar layar sempit (mis. 1280 px dengan semua kolom tampil); setiap nomor pre-order dan setiap nilai Total tampil dalam satu baris tanpa patah.

**Acceptance Scenarios**:

1. **Given** daftar Pre-order ditampilkan pada lebar layar sempit, **When** pengguna melihat kolom Nomor, **Then** setiap nomor pre-order tampil utuh dalam satu baris, termasuk bila ikon bendera "info pengiriman belum lengkap" tampil di depannya (ikon dan nomor tetap sebaris).
2. **Given** sebuah pre-order bernilai besar (mis. `Rp 125.000.000`), **When** pengguna melihat kolom Total, **Then** simbol mata uang dan angkanya tampil dalam satu baris.
3. **Given** nomor pre-order dalam satu baris, **When** pengguna mengklik nomor itu, **Then** detail pre-order terbuka seperti sebelumnya (perilaku klik tidak berubah).

---

### User Story 2 - Tanggal invoice/pengiriman dipindah ke tooltip di samping status (Priority: P1)

Saat ini, di kolom status pengiriman (dispatch), di bawah label status ("Invoice terkirim" / "Sedang dikirim") tampil satu atau dua baris teks kecil berisi tanggal invoice dikirim dan tanggal pengiriman dimulai. Teks ini membuat baris tabel lebih tinggi dan daftar lebih panjang untuk digulir. Setelah fitur ini, tanggal-tanggal tersebut tidak lagi tampil sebagai teks di bawah status; sebagai gantinya ada ikon info kecil di samping label status yang, saat diarahkan kursor atau difokuskan dengan keyboard, menampilkan tooltip berisi tanggal tersebut.

**Why this priority**: Bagian dari permintaan yang sama; menjaga tinggi baris tetap satu baris sehingga lebih banyak pre-order terlihat sekaligus.

**Independent Test**: Tandai sebuah pre-order Mail Order sebagai "Sedang dikirim" (sehingga punya tanggal invoice dan tanggal pengiriman). Di daftar, kolom status hanya menampilkan label status + ikon info dalam satu baris; mengarahkan kursor atau menekan Tab ke ikon menampilkan kedua tanggal.

**Acceptance Scenarios**:

1. **Given** pre-order berstatus dispatch "Invoice terkirim" dengan tanggal invoice, **When** pengguna melihat daftar, **Then** sel status menampilkan label status dan ikon info sebaris, tanpa teks tanggal di bawahnya.
2. **Given** sel seperti pada skenario 1, **When** pengguna mengarahkan kursor ke ikon info atau memfokuskannya dengan keyboard, **Then** tooltip tampil berisi "Invoice terkirim: <tanggal & jam>" dengan format tanggal yang sama seperti sebelumnya.
3. **Given** pre-order berstatus "Sedang dikirim" yang punya tanggal invoice dan tanggal pengiriman, **When** tooltip dibuka, **Then** keduanya tampil, masing-masing pada barisnya sendiri, dengan urutan invoice lalu pengiriman.
4. **Given** pre-order berstatus "Menunggu" (tidak punya tanggal invoice maupun pengiriman), **When** pengguna melihat daftar, **Then** tidak ada ikon info di samping status.
5. **Given** tooltip sedang tampil, **When** pengguna menekan Escape atau memindahkan kursor/fokus keluar, **Then** tooltip tertutup.
6. **Given** daftar bisa digulir secara horizontal/vertikal, **When** tooltip dibuka pada baris di dekat tepi tabel, **Then** tooltip tampil utuh (tidak terpotong oleh area gulir tabel).

---

### Edge Cases

- **Hanya tanggal pengiriman tanpa tanggal invoice** (data lama/impor): tooltip hanya menampilkan baris yang tanggalnya ada; ikon tetap tampil selama minimal satu tanggal ada.
- **Status dispatch kosong** (data lama tanpa nilai, dianggap "Menunggu"): tidak ada ikon info.
- **Nomor pre-order hasil split/duplikat** yang lebih panjang dari biasanya: tetap satu baris; kolom melebar, isi tidak dipotong (tidak ada elipsis yang menyembunyikan sebagian nomor).
- **Total bernilai nol atau negatif**: tetap satu baris, format nominal tidak berubah.
- **Layar sangat sempit**: tabel boleh menggulir horizontal (perilaku tabel yang sudah ada) daripada mematahkan nomor/total.
- **Panel detail pre-order** yang sudah menampilkan tanggal invoice/pengiriman sebagai teks: TIDAK berubah (hanya daftar yang diubah).
- **Kolom Outstanding** dan kolom tanggal Dibuat/Diperbarui: tidak berubah oleh fitur ini (kolom tanggal sudah satu baris).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Daftar Pre-order MUST menampilkan setiap nomor pre-order dalam satu baris, tanpa patah baris dan tanpa memotong (elipsis) sebagian nomor.
- **FR-002**: Ikon bendera "info pengiriman belum lengkap" (bila tampil) MUST tetap sebaris dengan nomor pre-order.
- **FR-003**: Daftar Pre-order MUST menampilkan nilai Total dalam satu baris (simbol mata uang dan angka tidak terpisah), tanpa memotong nominal.
- **FR-004**: Sel status dispatch di daftar MUST NOT lagi menampilkan teks tanggal invoice dikirim / tanggal pengiriman dimulai di bawah label status.
- **FR-005**: Bila pre-order punya minimal satu dari tanggal invoice dikirim atau tanggal pengiriman dimulai, sel status dispatch MUST menampilkan ikon info sebaris di samping label status; bila tidak punya keduanya, ikon MUST NOT tampil.
- **FR-006**: Ikon info MUST menampilkan tooltip berisi tanggal yang ada, dengan label dan format tanggal-jam yang sama seperti teks sebelumnya ("Invoice terkirim: …", "Pengiriman dimulai: …"), satu tanggal per baris, urutan invoice lalu pengiriman.
- **FR-007**: Tooltip MUST dapat dibuka dengan arahkan kursor DAN dengan fokus keyboard, MUST dapat ditutup dengan Escape, dan ikon MUST punya label aksesibel (pembaca layar mengumumkan isi tanggal).
- **FR-008**: Tooltip MUST tampil utuh di atas tabel (tidak terpotong oleh area gulir daftar).
- **FR-009**: Klik nomor pre-order, klik ikon bendera, pengurutan kolom, filter, seleksi baris, dan aksi baris MUST tetap berfungsi seperti sebelumnya.
- **FR-010**: Panel detail pre-order, ekspor Excel, dan dokumen cetak MUST NOT berubah oleh fitur ini.
- **FR-011**: Teks baru yang dibutuhkan (mis. label aksesibel ikon) MUST tersedia dalam Bahasa Indonesia dan Inggris.

### Key Entities

- **Pre-order (baris daftar)**: nomor pre-order, status dispatch, tanggal invoice dikirim (opsional), tanggal pengiriman dimulai (opsional), total. Tidak ada data baru; hanya cara penyajiannya di daftar yang berubah.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pada lebar layar 1280 px dengan semua kolom default tampil, 100% nomor pre-order dan 100% nilai Total di halaman daftar tampil dalam satu baris.
- **SC-002**: Baris pre-order yang punya tanggal invoice/pengiriman memiliki tinggi yang sama dengan baris tanpa tanggal (tidak ada baris yang lebih tinggi karena teks tanggal).
- **SC-003**: Pengguna dapat melihat tanggal invoice/pengiriman sebuah pre-order dari daftar dalam satu gerakan (arahkan kursor atau satu Tab ke ikon), tanpa membuka detail.
- **SC-004**: Tidak ada perubahan perilaku pada klik nomor, pengurutan, filter, seleksi, dan aksi baris (seluruh pengujian daftar Pre-order yang ada tetap lulus).

## Assumptions

- "PO Number" berarti **nomor pre-order** (format `PO-YYYYMMDD-NNNN`) di daftar Pre-order, bukan nomor Purchase Order; tanggal invoice/pengiriman hanya ada di pre-order, jadi seluruh permintaan merujuk ke layar yang sama.
- "Status" yang dimaksud adalah kolom **status dispatch** (Menunggu / Invoice terkirim / Sedang dikirim), karena di situlah tanggal invoice/pengiriman saat ini ditampilkan; kolom status pre-order (Dipesan/Tiba/…) tidak berubah.
- Hanya kolom Total yang diminta dibuat satu baris; kolom Outstanding tidak diubah (bisa ditambahkan bila diminta).
- Perubahan hanya tampilan di sisi antarmuka; data, API, ekspor, dan dokumen tidak berubah, karena daftar sudah menerima tanggal invoice/pengiriman dari server.
- Komponen tooltip yang sudah ada di aplikasi (aksesibel keyboard, tidak terpotong area gulir) dipakai ulang, bukan atribut `title` bawaan peramban yang tidak dapat diakses keyboard.
