# Implementation Plan: Hubungkan Aplikasi Android POS ke Data Server

**Branch**: `048-wire-android-pos-api` | **Date**: 2026-10-15 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/048-wire-android-pos-api/spec.md`

**Classification**: INTERNAL

## Summary

Aplikasi Android (`android-pos/`, fitur 047) kini menampilkan seluruh desain di atas data contoh dalam memori (`PosCatalog`, `PosStore`). Rencana ini mengganti sumber data itu dengan server Laravel yang sudah ada dan menambah kemampuan server yang belum ada (api-gap B2–B14, B17–B19, B21) dalam **empat irisan yang masing-masing dapat dikirim**:

| Irisan | Cerita | Isi server baru | Isi aplikasi |
|---|---|---|---|
| **S1** (MVP) | US1 + US2 | perangkat/terminal minimal (B14), pajak eksklusif (B8), nomor struk & antrean (B12), jumlah tamu + pra-bill (B13), OpenAPI tab/denah/tiket (B21 sebagian) | lapisan jaringan, penyimpanan token, penyiapan terminal, PIN + roster, menu, denah, order, kirim dapur, bayar tunai, struk |
| **S2** | US3 | kas awal per pecahan (B5), kas masuk/keluar (B3), X/Z-report + tutup buta (B4) | shift, kas, X-report, tutup shift |
| **S3** | US4 | persetujuan terikat aksi (B2), EDC (B6), QRIS tertunda (B7), void terkirim (B10), batal order (B11), 86 dengan persetujuan (B17) | dialog persetujuan, EDC, QRIS, void/batal |
| **S4** | US5 + US6 | diskon beralasan + batas peran (B9), bagi rata (B18), refund penuh (B19), heartbeat perangkat | diskon, bagi bill, cetak ulang, layar Perangkat nyata, ketahanan |

Pendekatan teknis: aplikasi tetap satu modul UI dengan `PosStore` sebagai pemegang state, tetapi semua data dan tulis lewat antarmuka `PosBackend` dengan dua implementasi — `RemoteBackend` (Retrofit/OkHttp ke server) dan `SampleBackend` (data contoh, **hanya** build debug/preset). Server tetap satu-satunya penegak uang, izin, dan outlet (Konstitusi IV).

## Technical Context

**Language/Version**: Kotlin 2.0.20 (katalog versi proyek), Compose BOM 2024.09.00, AGP 8.2.0 (diverifikasi pada `android-pos/gradle/libs.versions.toml`); backend PHP 8.3 / Laravel (`composer.json`).

**Primary Dependencies**: Retrofit 2.11.0 + converter (sudah di katalog); OkHttp (ikut Retrofit; versi **unverified**, ditentukan Gradle saat resolusi); kotlinx.serialization (BARU, versi **unverified** — dipilih saat tugas T-NET-01 dan dicatat di katalog); kotlinx.coroutines 1.8.1 (katalog). Tidak memakai Hilt (lihat research D2).

**Storage**: MySQL 8 (server, wajib; tabel baru di data-model.md). Perangkat: token & URL server di penyimpanan terenkripsi Android Keystore; tidak ada basis data lokal pada fitur ini (online-first).

**Testing**: Backend — PHPUnit `tests/Feature/` melawan MySQL `warkop_wjm_test` (Konstitusi II). Android — unit test JVM untuk mapper/aturan, ditambah verifikasi nyata di emulator terhadap server dev (bukti sesi kerja). Skrip alur tertulis (`quickstart.md`).

**Target Platform**: Android minSdk 26, targetSdk 34; tablet 8"/10"/13" dan telepon; server Laravel pada jaringan outlet.

**Project Type**: mobile-app + web-service (backend monorepo yang sama).

**Performance Goals**: siklus masuk→order 3 item→kirim→bayar tunai < 90 dtk (SC-001); penyegaran harga/ketersediaan ≤ 10 dtk (SC-008); denah via polling `version` (unchanged → badan kecil).

**Constraints**: online-first; tidak ada offline penuh; semua tulis idempoten; tidak ada rahasia/URL hardcoded; tidak ada PII di log.

**Scale/Scope**: satu outlet ~5–15 terminal; ≤ ~40 meja; menu ~30–200 item; shift ≤ ~1.000 transaksi.

## Constitution Check

*GATE — dievaluasi sebelum Phase 0, dievaluasi ulang setelah Phase 1.*

| Prinsip | Penilaian | Catatan |
|---|---|---|
| I. Kualitas kode / satu jalur tulis | LULUS | Logika baru di `app/Services/` (`DeviceService`, `OverrideGate`, `CashMovementService`, `ShiftReportService`, `TaxCalculator`). Pembayaran tetap lewat `PaymentRecorder`/`PaymentService`; stok lewat `StockService`; log lewat `ActivityLogger`. Tidak ada jalur kedua. |
| II. Pengujian | LULUS (dengan syarat) | Tiap kemampuan server baru wajib uji `tests/Feature` pada MySQL. Untuk Android, konstitusi menyebut browser; padanannya adalah emulator + server nyata dan **log logcat bersih** sebagai bukti; dicatat di tiap irisan. |
| III. Konsistensi UX | LULUS dengan catatan | Teks UI Indonesia; galat 422/409/403 dipetakan seragam di satu penangan klien. Catatan: desain menampilkan **ikon kunci** pada aksi yang butuh persetujuan atasan — itu bukan "tak berhak" (aksinya boleh dilakukan dengan persetujuan), jadi tidak melanggar aturan "sembunyikan yang tak boleh". Kontrol yang peran tak boleh pakai sama sekali (mis. verifikasi QRIS oleh peran tanpa izin sekalipun dengan persetujuan) disembunyikan. |
| IV. Keamanan | LULUS | Uang/total/pajak/diskon dihitung server; outlet diturunkan server (`X-Outlet-Id` hanya menyempit); persetujuan terikat hash aksi dan sekali pakai; PIN di-hash, kunci 5-salah; audit di transaksi yang sama; token di Keystore; tidak ada rahasia di log. |
| V. Performa | LULUS | Denah memakai `version` (304-gaya); daftar menu ramping + opt-in `with_modifiers`; cache apa pun dikunci oleh mode data + outlet + pengguna; tidak ada N+1 (eager load di resource). |
| Stack | LULUS | MySQL 8, satu server per organisasi, tanpa layanan cloud. |
| Dokumentasi | **DIPERLUKAN** | `docs/openapi-pos-mvp.yaml` diperbarui di commit yang sama dengan tiap perubahan rute (T-DOC); catatan bertanggal ditambahkan ke PRD dan `CLAUDE.md` (kapabilitas baru: aplikasi Android + perangkat + persetujuan). |

Tidak ada pelanggaran yang memerlukan Complexity Tracking. Evaluasi ulang pasca-desain: tetap LULUS (lihat bawah).

## Project Structure

### Documentation (this feature)

```text
specs/048-wire-android-pos-api/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── api-new.md          # rute baru/diubah (server)
│   └── android-client.md   # kontrak sisi klien (error, idempotensi, polling, penyimpanan)
└── tasks.md                # keluaran /speckit-tasks (belum dibuat)
```

### Source Code (repository root)

```text
# Backend (Laravel)
app/
├── Http/Controllers/Api/   # DeviceController, ApprovalController (ada; diperluas), CashMovementController, ShiftReportController, DiscountController
├── Http/Requests/          # FormRequest per aksi baru
├── Http/Resources/         # TabResource (+pajak, nomor), ShiftReportResource
├── Models/                 # Device, CashMovement, (Approval sudah ada)
├── Services/               # DeviceService, CashMovementService, ShiftReportService, TaxCalculator, ReceiptNumbering
└── Support/PermissionCatalog.php   # izin baru per aksi
database/migrations/        # tabel/kolom baru (data-model.md)
routes/api.php              # rute baru
tests/Feature/              # satu berkas uji per kemampuan
docs/openapi-pos-mvp.yaml   # kontrak (wajib diperbarui)

