INTERNAL

# 12-CustomerCommerce — catatan

Folder ini mendokumentasikan dan menguji API toko online untuk aplikasi Android pelanggan
(rancangan `docs/android-customer-commerce/design.md`, kontrak `docs/openapi-pos-mvp.yaml`):
etalase publik, akun pelanggan, alamat, quote dan checkout idempoten, pesanan dan
pelacakan, serta fulfilment pesanan pelanggan oleh staf.

Folder ini **mandiri**: semua data uji dibuat di dalam folder ini. Ia tidak memakai
`{{artist_id}}`/`{{variant_id}}` dari 02-MasterData (folder itu memakai kode artist/kategori
tetap sehingga tidak bisa dijalankan ulang) dan sengaja tidak memakai nama variabel
`customer_id` (sudah dipakai 02-MasterData untuk kontak pelanggan staf).

## Prasyarat

1. Server Laravel berjalan di `base_url` environment `local`
   (`php artisan serve` → `http://localhost:8000/api/v1`, atau stack Docker).
2. Migrasi terbaru sudah jalan (`php artisan migrate`: tabel `customer_*`, kolom
   `products.slug`/`published_at`, menu `customer_orders` untuk Owner/Admin). Tanpa itu
   rute baru biasanya menjawab 503 `schema_outdated`.
3. Database sudah di-seed (`php artisan db:seed`). Request 1 login sebagai `owner` dengan
   kredensial dummy dari seeder (lihat langkah 3 di `bruno/README.md`; hanya untuk lokal).
4. Instalasi sudah diaktivasi; tanpa itu semua rute menjawab 423.
5. Di mode data yang sedang aktif (DEMO/LIVE) ada minimal satu **artist aktif** berkode tiga
   huruf A–Z dan satu **kategori aktif** berkode dua huruf A–Z (bentuk `code_prefix`
   ART-CA-SEG). Misalnya setelah 02-MasterData dijalankan sekali, atau dibuat lewat layar
   Master Data. Request 4 dan 5 gagal dengan pesan yang jelas bila belum ada.

Saklar toko online dan produk terbit TIDAK perlu disiapkan manual: request 2 menyalakan
`customer_commerce_enabled` lewat `PUT /settings`, request 6–9 membuat dan menerbitkan produknya.

Efek samping yang sengaja ditinggalkan. Jalankan hanya di database pengembangan/uji, jangan
di instalasi toko sungguhan:

- saklar `customer_commerce_enabled` tetap MENYALA setelah run (matikan lewat `PUT /settings`
  dengan nilai `false` bila perlu);
- dua produk uji per run ("Etalase Bruno <angka>" terbit, "Tersembunyi Bruno <angka>" tidak
  terbit) beserta pergerakan stoknya;
- dua akun pelanggan `bruno.cust+…@example.test` dan `bruno.custb+…@example.test` (domain
  `.test` yang dicadangkan, tidak ada surel terkirim) dengan kontak `customers`, alamat, dan tiga
  pesanan pelanggan. Token hasil registrasi tetap berlaku sampai kedaluwarsa (logout hanya
  mencabut token yang sedang dipakai). Belum ada endpoint untuk menghapus akun pelanggan, dan
  kontak yang punya akun tidak bisa dihapus (409).

## Menjalankan

**Bruno desktop** (terpasang di mesin ini: 4.3.0): buka koleksi `bruno/`, pilih environment
`local`, lalu jalankan folder `12-CustomerCommerce` dengan *Run* (runner). Jalankan sebagai
folder, bukan per request: tiap request memakai variabel yang diisi request sebelumnya,
request 1 memakai `bru.runner.skipRequest()` dan request 73 mengulang dirinya sendiri lewat
`bru.runner.setNextRequest()`. Kedua fungsi itu ada di runner Bruno desktop 4.3.0 (dicek statis
pada berkas aplikasinya, belum dijalankan).

**Headless** (BELUM DIVERIFIKASI di mesin ini: `bru` tidak terpasang dan `npx` tidak
dijalankan). Dari direktori `bruno/`, karena CLI mencari `bruno.json` di direktori kerja:

