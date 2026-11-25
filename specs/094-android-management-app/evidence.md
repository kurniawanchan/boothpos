# Bukti Verifikasi: Aplikasi Android Manajemen (094)

**Klasifikasi**: INTERNAL

Semua angka dari sesi kerja 2026-11-25. Yang belum diverifikasi ditandai **BELUM**.

## Setup (T001–T007)

- `./gradlew :core-network:test :app:testDebugUnitTest :manager:assembleDebug` → lulus (EXIT 0); `manager-debug.apk` 18 MB.
- Versi terbukti tanpa menaikkan BOM `2024.09.00`: `navigation-compose 2.8.2`, `material3.adaptive:* 1.0.0`, `material3-adaptive-navigation-suite` dan `material3-window-size-class` dari BOM.
- Catatan lingkungan: build pertama gagal "No space left on device" (124 MB tersisa). Atas pilihan pengembang, `docker builder prune` membebaskan 4,7 GB (cache build saja; kontainer, image terpakai, dan volume tidak disentuh).

## Server: penanda peran & login manajer (T008–T022)

Perintah tes (flag env di `docker compose exec`; `-e` setelah `artisan test` ditolak PHPUnit 12 — "Unknown option -e"):

```
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=...
```

- Sebelum implementasi: 15 dari 16 tes baru GAGAL (kolom tidak ada); 1 lulus (403 kasir — perilaku lama).
- Sesudah: `RoleManagerAppFlagTest|ManagerSessionTest|ManagerAppAccessMiddlewareTest` → **16 lulus (62 asersi)**.
- Regresi `--filter='Auth|Login|Role|Pin|Throttle'` → **240 lulus, 1 gagal**: `SelfServiceQrTokenTest::test_a_freshly_issued_token_opens_the_right_table_without_login` (`data.available` false karena tidak ada kanal QRIS aktif di data tes; rute publik tanpa auth, tidak menyentuh login/peran). ASUMSI: kegagalan lama (riwayat proyek mencatat 2 kegagalan lama); belum dibuktikan dengan menjalankan tes di commit sebelum 094.
- `docs/openapi-pos-mvp.yaml` diperbarui; YAML valid (`yaml.safe_load`).

### Migrasi di DB dev

`migrate:status` hanya menunjukkan migrasi 094 tertunda; dijalankan. Hasil penanda: Owner 1, Admin 1, Outlet Manager 1; Kasir, Inventory, Waiter, Supervisor 0.

### Uji API nyata (server dev `:8010`)

| Akun | Outlet | Hasil |
|---|---|---|
| admin | 10 | 200, token terbit (peran Admin, 23 kunci menu) |
| sari (Supervisor) | 9 | 403 `manager_app_denied` |
| budi (Outlet Manager) | 9, 10 | 403 `outlet_not_permitted` (budi hanya ditugaskan ke outlet DEMO WJ3; mode dev saat itu memakai outlet 9/10) |

Token uji dihapus setelahnya. Token dan kata sandi tidak dicetak.

## Web: sakelar formulir Peran (T023–T025)

- `npm test -- qa-tests/component/RolesView qa-tests/component/UsersView` → **36 lulus, 2 dilewati** (dilewati = `it.skip` lama).
- `RolesView.test.js` diperbarui: payload simpan kini selalu membawa `manager_app_enabled` (perubahan kontrak yang disengaja).
- **BELUM**: verifikasi di peramban nyata (MCP Playwright/Chrome DevTools tidak tersambung di sesi ini).

## Celah validasi klien-saja (T026–T032)

- C1 (grup pilihan tunggal): 2 tes MERAH sebelum perubahan (server menerima `max_select` 2/null), lalu `ModifierLinkService::validate` menolak 422 `links.N.max_select`.
- C2 (resep tanpa blok/baris) dan C3 (jumlah pindah stok produk bulat): sudah ditegakkan server; tes pengunci lulus tanpa perubahan kode.
- `ModifierLinkSingleSelectTest|StockTransferTest` → 23 lulus; regresi `--filter='Modifier|Recipe|Transfer|MenuRelease|MenuChange|Import'` → **448 lulus (2408 asersi)**.

## Android: US1–US3 (sesi 2026-11-25 sore)

