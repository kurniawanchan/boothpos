# Feature Specification: Ringkasan Pilihan di Dialog "Attach" Grup Modifier

**Feature Branch**: `091-modifier-attach-selection-summary`

**Created**: 2026-11-06

**Status**: Draft

**Input**: User description: "show category name and product name if selected more than 1" (tangkapan layar: Restaurant Setup → Modifiers → dialog Attach "Suhu Minuman"; kolom Products hanya menampilkan "6 selected"). Revisi 2026-11-06: "show the names below the option field, add default selected value for category & product is none".

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Lihat nama pilihan di bawah isian (Priority: P1)

Owner/admin membuka dialog Attach sebuah grup modifier untuk menautkannya ke kategori dan produk. Saat ini, begitu lebih dari satu kategori atau produk dipilih, isian hanya menampilkan "6 selected" sehingga pengguna tidak tahu produk mana yang tercentang tanpa membuka daftar dan menggulir. Kini nama kategori dan produk yang dipilih ditampilkan DI BAWAH masing-masing isian (isian itu sendiri tetap ringkas, mis. "6 selected").

**Why this priority**: Ini inti permintaan. Menautkan grup modifier ke produk yang salah mengubah pesanan kasir, jadi pengguna harus bisa memastikan pilihannya sekilas.

**Independent Test**: Buka Attach pada grup yang sudah tertaut ke beberapa produk; di bawah isian Products dan Categories langsung tampil nama-nama itu tanpa membuka daftar.

**Acceptance Scenarios**:

1. **Given** dialog Attach terbuka dan 6 produk terpilih, **When** dialog tampil, **Then** di bawah isian Products tampil nama keenam produk.
2. **Given** 2 kategori terpilih, **When** dialog tampil, **Then** di bawah isian Categories tampil kedua nama kategori.
3. **Given** tepat 1 pilihan, **When** dialog tampil, **Then** isian menampilkan nama itu seperti sekarang dan tidak ada daftar tambahan yang mengulang nama yang sama.
4. **Given** pengguna mencentang atau membatalkan centang sebuah item, **When** daftar berubah, **Then** daftar nama di bawah isian langsung mengikuti.

---

### User Story 2 - Nilai awal "Tidak ada" (Priority: P1)

Saat grup belum tertaut ke kategori atau produk apa pun (atau dialog dibuka untuk grup baru), isian Categories dan Products menampilkan "None/Tidak ada" sebagai nilai terpilih, bukan "All/Semua". Dalam dialog ini daftar kosong berarti grup tidak berlaku untuk apa pun, jadi "Semua" menyesatkan.

**Why this priority**: Label salah pada kondisi awal membuat pengguna mengira grup sudah berlaku untuk semua item.

**Independent Test**: Buka Attach pada grup tanpa tautan; kedua isian menampilkan "Tidak ada" dan tidak ada daftar nama di bawahnya.

**Acceptance Scenarios**:

1. **Given** grup tanpa tautan, **When** dialog Attach dibuka, **Then** Categories dan Products sama-sama menampilkan "Tidak ada" dan tak ada daftar nama.
2. **Given** pengguna mencentang lalu membatalkan semua item, **When** tidak ada yang tersisa, **Then** isian kembali menampilkan "Tidak ada".
3. **Given** grup tanpa tautan, **When** pengguna menyimpan tanpa memilih apa pun, **Then** hasil simpan tetap sama seperti sebelumnya (tanpa tautan).

---

### User Story 3 - Daftar nama panjang tetap rapi (Priority: P2)

Bila nama yang dipilih sangat banyak (puluhan produk), daftar di bawah isian tidak membuat dialog meluap; daftar dibatasi tingginya dan dapat digulir, atau dilipat dengan penunjuk jumlah.

**Why this priority**: Tanpa ini, P1 merusak tampilan pada pemilihan besar.

**Independent Test**: Pilih semua produk pada katalog besar; dialog tetap muat di layar dan tombol Simpan/Batal tetap terlihat.

**Acceptance Scenarios**:

1. **Given** puluhan produk terpilih, **When** dialog tampil, **Then** daftar nama tidak mendorong tombol Simpan/Batal keluar dari layar dan semua nama tetap dapat dibaca (digulir atau diperluas).
2. **Given** nama produk sangat panjang, **When** tampil, **Then** teks terbungkus/dipotong rapi tanpa meluber.

---

### Edge Cases

- Dua item bernama sama (mis. produk sama di outlet berbeda): keduanya tetap tampil; tidak digabung.
- Item terpilih yang tidak lagi ada di daftar (dihapus/nonaktif): tidak menampilkan teks kosong atau "undefined"; hitungan tetap benar.
- Layar sempit/tablet: tata letak dialog tidak bergeser.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Bila LEBIH DARI SATU kategori/produk dipilih, dialog Attach MUST menampilkan nama semuanya di bawah isian yang bersangkutan.
- **FR-002**: Dengan tepat satu pilihan, MUST tidak ada daftar tambahan (nama sudah terlihat di isian).
- **FR-003**: Tanpa pilihan, isian Categories dan Products MUST menampilkan "Tidak ada" (bukan "Semua"), dan tidak ada daftar nama.
- **FR-004**: Daftar nama MUST selalu sejalan dengan pilihan sebenarnya, dan MUST tidak menaikkan tinggi dialog tanpa batas (batas tinggi dengan gulir atau perluasan).
- **FR-005**: Perubahan MUST hanya tampilan: data yang disimpan, perilaku simpan, dan hasil untuk pilihan yang sama MUST identik dengan sebelumnya.
- **FR-006**: Teks baru MUST tersedia dalam Indonesia dan Inggris.

### Key Entities

- **Grup modifier**: kumpulan opsi yang dapat ditautkan ke kategori dan produk; yang diubah hanya cara tautan itu ditampilkan di dialog.
- **Kategori / Produk**: item yang dipilih; atribut yang ditampilkan hanya nama.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Pengguna dapat menyebutkan produk dan kategori yang tertaut pada sebuah grup tanpa membuka daftar pilihan, pada 100% kasus pilihan 2–10 item.
- **SC-002**: Pada pemilihan seluruh katalog, tombol Simpan/Batal tetap terlihat tanpa menggulir halaman.
- **SC-003**: Pada 100% pembukaan dialog untuk grup tanpa tautan, isian menampilkan "Tidak ada", tidak pernah "Semua".
- **SC-004**: Tautan yang tersimpan identik dengan sebelum fitur ini untuk pilihan yang sama.

## Assumptions

- Cakupan: dialog Attach di Restaurant Setup → Modifiers. Komponen pilih-banyak yang sama dipakai di 9 tempat, dan di sana kondisi kosong berarti "Semua" (filter), jadi nilai awal "Tidak ada" HARUS khusus dialog Attach dan tidak boleh mengubah layar lain.
- Nama dipisahkan koma atau berupa daftar ringkas; urutan mengikuti urutan daftar pilihan.
- "Tidak ada" memakai teks yang sudah ada di aplikasi (Indonesia "Tidak ada", Inggris "None").
- Tidak ada perubahan server, data, izin, atau API.
- Kolom "Attached" pada tabel grup tidak termasuk permintaan ini.
