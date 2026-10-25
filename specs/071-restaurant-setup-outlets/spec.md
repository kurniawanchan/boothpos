# Feature Specification: Outlet di Restaurant Setup — info dan filter pada tabel Meja dan Stasiun, kolom outlet di formulir ubah

**Klasifikasi**: INTERNAL

**Feature Branch**: `071-restaurant-setup-outlets`

**Created**: 2026-10-25

**Status**: Draft

> Koreksi 2026-10-25 (saat perencanaan): draf awal memuat Story 3 "stasiun baru memilih outlet", padahal formulir New station sudah punya kolom itu. Story 3 dan FR-010 lama dihapus.

**Input**: User description: "in restaurant setup: add filter and outlet info in table Tables and Stations; outlet field in edit form (Edit table, Edit station)"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Melihat outlet tiap meja dan stasiun, dan menyaring per outlet (Priority: P1)

Pemilik atau admin dengan beberapa outlet membuka Restaurant Setup. Pada tab **Tables** dan **Stations**, setiap baris menampilkan outlet pemiliknya (kolom **Outlet**), dan di atas tabel ada filter **Outlet** (opsi "Semua outlet" dan satu opsi per outlet). Pengguna dapat langsung melihat meja atau stasiun milik satu outlet saja, atau membandingkan beberapa outlet.

**Why this priority**: Dengan "All outlets (2)" tabel menggabungkan meja kedua outlet tanpa petunjuk mana milik siapa (mis. dua meja "D1"); tanpa info outlet daftar tidak bisa dipakai.

**Independent Test**: Pada organisasi dengan dua outlet yang masing-masing punya meja dan stasiun, buka kedua tab; tiap baris menunjukkan outletnya; pilih satu outlet di filter; hanya baris outlet itu yang tampil.

**Acceptance Scenarios**:

1. **Given** dua outlet dengan meja berbeda, **When** pengguna membuka tab Tables, **Then** setiap baris menampilkan nama atau kode outletnya.
2. **Given** filter Outlet di tab Tables, **When** pengguna memilih satu outlet, **Then** hanya meja outlet itu yang tampil dan jumlah baris menyesuaikan.
3. **Given** filter Outlet di tab Stations, **When** pengguna memilih dua outlet, **Then** stasiun kedua outlet tampil tanpa duplikat.
4. **Given** pengguna sudah memilih outlet di bilah atas, **When** halaman dibuka, **Then** filter Outlet terisi outlet itu dan dapat diubah dari halaman.
5. **Given** filter aktif dan hasil kosong, **When** daftar tampil, **Then** ada pesan kosong dengan cara mengatur ulang filter.
6. **Given** organisasi satu outlet, **When** halaman dibuka, **Then** kolom dan filter Outlet tidak tampil.

---

### User Story 2 - Mengubah outlet meja atau stasiun di formulir ubah (Priority: P1)

Di formulir **Edit table** dan **Edit station** ada kolom **Outlet** berisi outlet saat ini. Pengguna dapat memindahkan meja atau stasiun ke outlet lain yang boleh diaksesnya, mis. meja yang salah dibuat di outlet yang keliru, tanpa menghapus dan membuat ulang.

**Why this priority**: Saat ini outlet hanya bisa dipilih ketika membuat meja atau stasiun, sehingga kesalahan outlet tidak bisa dibetulkan.

**Independent Test**: Buka Edit table sebuah meja di outlet A, ubah Outlet ke B, simpan; meja muncul di outlet B dan tidak lagi di A.

**Acceptance Scenarios**:

1. **Given** formulir ubah dibuka pada organisasi multi-outlet, **When** pengguna melihat formulir, **Then** kolom Outlet terisi outlet saat ini.
2. **Given** pengguna mengubah Outlet ke outlet lain yang boleh diaksesnya, **When** menyimpan, **Then** item pindah dan daftar menampilkan outlet barunya.
3. **Given** meja punya tab/pesanan terbuka, **When** pengguna mencoba memindahkannya, **Then** simpan ditolak dengan pesan jelas dan meja tetap di outlet lama.
4. **Given** stasiun masih dipakai kategori atau item menu, atau punya tiket dapur yang belum selesai, **When** pengguna mencoba memindahkannya, **Then** simpan ditolak dengan pesan yang menyebut alasannya.
5. **Given** pengguna hanya berhak atas sebagian outlet, **When** membuka kolom Outlet, **Then** hanya outlet yang boleh diaksesnya yang tersedia.
6. **Given** outlet tujuan sudah punya meja/stasiun dengan nama yang sama (bila nama harus unik per outlet), **When** menyimpan, **Then** simpan ditolak dengan pesan jelas.
7. **Given** organisasi satu outlet, **When** formulir ubah dibuka, **Then** kolom Outlet tidak tampil.

---

### Edge Cases

