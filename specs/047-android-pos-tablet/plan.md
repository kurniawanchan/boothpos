**Classification**: INTERNAL

# Implementation Plan: Aplikasi POS Android untuk Tablet (online-first)

**Branch**: `047-android-pos-tablet` | **Date**: 2026-10-13 | **Spec**: [spec.md](spec.md)
**Input**: spec.md, `api-gap.md`, ringkasan desain (`design-spec-digest.md`, `design-screens-digest.md`), inventaris API (`contracts/existing-api-inventory.md`). Arahan pemilik produk (2026-10-13): **online-first, bangun aplikasi dengan API yang sudah ada sekarang; API yang belum ada dibangun SETELAH fase ini; kode Android ditaruh di folder terpisah yang kelak dipindah ke repositori lain.**

## Ringkasan
Aplikasi native Kotlin + Jetpack Compose (Material 3) untuk tablet kasir/pelayan Warkop WJM. Fase ini: **tidak ada perubahan backend**. Aplikasi memakai API yang ada (login username+kata sandi, shift, denah meja, menu+modifier, tab, kirim dapur, pembayaran tunai/transfer/QRIS e-wallet, struk). Kebutuhan desain tanpa API (PIN, persetujuan atasan, X/Z, EDC, pajak, dsb. — `api-gap.md` B1–B21) dibangun di belakang antarmuka repositori + penanda kemampuan (`ApiCapabilities`) sehingga UI-nya ada, tampil "menunggu API" dan menyala begitu endpoint tersedia, tanpa endpoint karangan.

## Technical Context
**Language/Version**: Kotlin 2.0.20 (versi yang sudah dipakai `android/build.gradle.kts` di repo ini), JDK 17 target (JDK terpasang di mesin: 24.0.1)
**Primary Dependencies** (pilihan; **versi belum diverifikasi** kecuali Kotlin/AGP di atas — dipatok saat sinkron Gradle pertama): Jetpack Compose BOM + Material 3 + Material3 window-size-class, Navigation-Compose, Hilt, Retrofit + OkHttp, kotlinx.serialization, kotlinx.coroutines, DataStore + Keystore (Tink), Coil (gambar), Timber, JUnit5/Turbine/MockK/MockWebServer, Robolectric/Compose UI test. Tanpa Room pada fase ini (lihat research D3).
**Storage**: DataStore terenkripsi (sesi/token, pengaturan terminal, printer); tanpa DB lokal transaksi (server = sumber kebenaran).
**Testing**: unit (domain murni: kalkulasi tender/kembalian/pajak tampilan/aturan modifier), ViewModel, repositori terhadap MockWebServer, beberapa uji UI Compose; verifikasi desain per layar.
**Target Platform**: Android 8.0+ (minSdk 26 seperti `android/`), targetSdk 34 (keduanya mengikuti `android/`; **pilihan, belum diverifikasi vs toko pilot**), tablet 10" utama (1180×740dp), 8" potret, 13", ponsel kompak, tema terang/gelap.
**Project Type**: mobile-app (repositori terpisah kelak).
**Performance Goals**: tambah item ≤ 200 ms terasa (UI optimis, server mengonfirmasi), polling meja ±3 dtk / ketersediaan ±5 dtk dengan etag, daftar menu 31–hundreds item tanpa jank.
**Constraints**: online-first (tidak ada antrean transaksi offline); server otoritas uang & izin; idempotensi hanya di endpoint yang menyediakan (`local_ref`, `client_ref`); tidak ada kunci idempotensi pada tambah/ubah baris dan `send` → klien MUST mencegah ketukan ganda (debounce + status "mengirim") dan pulihkan lewat muat ulang tab setelah error jaringan; tidak menulis token/PIN ke log.
**Scale/Scope**: S01–S29 (R1 Must) + varian layout; ≤ 2 tablet, ≤ 3 printer, 1 outlet pilot.

### Keterbatasan lingkungan (fakta, bukan asumsi)
Mesin ini TIDAK punya Android SDK, Android Studio, maupun Gradle (hanya JDK 24). Karena itu: kode ditulis dan ditinjau di sini, tetapi **kompilasi/uji penuh belum terverifikasi** sampai SDK dipasang (lihat quickstart). Modul domain dibuat Kotlin-JVM murni agar uji unitnya bisa jalan dengan Gradle + JDK saja (tanpa SDK Android) begitu Gradle tersedia.

## Constitution Check
Konstitusi (`.specify/memory/constitution.md`, v2.0.0) mengatur repositori Laravel BoothPOS; aplikasi Android berada di folder terpisah dan kelak repositori terpisah, jadi hanya prinsip yang relevan diterapkan sebagai gerbang:
- **I Kualitas/DRY**: satu implementasi per kebutuhan (satu `MoneyFormatter`, satu `TenderCalculator`, satu klien API); logika bisnis di domain/use case, bukan di composable. ✅
- **II Pengujian**: logika uang diuji unit; tiap slice berakhir dengan uji; tidak menyatakan verifikasi desain yang tidak dilakukan. ✅ (dengan catatan keterbatasan build)
- **III Konsistensi UX**: salinan Bahasa Indonesia sesuai desain, token tema tunggal, aksi tak berizin disembunyikan (bukan dinonaktifkan) kecuali desain menetapkan nonaktif dengan alasan. ✅
- **IV Keamanan**: server memutuskan uang/izin; token di penyimpanan aman; tanpa log rahasia; header `X-Outlet-Id` hanya mempersempit. ✅
- **V Performa**: polling berbasis versi/etag; tanpa N+1 dari klien (satu muat menu). ✅
- **Tidak ada perubahan backend** pada fase ini → tidak ada pelanggaran konstitusi yang perlu dibenarkan. Daftar API yang belum ada (`api-gap.md`) adalah masukan untuk fitur backend berikutnya, yang akan tunduk pada konstitusi di repositori ini.

