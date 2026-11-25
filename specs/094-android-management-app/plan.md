# Implementation Plan: Aplikasi Android Manajemen (Ponsel & Tablet)

**Klasifikasi**: INTERNAL

**Branch**: `094-android-management-app` | **Date**: 2026-11-25 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/094-android-management-app/spec.md`

## Summary

Aplikasi Android kedua (`:manager`) untuk pemilik/admin/manajer outlet, di ponsel dan tablet, berisi Dashboard, Dapur & Bar, Pengaturan Restoran, Kategori, Produk, Stok, Vendor, Bahan, dan Purchase Order dengan formulir yang sama dengan web. Aplikasi adalah klien tipis: semua aturan, angka, dan izin tetap di server Laravel yang sudah ada; endpoint web dipakai ulang apa adanya.

Perubahan server kecil dan terarah: penanda peran `manager_app_enabled` (diatur di formulir Peran web), endpoint login `POST /auth/manager-sessions` yang berbagi logika kredensial dengan login web, middleware per-token yang mencabut akses saat penanda dimatikan, dan penutupan tiga celah validasi yang hari ini hanya ada di klien web. Di Android: modul aplikasi baru di proyek Gradle `android-pos/` yang berbagi `:core-network`/`:core-domain`, Compose + Material 3 adaptif (navigasi bawah di ponsel, rel + daftar-detail di tablet).

## Technical Context

**Language/Version**: Server PHP 8.x / Laravel 13 (sudah ada); web Vue 3 (sudah ada); Android Kotlin 2.0.20 (katalog `android-pos/gradle/libs.versions.toml`).

**Primary Dependencies**: Server: Sanctum (token Bearer, sudah ada). Android: Jetpack Compose (BOM `2024.09.00`), Material 3, Navigation Compose, pustaka Material 3 adaptif (`NavigationSuiteScaffold`, `ListDetailPaneScaffold`; versi tepat **unverified**, dipastikan saat setup — research D2), Retrofit + kotlinx.serialization + OkHttp lewat `:core-network`. Tanpa Hilt (research D3).

**Storage**: Server MySQL 8 (satu kolom baru). Perangkat: SharedPreferences terenkripsi AES-GCM/Keystore untuk profil server, outlet, token (pola `TokenVault`); cache tampilan di memori saja.

**Testing**: `tests/Feature/` (MySQL, flag `-e` wajib); `qa-tests/` (Vitest) untuk formulir Peran; tes unit JVM Android (`:manager`, `:core-network`, `:app` tetap hijau); verifikasi emulator/perangkat ponsel + tablet dicatat di `evidence.md`.

**Target Platform**: Android 8.0+ (`minSdk 26`), `targetSdk/compileSdk 34`; ponsel dan tablet, potret dan lanskap.

**Project Type**: Mobile app + perubahan kecil API/web yang sudah ada.

**Performance Goals**: Daftar utama tampil < 2 dtk di jaringan toko (SC-006); polling Dapur & Bar 5 dtk hanya saat layar tampil.

**Constraints**: Online-first, tanpa tulis luring; klien tidak menghitung harga/stok/pajak (FR-015); tidak menambah `throttle:N,1` pada rute tanpa token; `X-Outlet-Id` hanya menyempitkan; teks antarmuka bahasa Indonesia.

**Scale/Scope**: 9 menu, ±25 layar (daftar, detail, formulir), ±70 endpoint dipakai ulang, 1 endpoint baru + 1 middleware + 1 kolom.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Status | Bukti di rencana |
|---|---|---|
| I. Satu implementasi per kepedulian, tanpa abstraksi spekulatif | ✅ | Login manajer + web berbagi `CredentialLogin` (D4); klien HTTP dibagi lewat `:core-network` (D1); tanpa Hilt (D3); tanpa endpoint simpan produk kedua (D15); tanpa tata letak PDF kedua (D12). |
| II. Diuji, bukan diasumsikan | ✅ | Tes Feature untuk login/middleware/peran/celah validasi; Vitest untuk formulir Peran; tes unit Android; bukti emulator ponsel+tablet di `evidence.md` dengan yang belum terverifikasi ditandai. |
| III. Konsistensi UX | ✅ (dengan catatan) | Konvensi 422/409/403 lewat `ApiErrorMapper` bersama; aksi tanpa izin DISEMBUNYIKAN (menu dari `menu_keys`, aksi dari `permissions`); bahasa Indonesia. Catatan: token desain `app.css` adalah CSS web; Android memakai tema Material 3 dengan warna merek dari token yang sama (dipetakan sekali di `theme/`), tanpa hex lepas di komponen. |
| IV. Keamanan | ✅ | Penanda ditegakkan server di login DAN tiap permintaan (D4); `outlet_id` login hanya untuk verifikasi; tidak ada rute publik baru selain login (yang memakai pembatas bersama); token terenkripsi, log tanpa header/badan (`RedactedLogInterceptor`); celah validasi klien-saja ditutup di server sebelum layar Android dibuat (D16); log aktivitas perubahan peran di transaksi yang sama. |
| V. Performa | ✅ | Daftar memakai pagination server yang ada; varian hanya di detail (`with_variants=1`); polling hanya saat tampil; tanpa cache angka baru di server. |
| Stack: MySQL, tanpa sqlite; tanpa push tanpa izin | ✅ | Tes MySQL; tidak ada push/PR tanpa izin eksplisit. |
| Dokumentasi: OpenAPI di commit yang sama; catatan PRD bertanggal | ✅ | `docs/openapi-pos-mvp.yaml` untuk A1/A3; catatan bertanggal di PRD (kemampuan baru pasca-MVP: aplikasi manajemen Android) dan CLAUDE.md. |

**Hasil**: lulus, tanpa pelanggaran yang perlu dibenarkan.

**Pemeriksaan ulang pasca-desain**: lulus. Satu penyempitan cakupan butuh persetujuan pemilik (bukan pelanggaran konstitusi): PDF PO diganti ringkasan teks (D12) dan harga massal/rilis menu/BOM varian tidak di aplikasi (kontrak B5).

## Project Structure

### Documentation (this feature)

```text
specs/094-android-management-app/
├── plan.md
├── research.md          # D1–D17
├── data-model.md
├── quickstart.md
├── contracts/
│   └── manager-api.md   # A: perubahan server, B: endpoint dipakai ulang, C: celah validasi
├── checklists/
│   └── requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
# Server (perubahan kecil)
database/migrations/2027_01_xx_000001_add_manager_app_enabled_to_roles.php
app/Models/Role.php                                   # fillable + cast
app/Services/CredentialLogin.php                      # BARU: kredensial + jatah percobaan (diekstrak dari AuthController::login)
app/Http/Controllers/Api/AuthController.php           # login memanggil CredentialLogin
app/Http/Controllers/Api/ManagerSessionController.php # BARU: POST /auth/manager-sessions
app/Http/Requests/ManagerSessionRequest.php           # BARU
app/Http/Middleware/EnsureManagerAppAccess.php        # BARU
app/Http/Requests/StoreRoleRequest.php, UpdateRoleRequest.php
app/Http/Resources/RoleResource.php
app/Http/Controllers/Api/RoleController.php           # log aktivitas memuat penanda
app/Services/ModifierLinkService.php                  # C1
app/Http/Requests/Store|UpdateRecipeRequest.php       # C2 (atau RecipeService)
app/Services/StockTransferService.php                 # C3 (bila belum)
routes/api.php, bootstrap/app.php                     # rute + middleware grup
database/seeders/{DatabaseSeeder,WarkopPosStaffSeeder}.php, database/factories/UserFactory.php
docs/openapi-pos-mvp.yaml
tests/Feature/ManagerSessionTest.php, ManagerAppAccessMiddlewareTest.php, RoleManagerAppFlagTest.php, (C1–C3 tests)