# Android
android-pos/
├── core-domain/            # model domain + kontrak PosBackend
├── core-network/           # Retrofit service, DTO, mapper, interceptor (token, idempotensi), penangan galat
└── app/src/main/java/com/warkop/pos/
    ├── data/               # RemoteBackend, SampleBackend, TokenVault (Keystore), TerminalConfig
    ├── state/PosStore.kt   # memakai PosBackend (API ke layar tidak berubah)
    ├── theme/ ui/          # tidak berubah kecuali layar penyiapan terminal
    └── setup/              # layar penyiapan terminal (URL + login admin + pilih outlet)
```

**Structure Decision**: dua bagian yang sudah ada (backend monorepo + `android-pos/`). Modul `core-network` yang kini berisi stub diisi nyata; `core-domain` menampung kontrak `PosBackend` supaya `PosStore` tidak tahu soal HTTP. Layar tidak diubah kecuali penambahan layar penyiapan terminal dan keadaan galat/muat.

## Phase 0 & 1 artefak

research.md (keputusan D1–D14), data-model.md, contracts/api-new.md, contracts/android-client.md, quickstart.md — lihat berkas masing-masing.

## Re-evaluasi Konstitusi (pasca-desain)

Desain memperkenalkan satu hal yang perlu dijaga: **persetujuan di tempat** harus menjadi satu mekanisme (`OverrideGate`) yang dipanggil oleh void, batal, diskon, kas keluar, cetak ulang, QRIS verify, refund, dan 86 — bukan delapan pemeriksaan tersebar (Prinsip I). Catatan hasil membaca kode: `ApprovalService` (043) adalah antrean persetujuan **asinkron** untuk perubahan harga menu (peminta menunggu, pemutus memutuskan kemudian); persetujuan terminal bersifat **sinkron** (atasan mengetik PIN di layar yang sama, sekali pakai, terikat ke satu aksi). Keduanya tidak digabung — kebutuhan berbeda — tetapi `OverrideGate` memakai ulang aturan inti yang sama (pemutus ≠ peminta, izin per aksi lewat `PermissionCatalog`, log di transaksi yang sama). Semua gerbang tetap LULUS.

## Complexity Tracking

Tidak ada.
