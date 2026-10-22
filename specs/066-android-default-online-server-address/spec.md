# Feature Specification: Alamat server online bawaan untuk aplikasi Android

**Feature Branch**: `066-android-default-online-server-address`

**Created**: 2026-10-22

**Status**: Draft

**Input**: User description: "add https://smasher-nullify-subgroup.ngrok-free.dev as the server address in android app as the default server address when the value online server address in this page is empty" (halaman: Pengaturan → Umum → Server address)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Tablet tetap tersambung lewat alamat online bawaan saat admin belum mengisi alamat online (Priority: P1)

Admin memilih "Online (live server)" untuk tablet kasir di halaman Server address, tetapi kolom "Online server address" masih kosong. Saat ini tablet baru yang menyiapkan terminal berhenti dengan pesan "alamat belum diatur". Dengan fitur ini, aplikasi membawa satu alamat online bawaan (`https://smasher-nullify-subgroup.ngrok-free.dev`) dan memakainya selama kolom online di server kosong.

**Why this priority**: Inilah inti permintaan; tanpa ini tablet tidak bisa disiapkan lewat jalur online sebelum admin mengisi kolomnya.

**Independent Test**: Kosongkan alamat online di web, pilih "Online", siapkan terminal pada tablet yang tidak bisa menjangkau alamat lokal; tablet berhasil tersambung lewat alamat bawaan.

**Acceptance Scenarios**:

1. **Given** pilihan admin = Online dan kolom online kosong, **When** tablet baru menjalankan "Siapkan terminal" dan alamat bawaan menjawab, **Then** tablet menyimpan alamat bawaan itu dan melanjutkan penyiapan tanpa pesan "belum diatur".
2. **Given** pilihan admin = Online dan kolom online terisi alamat lain, **When** tablet menyiapkan terminal, **Then** alamat isian admin yang dipakai, bukan alamat bawaan.
3. **Given** pilihan admin = Local, **When** tablet menyiapkan terminal, **Then** perilaku tidak berubah (alamat lokal admin dipakai; alamat online bawaan tidak ikut campur).

---

### User Story 2 - Admin tahu alamat bawaan yang berlaku (Priority: P2)

Di halaman Server address, di bawah kolom "Online server address", admin melihat petunjuk bahwa bila dikosongkan, tablet memakai alamat bawaan aplikasi, dan alamat itu ditampilkan.

**Why this priority**: Mencegah kejutan: admin yang mengosongkan kolom tahu ke mana tablet akan tersambung.

**Independent Test**: Buka halaman; petunjuk dan alamat bawaan tampil di bawah kolom online dalam bahasa antarmuka aktif.

**Acceptance Scenarios**:

1. **Given** kolom online kosong, **When** admin membuka halaman, **Then** petunjuk menyebut alamat bawaan yang akan dipakai.
2. **Given** kolom online terisi, **When** admin membuka halaman, **Then** petunjuk tetap terlihat tetapi jelas bahwa isian admin menang.

---

### Edge Cases

- Alamat bawaan tidak menjawab (ngrok mati/berubah): tablet menampilkan "tidak terjangkau" yang sudah ada, tidak menyimpan alamat apa pun.
- Pilihan Online, kolom kosong, tetapi tablet bertanya lewat alamat lokal yang menjawab: alamat bawaan hanya disimpan bila terbukti terjangkau (aturan "jangan pindahkan tablet ke alamat mati" tetap berlaku).
- Build aplikasi yang sengaja diberi alamat online lain lewat konfigurasi build: nilai konfigurasi itu menggantikan bawaan ini.
- Tablet yang sudah menyimpan alamat: tidak berubah; alamat tersimpan tidak ditanyakan ulang (aturan 065 tetap).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Aplikasi Android MUST membawa `https://smasher-nullify-subgroup.ngrok-free.dev` sebagai alamat online bawaan bila build tidak menetapkan alamat online lain.
- **FR-002**: Saat penyiapan terminal, bila server menjawab pilihan Online tetapi alamat online di server kosong, aplikasi MUST memakai alamat online bawaan sebagai alamat server, asalkan alamat itu terjangkau.
- **FR-003**: Alamat online yang diisi admin MUST selalu menang atas alamat bawaan.
- **FR-004**: Pilihan Local MUST tidak berubah perilakunya; alamat bawaan tidak pernah menggantikan alamat lokal.
- **FR-005**: Bila alamat bawaan tidak terjangkau, aplikasi MUST menampilkan keadaan "tidak terjangkau" yang ada dan tidak menyimpan alamat apa pun.
- **FR-006**: Halaman Server address MUST menjelaskan bahwa kolom online yang kosong berarti tablet memakai alamat bawaan aplikasi, dan menampilkan alamat itu, dalam Indonesia dan Inggris.
- **FR-007**: Kolom "Online server address" MUST tetap boleh kosong dan tersimpan kosong; alamat bawaan tidak ditulis ke pengaturan server.

### Key Entities

- **Alamat online bawaan**: satu alamat yang ditanam di aplikasi tablet; hanya dipakai bila alamat online di server kosong.
- **Pengaturan alamat server**: tiga nilai milik admin (lokal, online, pilihan); tidak berubah bentuknya.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Dengan kolom online kosong dan pilihan Online, 100% penyiapan terminal pada tablet yang menjangkau alamat bawaan berhasil tanpa pesan "belum diatur".
- **SC-002**: Dengan kolom online terisi, 0 penyiapan memakai alamat bawaan.
- **SC-003**: Admin menemukan alamat bawaan yang berlaku di halaman Server address tanpa membuka dokumentasi.

## Assumptions

- **ASSUMPTION**: "default server address" berarti alamat online bawaan di aplikasi Android (bukan nilai default yang disimpan di server); permintaan menyebut "in android app".
- **ASSUMPTION**: Alamat ngrok adalah hostname publik, bukan rahasia; boleh tertanam di aplikasi dan dokumen ini. Karena ngrok gratis dapat berubah, mengganti alamat bawaan cukup lewat konfigurasi build tanpa ubah kode.
- Aturan 065 tetap: alamat bawaan hanya dipakai untuk bertanya/jatuhan saat penyiapan, tablet menyimpan alamat sampai data dihapus.
- Server dan APK tidak perlu dirilis bersamaan: kontrak `GET /server-address` tidak berubah.
- Label dokumen: INTERNAL.
