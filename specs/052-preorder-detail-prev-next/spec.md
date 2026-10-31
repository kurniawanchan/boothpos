**Klasifikasi**: INTERNAL

# Feature Specification: Navigasi Sebelumnya/Berikutnya di Detail Pre-order

**Feature Branch**: `083-preorder-detail-prev-next`

**Created**: 2026-10-31

**Status**: Draft

**Input**: User description: "add navigation to navigate previous and next in preorder detail, show the previous and next PO number" (disertai tangkapan layar panel detail pre-order `PO-20260927-0010` yang terbuka di atas daftar Pre-order)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Berpindah ke pre-order sebelumnya/berikutnya tanpa menutup detail (Priority: P1)

Pemilik/admin/kasir sedang memeriksa pre-order satu per satu (mis. menandai "Invoice sent", mengecek pembayaran, mencetak invoice). Saat ini, untuk pindah ke pre-order lain ia harus menutup panel detail, mencari baris berikutnya di daftar, lalu mengklik nomornya — berulang kali untuk puluhan pre-order. Dengan fitur ini, panel detail punya tombol **Sebelumnya** dan **Berikutnya** yang langsung membuka pre-order tetangga, dengan urutan yang sama seperti daftar yang sedang dilihat pengguna.

**Why this priority**: Ini inti permintaan; menghemat tiga langkah per pre-order pada pekerjaan yang dilakukan berurutan untuk banyak pesanan.

**Independent Test**: Buka daftar Pre-order, buka detail baris ke-2, klik **Berikutnya** → detail baris ke-3 terbuka; klik **Sebelumnya** dua kali → detail baris ke-1 terbuka. Panel tidak pernah tertutup.

**Acceptance Scenarios**:

1. **Given** daftar menampilkan pre-order A, B, C berurutan dan detail B terbuka, **When** pengguna mengklik **Berikutnya**, **Then** detail C terbuka di panel yang sama dengan seluruh isinya (status, pembayaran, pelanggan, pengiriman, item) milik C.
2. **Given** detail B terbuka, **When** pengguna mengklik **Sebelumnya**, **Then** detail A terbuka.
3. **Given** daftar sedang disaring (status, event, penjual, pencarian, dll.) dan/atau diurutkan menurut kolom tertentu, **When** pengguna berpindah dengan Sebelumnya/Berikutnya, **Then** urutannya mengikuti daftar yang tersaring dan terurut itu, dan pre-order yang tidak lolos saringan tidak pernah muncul.
4. **Given** detail pre-order terakhir di halaman daftar saat ini terbuka dan masih ada halaman berikutnya, **When** pengguna mengklik **Berikutnya**, **Then** pre-order pertama di halaman berikutnya terbuka (navigasi melintasi batas halaman); hal yang sama berlaku ke arah sebaliknya.

---

### User Story 2 - Melihat nomor pre-order tetangga sebelum berpindah (Priority: P1)

Agar pengguna tahu ke mana tombol akan membawanya, setiap tombol menampilkan nomor pre-order tujuannya (mis. "‹ PO-20260927-0011" dan "PO-20260927-0003 ›"), seperti yang diminta.

**Why this priority**: Bagian eksplisit dari permintaan; tanpa nomor, pengguna tidak tahu apakah ia sedang bergerak ke arah yang benar.

**Independent Test**: Buka detail pre-order di tengah daftar; tombol Sebelumnya menampilkan nomor baris di atasnya dan tombol Berikutnya menampilkan nomor baris di bawahnya, persis sama dengan nomor di daftar.

**Acceptance Scenarios**:

1. **Given** detail B terbuka di antara A dan C, **When** panel tampil, **Then** tombol Sebelumnya bertuliskan nomor A dan tombol Berikutnya bertuliskan nomor C.
2. **Given** pengguna berpindah ke C, **When** panel diperbarui, **Then** kedua label ikut diperbarui ke tetangga C.
3. **Given** detail pre-order pertama dalam daftar (tidak ada sebelumnya), **When** panel tampil, **Then** tombol Sebelumnya tetap terlihat tetapi nonaktif dengan keterangan bahwa ini pre-order pertama; demikian juga Berikutnya pada pre-order terakhir.
4. **Given** daftar hanya berisi satu pre-order, **When** detailnya dibuka, **Then** kedua tombol nonaktif.

---

### User Story 3 - Navigasi dengan keyboard (Priority: P3)

