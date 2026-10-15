**Classification**: INTERNAL

# Feature Specification: Aplikasi POS Android untuk Tablet

**Feature Branch**: `047-android-pos-tablet`
**Created**: 2026-10-13
**Status**: Draft
**Input**: "explore the project and create POS android app for tablet by following design spec (`design/spec`) and design UI (`design/screens`). use existing API, and list the one that does not exist."

> Sumber desain dibaca dari `/Users/chan/www/warkopwjm/design/` (ejaan folder berbeda dari repo `warkop-wjm`; jalur itulah yang ada). Peta lengkap API ada vs belum ada: `api-gap.md` (dokumen pendamping spec ini).

## Konteks
Warkop WJM 3 (pilot, go-live 31 Okt 2026) membutuhkan aplikasi kasir native untuk tablet: kasir, pelayan, dan supervisor membuat pesanan makan di tempat / bawa pulang / konter, mengirim tiket ke dapur, menerima pembayaran tunai / EDC / QRIS, dan menjalankan shift. Desain (Material 3, Bahasa Indonesia, fidelitas tinggi) mencakup 36 layar; R1 "Must" = S01–S29, "Stretch" = S30–S35. Backend sudah punya API restoran (shift, meja, tab, tiket dapur, pembayaran) tetapi belum punya banyak hal yang diminta desain (PIN, persetujuan atasan, laporan X/Z, EDC/QRIS, pajak, dsb.) — daftarnya di `api-gap.md`. Folder `android/` yang ada hanyalah cangkang WebView (fitur 008), bukan UI POS native.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Masuk dan membuka shift (Priority: P1)
Staf memilih namanya dari daftar dan memasukkan PIN; kasir tanpa shift diarahkan membuka shift dengan menghitung kas awal per pecahan; pelayan langsung ke denah meja.

**Why**: tanpa masuk dan shift terbuka tidak ada transaksi yang sah.
**Independent Test**: dari layar kunci, masuk sebagai kasir, buka shift Rp 500.000, dan tiba di pesanan konter baru.

**Acceptance Scenarios**:
1. **Given** daftar staf outlet, **When** staf memilih nama dan memasukkan PIN benar, **Then** masuk dan diarahkan menurut peran (kasir tanpa shift → buka shift; kasir ber-shift → pesanan konter; pelayan → meja; supervisor → daftar order).
2. **Given** PIN salah 5 kali, **When** mencoba lagi, **Then** terkunci 15 menit dengan pesan jelas.
3. **Given** total kas awal Rp 0, **When** menekan buka shift, **Then** muncul konfirmasi sebelum dilanjutkan.

### User Story 2 — Membuat pesanan dan mengirim ke dapur (Priority: P1)
Dari denah meja (pilih meja bebas + jumlah tamu) atau tombol pesanan konter, staf menambah menu dengan satu ketukan, mengisi modifier wajib/opsional dan catatan ≤100 karakter, lalu menekan "Kirim N" sehingga tiket per stasiun tercetak.

**Independent Test**: buat pesanan meja 3 dengan 2 menu (satu bermodifier wajib), kirim, dan lihat baris berstatus terkirim.

**Acceptance Scenarios**:
1. **Given** menu bermodifier wajib, **When** staf mengetuk menu, **Then** lembar modifier terbuka dan "Tambah" nonaktif sampai aturan min/maks terpenuhi; mengetuknya saat belum lengkap menandai grup pertama yang kurang.
2. **Given** baris belum terkirim, **When** dikurangi atau dihapus, **Then** berubah tanpa alasan; **Given** baris sudah terkirim, **When** dikurangi, **Then** diminta alasan + status dapur dan tercetak slip BATAL.
3. **Given** ada baris belum terkirim, **When** menekan "Kirim", **Then** hanya baris baru dicetak, satu tiket per stasiun; **Given** tak ada yang belum terkirim, **Then** tombol nonaktif.
4. **Given** meja sudah terisi di perangkat lain, **When** dibuka, **Then** ditolak dengan penjelasan.
5. Printer gagal → coba ulang 3×, lalu tetap antre dengan penanda di bilah atas.

