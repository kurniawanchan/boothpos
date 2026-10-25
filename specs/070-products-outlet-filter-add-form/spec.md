# Feature Specification: Filter outlet di halaman Produk, formulir tambah item setara halaman item, dan angka kepala mengikuti filter

**Klasifikasi**: INTERNAL

**Feature Branch**: `070-products-outlet-filter-add-form`

**Created**: 2026-10-25

**Status**: Draft

**Input**: User description: "update in product page: add filter outlet; update Variants & prices in add menu item to be same function with edit menu item; add availability section to add menu item; update the statistic count based on the filter"

## Revisi 2026-10-25 (permintaan pemilik setelah peninjauan layar)

- **US3 direvisi**: tabel "Varian & harga" di formulir TAMBAH mengikuti tata letak formulir UBAH (kolom harga modal bagi yang boleh melihat biaya, baris kedua batas stok rendah/lacak stok/margin, aksi markup dan duplikat, kolom Markup %). Kolom harga per outlet DIHAPUS dari formulir; `variants[].outlet_prices` tetap diterima API tetapi tidak dikirim UI.
- **US4 dicabut**: bagian "Ketersediaan" dihapus dari formulir TAMBAH; outlet pembawa kembali lewat "Aktif di outlet" (≥ 1 outlet wajib).
- **Baru**: Status Draft/Aktif ada di formulir UBAH (saat rilis menu aktif hanya catatan). Menonaktifkan item yang sudah terbit menjadikannya dihentikan (aturan model Produk), bukan draft.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Menyaring daftar produk per outlet dari baris filter (Priority: P1)

Pemilik atau admin dengan beberapa outlet membuka halaman Produk dan ingin melihat hanya item yang dijual di satu atau beberapa outlet tertentu, tanpa berpindah outlet aktif di bilah atas. Di baris filter (di samping Kategori dan Status) ada pilihan **Outlet** dengan opsi "Semua outlet" dan satu opsi per outlet.

**Why this priority**: Dengan dua outlet atau lebih dalam satu organisasi, daftar yang menggabungkan item kedua outlet (item kembar seperti "Mie Level 1-2" di WJM dan WJD) menyulitkan pencarian dan pengecekan. Ini keluhan utama pada tangkapan layar.

**Independent Test**: Buka halaman Produk pada instalasi dengan dua outlet yang masing-masing menjual item berbeda; pilih satu outlet di filter; daftar, hitungan tab, dan penomoran halaman hanya memuat item yang dijual di outlet itu.

**Acceptance Scenarios**:

1. **Given** dua outlet dengan menu berbeda, **When** pengguna memilih satu outlet di filter Outlet, **Then** daftar hanya berisi item yang dijual di outlet itu dan jumlah item di baris filter ikut berubah.
2. **Given** filter Outlet berisi dua outlet, **When** pengguna melihat daftar, **Then** item muncul bila dijual di salah satu outlet terpilih, tanpa baris ganda.
3. **Given** filter Outlet aktif, **When** pengguna menekan "Reset", **Then** filter kembali ke keadaan awal bersama filter lain.
4. **Given** filter Outlet aktif, **When** pengguna menyegarkan halaman atau membagikan alamatnya, **Then** pilihan yang sama dipulihkan.
5. **Given** organisasi dengan satu outlet, **When** halaman Produk dibuka, **Then** filter Outlet tidak tampil.

---

### User Story 2 - Angka kepala mengikuti filter yang aktif (Priority: P1)

Tiga kartu angka di atas daftar ("Menu item aktif", "86 hari ini", "Kualitas data") sekarang selalu menghitung seluruh instalasi dan tidak berubah saat filter dipakai. Setelah fitur ini, angka-angka itu dihitung dari himpunan item yang sama dengan daftar di bawahnya: outlet, kategori, status, dan pencarian yang sedang aktif.

**Why this priority**: Angka yang tidak cocok dengan daftar di bawahnya menyesatkan (mis. kartu menunjukkan 209 saat daftar yang tersaring berisi 80).

