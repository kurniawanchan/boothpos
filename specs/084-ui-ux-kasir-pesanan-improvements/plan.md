# Implementation Plan: Perbaikan UI/UX Kasir, Pesanan, Shift, dan Koneksi

Label: INTERNAL

**Branch**: `084-ui-ux-kasir-pesanan-improvements` | **Date**: 2026-10-30 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/084-ui-ux-kasir-pesanan-improvements/spec.md`

## Summary

Hampir seluruh permintaan adalah perubahan **aplikasi Android POS** (`android-pos/`): navbar, istilah, urutan menu, layar awal, teks Kasir/Meja/Pembayaran, pemilih Meja, QRIS otomatis terverifikasi, detail Pesanan Selesai, siklus shift, status printer, cetak X/Z (belum ada, harus dibangun), kursor isian uang, dan karakter struk. Pengecualian atas pilihan pemilik: **modifier dibangun di web `PosView`** (Vue) lewat `POST /orders` yang menerima `modifier_option_ids` dan memakai `ModifierResolver` yang sudah ada. Di server hanya dua perubahan kecil: QRIS dicatat `verified`, dan `move` menerima pesanan selesai. Tidak ada migrasi skema. Rincian dan alasan: [research.md](./research.md).

## Technical Context

**Language/Version**: PHP 8 / Laravel 13 (backend); Vue 3 + Pinia + Vite (web); Kotlin + Jetpack Compose (Android, modul `app`, `core-domain`, `core-network`)

**Primary Dependencies**: Laravel, Tailwind v4 (token `@theme`), Vitest; Compose Material 3, Retrofit, Hilt (sesuai modul yang ada)

**Storage**: MySQL 8 (`warkop_wjm`; tes `warkop_wjm_test`). Tanpa perubahan skema.

**Testing**: PHPUnit `tests/Feature/` (MySQL nyata); Vitest `qa-tests/`; JUnit JVM di `android-pos/app/src/test`; browser nyata untuk layar web; perangkat nyata untuk Android/printer (belum tersedia di sesi ini)

**Target Platform**: server lokal/bersama + browser; tablet Android dengan printer Bluetooth 58 mm

**Project Type**: web-service + SPA + aplikasi mobile (tiga basis kode di satu repo)

**Performance Goals**: jam berdetak tanpa jank; status printer berubah ≤2 dtk (SC-007); tidak ada N+1 baru pada daftar pesanan

**Constraints**: server menghitung harga/total (modifier tidak dipercaya dari klien); tanpa heks mentah di UI web; salinan UI Indonesia; `docs/openapi-pos-mvp.yaml` ikut berubah; `android/` (WebView lama) tidak disentuh

**Scale/Scope**: ~12 berkas Kotlin, ~5 berkas Vue/JS, ~4 berkas PHP + tes; 30 FR

## Constitution Check

| Prinsip | Status | Catatan |
|---------|--------|---------|
| I. Kode bersih / satu implementasi | PASS | Modifier lewat `ModifierResolver` saja; `PaymentRecorder::record()` tetap satu-satunya pencipta `payments`; `TabService::move` diperluas, bukan jalur kedua; satu fungsi `VariantLabel`, satu `LiveClock`. |
| II. Pengujian | PASS bersyarat | Tes backend + Vitest + JUnit direncanakan; layar web diuji di browser; **Android/printer butuh perangkat — ditandai belum terverifikasi, bukan lulus.** |
| III. Konsistensi UX | PASS | Token CSS di web; salinan Indonesia; kontrol dihapus/disembunyikan, bukan dinonaktifkan; pola galat 422/409/403. |
| IV. Keamanan | PASS | Harga modifier dihitung server; meja tujuan dibatasi outlet (server-derived); `table_changed` dicatat di log dalam transaksi yang sama; otorisasi `move` diperiksa di tiga mekanisme. |
| V. Performa | PASS | `with_modifiers=1` opt-in (sudah pola); modal modifier dimuat dinamis; eager-load pada daftar. |
| Dokumentasi | AKAN | OpenAPI + catatan bertanggal PRD/CLAUDE.md untuk QRIS otomatis (kebijakan baru) di commit yang sama. |

**Gerbang pasca-desain**: tidak ada pelanggaran → Complexity Tracking kosong.

Perhatian kebijakan: QRIS otomatis terverifikasi melepas kontrol verifikasi pembayaran (risiko keuangan: pembayaran QRIS tak terkonfirmasi tercatat lunas). Ini keputusan pemilik; dicatat sebagai **catatan bertanggal** dan risiko di PRD, dengan instruksi kasir mengecek bukti di aplikasi QRIS sebagai kompensasi.

## Project Structure

### Documentation (this feature)

```text
specs/084-ui-ux-kasir-pesanan-improvements/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/api-changes.md
├── checklists/requirements.md
└── tasks.md            # dibuat oleh /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Requests/StoreOrderRequest.php          # + items.*.modifier_option_ids
├── Services/OrderService.php                    # panggil ModifierResolver, simpan snapshot
├── Services/TabService.php                      # move(): terima pesanan selesai, log table_changed
├── Http/Controllers/Api/TabPaymentController.php (+ PaymentRecorder)  # QRIS → verified
└── Http/Controllers/Api/TabController.php       # daftar selesai: filter session_id
docs/openapi-pos-mvp.yaml                        # kontrak ikut berubah
tests/Feature/                                   # OrderModifier, TabMove(Closed), QrisAutoVerify, closed-list

