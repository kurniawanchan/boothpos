# Feature Specification: Hapus Grup Modifier Warisan Kategori dari Satu Item

**Klasifikasi**: INTERNAL

**Feature Branch**: `086-item-opt-out-category-modifier`

**Created**: 2026-10-30

**Status**: Draft

**Input**: User description: "in product page, modifier can be removed even set from category" (dua tangkapan layar: panel "Modifier groups" dan formulir Ubah item, keduanya menampilkan "From the category (not changed here)" untuk grup "Suhu" pada item ES JERUK).

## Latar

Grup modifier bisa ditautkan ke KATEGORI, lalu berlaku untuk semua item di kategori itu. Di halaman Produk, grup warisan kategori tampil sebagai daftar hanya-baca ("From the category (not changed here)"), tanpa kontrol apa pun. Akibatnya item yang tidak cocok dengan grup itu (mis. ES JERUK, minuman dingin, tetap ditanya "Suhu") tidak bisa melepasnya tanpa mengeluarkan item dari kategorinya atau mengubah kategori untuk SEMUA item.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Lepas grup warisan kategori dari satu item (Priority: P1)

Pengelola menu membuka item, melihat grup modifier yang datang dari kategori, dan melepas satu grup untuk item itu saja. Kasir tidak lagi ditanya grup itu saat memesan item tersebut.

**Why this priority**: Inilah inti permintaan. Tanpa ini item yang tidak cocok dengan grup kategori terus menampilkan pilihan yang salah di kasir.

**Independent Test**: Pilih item di kategori yang punya grup "Suhu"; lepas "Suhu" dari item itu; buka item di POS web dan tablet: pilihan "Suhu" tidak muncul untuk item itu, tetapi tetap muncul untuk item lain di kategori yang sama.

**Acceptance Scenarios**:

1. **Given** item berada di kategori yang bergrup "Suhu", **When** pengelola melepas "Suhu" dari item itu dan menyimpan, **Then** halaman Produk menampilkan "Suhu" sebagai dilepas untuk item ini, dan "Suhu" tidak lagi termasuk grup efektif item.
2. **Given** "Suhu" dilepas dari item A, **When** kasir menambahkan item A di POS web atau tablet, **Then** kasir tidak ditanya "Suhu"; **And** untuk item B di kategori yang sama kasir tetap ditanya "Suhu".
3. **Given** "Suhu" dilepas dari item A, **When** pengelola membuka kategori itu, **Then** grup kategori tidak berubah dan tetap berlaku untuk item lain.
4. **Given** pengguna tanpa izin mengubah tautan modifier, **When** membuka halaman Produk, **Then** kontrol melepas tidak tampil sama sekali (bukan tampil nonaktif).

---

### User Story 2 - Pulihkan grup yang pernah dilepas (Priority: P2)

Pengelola yang keliru melepas grup, atau berubah pikiran, dapat memulihkannya sehingga item kembali mengikuti kategori.

**Why this priority**: Melepas tanpa jalan kembali berisiko; kategori adalah sumber kebenaran dan item hanya mengecualikan.

**Independent Test**: Lepas lalu pulihkan "Suhu" dari item; pilihan "Suhu" kembali muncul di kasir.

**Acceptance Scenarios**:

1. **Given** "Suhu" dilepas dari item A, **When** pengelola memilih pulihkan dan menyimpan, **Then** "Suhu" kembali menjadi grup efektif item A dan tampil lagi di kasir.
2. **Given** ada grup yang dilepas, **When** halaman Produk dibuka, **Then** grup itu tampil terpisah dan jelas ("Dilepas untuk item ini") dengan aksi pulihkan, tidak hilang tanpa jejak.

---

### User Story 3 - Perilaku sama di kedua tempat dan tetap benar saat kategori berubah (Priority: P3)

Panel "Modifier groups" dan formulir Ubah item berperilaku sama. Bila kategori kelak mendapat grup baru, item tetap mengikutinya; hanya grup yang dilepas secara eksplisit yang dikecualikan.

**Why this priority**: Konsistensi dan mencegah kejutan, tetapi bukan inti.

**Independent Test**: Lepas "Suhu" dari item A di panel, buka formulir Ubah: keadaan sama. Tambah grup "Es" ke kategori: item A ikut "Es" tetapi tidak "Suhu".

**Acceptance Scenarios**:

1. **Given** grup dilepas lewat panel, **When** formulir Ubah item dibuka, **Then** keadaan lepas yang sama tampil di sana (dan sebaliknya).
2. **Given** item A melepas "Suhu", **When** kategori mendapat grup baru "Es", **Then** item A mendapat "Es" dan tetap tanpa "Suhu".
3. **Given** item A melepas "Suhu", **When** item itu dipindah ke kategori lain, **Then** kebiasaan untuk grup "Suhu" tetap sampai dipulihkan, dan kategori baru menambahkan grupnya sendiri.

---

### Edge Cases