**Independent Test**: Catat tiga angka tanpa filter; pilih satu outlet; ketiganya sama dengan hitungan manual untuk item outlet itu; lepas filter; angka kembali ke semula.

**Acceptance Scenarios**:

1. **Given** filter Outlet = WJM 2, **When** halaman dimuat, **Then** "Menu item aktif" menghitung hanya item aktif WJM 2 dengan keterangan "dari N item" untuk outlet itu.
2. **Given** ada item yang ditandai 86 di outlet lain, **When** filter Outlet = WJM 2, **Then** "86 hari ini" tidak menghitung item tersebut.
3. **Given** item tanpa harga hanya di outlet lain, **When** filter Outlet = WJM 2, **Then** "Kualitas data" tidak menyebutnya.
4. **Given** filter Kategori atau pencarian aktif, **When** daftar berubah, **Then** angka kepala ikut dihitung ulang dari himpunan yang sama.
5. **Given** angka kepala sedang menyaring daftar (klik "86 hari ini"), **When** filter lain berubah, **Then** hitungan tetap konsisten dengan daftar.

---

### User Story 3 - Tabel "Varian & harga" di formulir tambah item setara dengan halaman ubah item (Priority: P2)

Saat menambah menu item, pengguna mengelola varian dan harga dengan kemampuan yang sama seperti di bagian "Varian & harga" pada halaman item: nama varian, SKU, harga dasar, status aktif/nonaktif per varian, dan **harga per outlet** (sel kosong berarti mengikuti harga dasar) bila organisasi punya lebih dari satu outlet. Pengguna tidak perlu menyimpan item dulu lalu membuka halaman item hanya untuk menetapkan harga berbeda per outlet.

**Why this priority**: Saat ini penambahan hanya menerima satu harga dasar per varian, sehingga harga per outlet baru bisa diatur setelah item tersimpan. Itu langkah ganda dan memicu item tersimpan dengan harga yang salah di outlet lain.

**Independent Test**: Tambah item dengan dua varian dan harga berbeda untuk satu outlet; simpan; buka halaman item; varian, harga dasar, dan harga per outlet sama persis dengan yang diisi.

**Acceptance Scenarios**:

1. **Given** organisasi multi-outlet, **When** pengguna membuka formulir tambah, **Then** tabel varian menampilkan kolom harga per outlet seperti di halaman item.
2. **Given** harga per outlet diisi hanya untuk WJM 2, **When** item disimpan, **Then** WJM 2 memakai harga itu dan outlet lain mengikuti harga dasar.
3. **Given** rilis menu aktif, **When** item baru disimpan, **Then** harga per outlet mengikuti aturan yang sama dengan halaman item (item berstatus draft; harga berlaku saat rilis).
4. **Given** pengguna tanpa izin melihat biaya modal, **When** formulir tambah dibuka, **Then** kolom biaya tidak tampil, sama seperti di halaman item.
5. **Given** satu outlet saja, **When** formulir dibuka, **Then** tidak ada kolom harga per outlet.

---

### User Story 4 - Bagian "Ketersediaan" di formulir tambah item (Priority: P2)

Formulir tambah memuat bagian **Ketersediaan** yang menampilkan, per outlet, apakah item akan dijual di sana dan statusnya (Ada), dengan tata letak yang sama seperti bagian "Ketersediaan per outlet" di halaman item. Pengguna memilih outlet tempat item dijual dan status awal item (draft/aktif) di satu tempat.

**Why this priority**: Saat ini pemilihan outlet dan status terpisah dari bagian ketersediaan di halaman item, sehingga pengguna tidak melihat ringkasan "dijual di mana" sebelum menyimpan.

**Independent Test**: Tambah item dan hanya centang WJM 2; simpan; halaman item menampilkan ketersediaan hanya untuk WJM 2, dan item tidak muncul di menu POS outlet lain.

**Acceptance Scenarios**:

1. **Given** formulir tambah multi-outlet, **When** pengguna membuka bagian Ketersediaan, **Then** setiap outlet tampil sebagai baris dengan pilihan dijual atau tidak.
2. **Given** pengguna menghapus centang satu outlet, **When** item disimpan, **Then** item tidak dibawa outlet itu.
3. **Given** tidak ada outlet dicentang, **When** pengguna menyimpan, **Then** simpan ditolak dengan pesan jelas.
4. **Given** item baru, **When** bagian Ketersediaan tampil, **Then** penandaan "86" tidak ditawarkan (butuh item tersimpan) dan ada keterangan singkat mengapa.
5. **Given** organisasi satu outlet, **When** formulir dibuka, **Then** hanya pilihan status yang tampil.

---

### Edge Cases

- Pengguna memilih outlet di filter lalu mengganti outlet di bilah atas: filter mengikuti pilihan terakhir (lihat Asumsi).
- Filter Outlet menghasilkan nol item: daftar kosong dengan opsi reset, angka kepala menampilkan 0, bukan galat.
- Hitungan tab (Menu item, Item ritel, dst.) dihitung dengan filter outlet yang sama; tab bahan baku dan modifier tidak terpengaruh filter outlet item.
- Peran tanpa izin melihat semua outlet hanya melihat outlet yang boleh diaksesnya di filter.
- Formulir tambah ditutup tanpa menyimpan setelah mengisi harga per outlet: tidak ada data tersimpan sebagian.
- Penyimpanan gagal di tengah (mis. harga per outlet ditolak): item tidak tersimpan setengah jadi; pengguna melihat pesan galat dan data formulir tetap.
- Item hanya dijual di satu outlet, dengan harga per outlet untuk outlet lain: harga itu ditolak atau diabaikan dengan pesan, bukan disimpan diam-diam.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Halaman Produk MUST menyediakan filter Outlet pilihan-banyak di baris filter bila organisasi punya lebih dari satu outlet, dengan opsi "Semua outlet".
- **FR-002**: Daftar, jumlah item, hitungan tab, dan penomoran halaman MUST mengikuti filter Outlet; item dihitung satu kali walau dijual di beberapa outlet terpilih.
- **FR-003**: Filter Outlet MUST tersimpan di alamat halaman (dapat dibagikan, di-refresh, dan tombol kembali) dan terhapus oleh "Reset".
- **FR-004**: Tiga angka kepala ("Menu item aktif", "86 hari ini", "Kualitas data") MUST dihitung dari himpunan item yang sama dengan daftar, mengikuti filter Outlet, Kategori, Status, dan pencarian yang aktif.
- **FR-005**: Angka kepala MUST kembali ke total seluruh instalasi saat tidak ada filter, dan MUST dimuat ulang setiap filter berubah tanpa memuat ulang seluruh halaman.
- **FR-006**: Kartu angka MUST menampilkan keadaan memuat dan galat sendiri, dan tidak menampilkan angka lama yang sudah tidak cocok dengan filter.
- **FR-007**: Pengguna tidak boleh melihat data outlet yang tidak boleh diaksesnya melalui filter maupun angka kepala.
- **FR-008**: Tabel "Varian & harga" di formulir tambah MUST menyediakan kemampuan yang sama dengan bagian itu di halaman item: nama, SKU, harga dasar, status per varian, dan harga per outlet (multi-outlet).
- **FR-009**: Harga per outlet yang diisi saat menambah item MUST tersimpan bersama item dalam satu langkah simpan, sesuai aturan harga dan rilis menu yang berlaku di halaman item.
- **FR-010**: Aturan tampil biaya modal di formulir tambah MUST sama dengan halaman item (hanya untuk yang berhak).
- **FR-011**: Formulir tambah MUST memuat bagian "Ketersediaan" dengan satu baris per outlet (dijual atau tidak) dan status awal item, bernada sama dengan "Ketersediaan per outlet" di halaman item.
- **FR-012**: Simpan MUST ditolak bila item tidak dibawa satu outlet pun, dengan pesan yang jelas di bagian Ketersediaan.
- **FR-013**: Penandaan "86" MUST tidak ditawarkan saat menambah item; keterangan menyatakan penandaan tersedia setelah item tersimpan.
- **FR-014**: Hasil menambah item (varian, harga per outlet, outlet pembawa, status) MUST sama dengan yang dilihat pengguna di halaman item tepat setelah simpan.
- **FR-015**: Perubahan MUST tidak mengubah data item, harga, stok, atau penjualan yang sudah ada.