resources/js/
├── views/PosView.vue                            # muat with_modifiers, buka picker
├── stores/posCart.js                            # kunci baris variant_id+opsi, harga + delta (tampilan)
├── components/pos/PosCartPanel.vue              # tampilkan modifier
├── components/restaurant/ModifierPickerModal.vue # dipakai ulang
├── api/orders.js                                # kirim modifier_option_ids
└── locales/{id,en}.json
qa-tests/{unit,component}/                       # keranjang, aturan modifier

android-pos/app/src/main/java/com/warkop/pos/
├── ui/Shell.kt              # NAV, header, hamburger, LiveClock, ConnChip
├── ui/screens/{EditorScreen,TablesScreen,OrdersScreen,TenderScreen,ShiftScreens,PinScreen,PrinterScreen}.kt
├── ui/{CountEntry,QrisTexts}.kt, state/CountInput.kt
├── state/PosStore.kt        # layar awal, move meja, shift close clear
├── data/{PosBackend,RemoteBackend,OrderMapper,ReceiptMapper}.kt
├── print/{EscPos,Documents,PrinterService}.kt
├── model/PosModels.kt       # Order.label, TenderMethod label
└── (baru) ui/LiveClock.kt, model/VariantLabel.kt
android-pos/app/src/test/…   # uji murni baru
```

**Structure Decision**: tiga basis kode yang ada dipertahankan; tidak ada modul baru. Logika murni (label varian, transliterasi, pemetaan offset, dokumen X/Z, pemindai istilah) ditaruh di fungsi tanpa Compose agar teruji di JVM tanpa perangkat.

## Urutan pengerjaan yang disarankan (untuk `/speckit-tasks`)

1. **Server**: QRIS verified; `move` pesanan selesai + log; daftar selesai per shift; `modifier_option_ids` di `POST /orders`; OpenAPI; tes.
2. **Web**: modifier di `PosView` + keranjang + uji; verifikasi browser.
3. **Android — murni/teruji**: `VariantLabel`, transliterasi `EscPos`, `CountInput` offset, `Documents.xReport/zReport`, `PrinterStatus.Connecting`, pemindai istilah.
4. **Android — UI**: Shell (navbar, hamburger, NAV, jam), istilah, teks Kasir/Meja/Pembayaran, QRIS, pemilih Meja, detail Pesanan Selesai, siklus shift, panel Koneksi, form tutup shift.
5. **Docs + bukti**: catatan bertanggal PRD/CLAUDE.md, `evidence.md` (perangkat).

## Risiko dan pertanyaan terbuka

- **R1** — QRIS otomatis terverifikasi menghilangkan kontrol (kebijakan pemilik). Mitigasi: instruksi cek bukti; baris lama `pending` tetap dapat diverifikasi.
- **R2** — "Transfer" = EDC: ASUMSI bahwa nilai `bank_transfer` adalah EDC; cek data `payment_channels` sebelum rilis.
- **R3** — "Standard" pada struk membutuhkan jumlah varian per produk; bentuk datanya diputuskan di tugas pertama kelompok itu.
- **R4** — Hamburger hanya di layar lebar (mode compact memakai BottomNav).
- **R5** — Tidak ada adb/printer di sesi ini: D5/D6/D11/D12 hanya terbukti di JVM.
- **R6** — Spesifikasi awal menulis "web" untuk semua butir; sudah dikoreksi (Asumsi di spec.md).

## Complexity Tracking

Tidak ada pelanggaran yang perlu dibenarkan.
