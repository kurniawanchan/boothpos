# Implementation Plan: Pengaturan Pajak (tarif, label, tampil/sembunyi) di web, detail Penjualan, struk, dan Android

**Branch**: `083-tax-setting-sales-receipt-android` | **Date**: 2026-10-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/083-tax-setting-sales-receipt-android/spec.md`

## Summary

Pajak eksklusif sudah dihitung server (`TaxCalculator`, dibaca dari `tax_rate`) dan disimpan per order (`tax_amount`, `tax_rate_snapshot`), tetapi tarif dan label hanya bisa diubah lewat API/tinker, detail Penjualan dan struk web tidak menampilkan baris pajak (total terlihat "tidak cocok" dengan rinciannya), dan Android memakai tarif 10% yang di-hardcode untuk perkiraan lokal. Pendekatan: satu kunci setting baru `tax_show` (boolean, bawaan aktif) di samping `tax_rate`/`tax_label` yang sudah ada; bagian Pajak di Pengaturan web menyimpannya lewat `PUT /settings` dengan validasi baru; `GET /settings/features` dan struk membawa `tax_show`; web dan Android hanya menggerbang TAMPILAN baris pajak dengan `tax_show` — perhitungan total tidak berubah. Detail Penjualan (`TransactionItemsModal`, `OpenOrderDetail`) dan `ReceiptModal` mendapat baris pajak dari angka tersimpan di order. Android mengganti konstanta 10% dengan tarif dari server dan menggerbang baris pajak di editor, lembar struk, dan cetak.

## Technical Context

**Language/Version**: PHP 8 / Laravel (backend), Vue 3 + Vite + Tailwind v4 (web), Kotlin + Jetpack Compose (`android-pos/`). Versi persisnya tidak diperiksa di langkah ini.

**Primary Dependencies**: Pinia (`stores/settings.js`), vue-i18n (`locales/id.json`, `en.json`), kotlinx.serialization + Retrofit (`RemoteBackend`), tidak ada dependensi baru.

**Storage**: Tabel `settings` (key-value) yang sudah ada. Satu baris baru `tax_show`. Kolom `orders.tax_amount` dan `orders.tax_rate_snapshot` sudah ada. TIDAK ada migrasi skema.

**Testing**: `tests/Feature/` (MySQL 8, `warkop_wjm_test`, jalankan lewat container dengan `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test` dan `-e CACHE_STORE=array -e SESSION_DRIVER=array` sesuai CLAUDE.md), Vitest di `qa-tests/`, tes unit Android bila modul tesnya ada (belum diverifikasi), dan pemeriksaan browser nyata.

**Target Platform**: Laptop toko/server bersama (web), tablet Android POS.

**Project Type**: Web application (Laravel API + Vue SPA) + aplikasi seluler terpisah (`android-pos/`).

**Performance Goals**: Tidak ada beban baru: satu baris setting dibaca lewat `Setting::get` yang sudah ber-cache.

**Constraints**: Server tetap satu-satunya penghitung pajak (Konstitusi IV). Order lama tidak pernah dihitung ulang. Tablet harus tetap jalan offline dengan nilai terakhir yang diketahui.

**Scale/Scope**: 1 kunci setting baru, ~4 komponen web, 3 area Android (editor, struk layar, cetak), 1 bagian Pengaturan baru.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Penilaian |
|---|---|
| I. Kualitas kode (satu implementasi per hal) | LULUS. Rumus pajak tetap hanya di `TaxCalculator`. Tarif/label/tampil dibaca hanya lewat `PosSettings` (ditambah `taxShow()`), tidak ada `Setting::get('tax_*')` tersebar. Aturan tampil di web satu composable/store getter, bukan `v-if` yang menduplikasi logika per komponen. |
| II. Pengujian | LULUS dengan syarat: tes Feature untuk validasi, `features`, struk; Vitest untuk bagian Pengaturan dan baris pajak; pemeriksaan browser wajib untuk Pengaturan, POS, detail Penjualan, struk. Android belum bisa diverifikasi di perangkat (adb tidak ada, lihat catatan 077); itu dicatat jujur sebagai bukti yang belum ada. |
| III. Konsistensi UX | LULUS. Token desain, teks UI Indonesia, bagian Pengaturan hanya untuk peran dengan menu `settings` (disembunyikan, bukan dinonaktifkan). |
| IV. Keamanan | LULUS. Jumlah pajak tidak pernah dari klien. Perubahan pengaturan lewat `PUT /settings` yang sudah digerbang `SettingPolicy` dan dicatat `ActivityLogger` dalam transaksi yang sama. Tampilan memakai snapshot order, bukan tarif saat ini. |
| V. Performa | LULUS. Tanpa query baru per baris; `tax_show` ikut `features`. |
| Dokumentasi | `docs/openapi-pos-mvp.yaml` diperbarui di commit yang sama (field `tax_show` pada `features` dan struk). Catatan bertanggal di CLAUDE.md dan PRD (kontrol baru, bukan perubahan lingkup MVP). |

Tidak ada pelanggaran, jadi tabel Complexity Tracking tidak diisi.

## Project Structure

### Documentation (this feature)

```text
specs/083-tax-setting-sales-receipt-android/
├── plan.md              # File ini
├── research.md          # Keputusan dan temuan kode
├── data-model.md        # Pengaturan dan catatan pajak per order
├── quickstart.md        # Cara memverifikasi end-to-end
├── contracts/
│   └── tax-setting-api.md   # PUT /settings, GET /settings/features, GET /orders/{id}/receipt
├── checklists/requirements.md
└── tasks.md             # Dibuat /speckit-tasks (BUKAN oleh perintah ini)
```

### Source Code (repository root)

```text
app/
├── Support/PosSettings.php                    # + TAX_SHOW, taxShow()
├── Http/Requests/UpdateSettingsRequest.php    # + validasi tax_rate, tax_label, tax_show
├── Http/Controllers/Api/SettingsController.php# features(): + tax_show
└── Http/Controllers/Api/OrderController.php   # receipt(): + tax_show
tests/Feature/                                 # TaxSettingTest (validasi, features, struk, order lama tak berubah)