- Item sudah punya tautan SENDIRI ke grup yang sama dengan kategori (aturan wajib/min/maks khusus item): tautan item itu tetap berlaku; melepasnya tidak membuat grup kategori muncul lagi bila grup itu juga dilepas.
- Rilis menu aktif (perubahan menunggu rilis): pelepasan diperlakukan seperti perubahan tautan modifier lain, diantrekan dan berlaku saat rilis terbit.
- Grup yang dilepas lalu DIHAPUS atau dinonaktifkan di Setup restoran: catatan lepas tidak menimbulkan galat dan tidak menahan penghapusan grup.
- Kategori kehilangan grup yang pernah dilepas item: catatan lepas tidak menimbulkan galat; bila grup ditautkan lagi ke kategori, item tetap melepasnya.
- Tab/pesanan yang sudah berisi modifier dari grup itu sebelum dilepas: tidak berubah (riwayat tidak ditulis ulang).
- Item di kategori tanpa grup: tidak ada bagian warisan dan tidak ada kontrol lepas.
- Dua pengelola mengubah item yang sama bersamaan: perubahan terakhir menang tanpa merusak data; tidak ada grup ganda.
- Baris kosong "Select…" di tabel Modifier saat ini bisa dihapus dengan tombol silang; perilaku itu tidak berubah.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Pengelola dengan izin mengubah tautan modifier MUST bisa melepas, untuk SATU item, sebuah grup modifier yang diwarisi dari kategorinya.
- **FR-002**: Pelepasan MUST hanya berlaku pada item itu; kategori dan item lain MUST tidak berubah.
- **FR-003**: Grup yang dilepas MUST tidak termasuk grup efektif item: tidak ditanyakan di POS web dan tablet, tidak ikut aturan wajib/min/maks, tidak memengaruhi harga.
- **FR-004**: Grup yang dilepas MUST tetap terlihat di halaman Produk dalam bagian terpisah yang jelas, dengan aksi memulihkan.
- **FR-005**: Pengelola MUST bisa memulihkan grup yang dilepas sehingga item kembali mengikuti kategori.
- **FR-006**: Panel "Modifier groups" dan formulir Ubah item MUST menampilkan dan mengubah keadaan yang SAMA, dengan kata-kata yang sama.
- **FR-007**: Kontrol melepas dan memulihkan MUST disembunyikan sepenuhnya dari peran tanpa izin, dan server MUST menolak perubahan dari peran itu (403).
- **FR-008**: Grup baru yang kelak ditambahkan ke kategori MUST tetap berlaku untuk item yang melepas grup lain; hanya grup yang dilepas eksplisit yang dikecualikan.
- **FR-009**: Pelepasan MUST mengikuti aturan rilis menu yang sama dengan perubahan tautan modifier lain (diantrekan bila rilis aktif).
- **FR-010**: Pelepasan dan pemulihan MUST tercatat di log aktivitas dalam transaksi yang sama dengan perubahannya, tanpa isi sensitif.
- **FR-011**: Tab, pesanan, dan struk yang sudah ada MUST tidak berubah oleh pelepasan atau pemulihan.
- **FR-012**: Tautan modifier tingkat-item yang sudah ada (aturan wajib/min/maks per item) MUST tetap bekerja seperti sekarang.
- **FR-013**: Daftar modifier efektif MUST sama di semua tempat yang membacanya (daftar produk untuk POS, pencarian varian, halaman Produk), tanpa satu tempat pun masih menanyakan grup yang dilepas.

### Key Entities

- **Grup modifier**: kumpulan opsi dengan aturan pilih (wajib, min, maks); bisa ditautkan ke kategori dan ke item.
- **Tautan kategori**: grup yang berlaku untuk semua item di kategori.
- **Tautan item**: grup yang ditautkan langsung ke satu item, dengan aturan khusus item.
- **Pengecualian item**: catatan bahwa item tertentu melepas grup tertentu dari kategorinya; tidak mengubah kategori.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pengelola dapat melepas satu grup warisan dari satu item dalam kurang dari 30 detik tanpa mengubah kategori.
- **SC-002**: Pada 100% item uji yang melepas grup, kasir di POS web dan tablet tidak pernah ditanya grup itu, dan pada 100% item lain di kategori yang sama grup itu tetap ditanyakan.
- **SC-003**: Setelah pemulihan, grup tampil kembali di kasir pada 100% uji tanpa langkah tambahan.
- **SC-004**: 0 pesanan lama berubah akibat pelepasan atau pemulihan.
- **SC-005**: Peran tanpa izin melihat 0 kontrol melepas, dan 100% percobaan langsung ke server ditolak.
- **SC-006**: Panel dan formulir menampilkan keadaan yang sama pada 100% uji.

## Assumptions

- ASSUMPTION (penafsiran): permintaan "modifier can be removed even set from category" dibaca sebagai PERMINTAAN FITUR: item harus bisa melepas grup yang datang dari kategori. Alasan: kode sekarang selalu menggabungkan grup kategori ke setiap item tanpa jalan keluar, dan kedua tangkapan layar menyorot bagian "From the category (not changed here)". Bila yang dimaksud ternyata galat (sesuatu yang bisa dihapus padahal seharusnya terkunci), spec ini perlu direvisi.
- Isi grup (opsi dan harga) tetap hanya diubah di layar Setup restoran; fitur ini hanya mengubah hubungan item dengan grup.
- Izin yang dipakai sama dengan izin yang sekarang mengatur tautan modifier item (tanpa izin baru).
- Pelepasan terikat pada pasangan item + grup, bukan pada kategori, sehingga bertahan bila item pindah kategori sampai dipulihkan.
- Tidak ada pelepasan massal (banyak item sekaligus) dalam fitur ini.
- Tidak ada perubahan pada alur impor/ekspor Excel master data kecuali ditemukan saat perencanaan bahwa kolom modifier perlu ikut.
- Teks antarmuka mengikuti dua bahasa yang sudah ada (Indonesia dan Inggris).
- Verifikasi peramban dan tablet dilakukan terpisah; spec ini tidak mengklaim sudah diverifikasi.
