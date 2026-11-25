# Research: Aplikasi Android Manajemen (094)

**Klasifikasi**: INTERNAL

Setiap keputusan: **Keputusan**, **Alasan**, **Alternatif yang ditolak**. Label ASUMSI = belum dibuktikan dari kode atau dokumen.

---

## D1 — Letak kode: modul aplikasi kedua di proyek Gradle `android-pos/`

**Keputusan**: Aplikasi manajemen adalah modul aplikasi baru `:manager` di proyek Gradle `android-pos/` yang sudah ada (`applicationId` berbeda: `com.warkop.manager`), berbagi `:core-domain` (`Money`, `ApiError`) dan `:core-network` (`ApiClient`, `ApiErrorMapper`, `RedactedLogInterceptor`, `Retry`). Antarmuka Retrofit `ManagerApi` dan DTO-nya diletakkan di dalam `:manager` sendiri (bukan modul jaringan ketiga); yang dibagi hanya kode yang memang sudah generik.

**Alasan**:
- Fakta: `:core-network` sudah memisahkan klien HTTP (header token, `X-Outlet-Id`, log tersamarkan, batas waktu) dari aplikasi kasir. Memakainya ulang memenuhi Konstitusi I (satu implementasi per kepedulian): perilaku log tanpa header/badan dan pemetaan galat 401/403/409/422 tidak disalin.
- Satu katalog versi (`gradle/libs.versions.toml`) = satu set versi Compose/Kotlin untuk dua aplikasi.
- Fakta: `ApiClient.create()` saat ini mengembalikan `PosApi`. Perubahan kecil: `create<T>(service: Class<T>)` generik, `create(...)` lama tetap ada dan memanggilnya (aplikasi kasir tidak berubah perilaku).

**Alternatif yang ditolak**:
- Proyek Gradle terpisah `android-manager/`: menyalin `:core-network` (dua salinan log tersamarkan dan pemetaan galat akan menyimpang).
- Menambah layar manajemen ke aplikasi kasir: spesifikasi meminta aplikasi terpisah; aplikasi kasir login PIN dan terikat terminal/shift, model sesi berbeda.

---

## D2 — Tumpukan UI: Compose + Material 3 adaptif

**Keputusan**: Jetpack Compose + Material 3 (versi dari BOM yang sudah dipakai), ditambah pustaka adaptif Material 3: `NavigationSuiteScaffold` (navigasi bawah di ponsel, rel/laci di tablet otomatis) dan `ListDetailPaneScaffold` (daftar-detail berdampingan di layar lebar). Kelas ukuran jendela menentukan tata letak, bukan jenis perangkat. Tema dinamis (Material You) di Android 12+, skema warna merek sebagai cadangan; mode gelap; edge-to-edge.

**Alasan**: Inilah "UI/UX Android terkini" yang dipakai Google untuk aplikasi adaptif ponsel+tablet, dan cocok dengan FR-028/FR-029 tanpa menulis dua tata letak.

**Versi terbukti (T002/T007, 2026-11-25)**: `navigation-compose 2.8.2`, `material3.adaptive:adaptive|adaptive-layout|adaptive-navigation 1.0.0`, `material3-adaptive-navigation-suite` + `material3-window-size-class` dari BOM `2024.09.00` — `:manager:assembleDebug` lulus dan tes `:app`/`:core-network` tetap hijau tanpa menaikkan BOM.

**Catatan versi (asal)**: nomor versi artefak adaptif (`androidx.compose.material3:material3-adaptive-navigation-suite`, `androidx.compose.material3.adaptive:adaptive-layout`/`adaptive-navigation`) yang cocok dengan BOM `2024.09.00` **unverified** — dipastikan saat T-setup dengan build sungguhan. Bila butuh menaikkan BOM, aplikasi kasir ikut diuji ulang (tes unit + build) dalam commit yang sama. Gaya "Material 3 Expressive" TIDAK dipakai (ASUMSI: masih alfa pada versi yang tersedia; unverified).

**Alternatif yang ditolak**: Views/XML (bukan arah UI Android terkini, dan aplikasi kasir sudah Compose); Flutter/RN (tumpukan kedua untuk tim).