- `./gradlew :manager:assembleDebug :manager:testDebugUnitTest` → **EXIT 0, 35 tes lulus** (SessionRules 4, FormStatus 3, NavSpecs 4, LoginViewModel 4, DashboardViewModel 7, CategoryForm 6, StockForms 7).
- Selesai di kode: login/alur awal (T044–T049), Dashboard (T051–T054), komponen bersama (T041), Kategori (T056–T057), Stok/koreksi/transfer (T058–T061).
- Produk (T062–T067, T070): `:app` 537, `:core-network` 88 (termasuk `NoOutletHeaderTest` baru), `:manager` 50 tes — semua lulus.
- **BELUM**: semua verifikasi emulator (T050, T055, T072) — layar belum pernah dijalankan terhadap server dev.
- Keputusan desain yang menyimpang dari web:
  - Transfer baru: outlet ASAL dikunci ke outlet terpilih. `OutletContext::isEntitled` bagi pengguna bertugas hanya mengakui outlet di header `X-Outlet-Id`; asal bebas akan berujung 403 `outlet_forbidden` setelah formulir diisi. Menerima transfer = pilih outlet tujuan dulu (layar menjelaskannya bila `can.receive` false).
  - Kartu bahan/stok Dashboard membuka layar Bahan/Stok aplikasi (web membuka Laporan → Bahan, yang tidak ada di aplikasi); filter status belum terbawa.
  - Simpan produk: web TIDAK mengulang langkah gagal (toast lalu laci tertutup). Aplikasi mencatat kiriman sukses per langkah (`ProductForm.sent`), jadi Simpan ulang hanya menjalankan yang gagal/berubah dan tidak pernah `POST /products` kedua. Varian yang dinonaktifkan saat BUAT memakai `PUT /variants/{id}` per baris (web: `PUT /products/{id}/variants` sekaligus).
  - `@NoOutletHeader` ditambahkan di `:core-network` (setara `skipOutletHeader` web) untuk `GET /products` dan `/products/summary`.
  - Pilihan "Outlet pemilik" memakai `/me/scope` (outlet yang boleh dilihat), web memakai `GET /outlets?per_page=100`.
  - Dashboard TANPA penyegaran otomatis (keputusan pengembang 2026-11-25; web: 30 dtk) — diperbarui saat dibuka dan lewat tarik-untuk-segarkan.

## Uji perangkat — HP fisik (2026-11-25 malam)

- Perangkat: Xiaomi 22021211RG, Android 14, 1080×2400. Server dev `:8010` lewat `adb reverse tcp:8010 tcp:8010`. Emulator tidak bisa dipakai: disk Mac tidak cukup untuk AVD API 37.
- Pemasangan: MIUI menolak `adb install` (`INSTALL_FAILED_USER_RESTRICTED`) sampai "Install via USB" aktif dan kabel dicabut-pasang. Injeksi ketukan (`input tap`) tetap ditolak `INJECT_EVENTS`, jadi navigasi dilakukan pengembang. Agen hanya memotret layar dan membaca log `ManagerApi` (metode + path + status).
- **Terbukti di perangkat (log, semua 200):**
  - alur login: `pos-setup/outlets` → branding outlet → `POST /auth/manager-sessions` → `settings/features` → `auth/me` → Dashboard;
  - Dashboard (5 wilayah), Kategori (daftar + `PUT /categories/89` oleh pengembang), Produk (daftar, ringkasan, detail, riwayat harga, formulir ubah);
  - `GET /modifier-groups`, `GET /variants/{id}/outlet-prices`, `GET /stock/movements`;
  - tidak ada crash (`logcat -b crash` kosong).
- **BUG YANG DITEMUKAN & DIPERBAIKI:** panel "Perlu tindakan" selalu "gangguan di server" walau `/dashboard/attention` membalas 200. Penyebabnya `cash_difference` mengirim `"link":{"query":[]}`: larik PHP kosong menjadi array JSON, sedangkan DTO mengharapkan objek. `AttentionLinkDto.query` kini `JsonElement`. Tes regresi `ServerJsonShapesTest` memakai keluaran `AttentionService::items` asli DB dev. Setelah pasang ulang, panel tampil empat butir "Aman" (tangkapan layar diambil).
- **BELUM (diserahkan ke pengecekan manual pengembang):** quickstart 4–6 (buat produk 2 varian + foto kamera, koreksi stok, transfer), uji rotasi, lanskap/tablet, mode gelap, dan menonaktifkan penanda peran saat sesi berjalan.

## Temuan