### Key Entities

- **Item menu**: produk dengan varian, dimiliki satu outlet asal dan dibawa nol atau lebih outlet penjual; hitungan angka kepala dihitung dari himpunan ini.
- **Varian**: bentuk jual item dengan nama, SKU, harga dasar, status, dan harga per outlet opsional.
- **Harga per outlet**: harga yang menggantikan harga dasar di satu outlet; tanpa nilai berarti mengikuti harga dasar.
- **Ketersediaan outlet**: pernyataan "item dijual di outlet ini" beserta status (Ada/86) per outlet.
- **Angka kepala**: tiga ringkasan di atas daftar, dihitung dari himpunan item yang sedang disaring.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pada instalasi dua outlet, pengguna dapat menampilkan hanya item satu outlet dalam dua klik dari halaman Produk, dan daftar berisi 100% item yang dijual di outlet itu dan 0% item lain.
- **SC-002**: Untuk setiap kombinasi filter yang diuji, ketiga angka kepala sama dengan hitungan manual dari daftar yang tersaring (0 selisih).
- **SC-003**: Pengguna dapat menambah item multi-outlet beserta harga per outlet dalam satu kali simpan, tanpa membuka halaman item sesudahnya, pada 100% percobaan uji.
- **SC-004**: Setelah menambah item, data di halaman item (varian, harga per outlet, outlet pembawa, status) cocok 100% dengan yang diisi di formulir.
- **SC-005**: Angka kepala diperbarui dalam waktu yang pengguna rasakan seketika (di bawah 2 detik) setelah filter berubah pada katalog sekitar 250 item.
- **SC-006**: Tidak ada perubahan pada data item, harga, stok, atau penjualan yang sudah ada setelah fitur dipasang.

## Assumptions

- **Filter vs outlet bilah atas**: filter Outlet baru di halaman adalah pengendali utama daftar. Saat halaman dibuka, filter terisi dari outlet yang dipilih di bilah atas (bila ada); mengganti outlet di bilah atas mengatur ulang filter ke pilihan itu. Pengguna dapat memilih outlet lain atau semuanya langsung dari filter.
- **"Fungsi yang sama"**: dimaknai kesetaraan kemampuan (varian, harga per outlet, status per varian) dengan halaman item, bukan menyalin tata letaknya persis. Tombol "Kelola varian" yang memakai laci terpisah tidak diperlukan di formulir tambah.
- **Ketersediaan saat tambah**: backend sudah punya pilihan outlet pembawa dan status; bagian Ketersediaan menyatukan keduanya. Kuota harian dan channel tetap "—" karena belum ada backend (sama dengan halaman item) dan tidak dibangun di sini.
- **Angka kepala dan rilis menu**: perbandingan "aktif/draf/dihentikan" memakai definisi yang berlaku sekarang; hanya himpunan item yang menyempit oleh filter.
- **Tab di luar item**: tab Bahan baku, Setengah jadi, Kemasan, dan Modifier & combo tidak memakai filter Outlet.
- **Ruang lingkup**: hanya halaman Produk dan formulir tambah item; halaman item (ubah) tidak berubah perilaku, hanya menjadi acuan. Aplikasi Android tidak tersentuh.
- **Peran**: izin yang berlaku sekarang untuk melihat produk, mengelola menu, dan melihat biaya tidak berubah.