# Web
resources/js/views/RolesView.vue                      # sakelar "Boleh memakai aplikasi manajemen"
resources/js/i18n/...                                 # label id/en
qa-tests/unit/rolesManagerAppFlag.test.js

# Android (proyek Gradle android-pos/)
android-pos/settings.gradle.kts                       # include(":manager")
android-pos/gradle/libs.versions.toml                 # + navigation-compose, material3-adaptive*
android-pos/core-network/.../ApiClient.kt             # create<T>() generik; create() lama tetap
android-pos/manager/
├── build.gradle.kts                                  # applicationId com.warkop.manager
└── src/
    ├── main/java/com/warkop/manager/
    │   ├── ManagerApplication.kt, MainActivity.kt, AppContainer.kt
    │   ├── data/        # ManagerApi (Retrofit), dto/, SecureStore, ServerProfiles, SessionRepository,
    │   │                # InventoryRepository, PurchaseRepository, RestaurantRepository, DashboardRepository
    │   ├── domain/      # NavSpecs (menu→kunci), FormState, ProductSaveOrchestrator
    │   ├── ui/
    │   │   ├── theme/   # Material 3, warna merek, dinamis, gelap
    │   │   ├── shell/   # NavigationSuiteScaffold, pita mode DEMO/LIVE, pita Luring
    │   │   ├── setup/   # Pengaturan server, pilih outlet, login
    │   │   ├── dashboard/
    │   │   ├── kitchen/
    │   │   ├── dining/  # meja, stasiun, grup modifier
    │   │   ├── categories/ products/ stock/
    │   │   └── vendors/ materials/ purchaseorders/
    │   └── res/values/strings.xml                    # label disalin dari i18n web locale id
    └── test/java/com/warkop/manager/                 # NavSpecs, FormState 422, orchestrator, money