1. **Pesan validasi tak diterjemahkan** pada rute tanpa token: `POST /auth/login` dan `/auth/manager-sessions` mengembalikan `validation.required` mentah karena `lang/id/validation.php` tidak ada. Sudah ada sebelum 094. Aplikasi Android perlu teks cadangan per field bila pesan berupa kunci.
2. **Perintah tes di CLAUDE.md** (catatan T066 fitur 048) meletakkan `-e CACHE_STORE=array -e SESSION_DRIVER=array` setelah `artisan test`; dengan PHPUnit 12 perintah itu gagal sebelum tes berjalan. Bentuk yang benar ada di atas. CLAUDE.md belum diubah (menunggu pengembang).
3. **Label tipe pergerakan transfer tidak ada di web**: `utils/stockMovements.js` dan `master_data.type_*` tidak memuat tipe transfer (`transfer_out` dst.), jadi web menampilkan kunci mentah. Aplikasi menampilkan nama tipe mentah. Perlu label bersama.
4. **`Response<Unit>` Retrofit tidak melempar 4xx**: di aplikasi manajer semua aksi tanpa badan lewat `apiUnit` (`data/ApiCalls.kt`); `:app` mengulang pemeriksaan `isSuccessful` per pemanggilan.
5. **Filter kualitas `no_category` di web** (ProductsView.vue:61) tidak diterima server (`ProductCatalogRequest` hanya `no_station|no_price|no_cost`) — seharusnya 422. Belum diuji di peramban. Aplikasi tidak menawarkan filter kualitas.
6. **Tipe kolom tanpa cast** (`prep_minutes`, `low_stock_alert`) bergantung driver PDO; aplikasi memakai `FlexIntSerializer` agar angka atau string sama-sama diterima.
7. **Pembayaran PO Transfer/QRIS di web selalu gagal 422**: `PurchaseOrderDetailModal.vue` hanya mengirim `{method, amount, notes}`, sedangkan `PaymentRecorder` mewajibkan `channel_id` dan `proof_token` untuk metode non-tunai. Aplikasi hanya menawarkan Tunai.
8. **Pembayaran PO berlebih tidak dicek server**: begitu total dibayar ≥ total PO dan status received, PO otomatis menjadi paid.
9. **US4 (T077–T085)**: `:manager` 68 tes lulus, APK terbangun; belum diuji di perangkat (T086).
10. **US5 (T087–T093)**: `:manager` 74 tes lulus, APK terbangun; belum diuji di perangkat (T094). Keputusan:
    - QR meja ditampilkan sebagai alamat + Bagikan (server tidak mengirim gambar; menambah pustaka QR ditunda karena disk build hampir penuh).
    - KDS menampilkan `order_note` (web KDS tidak).
    - Polling 5 dtk hanya saat layar tampil.

## Penutup (T095–T097)

- **T095 — idempotensi:** rute yang memakai `idempotent` hanya `sessions/{id}/close`, `cash-movements`, `tabs/{id}/send`, `tabs/{id}/items` (POST/PATCH), dan `reports/materials/purchase-drafts`. Tidak satu pun dipakai aplikasi manajer, jadi header `Idempotency-Key` tidak ditambahkan (tidak berpengaruh).
  - **Temuan** — POST buat TANPA perlindungan ganda: `categories`, `products`, `products/{id}/variants`, `stock/adjustments`, `materials`, `materials/{id}/adjustments`, `materials/{id}/vendor-prices`, `vendors`, `purchase-orders`, `purchase-orders/{id}/payments`, `dining-tables`, `stations`, `modifier-groups`, `products/{id}/recipes`.
  - Risiko: bila respons hilang SETELAH server menyimpan (timeout), Simpan ulang membuat baris ganda. Paling berisiko: pembayaran PO dan koreksi stok. `stock/transfers` aman (`client_ref`).
  - Di sisi klien, simpan produk tidak pernah mengirim `POST /products` kedua SETELAH respons sukses diterima (T066), tetapi timeout tetap berisiko.
  - Middleware server TIDAK ditambahkan; butuh keputusan terpisah.
- **T096 — log:** satu-satunya `Log.d` di `android-pos/manager` ada di `AppContainer.kt:39`. Hanya aktif saat build `debuggable` dan dilewatkan `RedactedLogInterceptor` (metode + path + status; tanpa header, token, kata sandi, atau badan). Log uji perangkat (lihat atas) memperlihatkan format itu.
- **T097:** peringatan HTTP profil Online ada di Pengaturan server (`SetupScreens.kt:106`) dan layar login (`:184`). Server lama (404 login manajer) → "Versi server belum mendukung aplikasi manajemen" dikunci `LoginViewModelTest`.
- **T098 — uji luring:** belum. Butuh perangkat; diserahkan ke pengecekan manual.