---

## D3 — Arsitektur aplikasi: satu Activity, ViewModel + StateFlow, tanpa Hilt

**Keputusan**: Satu Activity, Navigation Compose, ViewModel per layar dengan `StateFlow<UiState>`, repositori tipis per modul (Inventori, Pembelian, Restoran, Dashboard, Sesi) di atas `ManagerApi`. Wiring dependensi manual lewat satu `AppContainer` (pola aplikasi kasir: `PosApplication`).

**Alasan**: Fakta: aplikasi kasir tidak memakai Hilt walaupun ada di katalog. Konstitusi I melarang abstraksi spekulatif; layar CRUD ini tidak butuh DI framework. Menyamakan pola memudahkan pemeliharaan dua aplikasi.

**Alternatif yang ditolak**: Hilt (plugin + kapt/ksp tambahan untuk ±25 layar sederhana); MVI framework pihak ketiga.

---

## D4 — Gerbang peran: penanda `roles.manager_app_enabled`, ditegakkan per token

**Keputusan** (FR-005, FR-005a; jawaban Q1 = C):
1. Kolom baru `roles.manager_app_enabled` (boolean, bawaan `false`). Migrasi mengisi `true` untuk peran sistem Owner dan Admin (`is_system_default` + nama) dan peran bernama `Outlet Manager`.
2. Endpoint login baru `POST /auth/manager-sessions` {username, password, outlet_id}. (Fakta: web juga memakai token Bearer Sanctum dari `POST /auth/login`, bukan cookie; tidak ada middleware `menu:xxx` — izin tetap di FormRequest/Policy/controller, jadi semua endpoint web langsung berlaku untuk token aplikasi.) Ia memakai logika kredensial + jatah percobaan yang SAMA dengan `POST /auth/login` (diekstrak ke satu service `CredentialLogin`; `AuthController::login` memanggilnya juga — satu implementasi, Konstitusi I). Setelah kredensial benar: tolak 403 `manager_app_denied` bila peran tidak memegang penanda; tolak 403 `outlet_not_permitted` bila `outlet_id` di luar outlet yang diizinkan pengguna. Token dibuat bernama `manager-app`.
3. Middleware `EnsureManagerAppAccess` di grup `auth:sanctum`: bila token saat ini bernama `manager-app` dan peran pengguna tidak (lagi) memegang penanda → 403 `manager_app_denied` dan token dihapus. Token web/kasir tidak terpengaruh.
4. Formulir Peran web: satu sakelar baru; `StoreRoleRequest`/`UpdateRoleRequest` menerima `manager_app_enabled` (boolean); `RoleResource` mengirimnya; log aktivitas perubahan peran mencatatnya.

**Alasan**: Penegakan di server (Konstitusi IV, FR-006). Memeriksa per permintaan (bukan hanya saat login) memenuhi FR-005a tanpa mencabut token secara massal. `outlet_id` di body login hanya dipakai untuk **menyempitkan/memverifikasi**, tidak pernah untuk menulis data — konsisten dengan Konstitusi IV ("permintaan hanya boleh menyempitkan"); sesudah login outlet dikirim lewat header `X-Outlet-Id` seperti klien lain.

**Catatan implementasi**: `$middleware->alias()` MENGGANTI seluruh peta (CLAUDE.md 090). Middleware baru dipasang lewat `appendToGroup`/daftar middleware rute `auth:sanctum`, bukan panggilan `alias()` kedua.

**Alternatif yang ditolak**: (a) nama peran tetap — rapuh saat peran diganti nama; (b) turunan dari kunci menu — peran Supervisor dengan menu Floor saja ikut lolos; abilities Sanctum per token saja — tidak bisa mencabut akses saat penanda dimatikan tanpa menyapu token.

---

## D5 — Server lokal dan online di perangkat