resources/js/
├── stores/settings.js                         # taxShow, setTax()
├── views/settings/TaxSection.vue              # BARU
├── views/SettingsView.vue                     # daftarkan bagian `tax`
├── components/pos/PosCartPanel.vue            # gerbang tampil
├── components/payment/PosPaymentModal.vue     # gerbang tampil
├── components/sales/TransactionItemsModal.vue # + baris pajak, total tersimpan
├── components/sales/OpenOrderDetail.vue       # + baris pajak
├── components/receipt/ReceiptModal.vue        # + baris pajak
└── locales/{id,en}.json
qa-tests/                                      # Vitest: TaxSection, baris pajak per keadaan tampil

android-pos/app/src/main/java/com/warkop/pos/
├── data/PosBackend.kt, RemoteBackend.kt       # TaxDisplay + show; taxDisplay() tidak lagi null saat label kosong
├── model/PosModels.kt                         # hapus TAX_RATE 0.10, pakai tarif server
├── state/PosStore.kt                          # simpan tax, nilai terakhir bila offline
├── ui/screens/EditorScreen.kt, ui/Sheets.kt   # gerbang baris pajak
├── print/Documents.kt, data/ReceiptMapper.kt  # gerbang baris pajak struk cetak
docs/openapi-pos-mvp.yaml, CLAUDE.md, docs/RUNBOOK.md (catatan)
```

**Structure Decision**: Mengikuti pola yang sudah ada: pengaturan POS di `PosSettings`, bagian Pengaturan sebagai komponen `views/settings/*Section.vue` (seperti `ReceiptDisplaySection.vue`), disimpan lewat `PUT /settings`. Tidak ada endpoint, tabel, atau migrasi baru.

## Complexity Tracking

Tidak ada pelanggaran Konstitusi yang perlu dibenarkan.