### User Story 3 — Menerima pembayaran dan menutup pesanan (Priority: P1)
Kasir (dengan shift terbuka) menekan "Bayar": tunai (nominal pas, pembulatan, keypad, kembalian), EDC (kode persetujuan 6–12 huruf/angka), atau QRIS statis (catat tertunda lalu verifikasi dengan referensi). Pesanan menutup otomatis saat sisa 0; struk tampil dan tercetak.

**Independent Test**: bayar tagihan Rp 90.000 dengan tunai Rp 100.000 dan lihat kembalian Rp 10.000, status Lunas, nomor struk.

**Acceptance Scenarios**:
1. Pelayan tidak punya hak bayar: tombol "Bayar" diganti "Cetak pre-bill".
2. Beberapa tender dalam satu tagihan; non-tunai dibatasi sisa; kembalian hanya untuk tunai.
3. EDC dengan nominal dan metode sama dalam 2 menit → konfirmasi "Catat lagi?".
4. QRIS tertunda memblokir tender non-tunai lain dan penutupan shift; verifikasi kasir butuh persetujuan atasan.
5. Tanpa shift terbuka, "Bayar" menawarkan buka shift.
6. Salinan struk ditandai "SALINAN n"; cetak ulang pertama gratis, berikutnya perlu persetujuan.

### User Story 4 — Persetujuan atasan (Priority: P1)
Aksi sensitif (void baris terkirim, batal order, 86 oleh kasir, verifikasi QRIS, cetak ulang berikutnya, kas keluar > Rp 200.000, selisih shift > Rp 5.000, diskon di atas batas) membuka dialog persetujuan yang terikat pada SATU aksi; penyetuju (supervisor/manajer) memasukkan PIN-nya, tidak boleh peminta sendiri.

**Independent Test**: kasir membatalkan baris terkirim; supervisor menyetujui dengan PIN; aksi terjadi dan tercatat atas nama keduanya.

### User Story 5 — Menjalankan dan menutup shift (Priority: P2)
Pusat shift menampilkan ringkasan (penjualan per jam, bauran pembayaran, kas masuk/keluar), pratinjau laporan X, kas masuk/keluar dengan alasan, dan penutupan buta: syarat (online, sinkron kosong, tak ada QRIS tertunda) → hitung per pecahan + total batch EDC → tampil diharapkan/dihitung/selisih → laporan Z → kembali ke layar PIN.

### User Story 6 — Daftar order, struk, dan 86 (Priority: P2)
Daftar order terbuka/selesai dengan detail, cetak ulang, dan (Stretch) refund penuh; tekan-lama menu untuk menandai habis (86) dengan persetujuan untuk kasir.

### User Story 7 — Status perangkat dan koneksi (Priority: P3)
Layar perangkat menampilkan kesehatan (koneksi, versi, selisih jam), antrean cetak (coba ulang/batal), dan status offline; bilah atas menampilkan "Offline · n tersimpan" dan "Offline terbatas".

### Edge Cases
- Koneksi putus saat membayar (hasil tidak diketahui): periksa status lewat kunci idempotensi sebelum mengulang; jangan dobel catat.
- Kunci layar otomatis setelah 5 menit; proses aplikasi mati saat pesanan berjalan: pesanan tetap ada di server dan dibuka ulang.
- Dua perangkat mengedit meja yang sama: perangkat kedua ditolak/disegarkan.
- Offline: QRIS, refund, tutup shift, ganti PIN, pindah/gabung diblokir dengan alasan terlihat; setelah 4 jam / Rp 3.000.000 kas belum sinkron → "Offline terbatas" (pesanan baru butuh persetujuan, kas baru ditolak).
- Tarif pajak belum diisi: "Bayar" nonaktif dengan alasan.
- Token kedaluwarsa di tengah alur; menu/harga berubah; riwayat panjang; peran tak berizin (UI menyembunyikan, server tetap menolak).
- Layar kompak (ponsel) dan gelap harus tetap dapat dipakai walau pilot memakai tablet.

## Requirements *(mandatory)*