Pengguna yang memeriksa banyak pre-order dapat memakai tombol panah kiri/kanan untuk berpindah, tanpa mengarahkan mouse.

**Why this priority**: Kenyamanan tambahan; fitur sudah bernilai penuh tanpa ini.

**Independent Test**: Dengan detail terbuka dan fokus tidak berada di kolom isian, tekan panah kanan → pre-order berikutnya terbuka; ketik di kolom isian mana pun → panah hanya menggerakkan kursor teks.

**Acceptance Scenarios**:

1. **Given** detail terbuka dan fokus tidak di kolom isian/teks, **When** pengguna menekan panah kanan/kiri, **Then** efeknya sama dengan mengklik Berikutnya/Sebelumnya.
2. **Given** fokus ada di kolom isian, dropdown, atau dialog lain di atas panel detail, **When** pengguna menekan panah, **Then** navigasi pre-order tidak terjadi.

---

### Edge Cases

- **Detail dibuka bukan dari daftar** (mis. tautan dari riwayat transaksi pelanggan atau laporan, atau dari tautan "split"/"sumber" di dalam detail): tetangga ditentukan menurut urutan daftar Pre-order yang sedang berlaku (saringan dan urutan saat ini). Jika pre-order itu tidak termasuk dalam daftar yang tersaring, kedua tombol nonaktif dengan keterangan bahwa pre-order ini tidak ada di daftar saat ini.
- **Daftar berubah selagi detail terbuka** (pre-order dihapus, diduplikasi, di-split, statusnya berubah sehingga tidak lagi lolos saringan): tetangga dihitung ulang dari daftar terkini saat pengguna berpindah; pengguna tidak pernah dibawa ke pre-order yang sudah dihapus. Jika tujuan sudah tidak ada, sistem menampilkan pesan dan memperbarui tetangga.
- **Nilai urutan yang sama** (mis. diurutkan per status atau per pelanggan, banyak baris bernilai sama): urutan harus tetap dan dapat diulang, sehingga Berikutnya lalu Sebelumnya selalu kembali ke pre-order semula dan tidak ada pre-order yang terlewat atau muncul dua kali.
- **Klik cepat berulang**: jika pengguna mengklik Berikutnya beberapa kali sebelum detail selesai dimuat, panel akhirnya menampilkan pre-order yang sesuai dengan klik terakhir, bukan respons yang datang terlambat.
- **Gagal memuat detail tujuan** (jaringan/izin): panel tetap terbuka, pesan galat tampil, dan pengguna tetap dapat mencoba lagi atau kembali.
- **Form/dialog yang belum disimpan** di atas detail (form pengiriman, dialog pembayaran, edit): berpindah pre-order tidak boleh membuang isian tanpa peringatan; tombol navigasi nonaktif selama dialog di atas panel terbuka.
- **Baris terpilih di daftar**: setelah berpindah, daftar di belakang panel menyorot/menggulir ke baris pre-order yang sedang dibuka bila baris itu ada di halaman yang dimuat.
- **Mode DEMO/LIVE**: navigasi hanya pernah melewati pre-order dari mode aktif, sama seperti daftar.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Panel detail pre-order MUST menampilkan kontrol **Sebelumnya** dan **Berikutnya** di area judul panel, terlihat tanpa menggulir.
- **FR-002**: Setiap kontrol MUST menampilkan nomor pre-order tujuannya; label MUST diperbarui setiap kali pre-order yang dibuka berganti.
- **FR-003**: "Sebelumnya" dan "Berikutnya" MUST berarti baris tepat di atas dan tepat di bawah pre-order yang terbuka dalam daftar Pre-order sebagaimana daftar itu sedang tersaring dan terurut (saringan, pencarian, dan urutan kolom yang sama dengan yang tampil).
- **FR-004**: Navigasi MUST melintasi batas halaman daftar (baris terakhir suatu halaman → baris pertama halaman berikutnya, dan sebaliknya).
- **FR-005**: Urutan yang dipakai MUST deterministik, termasuk ketika banyak pre-order bernilai sama pada kolom urutan, sehingga navigasi maju lalu mundur selalu kembali ke pre-order asal.
- **FR-006**: Pada pre-order pertama/terakhir dari daftar (atau bila pre-order tidak termasuk daftar saat ini), kontrol yang tidak berlaku MUST tetap terlihat tetapi nonaktif, dengan keterangan alasannya.
- **FR-007**: Berpindah pre-order MUST memuat detail lengkap pre-order tujuan, setara dengan membukanya dari daftar (status, penanda invoice/pengiriman, pembayaran, pelanggan, pengiriman, item, aksi yang tersedia), dan MUST NOT menutup panel.
- **FR-008**: Bila beberapa perpindahan diminta berturut-turut sebelum pemuatan selesai, panel MUST menampilkan hasil permintaan terakhir saja.
- **FR-009**: Navigasi MUST NOT mengubah data apa pun (status, stok, pembayaran, penanda); ia hanya membuka pre-order lain.
- **FR-010**: Navigasi MUST menghormati batas yang sama dengan daftar: hanya pre-order dari mode DEMO/LIVE aktif, dan hanya pre-order yang boleh dilihat pengguna.
- **FR-011**: Kontrol navigasi MUST nonaktif selama form/dialog yang menimpa panel detail sedang terbuka, agar isian belum tersimpan tidak hilang.
- **FR-012**: Saat detail dibuka di atas daftar dan pre-order tujuan berada di halaman daftar yang sedang dimuat, daftar MUST menandai baris pre-order yang sedang dibuka.
- **FR-013**: Panah kiri/kanan pada keyboard SHOULD menjalankan Sebelumnya/Berikutnya saat panel detail terbuka dan fokus tidak berada di kolom isian, dropdown, atau dialog lain.
- **FR-014**: Kontrol MUST dapat diakses: dapat difokus dengan keyboard, memiliki label yang dibacakan pembaca layar (mis. "Pre-order sebelumnya: PO-…"), dan teksnya tersedia dalam bahasa Indonesia dan Inggris sesuai pilihan bahasa pengguna.