Setelah desain (Phase 1): tetap lulus.

## Project Structure

### Dokumentasi (di repositori ini)
```text
specs/047-android-pos-tablet/
├── plan.md  research.md  data-model.md  quickstart.md  api-gap.md  spec.md
├── contracts/ (api-usage.md, existing-api-inventory.md)
├── design-spec-digest.md  design-screens-digest.md
└── tasks.md   # /speckit-tasks
```

### Kode sumber — folder terpisah, siap dipindah ke repositori lain
```text
android-pos/                      # akar proyek Android MANDIRI (tidak bergantung pada kode Laravel)
├── settings.gradle.kts  build.gradle.kts  gradle.properties  gradle/libs.versions.toml
├── README.md  .gitignore  docs/ (salinan spec.md, api-gap.md, contracts/ — agar repositori baru membawa konteksnya)
├── core-domain/                  # Kotlin JVM murni: model, aturan, kalkulasi, port repositori, ApiCapabilities
├── core-network/                 # Retrofit/OkHttp/serialization: DTO, ApiService, interceptor (token, X-Outlet-Id, 423/401/422/409 → ApiError)
├── core-printing/                # ESC/POS renderer + klien LAN (port 9100), antrean cetak
├── core-ui/                      # tema Material 3 (token desain), komponen bersama (keypad uang, kartu menu, sheet)
└── app/                          # Compose: navigasi, fitur (login, shift, meja, pesanan, tender, struk, order, perangkat), DI Hilt
```
**Keputusan struktur**: folder `android-pos/` berdiri sendiri (tidak mengacu berkas di luar dirinya) supaya `git subtree split`/salin ke repositori baru tanpa ubahan; spec/gap disalin ke `android-pos/docs/` pada saat implementasi. Folder `android/` lama (WebView 008) tidak disentuh.

## Urutan pengerjaan (vertical slice, tiap slice berakhir dengan uji)
0. **Fondasi**: proyek Gradle, katalog versi, tema (token desain terang/gelap), `core-domain` (Money, Role, ApiCapabilities), `core-network` (klien, error, interceptor), navigasi + kelas ukuran jendela.
1. **Masuk & shift** (S01-ish, S03): login username/kata sandi (PIN menunggu B1), `GET /me/scope` + pilih outlet, buka shift (kas awal total; pecahan menunggu B5), pengalihan menurut peran/izin.
2. **Menu & pesanan**: katalog (`/products?pos=1…`), pencarian, denah meja (`/floor`), buat tab, tambah/ubah/hapus baris, lembar modifier, catatan; pencegahan ketukan ganda.
3. **Kirim dapur & cetak**: `/tabs/{id}/send` → tiket → renderer ESC/POS → printer LAN, coba ulang 3× + antrean + penanda; slip pra-bill (lokal, tanpa status server).
4. **Bayar**: keypad tunai, tender ganda (cash/transfer/QRIS e-wallet via `payment-channels` + bukti opsional), kembalian, `client_ref`, penutupan otomatis, struk layar + cetak; EDC/QRIS-tertunda/pajak/persetujuan = "menunggu API".
5. **Daftar order & struk**: tab terbuka/selesai, detail, struk, cetak ulang (penghitung lokal sementara).
6. **Pusat shift**: ringkasan `/sessions/{id}/summary`, tutup shift dasar (jumlah kas + syarat tab terbuka); X/Z, kas masuk/keluar, hitung per pecahan = "menunggu API".
7. **Perangkat & koneksi**: status koneksi, antrean cetak, pengaturan printer/terminal, kunci layar idle.
8. **Adaptif & gelap**: 8"/13"/ponsel, tema gelap, pemeriksaan terhadap tangkapan desain.
Slice 9 (Stretch) menyusul: bagi tagihan per item (`/split`), pindah/gabung meja (`/move`,`/merge`).

## Risiko
- **Build tak bisa diverifikasi di mesin ini** (tanpa SDK/Gradle) → kemungkinan galat kompilasi baru terlihat saat pertama dibuka di Android Studio. Mitigasi: kode konservatif, domain murni terpisah, panduan quickstart, opsi memasang SDK/Gradle jika pemilik mengizinkan.
- **Tidak ada idempotensi tambah baris/send** → risiko dobel bila jaringan putus; mitigasi klien + permintaan B15.
- **Peran/izin**: peran Pelayan/Supervisor belum ada di backend; UI membaca `menu_keys`/`permissions` dari `/auth/login` dan menyembunyikan aksi; sampai B2, aksi sensitif hanya untuk peran dengan izin settings.
- **Token tak kedaluwarsa** (tak ada refresh) → logout lokal manual + kunci layar; dicatat untuk B1.
- Desain mengasumsikan DB lokal; versi online-first lebih sederhana tetapi tidak tahan jaringan buruk (diterima untuk fase ini).