### Functional Requirements
- **FR-001**: Aplikasi MUST berjalan native di tablet (≥ 600dp, utama 10" 1180×740dp; juga 8" potret dan 13") dengan tata letak adaptif menurut kelas ukuran jendela, dan dapat dipakai di ponsel (kompak) sesuai desain.
- **FR-002**: Aplikasi MUST mengikuti sistem visual desain (Material 3, seed #EC3013, tema terang/gelap, token warna/tipografi/bentuk/jarak, Bahasa Indonesia) dan target sentuh ≥ 48dp (aksi utama 56dp); uang memakai keypad kustom, bukan IME sistem.
- **FR-003**: Aplikasi MUST menyediakan masuk dengan daftar staf + PIN 6 digit, kunci setelah 5 salah selama 15 menit, kunci otomatis idle 5 menit, dan pengalihan awal menurut peran.
- **FR-004**: Aplikasi MUST membuka shift dengan kas awal per pecahan (satu shift terbuka per terminal) dan mewajibkan shift terbuka untuk menerima pembayaran.
- **FR-005**: Aplikasi MUST menampilkan denah meja per area dengan status (Tersedia, Terisi, Bill tercetak, Perangkat lain), membuka pesanan meja dengan jumlah tamu 1–99, dan pesanan bawa pulang/konter dengan nomor antrean.
- **FR-006**: Aplikasi MUST menambah item dengan satu ketukan (≤ 200 ms respons lokal), menegakkan aturan modifier wajib min/maks, catatan ≤ 100 karakter, dan membedakan baris belum terkirim (bebas ubah) dari terkirim (void dengan alasan).
- **FR-007**: Aplikasi MUST mengirim tiket per stasiun hanya untuk baris baru ("Kirim N"), mencetak lewat printer jaringan (ESC/POS 58/80 mm), mengulang 3× lalu mengantre dengan penanda terus-menerus.
- **FR-008**: Aplikasi MUST memproses tender tunai, EDC (kode persetujuan, tanpa data kartu), dan QRIS statis (tertunda → verifikasi), mendukung banyak tender, kembalian hanya tunai, penutupan otomatis saat sisa 0, dan perlindungan ganda (debounce, satu kunci idempotensi per percobaan, konfirmasi bayar ganda 2 menit).
- **FR-009**: Aplikasi MUST menghitung/menampilkan pajak eksklusif sesuai tarif tenant dan menampilkan total yang dikembalikan server sebagai acuan uang.
- **FR-010**: Aplikasi MUST meminta persetujuan atasan terikat satu aksi untuk daftar aksi sensitif (User Story 4) dengan aturan penyetuju ≠ peminta dan PIN penyetuju.
- **FR-011**: Aplikasi MUST menyediakan struk di layar dan cetak (nomor struk per terminal, "SALINAN n", cetak ulang pertama gratis), pra-bill, dan slip BATAL.
- **FR-012**: Aplikasi MUST menyediakan pusat shift, kas masuk/keluar, laporan X, tutup shift buta dengan selisih/toleransi, dan laporan Z.
- **FR-013**: Aplikasi MUST menampilkan daftar order terbuka/selesai, detail, dan cetak ulang; menandai menu habis (86) dengan persetujuan bagi kasir.
- **FR-014**: Aplikasi MUST menampilkan status koneksi/perangkat/antrean cetak dan memblokir aksi yang butuh daring (QRIS, refund, tutup shift) dengan alasan terlihat.
- **FR-015**: Aplikasi MUST memakai API yang SUDAH ada (`api-gap.md` bagian A) dan TIDAK mengarang endpoint; kebutuhan yang belum ada (bagian B) MUST tampil sebagai daftar yang disepakati dengan tim backend, dan fitur yang bergantung padanya diberi tanda "menunggu API".
- **FR-016**: Aplikasi MUST menjaga server sebagai otoritas uang dan izin: klien hanya menampilkan; semua nominal dihitung ulang server; pembatasan peran di UI hanya menyembunyikan.
- **FR-017**: Aplikasi MUST tidak menyimpan/mencatat data kartu, tidak mencatat token/PIN di log, dan menyimpan kredensial di penyimpanan aman perangkat.
- **FR-018** (Stretch): bagi tagihan (per item / rata 2–5), diskon order/baris dengan batas per peran, refund penuh daring, pindah/gabung meja, tahan pesanan, setoran aman.

### Key Entities
- **Staf**: nama, peran (Kasir, Pelayan, Supervisor, Manajer Outlet), PIN (disimpan hash di server), status kunci.
- **Terminal/Perangkat**: id, awalan nomor struk, versi, status koneksi.
- **Shift**: kas awal per pecahan, kas masuk/keluar, tender per metode, hasil hitung & selisih.
- **Meja/Area**, **Pesanan (tab)**: tipe, meja/antrean, jumlah tamu, baris (menu, modifier, catatan, status terkirim/dapur), diskon, pajak, total, pembayaran.
- **Tiket dapur**: stasiun, putaran, baris, status.
- **Pembayaran/Tender**: metode, nominal, kode persetujuan/referensi, status (lunas/menunggu verifikasi/gagal).
- **Persetujuan**: aksi, muatan terikat, peminta, penyetuju, alasan, waktu.
- **Struk**: nomor, salinan ke-n, isi cetak.

## Success Criteria *(mandatory)*
- **SC-001**: Kasir menyelesaikan pesanan 3 item + bayar tunai < 90 detik dari layar kunci pada tablet pilot.
- **SC-002**: Menambah item tanpa modifier terasa instan: ≤ 200 ms antara ketukan dan baris tampil.
- **SC-003**: 0 pembayaran ganda pada 100 percobaan ulang/ketukan ganda terhadap tombol bayar dan kirim.
- **SC-004**: Seluruh 29 layar R1 (S01–S29) dapat dijalani end-to-end pada tablet 10", dan tata letak diperiksa terhadap tangkapan desain 8", 10", 13", ponsel, dan gelap.
- **SC-005**: Setiap kebutuhan desain yang tak punya API tercatat di `api-gap.md` dengan bentuk usulan dan prioritas; 0 fitur memakai endpoint karangan.
- **SC-006**: Tutup shift menghasilkan diharapkan/dihitung/selisih yang sama dengan angka server pada 100% uji tutup shift.
- **SC-007**: 90% staf baru menyelesaikan masuk + buka shift + satu penjualan tanpa bantuan pada uji coba pilot.

## Assumptions
- **ASSUMPTION — online-first untuk R1**: API sekarang tidak punya outbox/sinkron (B16). R1 aplikasi memakai API daring; mode offline penuh (DB lokal terenkripsi + outbox) menyusul setelah B16 ada. Bilah "Offline"/"Offline terbatas" tampil sebagai status koneksi dan memblokir aksi daring, tanpa antrean transaksi sampai B16.
- **ASSUMPTION — urutan pengiriman**: layar yang bergantung API belum ada (B1 PIN, B2 persetujuan, B3–B5 shift, B6–B7 EDC/QRIS, B8 pajak, B10–B13) dibangun di belakang antarmuka repositori dengan implementasi menunggu API; sampai itu ada, login memakai username+kata sandi yang sudah ada dan aksi sensitif memakai peran owner/admin yang sudah ada.
- Tata letak adaptif mengikuti README desain (kompak/sedang/luas/besar); varian yang tak tergambar memakai asumsi yang dicatat di rencana.
- Batas diskon per peran (Kasir 10%, Pelayan 0%, Supervisor 50%, Manajer 100%), ambang kas keluar Rp 200.000, toleransi selisih Rp 5.000, tarif pajak 10% adalah **placeholder desain** (menunggu OQ-023 dan tenant).
- Printer: LAN ESC/POS 80 mm (Kasir, Dapur); antarmuka lain (Bluetooth/USB) belum dipastikan (OQ-049).
- Perangkat pilot: ≤ 2 tablet, ≤ 3 printer, 1 mesin EDC BCA mandiri, tanpa laci kas.
- Daftar warna/tipografi pasti dari README desain; versi pustaka dipilih saat rencana (belum diverifikasi).
- Folder `android/` (cangkang WebView 008) tidak dipakai ulang sebagai UI; proyek native baru dibuat terpisah.
- Pembayaran EDC/QRIS tidak terintegrasi ke jaringan pembayaran; kasir hanya mencatat hasil dari mesin/aplikasi merchant.