### Key Entities

- **Pre-order**: entitas yang sudah ada; yang dipakai di sini hanya identitas dan nomornya (`PO-YYYYMMDD-NNNN`). Tidak ada atribut baru yang disimpan.
- **Konteks daftar**: saringan, pencarian, dan urutan kolom yang sedang berlaku di layar Pre-order — penentu siapa tetangga suatu pre-order. Tidak disimpan di luar layar.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Berpindah dari satu detail pre-order ke pre-order berikutnya cukup dengan 1 tindakan (sebelumnya: 3 — tutup, cari, buka).
- **SC-002**: Memeriksa 20 pre-order berurutan dari panel detail dapat dilakukan tanpa menutup panel sekali pun.
- **SC-003**: Dalam pengujian dengan saringan dan urutan apa pun, urutan yang dilalui lewat Berikutnya dari baris pertama sampai terakhir sama 100% dengan urutan baris di daftar (tanpa terlewat, tanpa duplikat), termasuk melintasi halaman.
- **SC-004**: Nomor pre-order yang tertera pada tombol selalu sama dengan pre-order yang terbuka setelah tombol diklik.
- **SC-005**: Detail pre-order tujuan tampil dalam waktu yang sama dengan membuka detail dari daftar saat ini (tidak terasa lebih lambat).

## Assumptions

- "PO number" pada permintaan berarti **nomor pre-order** (`PO-YYYYMMDD-NNNN`, seperti di tangkapan layar), bukan nomor Purchase Order ke vendor.
- Tetangga mengikuti **urutan daftar yang sedang dilihat** (tersaring dan terurut), bukan urutan nomor pre-order mentah. Alasan: tangkapan layar menunjukkan daftar yang tidak berurutan per nomor (0022, 0021, 0003, 0018, …), dan pengguna memeriksa pesanan sesuai daftar di depannya. Bila yang diinginkan adalah urutan nomor, itu perubahan lingkup yang perlu diputuskan lewat `/speckit-clarify`.
- Fitur berlaku untuk semua peran yang sudah dapat membuka detail pre-order; tidak ada aturan izin baru.
- Kotak centang/pilihan massal di daftar tidak terpengaruh oleh navigasi.
- Tidak ada perubahan data tersimpan; perubahan apa pun pada cara daftar menentukan urutan (mis. pengurutan pengikat agar deterministik) tidak boleh mengubah urutan tampilan daftar yang sudah ada selain memecah nilai yang sama secara konsisten.
- Lingkup hanya panel detail pre-order. Detail Purchase Order, transaksi Sales, dan layar lain tidak termasuk.