```bash
cd bruno
npx --yes @usebruno/cli@4.3.0 run 12-CustomerCommerce --env local
# atau dengan instalasi global @usebruno/cli 4.3.0:
bru run 12-CustomerCommerce --env local
```

Versi 4.3.0 dipilih supaya sama dengan aplikasi desktop. Bila CLI menolak Node 18 (versi aktif
di mesin ini), pakai Node 22 atau 24 dari nvm (BELUM DIVERIFIKASI).

## Awalan `12-` yang sama dengan `12-Language`

Nama `12-CustomerCommerce` mengikuti rancangan yang sudah disetujui. Nomor di depan nama folder
hanya alat bantu urutan baca: tidak ada berkas atau skrip yang merujuk nomor folder, dan `seq`
request dihitung per folder. Kedua folder tidak saling bergantung: `12-Language` hanya mengganti
bahasa akun staf, sedangkan folder ini tidak memeriksa teks pesan, hanya status HTTP dan `code`.
Secara alfabet `12-CustomerCommerce` berjalan lebih dulu.

## Menjalankan ulang: tunggu minimal satu menit

Request 73 SENGAJA mengulang login pelanggan dengan satu e-mail yang tidak pernah terdaftar
sampai server menjawab 429, dan ia selalu request terakhir. Setelah satu run, ember limiter per
IP sudah terpakai: `customer-login` 8 dari 10 dan `customer-register` 3 dari 5. Run yang dimulai
kurang dari 60 detik kemudian akan mendapat 429 di request 19 (login) dan 29 (registrasi).
Tunggu minimal satu menit setelah run selesai (ember dihitung 60 detik sejak hit pertama).
Setelah itu run ulang aman: nama produk, segmen kode, e-mail, dan kunci idempotensi dibuat baru
setiap run.

Di Bruno desktop, variabel runtime (termasuk `owner_token`) bertahan selama aplikasi terbuka,
jadi run ulang memakai token yang sama dan request 1 dilewati. Bila database sempat direset,
token itu basi dan semua request staf menjawab 401: kosongkan variabel runtime (misalnya dengan
memulai ulang aplikasi Bruno) lalu jalankan lagi.

## Variabel

`bruno/environments/local.bru` hanya menambah placeholder kosong ditambah satu kata sandi uji
palsu. Format environment Bruno tidak punya komentar, jadi dokumentasinya di sini. Semua
placeholder diisi skrip saat run dengan `bru.setVar` (variabel runtime menimpa nilai
environment):

| Variabel | Diisi oleh request | Isi |
|---|---|---|
| `customer_password` | tetap di environment | kata sandi uji palsu untuk kedua akun pelanggan; bukan kredensial sungguhan |
| `customer_email`, `customer_token` | 15, 17 | akun A: `bruno.cust+<Date.now()>@example.test`; token dari login |
| `customer_b_email`, `customer_b_token` | 29 | akun B untuk uji lintas akun |
| `customer_address_id`, `customer_b_address_id` | 27, 30 | alamat milik A dan milik B |
| `storefront_product_slug`, `storefront_variant_id`, `storefront_empty_variant_id` | 6 | slug produk etalase, varian berstok ("Ukuran S"), varian stok 0 ("Ukuran M") |
| `hidden_product_slug` | 7 | slug produk yang tidak diterbitkan |
| `idempotency_key` | 36 (pre-request) | kunci yang dikirim ulang oleh request 37 dan 38 |
| `customer_order_number`, `customer_pickup_order_number` | 36, 41 | nomor publik `CO-…` (hanya ini yang dipakai di URL pelanggan) |
| `customer_order_staff_id`, `customer_pickup_order_staff_id` | 49, 61 | id numerik untuk rute staf `/customer-orders/{id}`, dicari lewat `GET /customer-orders?q=<order_number>` |

Variabel runtime lain (tanpa placeholder, hanya hidup selama run): `owner_token` (1),
`storefront_max_qty_per_line` (3), `storefront_artist_id` (4), `storefront_category_id` (5),
`storefront_run_id`, `storefront_product_segment`, `storefront_product_id` (6),
`hidden_product_segment`, `hidden_product_id`, `hidden_variant_id` (7), `storefront_variant_price`
(11), `invalid_credentials_body` (18), `unknown_customer_email` (19), `quote_total` (34),
`foreign_address_idempotency_key` (40), `pickup_idempotency_key` (41), `cancel_idempotency_key`,
`customer_cancel_order_number` (42), `cross_account_not_found_body` (46),
`customer_tracking_number` (56), `customer_cancel_order_staff_id` (67), `customer_cancel_reason`
(68), `rate_limit_attempt`, `rate_limit_email`, `rate_limit_continue` (73).