**Keputusan**: Aplikasi menyimpan dua profil alamat (Lokal, Online) + profil aktif di penyimpanan terenkripsi (pola `TokenVault`: AES-GCM dengan kunci Android Keystore). "Uji sambungan" memanggil `GET /pos-setup/branding` (tanpa token, sudah ada) untuk memastikan server BoothPOS dan membaca mode. Opsional "Ambil dari server": `GET /server-address` (sudah ada, fitur 065) mengisi alamat pilihan admin. Mengganti profil aktif menghapus token sesi.

**Alasan**: Fakta: server sudah menyimpan pasangan alamat lokal/online (`/settings/server-address`) dan memberikan alamat terpilih tanpa token. Kita memakai ulang, tidak membuat endpoint baru. Peringatan HTTP tanpa TLS untuk profil Online (edge case spesifikasi) ditampilkan di aplikasi; lalu lintas cleartext diizinkan hanya karena instalasi lokal umumnya tanpa TLS (sama dengan aplikasi kasir).

**Alternatif yang ditolak**: satu kolom alamat saja (spesifikasi meminta lokal + online); penemuan otomatis mDNS (cakupan tambahan).

---

## D6 — Pemilihan outlet sebelum login

**Keputusan**: Pakai `GET /pos-setup/outlets` (tanpa token, pengecualian Konstitusi IV yang sudah dicatat 073: hanya nama/kode). Logo dan mode per outlet dari `GET /pos-setup/outlets/{outlet}/branding` (sudah ada, 079). Tidak ada endpoint publik baru.

**Alasan**: Memenuhi FR-003 tanpa memperluas permukaan publik.

---

## D7 — Paritas formulir dengan web

**Keputusan**:
1. Validasi otoritatif = FormRequest server. Aplikasi menampilkan galat 422 per field dari server (kunci field sama dengan web).
2. Aplikasi mencerminkan validasi sisi-klien web hanya untuk umpan balik cepat, dan HANYA bila aturan yang sama ada di server.
3. Setiap aturan yang hari ini **hanya** ada di sisi klien Vue (lihat inventori di `contracts/manager-api.md` §Celah validasi) dipindah/ditambahkan ke FormRequest dulu, dengan tes `tests/Feature/`. Ini prasyarat paritas: tanpa itu aplikasi Android bisa menyimpan data yang web tolak.
4. Daftar periksa paritas per formulir (`checklists/form-parity.md`, dibuat di fase tugas) membandingkan field, bawaan, wajib, batas, dan pesan.
5. Teks label dan pesan berasal dari sumber yang sama: pesan galat datang dari server (sudah berbahasa Indonesia); label disalin dari `resources/js/i18n` locale `id` ke `strings.xml` aplikasi.

**Alasan**: SC-002 dan FR-014; Konstitusi IV (server satu-satunya penentu).

**Alternatif yang ditolak**: membangkitkan formulir dari skema dinamis server (endpoint skema baru + mesin formulir; berlebihan, Konstitusi I).

---

## D8 — Tanpa logika bisnis di klien

**Keputusan**: Aplikasi tidak menghitung harga, pajak, HPP, porsi, stok, atau total (FR-015). Angka dibaca dari respons server dan ditampilkan; uang dari string server diformat dengan `Money` di `:core-domain`.

**Alasan**: Konstitusi IV; pola yang sama dengan aplikasi kasir (085/092: klien TIDAK menghitung ulang).

---

## D9 — Luring, coba ulang, dan data ganda

**Keputusan**: Online-first. Cache baca-saja di memori per layar (data terakhir yang dimuat) ditampilkan dengan pita "Luring"; tombol simpan dinonaktifkan saat luring (FR-031). Untuk FR-032: permintaan buat (POST) yang gagal karena koneksi tidak diulang otomatis; sebelum mengulang, aplikasi memuat ulang daftar dan memberi tahu bila data sudah tersimpan. Endpoint POST yang sudah mendukung `Idempotency-Key` (middleware `idempotent`) memakainya; aplikasi mengirim kunci acak per percobaan simpan formulir.

**ASUMSI**: Tidak semua rute buat master data memakai middleware `idempotent`; daftar rute yang perlu ditambah ditentukan di fase tugas dari inventori kontrak.

