# Tasks: Aplikasi Android Manajemen (Ponsel & Tablet)

**Klasifikasi**: INTERNAL

**Input**: Design documents from `specs/094-android-management-app/` (plan.md, spec.md, research.md D1–D17, data-model.md, contracts/manager-api.md, quickstart.md)

**Tests**: WAJIB — Konstitusi II menuntut tes `tests/Feature/` (MySQL) untuk perubahan server, `qa-tests/` untuk perubahan web, dan bukti nyata (emulator/perangkat) untuk layar. Tes server ditulis dulu dan harus GAGAL sebelum implementasi.

**Perintah tes server** (WAJIB flag `-e`, CLAUDE.md; satu proses tes per DB tes):
`docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<Nama>` (catatan 2026-11-25: `-e` SETELAH `artisan test` diteruskan ke PHPUnit 12 dan ditolak "Unknown option -e"; semua flag env di `docker compose exec`)`

**Menunggu keputusan pemilik** (tugas bertanda ⏸ dikerjakan sesuai rencana, tetapi dapat dibalik bila pemilik menolak): PDF PO → ringkasan teks (D12), harga massal/rilis menu/BOM varian tidak di aplikasi (kontrak B5), QR massal ditunda (D17).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: bisa paralel (file berbeda, tanpa ketergantungan pada tugas yang belum selesai)
- **[Story]**: US1–US6 sesuai spec.md

## Path Conventions

- Server: `app/`, `routes/`, `database/`, `tests/Feature/`, `docs/openapi-pos-mvp.yaml`
- Web: `resources/js/`, `qa-tests/`
- Android: proyek Gradle `android-pos/`; modul baru `android-pos/manager/`. Paket Kotlin `com.warkop.manager` → `android-pos/manager/src/main/java/com/warkop/manager/` (disingkat **`M/`** di bawah); tes unit `android-pos/manager/src/test/java/com/warkop/manager/` (disingkat **`MT/`**); resource `android-pos/manager/src/main/res/` (disingkat **`MR/`**).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: modul Android baru dan dependensi; tanpa perubahan perilaku aplikasi kasir.

- [X] T001 Tambah `include(":manager")` di `android-pos/settings.gradle.kts`
- [X] T002 Tambah ke `android-pos/gradle/libs.versions.toml`: `androidx.navigation:navigation-compose`, `androidx.compose.material3:material3-window-size-class`, `androidx.compose.material3:material3-adaptive-navigation-suite`, `androidx.compose.material3.adaptive:adaptive`, `adaptive-layout`, `adaptive-navigation`, `androidx.lifecycle:lifecycle-runtime-compose`; pilih versi yang cocok dengan BOM `2024.09.00` dan buktikan dengan build (versi unverified di riset — catat versi terpilih di `specs/094-android-management-app/research.md` D2)
- [X] T003 Buat `android-pos/manager/build.gradle.kts` (aplikasi, `namespace`/`applicationId` `com.warkop.manager`, `minSdk 26`, `compileSdk/targetSdk 34`, Compose, kotlinx.serialization, `resValue app_build` sha+tanggal seperti `android-pos/app/build.gradle.kts`, dependensi `project(":core-network")`, `project(":core-domain")`)
- [X] T004 Buat `android-pos/manager/src/main/AndroidManifest.xml` (INTERNET, CAMERA opsional `required=false`, `usesCleartextTraffic` dengan komentar alasan instalasi lokal tanpa TLS, satu `MainActivity`, `windowSoftInputMode=adjustResize`)
- [X] T005 [P] Buat kerangka `M/ManagerApplication.kt`, `M/MainActivity.kt` (edge-to-edge, `setContent`) dan `M/AppContainer.kt` (wiring manual, pola `PosApplication`)
- [X] T006 Jadikan `ApiClient.create` generik: tambah `fun <T> create(service: Class<T>, baseUrl, context, log)` dan biarkan `create(...): PosApi` lama memanggilnya, di `android-pos/core-network/src/main/java/com/warkop/core/network/ApiClient.kt`
- [X] T007 Jalankan `./gradlew :app:testDebugUnitTest :core-network:test :manager:assembleDebug` dari `android-pos/` dan pastikan aplikasi kasir tetap hijau setelah T002/T006

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: gerbang peran di server, celah validasi klien-saja (prasyarat paritas), dan kerangka aplikasi (sesi, klien API, shell adaptif, formulir). ⚠️ Tidak ada layar modul sebelum fase ini selesai.

### Server: penanda peran & login manajer — tes dulu

- [X] T008 [P] Tulis `tests/Feature/RoleManagerAppFlagTest.php`: kolom bawaan false; migrasi mengisi true untuk Owner/Admin/Outlet Manager; `POST/PUT /roles` menerima dan `GET /roles` mengembalikan `manager_app_enabled`; non-boolean → 422; tanpa menu `roles` → 403; log aktivitas memuat nilai sebelum/sesudah
- [X] T009 [P] Tulis `tests/Feature/ManagerSessionTest.php`: 200 + token bernama `manager-app` untuk peran berpenanda; 401 seragam untuk username salah/kata sandi salah/akun nonaktif; 403 `manager_app_denied` (tanpa token tercipta); 403 `outlet_not_permitted` untuk outlet tak ditugaskan; `all_outlets` lolos semua outlet aktif; 422 outlet tidak ada/nonaktif; 429 berbagi hitungan dengan `POST /auth/login` (per akun+IP dan per akun)
- [X] T010 [P] Tulis `tests/Feature/ManagerAppAccessMiddlewareTest.php`: token `manager-app` ditolak 403 `manager_app_denied` dan dihapus setelah penanda peran dimatikan; token `pos-session` dan `pos-pin` pengguna yang sama TIDAK terpengaruh; token `manager-app` dengan penanda aktif tetap bisa memanggil `GET /auth/me`
- [X] T011 Jalankan tes T008–T010 dan pastikan GAGAL (merah) sebelum implementasi

### Server: implementasi

- [X] T012 Buat migrasi `database/migrations/2027_01_10_000001_add_manager_app_enabled_to_roles.php` (kolom boolean bawaan false; isi true untuk Owner/Admin sistem dan peran bernama `Outlet Manager`, tanpa beda huruf besar/kecil; `down()` menghapus kolom); periksa nama berkas lebih baru dari migrasi terakhir di `database/migrations/`
- [X] T013 Tambah `manager_app_enabled` ke `$fillable` dan `casts` boolean di `app/Models/Role.php`
- [X] T014 [P] Isi penanda di `database/seeders/DatabaseSeeder.php` (Owner/Admin — tidak perlu diubah: peran dibuat migrasi 2026_10_09 dan diisi migrasi 094), `database/seeders/WarkopPosStaffSeeder.php` (Outlet Manager true, lainnya false), `database/factories/UserFactory.php` (owner/admin true)
- [X] T015 Terima `manager_app_enabled: sometimes|boolean` di `app/Http/Requests/StoreRoleRequest.php` dan `app/Http/Requests/UpdateRoleRequest.php`; kirimkan di `app/Http/Resources/RoleResource.php`; pastikan log aktivitas perubahan peran di `app/Http/Controllers/Api/RoleController.php` memuat nilai sebelum/sesudah, di dalam transaksi yang sama
- [X] T016 Ekstrak logika kredensial + jatah percobaan dari `AuthController::login` ke `app/Services/CredentialLogin.php` (method mengembalikan `User` terautentikasi atau melempar respons 401/429 yang sama persis; komentar alasan 078 ikut pindah); `app/Http/Controllers/Api/AuthController.php::login` memanggilnya — perilaku web TIDAK berubah (jalankan tes login web yang ada, mis. `--filter=Auth`)
- [X] T017 Buat `app/Http/Requests/ManagerSessionRequest.php` (username required|max:50, password required, outlet_id required|integer|exists outlet aktif)
- [X] T018 Buat `app/Http/Controllers/Api/ManagerSessionController.php::store`: `CredentialLogin` → cek `role->manager_app_enabled` (403 `manager_app_denied`) → cek outlet diizinkan lewat mekanisme izin outlet yang ada (`OutletContext`/penugasan + `all_outlets`; 403 `outlet_not_permitted`) → perbarui `last_login_at` → `createToken('manager-app')` → `{token, user}` dengan bentuk `AuthController::userPayload` (jadikan payload itu satu sumber, jangan disalin)
- [X] T019 Buat `app/Http/Middleware/EnsureManagerAppAccess.php` (hanya bertindak untuk token bernama `manager-app`; hapus token + 403 `{code: manager_app_denied}`)
- [X] T020 Daftarkan `POST /auth/manager-sessions` (tanpa token, di samping `/auth/login`, tanpa `throttle:N,1`) dan pasang `EnsureManagerAppAccess` di grup `auth:sanctum` setelah `EnsureUserIsActive` di `routes/api.php`; JANGAN menambah panggilan `$middleware->alias()` kedua di `bootstrap/app.php`
- [X] T021 Jalankan T008–T010 sampai hijau, lalu tes login/peran yang sudah ada (`--filter=Auth`, `--filter=Role`)
- [X] T022 [P] Perbarui `docs/openapi-pos-mvp.yaml`: `POST /auth/manager-sessions` (200/401/403 dua kode/422/429) dan `manager_app_enabled` di skema Role + request store/update

### Web: sakelar formulir Peran

- [X] T023 [P] Tulis `qa-tests/unit/rolesManagerAppFlag.test.js`: formulir Peran memuat sakelar, nilai awal dari peran, payload simpan membawa `manager_app_enabled`
- [X] T024 Tambah sakelar "Boleh memakai aplikasi manajemen" (dengan teks bantuan) di formulir `resources/js/views/RolesView.vue` (form `{name, menu_keys, permissions, all_outlets, manager_app_enabled}`), label di `resources/js/i18n` locale id dan en, token desain tanpa hex
- [ ] T025 Jalankan `npm test -- rolesManagerAppFlag` dan verifikasi di peramban nyata (Docker app `:8010`): ubah penanda peran, simpan, muat ulang; konsol tanpa galat — catat di `specs/094-android-management-app/evidence.md`

### Server: celah validasi klien-saja (research D16, kontrak C) — prasyarat US3

- [X] T026 [P] Tulis tes C1 di `tests/Feature/ModifierLinkSingleSelectTest.php`: tautan grup pilihan tunggal dengan `max_select ≠ 1` atau `min_select > 1` → 422 per baris (`links.N.max_select`); grup multi tidak berubah. Tentukan dulu bagaimana "pilihan tunggal" didefinisikan dari `resources/js/views/products/modifierLinkRows.js:34-50` (atribut grup)
- [X] T027 [P] Tulis tes C2 (dilakukan sebagai metode baru di `tests/Feature/RecipeApiTest.php` — memakai setup yang ada) di `tests/Feature/RecipeRequiresBlocksTest.php`: resep tanpa blok atau blok tanpa baris → 422 (bentuk `blocks[]` dan bentuk lama `variant_ids`+`lines`); periksa dulu apakah `RecipeService::validate` sudah menolaknya — bila sudah, tes hanya mengunci perilaku
- [X] T028 [P] Tulis tes C3 (dilakukan sebagai metode baru di `tests/Feature/StockTransferTest.php`) di `tests/Feature/StockTransferWholeQtyTest.php`: baris `kind=variant` dengan `qty` pecahan → 422; `kind=material` pecahan tetap boleh
- [X] T029 Implementasi C1 di `app/Services/ModifierLinkService.php` (pesan Indonesia, kunci field sama dengan payload web)
- [X] T030 (tidak perlu — sudah ditegakkan `RecipeService::validate`) Implementasi C2 di `app/Http/Requests/StoreRecipeRequest.php`/`UpdateRecipeRequest.php` atau `app/Services/RecipeService.php` (satu tempat saja)
- [X] T031 (tidak perlu — sudah ditegakkan `StockTransferService::normalise`) Implementasi C3 di normalisasi baris `app/Services/StockTransferService.php` (atau service transfer yang ada; satu tempat)
- [X] T032 Jalankan T026–T028 sampai hijau + tes modifier/resep/transfer yang ada (`--filter=Modifier`, `--filter=Recipe`, `--filter=Transfer`); perbarui deskripsi aturan di `docs/openapi-pos-mvp.yaml`

### Android: kerangka bersama

- [X] T033 [P] Buat `M/data/SecureStore.kt` (AES-GCM + kunci Android Keystore, pola `android-pos/app/src/main/java/com/warkop/pos/data/TokenVault.kt`; jangan pernah log nilai)
- [X] T034 [P] Buat `M/data/ServerProfiles.kt` (profil LOCAL/ONLINE, satu aktif, URL dinormalkan lewat `ApiClient.normalise`; ganti profil aktif/URL → hapus sesi) sesuai data-model ServerProfile
- [X] T035 [P] Buat `M/data/ManagerApi.kt` (antarmuka Retrofit; mulai dengan endpoint B0 kontrak: branding, server-address, pos-setup/outlets, outlet branding, manager-sessions, auth/me, settings/features, logout) dan DTO di `M/data/dto/SessionDtos.kt`
- [X] T036 Buat `M/data/SessionRepository.kt` (login, `GET /auth/me` → `ManagerSession{menuKeys, permissions}`, logout, hapus sesi pada 401 / 403 `manager_app_denied`) dan `RequestContext` (token + `X-Outlet-Id` dari `SelectedOutlet`) di `M/AppContainer.kt`
- [X] T037 [P] Buat `M/domain/NavSpecs.kt` (satu daftar menu → kunci menu: dashboard, kitchen, dining_setup, categories, products, stock, vendors, materials, purchase_orders; grup Dashboard/Restoran/Inventori/Pembelian) dan `MT/domain/NavSpecsTest.kt` (menu tanpa kunci tidak tampil; tanpa kunci sama sekali → keadaan "Tidak ada menu")
- [X] T038 [P] Buat `M/domain/FormState.kt` (nilai, `fieldErrors` dari 422 per kunci field server, `conflict` 409, `forbidden` 403, `saving`; simpan di `SavedStateHandle`) memakai `ApiErrorMapper` dari `:core-network`, dan `MT/domain/FormStateTest.kt`
- [X] T039 [P] Buat `M/ui/theme/Theme.kt`, `Color.kt`, `Type.kt` (Material 3, warna dinamis Android 12+, cadangan warna merek dipetakan sekali dari token `resources/css/app.css`, mode gelap, Plus Jakarta Sans bila lisensi font sudah dipakai web)
- [X] T040 Buat shell adaptif `M/ui/shell/AppShell.kt` (`NavigationSuiteScaffold` dari NavSpecs + `ManagerSession.menuKeys`; pita mode DEMO/LIVE; pita "Luring" dari pemantau koneksi; menu akun: ganti outlet, ganti server, keluar) dan `M/ui/shell/ManagerNavHost.kt` (Navigation Compose)
- [X] T041 [P] Buat komponen bersama `M/ui/components/`: `ListScaffold.kt` (`ListDetailPaneScaffold`, tarik-untuk-segarkan, memuat/kosong/galat, muat bertahap dari amplop `{data, meta}`), `FormFields.kt` (teks/angka/uang/pilih/sakelar/tanggal dengan galat per field), `ConfirmDialog.kt`, `MoneyText.kt` (format dari `Money` `:core-domain`)
- [X] T042 [P] Buat `M/data/Paged.kt` (DTO amplop paginasi `{data, meta{current_page, per_page, total, last_page}}`) dan `M/data/Connectivity.kt` (status online/luring; blokir simpan saat luring, FR-031)
- [X] T043 Salin label yang dibutuhkan dari `resources/js/i18n` locale id ke `MR/values/strings.xml` (nama menu, tombol umum, pesan sambungan/login) — teks Indonesia — (catatan 2026-11-25: TIDAK dipakai; aplikasi kasir `:app` menulis teks Indonesia langsung di Compose, 0 `stringResource`, jadi manajer mengikuti pola itu)

**Checkpoint**: server menegakkan penanda; celah validasi ditutup; aplikasi bisa dibangun dan punya kerangka sesi/shell.

---

## Phase 3: User Story 1 - Sambung server, pilih outlet, login (Priority: P1) 🎯 MVP

**Goal**: pengguna mengatur alamat lokal/online, memilih outlet, dan login; peran tanpa penanda ditolak.

**Independent Test**: profil lokal → daftar outlet → login `admin` masuk Dashboard kosong; `kasir01` ditolak; ganti ke profil online dan ulangi (quickstart skenario 1–3).

- [X] T044 [P] [US1] Tulis `MT/ui/setup/LoginViewModelTest.kt` (200 → sesi tersimpan; 401 → pesan kredensial; 403 `manager_app_denied` → pesan akses tanpa sesi; 403 `outlet_not_permitted`; 429 → lama tunggu; 404 → "versi server belum mendukung aplikasi manajemen")
- [X] T045 [US1] Buat layar Pengaturan server `M/ui/setup/ServerSettingsScreen.kt` + ViewModel (dua profil, uji sambungan lewat `GET /pos-setup/branding`, "Ambil dari server" lewat `GET /server-address`, peringatan HTTP untuk profil Online, penanda build `app_build`)
- [X] T046 [US1] Buat layar pilih outlet `M/ui/setup/OutletPickerScreen.kt` + ViewModel (`GET /pos-setup/outlets`, logo/mode dari `/pos-setup/outlets/{id}/branding`; outlet terpilih disimpan per profil server)
- [X] T047 [US1] Buat layar login `M/ui/setup/LoginScreen.kt` + `LoginViewModel` (username/kata sandi, tampilkan nama outlet terpilih, tautan ganti outlet/server; kata sandi tidak pernah disimpan)
- [X] T048 [US1] Rangkai alur awal di `M/ui/shell/ManagerNavHost.kt`: tanpa profil → Pengaturan; tanpa outlet → pilih outlet; tanpa sesi → login; sesi ada → muat ulang `GET /auth/me` saat kembali ke depan, 401/403 → login dengan pesan (FR-005a)
- [X] T049 [US1] Tangani mode DEMO/LIVE: baca dari `GET /settings/features` setelah login; perubahan mode selama sesi → dialog dan muat ulang data (edge case spesifikasi) di `M/ui/shell/AppShell.kt`
- [ ] T050 [US1] Verifikasi di emulator ponsel terhadap server dev (quickstart 1–3, termasuk mematikan penanda peran di web saat sesi berjalan) dan catat di `specs/094-android-management-app/evidence.md`

**Checkpoint**: MVP gerbang akses berfungsi end-to-end.

---

## Phase 4: User Story 2 - Dashboard (Priority: P1)

**Goal**: angka Dashboard sama dengan web untuk outlet dan periode yang sama.

**Independent Test**: bandingkan tiap kartu dengan Dashboard web outlet yang sama (quickstart 1).

- [X] T051 [P] [US2] Tambah endpoint B1 (`/dashboard/summary`, `/attention`, `/shifts`, `/orders`, `/materials`) ke `M/data/ManagerApi.kt` dan DTO di `M/data/dto/DashboardDtos.kt` (uang sebagai string; kolom rupiah opsional)
- [X] T052 [US2] Buat `M/data/DashboardRepository.kt` dan `M/ui/dashboard/DashboardViewModel.kt` (filter `period` today|7d|30d; tidak menghitung ulang angka)
- [X] T053 [US2] Buat `M/ui/dashboard/DashboardScreen.kt` mencerminkan `resources/js/views/DashboardView.vue` (KPI, statistik bahan, perhatian, shift terbuka, pesanan terbaru; rupiah disembunyikan bila server tidak mengirim/`reports` tidak dipegang; kartu peringatan → Stok/Bahan dengan filter status; tarik-untuk-segarkan; tata letak kartu adaptif)
- [X] T054 [P] [US2] Tulis `MT/ui/dashboard/DashboardViewModelTest.kt` (rupiah tak tampil tanpa `reports`; ganti periode memuat ulang)
- [ ] T055 [US2] Verifikasi angka vs web (admin dan peran tanpa `reports`) di emulator; catat di `evidence.md`

---

## Phase 5: User Story 3 - Inventori: Kategori, Produk, Stok (Priority: P1)

**Goal**: kelola kategori, produk lengkap, dan stok dengan formulir identik web.

**Independent Test**: buat produk 2 varian + harga outlet + foto dari aplikasi → web menampilkan field identik; koreksi stok → riwayat web mencatatnya (quickstart 4–5).

**Prasyarat**: T026–T032 (celah validasi C1–C3) selesai.

### Kategori

- [X] T056 [P] [US3] Tambah endpoint B4 ke `M/data/ManagerApi.kt`, DTO `M/data/dto/CategoryDtos.kt`, `M/data/InventoryRepository.kt` (bagian kategori)
- [X] T057 [US3] Buat `M/ui/categories/CategoriesScreen.kt` (daftar/cari, detail) dan `CategoryFormScreen.kt` sesuai `resources/js/views/CategoriesView.vue` + `StoreCategoryRequest`: code 2 huruf dikirim huruf besar dan terkunci saat ubah, name ≤ 100, parent, display_order, is_active, station; foto jpeg/png ≤ 5 MB lewat Photo Picker/kamera; galat 409 hapus kategori terpakai ditampilkan

### Stok

- [X] T058 [P] [US3] Tambah endpoint B6 ke `M/data/ManagerApi.kt` dan DTO `M/data/dto/StockDtos.kt`
- [X] T059 [US3] Buat `M/ui/stock/StockScreen.kt` (saldo outlet terpilih, stok menipis, riwayat pergerakan) sesuai `resources/js/views/StockView.vue`
- [X] T060 [US3] Buat `M/ui/stock/StockAdjustScreen.kt` (alasan wajib ≤ 255, baris varian + delta bulat ≠ 0, saring baris nol seperti web, `outlet_id` = outlet terpilih)
- [X] T061 [US3] Buat `M/ui/stock/TransfersScreen.kt` + `NewTransferScreen.kt` + `ReceiveTransferScreen.kt` sesuai `resources/js/views/stock/TransfersView.vue` dan `NewTransferDrawer.vue` (dari/ke outlet, catatan ≤ 255, `client_ref` acak per percobaan, baris kind/item/qty, terima penuh/sebagian dengan alasan, batal; aksi disembunyikan tanpa izin `stock.transfer.dispatch|receive|cancel`)

### Produk

- [X] T062 [P] [US3] Tambah endpoint B5 ke `M/data/ManagerApi.kt` dan DTO `M/data/dto/ProductDtos.kt`, `RecipeDtos.kt`, `ModifierDtos.kt` (daftar tanpa `X-Outlet-Id` + `outlet_id[]` sesuai pola 070)
- [X] T063 [US3] Buat `M/ui/products/ProductsScreen.kt` (daftar, tab, filter, cari, angka kepala dari `/products/summary`) sesuai `resources/js/views/ProductsView.vue`
- [X] T064 [US3] Buat `M/ui/products/ProductDetailScreen.kt` sesuai `resources/js/views/ProductDetailView.vue` (info, varian, harga outlet, riwayat harga, modifier efektif + dilepas, resep, stok; aksi sold-out/discontinue/reactivate)
- [X] T065 [US3] Buat formulir produk berbagian `M/ui/products/form/ProductFormScreen.kt` (Info, Varian & harga, Stok, Modifier, Resep) dengan field dan aturan `StoreProductRequest`/`UpdateProductRequest` dan `resources/js/views/products/ProductFormDrawer.vue`: home outlet, outlet_ids (wajib saat buat), kategori, segmen 3 huruf, nama ≤ 150, short_name ≤ 24, tax_category, prep_minutes 0–600, stock_source, use_as_ingredient + ingredient_unit (trim, kosong → null, hanya tampil bila aktif), station, status Draft/Aktif hanya di ubah; varian (nama bawaan "Standard" bila satu varian — pastikan perilaku web dulu), harga jual/modal, low_stock_alert, track_stock; alasan stok wajib bila stok diubah dan dilewati bila `stock_source = modifier`
- [X] T066 [US3] Buat `M/domain/ProductSaveOrchestrator.kt` (urutan sama dengan `ProductFormDrawer.vue:455-580`: produk → varian → tautan modifier → foto → koreksi stok; catat `completedSteps`; ulangi hanya langkah yang gagal; mode ubah setelah produk tercipta) dan `MT/domain/ProductSaveOrchestratorTest.kt` (kegagalan di tiap langkah tidak mengulang langkah sukses; tidak ada POST produk kedua)
- [X] T067 [US3] Buat `M/ui/products/form/VariantOutletPricesSheet.kt` (`GET/PUT /variants/{id}/outlet-prices[/{outlet}]`, harga kosong = ikut dasar, catatan) dan `VariantPhotoPicker` (`POST /variants/{id}/image`)
- [X] T068 [US3] Buat `M/ui/products/form/ModifierLinksScreen.kt` sesuai `resources/js/components/product/ModifierLinksDrawer.vue` + `InheritedModifierList.vue` (tautan ≤ 30, wajib/min/max/urutan, lepas/pulihkan grup warisan; `excluded_group_ids` dikirim HANYA bila berubah; aturan pilihan tunggal tercermin — otoritatif di server C1)
- [X] T069 [US3] Buat `M/ui/products/form/RecipeScreen.kt` sesuai `resources/js/components/product/RecipeDrawer.vue` (nama ≤ 100 prefill dari produk, Portions/`yield` > 0, effective_from ≤ 30 hari lalu, catatan ≤ 500, blok dasar/bermodifier dari `/products/{id}/recipe-modifier-options`, baris bahan dari `/recipe-ingredients` — material atau produk bahan, jumlah bulat untuk produk bahan, susut 0–99,99; ≥ 1 blok dan ≥ 1 baris; tulis disembunyikan tanpa izin `menu.recipe.edit`; HPP dari server, tidak dihitung)
- [X] T070 [P] [US3] Tulis `MT/ui/products/ProductFormViewModelTest.kt` (422 dari tiap langkah dipetakan ke field/bagian yang benar; alasan stok wajib hanya saat stok berubah dan `stock_source = variant`)
- [X] T071 [US3] Buat daftar periksa paritas `specs/094-android-management-app/checklists/form-parity.md` untuk Kategori, Produk (semua bagian), Varian, Harga outlet, Tautan modifier, Resep, Koreksi stok, Pindah stok (field, bawaan, wajib, batas, pesan) dan isi dengan membandingkan layar Android vs web
- [ ] T072 [US3] Verifikasi quickstart 4–5 di emulator ponsel (buat produk 2 varian + harga outlet + foto kamera; koreksi stok; pindah stok) dan bandingkan di web; catat di `evidence.md`

**Checkpoint**: US1–US3 = MVP yang bisa dirilis.

---

## Phase 6: User Story 6 - Tata letak ponsel dan tablet (Priority: P2)

**Goal**: semua layar yang sudah ada bekerja di ponsel dan tablet, potret dan lanskap, gelap dan teks besar.

**Independent Test**: jalankan alur US3 di ponsel ≈6" dan tablet ≈10", potret/lanskap, putar layar di tengah isian (quickstart 9).

- [X] T073 [US6] Pastikan semua layar daftar memakai `ListDetailPaneScaffold` dari `M/ui/components/ListScaffold.kt` (dua panel di lebar Expanded) dan formulir panjang memakai dua kolom di lebar Expanded, satu kolom di Compact — (2026-11-25: `ListDetail` di Kategori/Stok/Produk; formulir Produk dua kolom saat EXPANDED)
- [ ] T074 [P] [US6] Tulis tes UI Compose `android-pos/manager/src/androidTest/java/com/warkop/manager/AdaptiveShellTest.kt` (lebar Compact → navigasi bawah; Expanded → rel navigasi + dua panel)
- [X] T075 [US6] Audit rotasi: isian semua formulir US1–US3 bertahan saat rotasi/proses dibuat ulang (`SavedStateHandle`), perbaiki yang hilang — (2026-11-25: audit kode — semua formulir lewat `SavedForm`; login sengaja tidak menyimpan kata sandi; uji rotasi di perangkat diserahkan ke pengembang)
- [ ] T076 [US6] Verifikasi mode gelap + ukuran teks 200% + tablet lanskap di emulator Pixel Tablet; catat tangkapan dan temuan di `evidence.md`

---

## Phase 7: User Story 4 - Pembelian: Vendor, Bahan, Purchase Order (Priority: P2)

**Goal**: kelola vendor, bahan (koreksi, riwayat, harga vendor), dan PO dengan alur status sama dengan web.

**Independent Test**: buat PO → ordered → received; saldo bahan bertambah di web (quickstart 6).

- [X] T077 [P] [US4] Tambah endpoint B7–B9 ke `M/data/ManagerApi.kt`, DTO `M/data/dto/VendorDtos.kt`, `MaterialDtos.kt`, `PurchaseOrderDtos.kt`, dan `M/data/PurchaseRepository.kt`
- [X] T078 [US4] Buat `M/ui/vendors/VendorsScreen.kt` + `VendorFormScreen.kt` (code alpha_dash ≤ 20 huruf besar, terkunci saat ubah; name ≤ 150; telepon ≤ 30; surel valid ≤ 100; catatan; aktif) sesuai `resources/js/views/VendorsView.vue`
- [X] T079 [US4] Buat `M/ui/materials/MaterialsScreen.kt` + `MaterialFormScreen.kt` sesuai `resources/js/components/masterData/MaterialsTable.vue` (code, name, satuan dari daftar preset `resources/js/utils/materialUnits.js` + "lainnya" — salin daftar ke `M/domain/MaterialUnits.kt`, kind, `standard_cost` hanya dikirim bila `semi_finished`, catatan, aktif; saldo = outlet terpilih tanpa jatuh ke total, pola `utils/materialStock.js`)
- [X] T080 [US4] Buat `M/ui/materials/MaterialAdjustSheet.kt` (qty ≠ 0 dalam ±1e9, alasan wajib ≤ 255, outlet terpilih) dan `MaterialLedgerScreen.kt` (`/materials/{id}/movements`, `/materials/movements`)
- [X] T081 [US4] Buat `M/ui/materials/VendorPricesSheet.kt` (`POST /materials/{id}/vendor-prices`, `PUT|DELETE /vendor-prices/{id}`; vendor unik per bahan, harga 0..999999999999,99, preferred, catatan)
- [X] T082 [US4] Buat `M/ui/purchaseorders/PurchaseOrdersScreen.kt` (filter status) + `PurchaseOrderFormScreen.kt` (vendor, outlet = outlet terpilih, catatan, baris material/jasa: material wajib untuk baris material, deskripsi ≤ 255 untuk jasa, qty ≥ 0,001, harga ≥ 0; baris terkunci setelah keluar draf) sesuai `resources/js/views/PurchaseOrdersView.vue`
- [X] T083 [US4] Buat `M/ui/purchaseorders/PurchaseOrderDetailScreen.kt` (isi dari `GET /purchase-orders/{id}/invoice`; transisi draft→ordered|cancelled, ordered→received|cancelled, received→paid dari server — tombol hanya untuk transisi sah; alasan batal opsional ≤ 255; pembayaran `POST /purchase-orders/{id}/payments` hanya saat received/paid; ⏸ tombol Bagikan ringkasan teks lewat lembar berbagi Android, tanpa PDF — research D12)
- [X] T084 [P] [US4] Tulis `MT/ui/purchaseorders/PurchaseOrderDetailViewModelTest.kt` (tombol transisi sesuai status; 409 transisi tidak sah ditampilkan) dan `MT/ui/materials/MaterialFormViewModelTest.kt` (`standard_cost` hanya terkirim untuk `semi_finished`)
- [X] T085 [US4] Tambah Vendor, Bahan, Koreksi bahan, Harga vendor, PO ke `specs/094-android-management-app/checklists/form-parity.md` dan isi
- [ ] T086 [US4] Verifikasi quickstart 6 di emulator; catat di `evidence.md`

---

## Phase 8: User Story 5 - Restoran: Dapur & Bar dan Pengaturan Restoran (Priority: P2)

**Goal**: pantau/ubah status tiket dapur; kelola meja, stasiun, grup modifier, QR, pindah outlet.

**Independent Test**: tiket preparing → ready terlihat di web ≤ 5 dtk; tambah meja muncul di web; pindah meja ber-order terbuka → 409 (quickstart 7–8).

- [X] T087 [P] [US5] Tambah endpoint B2–B3 ke `M/data/ManagerApi.kt`, DTO `M/data/dto/KitchenDtos.kt`, `DiningDtos.kt`, `ModifierGroupDtos.kt`, dan `M/data/RestaurantRepository.kt`
- [X] T088 [US5] Buat `M/ui/kitchen/KitchenScreen.kt` + ViewModel sesuai `resources/js/views/KitchenView.vue`/`StationBoard`/`TicketCard` (pilih stasiun, tiket + catatan order, polling `GET /stations/{id}/tickets?version=` tiap 5 dtk hanya saat layar tampil — pola `SelfServicePoller`; `PATCH /ticket-items/{id}/status` maju saja; new→preparing→ready hanya bila `kitchen`, ready→served bila `kitchen` atau `floor`; tombol tak berizin disembunyikan)
- [X] T089 [P] [US5] Tulis `MT/ui/kitchen/KitchenViewModelTest.kt` (polling berhenti saat tidak tampil; tombol berikut sesuai status dan izin)
- [X] T090 [US5] Buat `M/ui/dining/TablesScreen.kt` + `TableFormScreen.kt` sesuai `resources/js/components/restaurant/TablesAdmin.vue` (daftar tanpa header + `outlet_id[]`, dikelompokkan per `area` teks; name ≤ 60 trim, area ≤ 60, seats 1–99, display_order, is_active; `outlet_id` dikirim HANYA bila berubah; 409 `OutletMoveGuard` ditampilkan; putar QR `POST /dining-tables/{id}/qr/rotate` dengan konfirmasi; ⏸ QR massal tidak ada)
- [X] T091 [US5] Buat `M/ui/dining/StationsScreen.kt` + `StationFormScreen.kt` sesuai `StationsAdmin.vue` (name ≤ 60, display_order, is_active, pindah outlet bila berubah)
- [X] T092 [US5] Buat `M/ui/dining/ModifierGroupsScreen.kt` + `ModifierGroupFormScreen.kt` + `AttachSheet.kt` sesuai `resources/js/components/restaurant/ModifierGroupsAdmin.vue` (pilihan dengan harga, `min_qty/max_qty`, bahan per pilihan `options[].materials`, `portion_per_option`; Attach dengan kosong = "Tidak ada" dan ringkasan nama bila ≥ 2 dipilih, pola 091)
- [X] T093 [US5] Tambah Meja, Stasiun, Grup modifier, Attach ke `specs/094-android-management-app/checklists/form-parity.md` dan isi
- [ ] T094 [US5] Verifikasi quickstart 7–8 di emulator; catat di `evidence.md`

---

## Phase 9: Polish & Cross-Cutting Concerns

- [X] T095 [P] Tambah `Idempotency-Key` acak per percobaan simpan pada POST buat di `M/data/ManagerApi.kt` untuk rute yang sudah memakai middleware `idempotent`; daftar rute POST master data tanpa perlindungan ganda dan catat di `specs/094-android-management-app/evidence.md` sebagai temuan (jangan menambah middleware server tanpa keputusan terpisah)
- [X] T096 [P] Audit log: pastikan `RedactedLogInterceptor` dipakai dan tidak ada token/kata sandi/isi badan di Logcat (grep `Log.`/`Timber` di `android-pos/manager/`)
- [X] T097 [P] Pastikan peringatan profil Online HTTP dan uji "server lama" (404 login manajer) di `M/ui/setup/ServerSettingsScreen.kt`
- [ ] T098 Uji luring: matikan jaringan di tengah simpan untuk produk, koreksi stok, PO (quickstart 10); pastikan tanpa data ganda dan pita Luring tampil; catat di `evidence.md`
- [X] T099 [P] Tambah catatan bertanggal (2026-11-25, fitur 094) di `docs/PRD-POS-Event-Multivendor.md` (kemampuan baru pasca-MVP: aplikasi Android manajemen; Lantai & Tab tidak termasuk) dan di bagian Domain rules `CLAUDE.md` (penanda `manager_app_enabled`, `POST /auth/manager-sessions`, `EnsureManagerAppAccess`, `CredentialLogin`, modul `android-pos/manager/`)
- [X] T100 [P] Perbarui `docs/RUNBOOK.md` (membangun/memasang APK manajemen, mengaktifkan penanda peran) dan `android-pos/quickstart.md`
- [ ] T101 Jalankan suite penuh server (perintah aman `-e`), `npm test`, `./gradlew :app:testDebugUnitTest :core-network:test :manager:testDebugUnitTest :manager:assembleDebug`; catat hasil (termasuk kegagalan yang sudah ada sebelumnya) di `evidence.md`
- [X] T102 Tinjauan keamanan diff server (kontrol akses login manajer + middleware + celah validasi) dan tandai di `evidence.md` apa yang belum diverifikasi di perangkat nyata

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (P1)** → **Foundational (P2)** → semua user story.
- Di dalam Foundational: T008–T011 (tes) → T012–T021 (server) → T022; T023–T025 (web) setelah T015; T026–T032 (celah validasi) independen dari penanda peran; T033–T043 (Android kerangka) setelah T006, dan T035/T036 setelah T020 untuk uji nyata.
- **US1** setelah Foundational. **US2, US3, US4, US5** masing-masing hanya butuh US1 (sesi) dan bisa paralel antar-pengembang.
- **US3** juga butuh T026–T032.
- **US6** paling baik setelah US3 (ada layar untuk diaudit) dan diulang ringan untuk US4/US5 di tugas verifikasinya.
- **Polish** setelah story yang dirilis.

### User Story Dependencies

```text
Setup → Foundational ─┬─ US1 ─┬─ US2
                      │       ├─ US3 ─ US6
                      │       ├─ US4
                      │       └─ US5
                      └─ (C1–C3) → US3
