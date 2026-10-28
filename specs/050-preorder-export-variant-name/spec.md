# Feature Specification: Nama Varian di Ekspor Pre-order

**Feature Branch**: `081-preorder-export-variant-name`

**Created**: 2026-10-29

**Status**: Draft

**Input**: User description: "add product variant name along with the sku in export preorder"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Membaca ekspor pre-order tanpa menghafal SKU (Priority: P1)

Pemilik/admin mengekspor daftar pre-order ke Excel. Kolom produk saat ini hanya berisi SKU (mis. `NEK-KY-MIK-1`), sehingga untuk tahu barang apa yang dipesan orang harus membuka aplikasi atau katalog. Dengan fitur ini, setiap SKU di ekspor disertai nama varian-nya, sehingga berkas Excel bisa langsung dibaca, difilter, dan dibagikan ke penjual atau tim produksi.

**Why this priority**: Ini satu-satunya inti permintaan; nilainya langsung terasa pada setiap ekspor.

**Independent Test**: Ekspor pre-order yang berisi dua varian berbeda; di berkas hasil, setiap SKU punya nama varian pada urutan yang sama, dan berkas tetap berisi semua kolom lama.

**Acceptance Scenarios**:

1. **Given** sebuah pre-order dengan dua item (dua SKU), **When** pengguna mengekspor, **Then** baris pre-order itu memuat nama varian untuk kedua SKU, berurutan sama dengan SKU, kuantitas, dan harga satuannya.
2. **Given** sebuah pre-order dengan satu item, **When** diekspor, **Then** nama varian muncul tanpa tanda pemisah tambahan.
3. **Given** ekspor dengan filter (status, event, tanggal, pencarian), **When** diekspor, **Then** nama varian ikut hadir pada setiap baris yang lolos filter, dan baris yang disaring tidak berubah.

---

### User Story 2 - Berkas ekspor tetap bisa diimpor ulang (Priority: P1)

Berkas ekspor pre-order dirancang agar bisa diunggah kembali ke impor (round-trip). Penambahan nama varian tidak boleh merusak itu: kolom SKU tetap persis seperti sekarang, dan impor tidak menganggap kolom baru sebagai kesalahan.

**Why this priority**: Merusak round-trip lebih mahal daripada manfaat fitur ini.

**Independent Test**: Ekspor pre-order, unggah berkas hasilnya ke impor (pratinjau/dry run); hasilnya sama seperti sebelum fitur ini: tidak ada galat baru dan pesanan terbaca dengan SKU, kuantitas, dan harga yang sama.

**Acceptance Scenarios**:

1. **Given** berkas hasil ekspor yang memuat kolom nama varian, **When** diunggah ke impor, **Then** tidak ada galat atau peringatan karena kolom tersebut, dan nilainya tidak pernah dipakai untuk menentukan barang yang diimpor.
2. **Given** berkas lama tanpa kolom nama varian, **When** diunggah ke impor, **Then** impor berjalan seperti biasa.
3. **Given** nama varian yang diedit manual di berkas, **When** diimpor, **Then** barang tetap ditentukan oleh SKU, bukan oleh nama.

---

### Edge Cases

- Varian sudah dihapus (soft delete) atau product-nya dihapus setelah pre-order dibuat: nama tetap dari catatan terakhir yang tersedia; bila benar-benar tidak ada, bagian itu kosong tetapi urutan tetap sejajar dengan SKU (tidak menggeser item lain).
- Nama varian mengandung koma atau tanda pemisah yang dipakai kolom: pemisahan SKU/kuantitas/harga tidak boleh rusak, dan pembacaan manual tetap tidak ambigu.
- Varian tanpa nama (kosong): posisinya tetap dipertahankan.
- Nama varian diketik pengguna dan bisa berawalan `=`, `+`, `-`, `@`: harus tampil sebagai teks, tidak dijalankan sebagai rumus saat berkas dibuka.
- Pre-order tanpa item: kolom kosong, tanpa galat.
- Pre-order dalam mode DEMO/LIVE: nama diambil dari data mode aktif saja, sama seperti kolom lain.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Ekspor pre-order MUST menyertakan nama varian untuk setiap SKU pada setiap pre-order, pada urutan yang sama dengan SKU, kuantitas, dan harga satuan.
- **FR-002**: Kolom SKU yang ada (`products`), kuantitas, dan harga satuan MUST tetap berisi persis nilai dan format yang sama seperti sebelum fitur ini.
- **FR-003**: Nama varian MUST ditempatkan di kolom terpisah, bukan digabung ke dalam sel SKU, supaya berkas hasil ekspor tetap bisa diimpor ulang tanpa perubahan.
- **FR-004**: Impor pre-order MUST mengabaikan kolom nama varian: tidak menimbulkan galat, dan tidak pernah memengaruhi barang yang diimpor (SKU tetap satu-satunya penentu).
- **FR-005**: Template impor MUST tidak berubah (kolom nama varian hanya ada di berkas ekspor, seperti kolom waktu dibuat/diperbarui).
- **FR-006**: Bila nama varian tidak tersedia, posisinya MUST tetap terisi (kosong) agar urutan dengan SKU tidak bergeser.
- **FR-007**: Teks nama varian MUST tersimpan sebagai teks di Excel, tidak pernah ditafsirkan sebagai rumus.
- **FR-008**: Filter, pengurutan, jumlah baris, dan izin akses ekspor MUST tidak berubah.
- **FR-009**: Judul kolom baru mengikuti konvensi judul kolom ekspor pre-order yang sudah ada.

### Key Entities

- **Pre-order & itemnya**: setiap item merujuk satu varian lewat SKU yang dicatat saat pesanan dibuat.
- **Varian produk**: memiliki SKU dan nama varian; sumber nama yang ditampilkan di ekspor.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Dalam 100% baris ekspor yang punya item, jumlah nama varian sama dengan jumlah SKU dan urutannya sejajar.
- **SC-002**: Berkas hasil ekspor diunggah ke impor (pratinjau) tanpa galat baru dibanding sebelum fitur ini, untuk semua pre-order uji.
- **SC-003**: Pembaca berkas dapat menyebut barang yang dipesan pada suatu baris tanpa membuka aplikasi.
- **SC-004**: Tidak ada sel nama varian yang berisi rumus, termasuk untuk nama berawalan karakter rumus.

## Assumptions

- "Nama varian" berarti nama varian saja (mis. "Mikasa"), bukan "nama produk — nama varian"; nama produk tidak diminta.
- Kolom baru diberi sebagai kolom terpisah yang sejajar dengan SKU (bukan digabung ke sel SKU), karena sel SKU dipakai balik oleh impor; ini selaras dengan preseden kolom hanya-ekspor yang sudah ada.
- Nama diambil dari varian saat ekspor dilakukan (nama terkini); pencatatan baru (snapshot nama varian) tidak ditambahkan.
- Hanya ekspor pre-order yang berubah; ekspor lain (penjualan, laporan, master data) di luar cakupan.
- Peringatan keamanan terkait sel berawalan rumus pada ekspor Excel lain ditangani terpisah; fitur ini hanya menjamin kolom barunya aman.
- Berkas template impor dan format impor tidak berubah.