**Alternatif yang ditolak**: antrean tulis luring (konflik dengan aturan server, stok, dan izin; di luar cakupan ASUMSI spesifikasi); Room sebagai cache persisten (tidak dibutuhkan untuk baca-saja jangka pendek).

---

## D10 — Pembaruan berkala Dapur & Bar

**Keputusan**: Polling `GET` tiket tiap 5 detik hanya saat layar tampil (pola `SelfServicePoller` aplikasi kasir), berhenti di latar belakang. Perubahan status memakai endpoint yang sama dengan layar dapur web.

**Alasan**: Server tidak punya kanal push; polling sudah terbukti di aplikasi kasir. Konstitusi V: tidak polling saat tidak terlihat.

---

## D11 — Unggah foto produk

**Keputusan**: Photo Picker sistem (tanpa izin penyimpanan) atau kamera via `TakePicture`; gambar dikecilkan di perangkat ke batas sisi terpanjang yang sama dengan web lalu diunggah multipart ke endpoint foto yang sama. Batas ukuran/format otoritatif tetap di server (galat 422 ditampilkan).

**ASUMSI**: batas sisi terpanjang/ukuran web diambil dari `ImageUploadService` saat implementasi (unverified di riset ini).

---

## D12 — Dokumen PO

**Fakta (inventori)**: web membuat PDF PO di klien (`downloadElementAsPdf`, `utils/pdfCapture.js`) dari data `GET /purchase-orders/{id}/invoice` (JSON). Server tidak menyajikan PDF.

**Keputusan**: Rilis pertama aplikasi TIDAK membuat PDF PO. Layar detail PO menampilkan isi yang sama dengan `/invoice` dan tombol **Bagikan** mengirim ringkasan teks (nomor PO, vendor, baris, total dari server) lewat lembar berbagi Android. PDF identik dengan web ditunda ke fitur lanjutan yang memindahkan pembuatan PDF ke server (satu tata letak untuk web dan Android).

**Alasan**: CLAUDE.md/Konstitusi I: dokumen didefinisikan SEKALI; menggambar ulang tata letak PO di Android akan menjadi tata letak kedua yang menyimpang. **Perlu persetujuan pemilik**: ini mempersempit FR-024 ("unduh/bagikan dokumen PO") menjadi "bagikan ringkasan".

**Alternatif yang ditolak**: menggambar PDF di Android dengan `PdfDocument` (tata letak kedua); WebView yang memuat halaman web (butuh sesi web terpisah di WebView).

---

## D15 — Simpan produk = urutan beberapa panggilan (sama dengan web)

**Fakta (inventori)**: `ProductFormDrawer.vue:455-580` menyimpan produk lewat beberapa panggilan berurutan: buat/ubah produk → varian → tautan modifier → foto → koreksi stok (`POST /stock/adjustments`, wajib `stock_adjustment_reason`, dilewati bila `stock_source = modifier`). Server tidak membungkusnya dalam satu transaksi.

**Keputusan**: Aplikasi mengikuti urutan dan payload yang SAMA (paritas perilaku), dalam satu `ProductSaveOrchestrator` di `:manager`. Bila langkah ke-n gagal, langkah sebelumnya sudah tersimpan: formulir tetap terbuka dalam mode ubah (id produk sudah diketahui), langkah yang gagal ditandai, dan "Simpan" berikutnya hanya mengulang langkah yang belum berhasil. Tidak ada endpoint gabungan baru di rilis ini.

**Alasan**: Mengubah web ke endpoint transaksional tunggal adalah perubahan perilaku web di luar cakupan; tanpa itu, cara paling aman adalah meniru urutan web dan membuat kegagalan sebagian terlihat dan bisa dilanjutkan (FR-032).

**Alternatif yang ditolak**: endpoint `POST /products/full` transaksional baru (benar secara desain, tetapi menciptakan jalur simpan kedua yang berbeda dari web; dicatat sebagai kandidat perbaikan bersama web+Android).

---

## D16 — Celah validasi yang hanya ada di klien web