```

### Within Each User Story

DTO/endpoint → repository → ViewModel → layar → tes unit → daftar periksa paritas → verifikasi emulator.

## Parallel Example

```text
# Foundational, tes server bersamaan:
T008 RoleManagerAppFlagTest   T009 ManagerSessionTest   T010 ManagerAppAccessMiddlewareTest
T026 C1 test                  T027 C2 test              T028 C3 test

# Foundational, kerangka Android bersamaan:
T033 SecureStore  T034 ServerProfiles  T035 ManagerApi  T037 NavSpecs  T038 FormState  T039 Theme  T041 komponen  T042 Paged/Connectivity

# Setelah US1, satu pengembang per story:
US2 (T051–T055)   US3 (T056–T072)   US4 (T077–T086)   US5 (T087–T094)
```

## Implementation Strategy

### MVP First

1. Setup + Foundational (gerbang peran, celah validasi, kerangka).
2. US1 → verifikasi akses end-to-end. **STOP & VALIDATE.**
3. US2 (Dashboard) → rilis internal pertama untuk pemantauan.
4. US3 (Inventori) → MVP penuh untuk manajer.

### Incremental Delivery

5. US6 audit adaptif atas layar US1–US3.
6. US4 (Pembelian), lalu US5 (Restoran), masing-masing dengan daftar periksa paritas dan bukti emulator.
7. Polish + catatan dokumentasi.

### Notes

- Tidak ada push/PR tanpa izin eksplisit per kejadian.
- Commit per kelompok logis, pesan bahasa Indonesia; OpenAPI ikut commit perubahan rute.
- Yang belum diverifikasi di perangkat nyata ditandai jelas di `evidence.md`.