```

**Structure Decision**: Server dan web memakai tata letak Laravel/Vue yang ada. Android menjadi modul aplikasi kedua `android-pos/manager/` di proyek Gradle yang sama dengan aplikasi kasir agar `:core-network` dan `:core-domain` dibagi, bukan disalin (research D1). Aplikasi kasir `:app` tidak diubah kecuali pemanggilan `ApiClient` yang tetap kompatibel.

## Urutan pengerjaan (masukan untuk /speckit-tasks)

1. **Server dasar (US1)**: migrasi + model peran → `CredentialLogin` (refaktor tanpa ubah perilaku, tes login web lama tetap hijau) → `POST /auth/manager-sessions` → middleware per-token → formulir/resource Peran + log → OpenAPI. Web: sakelar Peran + Vitest.
2. **Celah validasi (D16, C1–C3)**: tes merah dulu, lalu aturan server. Prasyarat layar Produk/Stok Android.
3. **Android kerangka (US1, US6)**: modul `:manager`, `ApiClient` generik, penyimpanan aman, Pengaturan server, pilih outlet, login, shell adaptif, NavSpecs, pita mode/luring.
4. **Dashboard (US2)**.
5. **Inventori (US3)**: Kategori → Stok (koreksi, pindah) → Produk (daftar, detail, formulir, varian, harga outlet, foto, tautan modifier, resep, `ProductSaveOrchestrator`).
6. **Pembelian (US4)**: Vendor → Bahan (koreksi, riwayat, harga vendor) → PO (formulir, status, pembayaran, bagikan ringkasan).
7. **Restoran (US5)**: Dapur & Bar (polling + status) → Pengaturan Restoran (meja, QR, stasiun, grup modifier + Attach, pindah outlet).
8. **Penutup**: daftar periksa paritas per formulir, verifikasi ponsel+tablet, `evidence.md`, catatan bertanggal PRD + CLAUDE.md.

## Risiko

| Risiko | Mitigasi |
|---|---|
| Paritas formulir menyimpang dari web seiring waktu | Daftar periksa paritas per formulir; validasi otoritatif di server; label dari i18n web. |
| Simpan produk sebagian (urutan beberapa panggilan) | `ProductSaveOrchestrator` melanjutkan langkah yang gagal (D15); kandidat endpoint transaksional bersama web dicatat. |
| Menaikkan BOM/pustaka adaptif mengganggu aplikasi kasir | Tes unit + build `:app` di commit yang sama; versi dipastikan dengan build nyata. |
| Ukuran cakupan (±25 layar) | Prioritas US1→US2→US3 sebagai MVP; US4/US5 bisa dirilis terpisah. |
| Formulir Produk web yang besar sulit di ponsel | Formulir dipecah menjadi bagian (Info, Varian & harga, Stok, Modifier, Resep) dengan field yang sama; tablet memakai dua panel. |

## Complexity Tracking

Tidak ada pelanggaran konstitusi yang perlu dibenarkan.