Selain `Authorization`, folder ini mengirim dua header lain: `Idempotency-Key` (checkout) dan
`X-Request-Id` (request 20, membuktikan id korelasi dipantulkan).

## Anggaran limiter per run

Angka dari `config/customer_commerce.php` (`rate_limits`) dan limiter bernama di
`AppServiceProvider`. Laravel pada repo ini memeriksa SEMUA batas sebuah limiter lebih dulu, baru
menambah hitungannya; permintaan yang ditolak 429 tidak ikut dihitung.

| Limiter | Batas | Dipakai satu run | Keterangan |
|---|---|---|---|
| `throttle:5,1` login staf (per IP) | 5/menit | 1 (0 bila `owner_token` sudah ada) | koleksi penuh: 01-Auth/1–3 + 11-User-Role/3 = 4, jadi total tetap ≤ 5 |
| `customer-register` | 5/menit per IP + 3/menit per e-mail | 3 per IP (15, 16, 29); e-mail A 2, e-mail B 1 | |
| `customer-login` | 10/menit per IP + 5/menit per e-mail | 8 per IP (17, 18, 19 + lima percobaan 401 di 73) | percobaan ke-6 di 73 ditolak ember e-mail (5 dari 5) → 429; ember IP berhenti di 8 |
| `customer-checkout` | 10/menit per akun | akun A 7 (36–42) | replay dan penolakan (409/422) ikut dihitung |
| `customer-api` | 120/menit per akun | akun A 21, akun B 6 | permintaan tanpa token/token dicabut ditolak `auth:customer` sebelum limiter |
| `storefront` | 120/menit per IP | 6 (3, 10–14) | |

Satu-satunya 429 yang disengaja adalah request 73.

## Apa yang dibuktikan tiap bagian

Status dan `code` mesin selalu diperiksa (aplikasi Android memutuskan dari `code`); teks pesan
tidak pernah diperiksa karena bergantung bahasa.

- **A. Persiapan staf (1–9).** Login owner (dilewati bila `owner_token` sudah ada, karena login
  staf `throttle:5,1`), nyalakan saklar lewat endpoint pengaturan staf, baca
  `GET /storefront/config` tanpa token, pilih artist dan kategori aktif, buat produk etalase
  dengan dua varian dan produk kedua yang tidak diterbitkan, beri stok lewat
  `POST /stock/adjustments` hanya untuk satu varian etalase ("Ukuran M" tetap 0) dan untuk produk
  tersembunyi (supaya terbukti ia tersembunyi karena belum terbit, bukan karena stok kosong),
  lalu terbitkan produk etalase dengan `with_variants: true` (kedua varian terbit, termasuk yang
  stoknya 0).
- **B. Katalog publik tanpa token (10–14).** Daftar dan detail hanya memuat produk terbit dan
  varian berstok; produk tersembunyi tidak muncul dan detailnya 404 `not_found`; varian stok 0
  tidak ditawarkan dan harganya tidak menggeser rentang harga; tidak ada `cost_price`,
  `current_stock`, `sku`, `code_prefix`, `data_mode`, `image_path`, `low_stock_alert`, maupun id
  numerik produk; `max_qty` menggantikan angka stok; `per_page` di atas 50 dipangkas; `sort` tak
  dikenal 422 `validation_failed`.
- **C. Akun (15–22).** Registrasi 201 dengan token, e-mail ganda 422 `registration_failed`
  tanpa `errors.email`, login, password salah dan e-mail tak terdaftar menghasilkan badan 401
  `invalid_credentials` yang identik (selain `request_id`), profil, ubah profil (e-mail tidak bisa
  diubah), tanpa token 401 `unauthenticated` berformat `application/problem+json`, `X-Request-Id`
  dipantulkan.