## T101 — suite tes (2026-11-25 malam)

- Android: `./gradlew :app:testDebugUnitTest :core-network:testDebugUnitTest :manager:testDebugUnitTest :manager:assembleDebug` → **EXIT 0**; `:app` 537, `:core-network` 88, `:manager` 74 tes; semua lulus.
- Web: `npx vitest run` → **255 berkas, 2163 lulus, 2 dilewati, 0 gagal**, tetapi exit 1 karena **1 unhandled rejection** dari `qa-tests/component/RestaurantSetupOutletFilter.test.js` (selalu muncul, 3/3 percobaan terpisah).
  - Penyebab: tes tidak memalsukan API bahan yang dimuat `ModifierGroupsAdmin` sejak 087, sehingga panggilan sungguhan ditolak.
  - **Sudah ada sebelum 094**: berkas tes terakhir diubah di 090, dan 094 tidak menyentuh kode restoran web. Belum diperbaiki (di luar lingkup).
- Server: **BELUM dijalankan** — Docker daemon mati saat fase penutup (socket tidak ada). Perintah aman ada di bagian atas berkas ini. Suite regresi terfilter sebelumnya (T032): 448 lulus.

## T102 — tinjauan keamanan diff server (2026-11-25)

Ditinjau agen peninjau terpisah (statis, tanpa menjalankan apa pun). Tiga klaim utama diperiksa ulang di kode oleh pelaksana.
**Tidak ada temuan Critical/High.** Login yang ditolak tidak pernah membuat token; perilaku `POST /auth/login` web tidak berubah; jatah percobaan berbagi kunci yang sama dengan web; middleware tidak bisa dilewati (nama token hanya ditetapkan server).

- **M1 (terbukti)** — token `manager-app` tanpa kedaluwarsa (`ManagerSessionController.php:49`, `config/sanctum.php:53` `expiration => null`) dengan abilities `*`. Setara token web `pos-session`, tetapi lebih lemah dari token PIN tablet (`PinAuthService.php:294` memakai `$expires`). HP hilang = sesi hidup sampai logout, penanda dimatikan, atau akun dinonaktifkan. **Perlu keputusan:** TTL/sliding window.
- **L1 (terbukti)** — mematikan penanda hanya menghapus token pada permintaan berikutnya dari perangkat itu (`EnsureManagerAppAccess.php:22`). Mati → nyala lagi tanpa permintaan di antaranya = token lama berlaku lagi. Saran: hapus semua token `manager-app` pengguna peran itu di `RoleController::update` saat penanda berubah true→false (dalam transaksi yang sama).
- **L2** — siapa pun pemegang menu `roles` bisa menyalakan penanda untuk peran mana pun (tidak ada penjaga setara `guardAllOutlets`). Bukan eskalasi hak: token = hak API yang sama dengan sesi web; tercatat di log aktivitas.
- **L3** — perbedaan waktu `Hash::check` untuk username tak dikenal (temuan lama 078), kini juga di endpoint kedua. Saran: hash tiruan.
- **L4** — 422 `outlet_id` sebelum kredensial dicek bisa dipakai menebak id outlet aktif. Info sudah publik lewat `GET /pos-setup/outlets`.
- **Info: regresi fungsional C1 (terbukti di kode)** — `ModifierLinkService.php:129` kini menolak tautan grup pilihan tunggal dengan `max_select` null/>1 atau min > 1. Bila baris seperti itu SUDAH tersimpan (mis. lewat impor 090 atau sebelum aturan ini), setiap `PUT /products/{id}/modifier-links` berikutnya untuk produk itu gagal 422 (daftar tautan dikirim utuh), termasuk dari web, dan rilis menu yang sudah antre lewat `ModifierLinkApplier` bisa gagal saat terbit. **Belum dicek di DB** (Docker mati). Saran: kueri pemeriksa + migrasi data (max = 1, min = min(min, 1)) sebelum rilis.
- **Info: migrasi penanda** menyalakan peran bernama "outlet manager" (tanpa beda huruf besar/kecil); peran buatan pelanggan dengan nama itu ikut mendapat akses.


- **Keputusan pengembang 2026-11-25:** M1, L1, dan pemeriksaan data C1 DITUNDA ("ignore untuk sekarang"). Belum ada perubahan kode untuk ketiganya.
