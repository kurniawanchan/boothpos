# Research: Ganti Bahasa Antarmuka (Indonesia/English)

## Starting point — tidak ada infrastruktur i18n sama sekali

Sebelum menulis keputusan, pemeriksaan langsung ke kode mengonfirmasi titik
awal literal:

- **Backend**: tidak ada direktori `lang/` sama sekali di proyek ini
  (Laravel 11+ tidak lagi menerbitkan `lang/en` bawaan). `config/app.php`
  punya `'locale' => env('APP_LOCALE', 'en')` — nilai default framework
  yang tidak pernah disesuaikan, TIDAK mencerminkan bahwa produk ini
  selama ini selalu berbahasa Indonesia. Setiap pesan yang dikembalikan ke
  klien (pesan login `AuthController`, pesan guard `RolePolicy`/
  `UserPolicy`, `messages()` di `FormRequest`, dll) adalah **string
  literal Bahasa Indonesia yang ditulis langsung di kode PHP**, bukan
  lewat helper `__()`/`trans()`. Tidak ada satu pun pemanggilan fungsi
  terjemahan di seluruh `app/`.
- **Frontend**: tidak ada dependency i18n apa pun di `package.json`. Setiap
  label, judul kolom, placeholder, dan pesan di ~20+ komponen Vue adalah
  string Bahasa Indonesia yang ditulis langsung di template.

Konsekuensinya: fitur ini bukan sekadar "pasang toggle", melainkan
memperkenalkan infrastruktur terjemahan dari nol di kedua sisi, lalu
memigrasikan seluruh string yang sudah ada (bukan hanya yang baru) ke
dalamnya — sesuai FR-008 (cakupan penuh, bukan bertahap). Ini dicatat
eksplisit di sini karena ukurannya jauh lebih besar dari kesan pertama
permintaan fitur, dan harus tercermin di estimasi task (`/speckit-tasks`).

## Decision 1 — Library i18n frontend: `vue-i18n` (v9, Composition API)

**Decision**: Pakai `vue-i18n` v9+ (kompatibel Vue 3), mode Composition API
(`useI18n()`), dengan katalog pesan sebagai berkas JSON per bahasa:
`resources/js/locales/id.json` dan `resources/js/locales/en.json`.