- **D. Batas token (23–26, ditambah 50).** Token pelanggan ditolak 401 `{"message":
  "Unauthenticated."}` di `GET /auth/me`, `GET /catalog`, `POST /orders` (POS) dan di aksi
  fulfilment staf `POST /customer-orders/{id}/confirm`; token staf ditolak 401 `unauthenticated` di
  `GET /customer/me`.
- **E. Alamat dan kepemilikan (27–33, ditambah 40).** Alamat pertama otomatis jadi alamat utama;
  akun B dibuat; A mengubah dan menghapus alamat B → 404 `not_found`; alamat B tetap utuh;
  checkout kurir A dengan alamat milik B → 422 `validation_failed` pada `errors.address_id`.
- **F. Quote dan checkout idempoten (34–42).** Harga dan total dihitung server; varian stok 0
  → 409 `cart_invalid` (`unavailable`, `max_qty: 0`); checkout dengan `Idempotency-Key` yang
  dibuat SEKALI di pre-request (36) → 201; request terpisah tanpa pre-request dengan kunci dan isi
  yang sama (37) → 200 dengan `order_number` yang sama; kunci sama isi berbeda → 409
  `idempotency_key_reused`; tanpa kunci → 422 `idempotency_key_required`; pesanan ambil di tempat
  (kontak dari profil) dan pesanan ketiga untuk dibatalkan.
- **G. Pesanan pelanggan (43–48).** Daftar berisi tiga pesanan dan pesanan kurir hanya sekali
  walau checkout diulang; detail memuat snapshot alamat/kontak/item dan linimasa tanpa metadata
  staf; pelacakan `updated_by: "store"`; akun B membaca pesanan dan pelacakan milik A → 404 dengan
  badan yang sama persis dengan nomor pesanan yang tidak ada.
- **H. Fulfilment kurir (49–60).** Id staf dicari lewat `?q=`; kirim sebelum konfirmasi → 409
  `invalid_transition`; konfirmasi dengan `shipping_cost` (total dihitung ulang, stok baru
  berkurang di sini: 10 → 8); kemas; siap-diambil pada pesanan kurir → 409
  `wrong_fulfilment_method`; kirim tanpa `courier_name` → 422; kirim dengan kurir dan nomor resi;
  perbaiki resi lewat `PATCH …/shipment` (riwayat lama tidak ditulis ulang); pelacakan pelanggan
  menampilkan pengiriman; tandai terkirim; batalkan pesanan terkirim → 409 `invalid_transition`.
- **I. Fulfilment ambil di tempat (61–66).** Konfirmasi (stok 8 → 7), kemas, kirim lewat kurir
  → 409 `wrong_fulfilment_method`, siap diambil, sudah diambil.
- **J. Pembatalan (67–69).** Pesanan yang masih menunggu konfirmasi dibatalkan dengan alasan;
  stok tidak bergerak (tetap 7); pelanggan melihat status batal dan alasannya di linimasa.
- **K. Logout dan pembatasan (70–73).** Logout 204, token yang sudah logout ditolak 401, logout
  akun B; lalu login berulang dengan satu e-mail yang tidak pernah terdaftar sampai 429
  `too_many_requests` dengan header `Retry-After` (percobaan sebelumnya 401
  `invalid_credentials`, paling banyak 12 percobaan).

## Yang belum diverifikasi

- Folder ini belum pernah dijalankan terhadap server sungguhan (sesi pembuatnya tidak boleh
  menjalankan server). Semua assertion diturunkan dari kode yang ada di working tree (rute,
  controller, FormRequest, resource, service) dan contoh respons di dokumentasi backend.
- Perintah headless di atas belum dijalankan (CLI tidak terpasang).
- `bru.runner.skipRequest()`, `bru.runner.setNextRequest()`, `res.getHeader()` (nama header
  di-lowercase) dan urutan prioritas variabel runtime di atas environment dicek statis pada berkas
  aplikasi Bruno desktop 4.3.0, belum dijalankan; perilaku CLI belum diverifikasi.
- Rute fulfilment staf (`/customer-orders/*`) masih bisa berubah oleh pekerjaan backend yang
  sedang berjalan; periksa ulang request 49–68 bila bentuk responsnya berubah.
