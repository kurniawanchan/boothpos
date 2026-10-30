# Research: 085 — Beranda login dua kolom & perbaikan hitungan pajak

**Klasifikasi**: INTERNAL · **Tanggal**: 2026-10-30

Semua keputusan di bawah berdasarkan pembacaan kode dan satu reproduksi server yang dijalankan di sesi ini. Yang belum dibuktikan ditandai ASSUMPTION.

## Bagian A — Pajak

### Temuan (fakta, diverifikasi sesi ini)

| # | Temuan | Bukti |
|---|--------|-------|
| F1 | Server menghitung pajak dengan tarif tersimpan, benar untuk 100%, 5%, dan 2,5%. | Tes sementara memakai alur nyata (`PUT /settings` lalu tambah baris tab): 3 lulus, 12 asersi, di DB `warkop_wjm_test`. Tes sudah dihapus. |
| F2 | Tak satu pun tes pajak yang ada memakai tarif selain 10%. | `TaxSettingTest` dan `TabTaxTest`: semua `saveTax('10', …)` / `setTax('10')`, kecuali satu uji validasi 100. Celah ini menjelaskan mengapa tarif macet tidak pernah tertangkap. |
| F3 | Di DB dev, `tax_rate` = `100`, `tax_show` = `0`; lima order terakhir tersimpan `tax_rate_snapshot` 100.00, `tax_amount` 17000.00 atas subtotal 17000 (total 34000). | Kueri baca-saja di DB `warkop_wjm`. Jadi data server sudah memakai 100%. |
| F4 | Kode Android (`PosModels.kt:115`) memakai `serverTax ?: 0`; cadangan 10% sudah dihapus di 083. Tidak ada literal 10%/11% yang terkait pajak di `android-pos/app/src/main`. | Pencarian pola di sesi ini. |
| F5 | Web: `posTotals.taxOnBase` memakai `settings.taxRate`; tidak ada literal tarif. | Baca kode. |
| F6 | `Setting` tidak memakai `HasDataMode`; DB dev hanya punya satu baris `tax_rate`. CLAUDE.md menulis "dibagi mode DEMO dan LIVE". | **Dikoreksi saat implementasi**: "dibagi" di sana berarti DIPAKAI BERSAMA, bukan dipisah. `specs/083-.../research.md` D8 menetapkan satu nilai untuk kedua mode. Tidak ada selisih dokumen/kode; yang salah adalah bacaan saya di tahap rencana. Hipotesis H-mode (tarif tersimpan di mode lain) gugur. |

### Interpretasi

Gejala pemilik ("atur 100%, hitungan selalu 10%") tidak muncul di server dan data dev. Karena `tax_show` = 0 di DB dev, baris pajak **tidak tampil** di keranjang, pembayaran, dan struk, padahal total sudah memuat 100%. ASSUMPTION: gejala bisa berasal dari salah satu hal berikut, dan belum ada yang terbukti:

- **H1 — APK lama**: tablet menjalankan build sebelum 083 yang masih membawa cadangan 10%.
- **H2 — Pratinjau web basi**: `settings.taxRate` di `PosView` tidak segar setelah tarif diubah (store diisi sekali oleh `load()`; `setTax` memperbaruinya hanya di tab yang menyimpan).
- **H3 — Cache**: `Setting::get` memakai `Cache::rememberForever`; invalidasi lewat model event hanya berlaku di penyimpanan cache yang sama. Dev memakai cache `file`. Penulisan dari proses lain ke penyimpanan lain dapat meninggalkan nilai lama.
- **H4 — Selisih persepsi**: baris pajak tersembunyi (`tax_show` = 0) sehingga total terlihat "tidak sesuai tarif".

### Keputusan

- **D1 — Reproduksi dulu, tanpa menebak perbaikan.** Tahap implementasi dimulai dengan reproduksi di tiga permukaan (Web, tablet, server) pada tarif 100%, memakai `evidence.md`. Perbaikan hanya untuk penyebab yang terbukti. *Alternatif ditolak*: mengubah kode pajak sekarang. Server terbukti benar, jadi perubahan itu hanya mengganti kode yang sudah benar.
- **D2 — Satu kumpulan kasus paritas bersama.** Satu berkas fixture (tarif, dasar pajak, pajak yang diharapkan) dibaca oleh tes PHPUnit (`TaxCalculator`), Vitest (`taxOnBase`/`cartTotals`), dan JUnit Android (pemetaan angka server). Kasus memuat tarif non-10 (100, 5, 2,5, 0,5), pembulatan setengah ke atas, dan dasar 0. *Alternatif ditolak*: tes terpisah per platform tanpa data bersama, karena itulah yang membuat celah F2 lolos.
- **D3 — Tes anti-literal.** Tes pemindai sumber (pola yang sama dengan tes `outletId` di `StockService`) yang gagal bila ada angka tarif tertulis di jalur pajak Android/web/server. Menjaga FR-017.
- **D4 — Pratinjau web diikat ke tarif segar.** Muat ulang `settings` saat layar POS dibuka / saat tab kembali fokus, supaya H2 tidak mungkin. Server tetap penentu total (Konstitusi IV).
- **D5 — Versi aplikasi terlihat.** Android menampilkan nomor build di layar penyiapan/Koneksi agar pemilik bisa memastikan APK sudah terbaru (H1). ASSUMPTION: ada tempat yang wajar; dikonfirmasi saat implementasi.
- **D6 — Cache pajak.** Bila H3 terbukti, kunci cache memuat semua masukan yang mengubah nilainya (Konstitusi V). Bila tidak terbukti, cache dibiarkan.
- **D7 — F6 terselesaikan.** Tidak ada selisih: pajak satu nilai untuk kedua mode. Yang diperbaiki hanya kata "dibagi" yang ambigu di CLAUDE.md (menjadi "dipakai bersama").