**Rationale**: Standar de-facto untuk Vue 3, reaktif secara native (ganti
`locale.value` membuat SELURUH komponen yang memakai `t()` re-render
seketika — memenuhi SC-001 "<1 detik, tanpa reload"), mendukung
interpolasi/pluralization bawaan (dibutuhkan untuk pesan seperti "masih
dipakai {n} pengguna" di guard 409), dan terawat aktif dengan dokumentasi
matang. Tidak menambah beban maintenance ekstra dibanding menulis
composable sendiri, dan justru mengurangi risiko bug locale-switching yang
sudah pernah ditemukan proyek lain (edge case pluralization, format
angka/tanggal).

**Alternatives considered**:
- **Composable buatan sendiri (`useTranslation()` custom + object literal
  key-value)** — ditolak karena harus membangun ulang reaktivitas,
  interpolasi, dan pluralization yang sudah disediakan matang oleh
  vue-i18n; tidak ada keuntungan nyata untuk effort ekstra itu.
- **i18next + vue-i18next** — ditolak karena i18next dirancang framework-
  agnostic dengan API lebih berat, sementara vue-i18n terintegrasi lebih
  ringan khusus untuk Vue 3 dan sudah cukup untuk kebutuhan dua-bahasa
  proyek ini.

## Decision 2 — Locale backend: `App::setLocale()` per-request dari preferensi pengguna, bukan session

**Decision**: Middleware baru `SetLocaleFromUser` didaftarkan di grup route
`auth:sanctum` (BUKAN di grup route publik/login), memanggil
`App::setLocale($request->user()->language)` di awal siklus request.
Middleware ini SATU-SATUNYA tempat locale backend diputuskan — tidak ada
logic locale kedua yang duplikat di controller manapun. Locale default
aplikasi (`config/app.php` `locale`) diubah ke `'id'` — bukan karena
pengguna login memakai Indonesia, tapi karena inilah locale yang harus
aktif untuk SELURUH permintaan yang belum melewati middleware ini: rute
login itu sendiri dan tamu yang belum terautentikasi (FR-001).

**Rationale**: Aplikasi ini API stateless berbasis token Sanctum (bukan
session cookie) — locale TIDAK BOLEH disimpan di session server, harus
diresolusi ulang dari identitas token di setiap request, konsisten dengan
pola arsitektur stateless yang sudah ada di seluruh kodebase ini. Middleware
tunggal ini juga jadi satu-satunya sumber kebenaran, sejalan dengan
Prinsip I Konstitusi (satu jalur per concern) — tidak boleh ada endpoint
yang menerka locale-nya sendiri secara terpisah.

**Alternatives considered**:
- **Header `Accept-Language` dari klien** — ditolak karena FR-005
  eksplisit: preferensi melekat ke AKUN (server-side), bukan ke
  browser/perangkat pengirim request; kalau kasir B login di perangkat
  yang masih mengirim header dari sesi kasir A sebelumnya, hasilnya salah.
- **Query parameter `?lang=` di setiap request** — ditolak karena
  menambah state yang harus disinkronkan manual oleh setiap pemanggil API
  di frontend, padahal cukup diturunkan otomatis dari token yang sudah
  dikirim tiap request.

## Decision 3 — Migrasi seluruh string literal ke katalog terjemahan: strategi bertahap per lapisan, bukan big-bang

**Decision**: Karena TIDAK ADA infrastruktur terjemahan sama sekali (lihat
bagian atas), pekerjaan migrasi string dipecah per lapisan independen di
`/speckit-tasks`, masing-masing bisa diverifikasi terpisah:
1. Pesan validasi & pesan bisnis di `FormRequest`/`Policy`/`Controller`
   yang dikembalikan SETELAH login → dikonversi dari string literal
   menjadi kunci `__('key')`, dengan berkas `lang/id/*.php` dan
   `lang/en/*.php` yang isinya PERSIS string Indonesia yang sudah ada
   sekarang (supaya perilaku default `'id'`... catatan: default locale
   APLIKASI adalah `'id'` untuk rute publik, tapi begitu middleware
   men-set locale pengguna ke `'en'`, string yang sama harus punya padanan
   Inggrisnya).
2. String UI statis (label, judul, tombol, placeholder) di setiap
   komponen Vue → dipindah ke `resources/js/locales/id.json` /
   `en.json`, dipanggil lewat `t('key')`.
3. Pesan galat 422/409 dari backend yang diterima frontend → SUDAH
   otomatis berbahasa yang benar begitu langkah 1 selesai (backend
   mengembalikan string yang sudah diterjemahkan berdasar locale
   pengguna), frontend tidak perlu menerjemahkan ulang pesan itu — hanya
   menampilkannya apa adanya.

**Rationale**: Memisahkan per lapisan memungkinkan tiap lapisan diuji dan
dikerjakan independen (konsisten dengan pola task-per-user-story yang
sudah dipakai proyek ini di `001-user-store-settings`), dan mencegah
"big bang" satu PR raksasa yang sulit direview atau diverifikasi.
Pendekatan literal-string-jadi-kunci (bukan menerjemahkan on-the-fly saat
runtime) juga konsisten dengan cara Laravel bekerja secara native dan
tidak menambah dependency machine-translation apa pun saat runtime.

**Alternatives considered**:
- **Layanan terjemahan otomatis (mis. Google Translate API) saat
  runtime** — ditolak: menambah dependency eksternal, biaya, dan latensi
  untuk produk yang berjalan offline-lokal di satu mesin toko tanpa
  jaminan koneksi internet stabil (lihat batasan lingkungan proyek ini).
- **Menyimpan string terjemahan di database (tabel `translations`)** —
  ditolak: over-engineering untuk kebutuhan dua bahasa statis yang
  ditentukan saat build, bukan konten yang berubah-ubah oleh pengguna
  akhir; berkas JSON/PHP array statis lebih sederhana dan konsisten
  dengan Prinsip I (tidak lebih rumit dari kebutuhan).

## Decision 4 — Kolom preferensi bahasa & endpoint ganti-bahasa: self-service, terpisah dari `UserPolicy`

**Decision**: Tambah kolom `users.language` (`ENUM('id','en')`, `NOT NULL
DEFAULT 'en'`) lewat migration baru mengikuti pola dua-tahap yang sama
seperti `role_id` bila diperlukan — tapi karena ini kolom nullable-default
sederhana (bukan pergantian struktural seperti enum→FK), migration SATU
TAHAP cukup: tambah kolom dengan default `'en'` langsung. Endpoint baru
`PUT /auth/language` (bukan lewat `PUT /users/{id}`) menerima
`{"language": "id"|"en"}`, HANYA mengubah bahasa AKUN YANG SEDANG LOGIN
sendiri — TIDAK digerbang `UserPolicy`/`canAccessMenu('users')` sama
sekali, karena setiap pengguna (termasuk kasir tanpa akses menu `users`)
harus bisa mengganti bahasanya sendiri.

**Rationale**: `PUT /users/{id}` sudah digerbang `UserPolicy::update()`
yang mensyaratkan `canAccessMenu('users')` — kalau endpoint ganti-bahasa
dipaksakan lewat situ, seorang kasir (yang secara sengaja TIDAK diberi
akses menu `users`, lihat peran default di fitur `001-user-store-settings`)
tidak akan bisa mengganti bahasanya sendiri, padahal FR-004 mengharuskan
kontrol ganti bahasa tersedia untuk SEMUA pengguna dari mana pun di
aplikasi. Pola endpoint "self-service, tidak digerbang policy resource"
ini konsisten dengan `GET /auth/me` yang sudah ada — akun mana pun boleh
membaca/mengubah datanya sendiri yang bersifat preferensi personal, tanpa
melalui gerbang akses menu resource.

**Alternatives considered**:
- **Menambahkan field `language` ke payload `PUT /users/{id}` yang sudah
  ada** — ditolak karena tetap terikat gerbang `canAccessMenu('users')`
  milik endpoint itu, menutup jalan bagi pengguna tanpa akses menu users
  untuk mengubah bahasanya sendiri.
- **Menyimpan preferensi di localStorage saja (tanpa kolom database)** —
  ditolak eksplisit oleh FR-005/FR-006 spec: preferensi harus melekat ke
  akun agar berpindah bersama pengguna lintas perangkat, bukan ke
  perangkat/browser.

## Decision 5 — Struk (FR-009) sengaja TIDAK ikut memakai `t()`/`__()`

**Decision**: Komponen `ReceiptModal.vue` dan seluruh proses pembuatan
respons `GET /orders/{id}/receipt` di backend TIDAK diikutsertakan dalam
migrasi string ke katalog terjemahan — label-labelnya ("Subtotal",
"Diskon", "Kembalian", "Kasir", dst.) tetap string Indonesia hardcoded
seperti sekarang.

**Rationale**: FR-009 eksplisit — struk adalah dokumen yang dibaca
PELANGGAN, harus selalu Bahasa Indonesia terlepas dari bahasa antarmuka
kasir yang sedang login. Middleware `SetLocaleFromUser` (Decision 2)
sudah aktif untuk seluruh request `auth:sanctum` termasuk
`GET /orders/{id}/receipt` — kalau labelnya ikut memakai `__()`, struk
akan salah ikut berubah ke Inggris saat kasir memilih English. Karena itu
label struk backend TETAP literal, dan render frontend-nya TETAP literal
juga — bukan celah yang lupa dikonversi, melainkan pengecualian yang
disengaja dan harus didokumentasikan sebagai komentar `BUG YANG
DITEMUKAN & DIPERBAIKI`-style di kode agar kontributor berikutnya tidak
"memperbaikinya" jadi ikut ter-i18n-kan.

## Catatan Constitution Check — konflik dengan Prinsip III

Prinsip III Konstitusi proyek ini menyatakan: *"All UI copy, code
comments, and commit messages are written in Indonesian ... new
contributions MUST NOT introduce English inconsistently."* Fitur ini
secara literal memperkenalkan English sebagai bahasa TAMPILAN produk
(bahkan sebagai default), yang berbeda dari cakupan asli aturan itu.

Ini BUKAN pelanggaran yang tidak disengaja — ini permintaan eksplisit
pemilik produk lewat proses klarifikasi spec (`Q1: A`, cakupan penuh).
Yang TETAP tidak berubah dan TETAP wajib Bahasa Indonesia sesuai
Prinsip III: **kode sumber, komentar kode, dan pesan commit** — hanya
teks yang dilihat PENGGUNA AKHIR di antarmuka produk (setelah login) yang
kini bisa berbahasa Inggris sesuai pilihan pengguna. Bagian "Constitution
Check" pada `plan.md` mencatat ini sebagai deviasi yang dibenarkan
(Complexity Tracking), dan merekomendasikan Prinsip III diamandemen lewat
`/speckit-constitution` (bump MINOR — memperjelas cakupan "UI copy dalam
Indonesia" hanya berlaku sebagai BAHASA SUMBER/default sebelum fitur i18n
ini, bukan lagi larangan mutlak menampilkan bahasa lain kepada pengguna
akhir) sebelum atau bersamaan fitur ini digabungkan ke `main`.