**Fakta (inventori)**: aturan berikut hanya ada di Vue, tidak di server:
1. Grup modifier pilihan tunggal: `min_select ≤ 1` dan `max_select = 1` (`modifierLinkRows.js:34-50`). Server hanya memeriksa min ≥ 0, max ≥ 1, max ≥ min.
2. Resep minimal satu blok dan tiap blok minimal satu baris (`RecipeDrawer.vue:350,363`). Aturan request membolehkan `blocks` kosong (penegakan di `RecipeService` unverified).
3. Pindah stok: jumlah ≤ tersedia dan bulat untuk varian (`NewTransferDrawer.vue:76`). Saldo ditolak 409 oleh service; bulat untuk varian unverified.
4. Koreksi stok minimal satu baris dengan delta ≠ 0 (server sudah `items: min:1` + `not 0`; klien hanya menyaring baris nol — perilaku klien, bukan celah).
5. Gambar kategori: klien menerima `image/*` ≤ 5 MB, server hanya jpeg/png (klien lebih longgar; server otoritatif — bukan celah keamanan).
6. Varian tunggal boleh tanpa nama di klien; server mewajibkan `variant_name` (ASUMSI: klien mengisi nama bawaan "Standard" sebelum kirim; dipastikan saat implementasi).

**Hasil implementasi (2026-11-25)**: hanya celah 1 yang nyata. Celah 2 (`RecipeService::validate`: `recipe_variants_required` / `recipe_lines_required`) dan celah 3 (`StockTransferService::normalise`: `transfer_whole_qty`) ternyata SUDAH ditegakkan server; tes ditambahkan hanya untuk mengunci perilaku itu.

**Keputusan**: Celah 1–3 ditutup di server (FormRequest/service + tes `tests/Feature/`) SEBELUM layar Android padanannya dibuat. Aplikasi meniru perilaku klien 4–6 (menyaring baris nol, mengisi nama varian bawaan sama dengan web, membatasi jenis gambar ke jpeg/png).

**Alasan**: Konstitusi IV (server satu-satunya penentu) dan SC-002 (paritas). Klien kedua membuat celah klien-saja menjadi jalan nyata untuk menyimpan data yang web tolak.

---

## D17 — Pengaturan Restoran mencakup grup modifier; area bukan entitas

**Fakta (inventori)**: `DiningSetupView` berisi tiga bagian: meja (`TablesAdmin`), stasiun (`StationsAdmin`), dan grup modifier (`ModifierGroupsAdmin`, termasuk dialog Attach 091). "Area" hanyalah kolom teks bebas `area` pada meja, bukan entitas.

**Keputusan**: Pengaturan Restoran Android memuat ketiga bagian itu, termasuk QR (putar ulang per meja; `qr-batch` untuk unduh massal ditunda karena menghasilkan dokumen cetak — ASUMSI). "Area" ditampilkan sebagai pengelompokan/isian teks pada meja, bukan layar sendiri.

---

## D13 — Pengujian

**Keputusan**:
- Server: `tests/Feature/` untuk login manajer (penanda, outlet, jatah percobaan bersama), middleware per-token, formulir Peran, dan setiap aturan validasi yang dipindah dari klien ke server. Dijalankan dengan MySQL dan flag `-e` wajib dari CLAUDE.md.
- Web: `qa-tests/` untuk sakelar formulir Peran.
- Android: tes unit JVM (`:manager` + `:core-network`) untuk pemetaan galat, penentuan menu dari `GET /auth/me`, format uang, dan ViewModel formulir (field 422 → galat per field). Tes UI Compose untuk tata letak adaptif (lebar ponsel vs tablet) bila waktu ada.
- Verifikasi perangkat/emulator ponsel ≈6" dan tablet ≈10" dicatat di `evidence.md` (Konstitusi II); yang belum diverifikasi ditandai jelas.

---

## D14 — Distribusi dan versi

**Keputusan**: APK terpisah dipasang manual; `minSdk 26`, `compileSdk/targetSdk` sama dengan aplikasi kasir (34). Penanda build (sha git + tanggal) di layar Pengaturan, pola 085. Kompatibilitas server diperiksa dengan memanggil `POST /auth/manager-sessions`: 404 = "versi server belum mendukung aplikasi manajemen" (edge case spesifikasi).