## Bagian B — Beranda login

### Temuan

- Layar ada di `android-pos/.../ui/screens/PinScreen.kt` (289 baris) dan aturan bentuk di `ui/PinLayoutRule.kt` (tiga bentuk: `THREE_COLUMN` ≥ 960 dp, `TWO_COLUMN` ≥ 720 dp, `STACKED`). Layar saat ini tiga kolom: toko | staf | PIN.
- "Ganti outlet / terminal" ada **dua kali**: `TextButton` di bawah keypad dan `IconButton` roda gigi di `BrandPanel`. Keduanya memanggil `store.goToSetup()`.
- Sudah ada: jam yang berdetak (`liveClockText`, 084), tanggal, `StoreMark` (logo + lencana mode), chip koneksi (`OutletStatusChips`), `UserAvatar`, `staffRows`, `selectedAfterRosterChange`, probe koneksi PIN. Dipakai ulang, tidak ditulis ulang.
- Keypad: lebar maks 340 dp, tinggi tombol 72 dp (56 dp pada layar kompak); titik PIN 16 dp.
- Tidak ada kolom foto outlet di server dan tidak ada aset latar di `res/`.

### Keputusan

- **D8 — Dua kolom menggantikan tiga kolom.** Kolom 1 (≈ 56%): atas = `OutletInfoPanel`, bawah = `StaffCardGrid`. Kolom 2 (≈ 44%): PIN. Layar sempit tetap bertumpuk dalam urutan sama. `THREE_COLUMN` dihapus dari `PinLayout`; `PinLayoutRuleTest` diperbarui. *Alternatif ditolak*: mempertahankan tiga kolom pada layar lebar, karena bertentangan dengan permintaan "dua kolom".
- **D9 — Foto latar = aset bawaan di aplikasi** (`res/drawable-nodpi`, JPEG ±1280 px, bukan PNG 2000 px asli). Lapisan gelap (scrim) bergradien di atas foto agar teks putih terbaca. Tidak ada unggah foto per outlet (di luar cakupan, Asumsi spec). Bila kelak ada foto outlet dari server, komponen menerima sumber gambar sebagai parameter; tidak dibuat sekarang (Konstitusi I: tanpa titik ekstensi spekulatif).
- **D10 — Kartu pengguna seragam**: `LazyVerticalGrid` dengan `GridCells.Adaptive` dan tinggi kartu tetap. Isi kartu: avatar (atas), nama dan jabatan (bawah), satu baris tiap teks dengan elipsis. Staf tanpa PIN tetap tampil redup dengan `NO_PIN_REASON` (perilaku 073/078 dipertahankan). Terpilih ditandai cincin tebal, bukan ukuran berbeda (ukuran harus seragam, FR-008). `StaffRow.expanded` tetap dipakai sebagai penanda terpilih, tanpa membesar.
- **D11 — Area PIN lebih besar**: keypad maks ≈ 440 dp, tinggi tombol 88 dp (kompak 64 dp), titik PIN 22 dp, judul PIN lebih besar. Kolom PIN tetap bisa digulir pada tinggi layar kecil (perilaku 054/US4 dipertahankan). Angka akhir diuji di tablet; di spec dinyatakan relatif ("lebih besar dari sebelumnya").
- **D12 — Tombol "Ganti outlet / terminal"**: `OutlinedButton` lebar penuh di bawah keypad dengan ikon dan teks; roda gigi dihapus (FR-011). Teks tetap "Ganti outlet / terminal" (sama dengan yang diuji `PinScreenCopyTest`).
- **D13 — Info Outlet** memuat: logo + nama outlet (`StoreMark`), lencana mode, nama terminal, tanggal, jam, chip online. Nama terminal tampil sebagai baris tersendiri, tidak lagi digabung "Outlet · Terminal".
- **D14 — Tidak ada perubahan server untuk bagian B.** `GET /pos-setup/*`, `GET /staff/roster` dan `POST /auth/pin-sessions` tidak berubah (FR-012, Konstitusi IV pengecualian 073 tetap).

## Risiko dan hal yang belum diverifikasi

- Tampilan tablet nyata belum terverifikasi (tidak ada perangkat di sesi ini); mengikuti pola 077/078, bukti perangkat dicatat di `evidence.md` dan tidak diklaim sebelum dijalankan.
- Penyebab gejala 10% belum terbukti (H1–H4).
- Hasil suite backend penuh tidak dijalankan di tahap rencana.