- Meja/stasiun nonaktif tetap tampil dengan outletnya dan ikut tersaring.
- Memindahkan item tidak mengubah riwayat: pesanan dan tiket lama tetap tercatat di outlet tempat transaksi terjadi.
- Pemindahan dua pengguna bersamaan pada item yang sama: yang terakhir menang tanpa data rusak; aturan penolakan tetap diperiksa saat simpan.
- Filter Outlet menghasilkan nol baris: keadaan kosong, bukan galat.
- Pengguna tanpa izin mengelola Restaurant Setup tidak melihat perubahan apa pun (izin lama tidak berubah).
- Outlet tujuan yang tidak boleh diakses pengguna ditolak walau dikirim langsung.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Tabel Tables dan Stations MUST menampilkan outlet pemilik tiap baris bila organisasi punya lebih dari satu outlet.
- **FR-002**: Halaman MUST menyediakan filter Outlet pilihan-banyak ("Semua outlet" + satu opsi per outlet) pada tab Tables dan Stations; hasil, jumlah baris, dan keadaan kosong mengikuti filter.
- **FR-003**: Filter Outlet MUST terisi dari outlet yang dipilih di bilah atas saat halaman dibuka, dan pengguna MUST dapat memilih outlet lain dari filter tanpa mengubah bilah atas; opsi hanya outlet yang boleh diakses pengguna.
- **FR-004**: Kolom dan filter Outlet MUST disembunyikan pada organisasi satu outlet.
- **FR-005**: Formulir Edit table dan Edit station MUST memuat kolom Outlet berisi outlet saat ini dan memungkinkan memilih outlet lain yang boleh diakses pengguna.
- **FR-006**: Memindahkan meja MUST ditolak dengan pesan jelas bila meja punya pesanan terbuka.
- **FR-007**: Memindahkan stasiun MUST ditolak dengan pesan jelas bila stasiun masih dipakai kategori atau item menu, atau punya tiket dapur yang belum selesai.
- **FR-008**: Pemindahan MUST ditolak bila outlet tujuan tidak boleh diakses pengguna, atau (bila nama harus unik per outlet) nama sudah dipakai di outlet tujuan.
- **FR-009**: Pemindahan yang berhasil MUST tercatat di log aktivitas (siapa, dari outlet mana ke mana) dan tidak mengubah outlet riwayat pesanan/tiket yang sudah ada.
- **FR-010**: Perubahan MUST tidak mengubah izin, urutan tampil, status aktif, atau data meja/stasiun yang tidak dipindahkan.

### Key Entities

- **Meja (Table)**: meja makan milik satu outlet, dengan nama, area, kursi, urutan, status aktif.
- **Stasiun (Station)**: stasiun persiapan (Dapur, Bar) milik satu outlet; ditunjuk oleh kategori dan item menu serta menerima tiket dapur.
- **Outlet**: tempat usaha; meja dan stasiun dimiliki tepat satu outlet pada satu waktu.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pada organisasi dua outlet, pengguna menemukan outlet pemilik setiap meja dan stasiun tanpa membuka formulir (100% baris menampilkan outlet).
- **SC-002**: Pengguna menampilkan hanya meja satu outlet dalam dua klik; 0 baris outlet lain muncul.
- **SC-003**: Meja atau stasiun yang salah outlet dapat dipindahkan dalam satu kali simpan (di bawah 30 detik) tanpa membuat ulang.
- **SC-004**: 100% percobaan memindahkan meja berpesanan terbuka, atau stasiun yang masih dipakai, ditolak dengan pesan yang menyebut alasannya; 0 pesanan atau tiket yatim.
- **SC-005**: Setelah pemindahan, riwayat pesanan dan tiket lama tidak berubah outlet (0 selisih).

## Assumptions

- **Filter dan bilah atas**: perilakunya sama dengan filter Outlet halaman Produk (070): terisi dari bilah atas, tetapi tidak dibatasi olehnya, sehingga bisa memilih outlet lain.
- **Aturan pemindahan** (bukan permintaan eksplisit, usulan aman): meja berpesanan terbuka dan stasiun yang masih dipakai/punya tiket belum selesai tidak boleh dipindah; pemilik membereskan dulu (selesaikan pesanan, pindahkan kategori/item). Alternatif memindahkan beserta ketergantungannya tidak dibangun karena berisiko mengubah menu outlet lain.
- **Hak pemindahan**: pengguna yang sudah boleh mengubah meja/stasiun, ke outlet mana pun yang boleh diaksesnya; izin baru tidak dibuat.
- **Formulir baru**: New table dan New station sudah punya kolom Outlet (hanya saat membuat) dan tidak berubah; yang baru hanya formulir ubah.
- **Tab Modifiers**: tidak tersentuh (grup modifier bukan milik outlet).
- **Ruang lingkup**: hanya Restaurant Setup; layar lantai/kasir dan dapur tidak berubah perilaku, selain ikut data outlet yang baru.
