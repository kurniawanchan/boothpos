# Research: Pesan Mandiri via QR Meja

**Klasifikasi**: INTERNAL

**Feature**: 090-self-service-qr-ordering | **Tanggal**: 2026-11-03

Setiap keputusan di bawah berdasar kode yang dibaca pada sesi ini (rujukan berkas disebut). Hal yang belum terbukti ditandai **ASUMSI** atau **belum diverifikasi**.

## D1 — Pesanan mandiri ditahan di entitas sendiri, baru menjadi `orders` saat "Kirim ke dapur"

**Keputusan**: tabel baru `self_service_orders` menyimpan kiriman pelanggan (potret keranjang, total, data diri, bukti bayar, status). Baris `orders` (dan stok, tiket dapur, pembayaran) baru dibuat saat staf menekan **Kirim ke dapur**.

**Alasan** (fakta dari kode):
- `orders.session_id` NOT NULL dengan FK ke `cashier_sessions` (`2026_10_04_000003_create_orders_and_payments_tables.php:16`). Saat pelanggan mengirim, tidak ada shift yang bisa dipakai.
- `TabService::addLine()` memotong stok seketika per baris (`applyLineMovementIfTracked`, `TabService.php:~205`). Spec FR-019 melarang stok bergerak sebelum verifikasi.
- Indeks `uniq_orders_open_table_key` menjamin satu order `open` per meja (`2026_10_31_000006`). Pesanan mandiri tidak boleh bertabrakan dengan tab kasir (Asumsi spec).
- `orders.status` berupa ENUM `open|completed|voided`; menambah status baru menyentuh ±41 titik pemakaian di `app/` (`SalesFigures`, `DashboardService`, `ShiftReportService`, dst.). Entitas terpisah menghindari risiko laporan menghitung pesanan belum terverifikasi.

**Alternatif ditolak**:
- Status order baru `pending_verification`: butuh `session_id`, memotong stok lewat jalur tab, dan mengubah semua pembaca status.
- Order `open` tanpa meja: tampil di daftar tab/lantai kasir dan stok terpotong saat kirim.

## D2 — Siklus status

`awaiting_verification` → `verified` → `dispatched`; `awaiting_verification|verified` → `rejected`. Tidak ada jalan mundur dari `dispatched` / `rejected` (asumsi spec: ditolak = final). Semua transisi memakai `lockForUpdate` agar dua staf tidak berhasil bersamaan (spec edge case).

## D3 — Dispatch memakai ulang layanan yang ada, tanpa jalur tulis baru

Urutan di satu transaksi `SelfServiceOrderService::dispatch()`:

1. Syarat: status `verified`; staf punya **shift terbuka di outlet pesanan** (`ShiftSource`, satu-satunya pencari shift). Tanpa itu → 409 `no_open_shift`. *(Temuan: spec belum menyebut syarat shift; dicatat sebagai asumsi tambahan.)*
2. Buat `orders` berstatus `open` **tanpa `table_id`** (cara ini menghindari `uniq_orders_open_table_key` bila meja sedang punya tab kasir), `service_type` dari pilihan pelanggan, `customer_id`, `source = self_service`.
3. Tiap baris lewat `TabService::addLine()` → ketersediaan (`MenuAvailability`), harga outlet, modifier (`ModifierResolver`), dan stok semuanya divalidasi/ditulis oleh kode yang sudah ada. Baris disimpan sebagai id varian + `modifier_selections`, bukan harga.
4. Bandingkan `total_amount` order dengan total yang dibayar pelanggan. Beda (harga/pajak berubah sejak kirim) → 409 `total_changed`, transaksi dibatalkan; staf memilih Tolak. Pelanggan membayar nominal yang ia lihat; selisih tidak pernah ditagih diam-diam.
5. `KitchenService::send()` membuat tiket per stasiun.
6. `TabSettlementService::settle()` mencatat pembayaran `qr_ewallet` lewat `PaymentRecorder` (satu-satunya pembuat `payments`), `autoVerify` menjadikannya `verified`, dan order `completed` (memicu `ModifierMaterialConsumption::consumeOrder`).
7. Set `orders.table_id` ke meja QR (order sudah `completed`, jadi indeks unik tidak berlaku), tautkan `self_service_order_id`, status entitas → `dispatched`.

Catatan: langkah 2 dan 7 adalah satu-satunya "akal" di rencana ini; ia dicatat di Complexity Tracking dan diuji dengan kasus meja yang sedang punya tab terbuka. **Belum diverifikasi**: bahwa `KitchenService::send()` dan `settle()` berjalan benar untuk order tanpa `table_id` dan tanpa `device_id` — harus dibuktikan oleh tes (tugas T-awal US6).

## D4 — Verifikasi pembayaran

`verify` adalah aksi pada entitas (belum ada baris `payments`, jadi tidak bisa memakai `PaymentService::markVerified`). Aturan disamakan dengan verifikasi QRIS yang ada (`PaymentService::verifyWithApproval`): referensi bank minimal 4 karakter, pemegang izin yang sama. Aturan "siapa boleh" diekstrak jadi satu metode yang dipakai dua jalur (Konstitusi I), bukan disalin. **ASUMSI**: MVP hanya pemegang izin verifikasi QRIS yang boleh memverifikasi; jalur persetujuan penyelia (`OverrideGate qris_verify`) untuk kasir biasa tidak ikut. **Perlu konfirmasi pemilik.**

## D5 — Rute publik tanpa token (pengecualian Konstitusi IV)

Preseden: 073 (`/pos-setup/*`, `/staff/roster`) dicatat sebagai pengecualian. Rute baru `/api/v1/self-service/...` juga tanpa `auth:sanctum`, tetapi tetap melewati `EnsureInstallationIsActivated` (FR-026; hanya dua rute lisensi yang dikecualikan). Konteks outlet diikat server-side dari token QR: `OutletContext::bind(null, $outletId)` membuat `singleId()` = outlet itu (`OutletContext.php`: `effectiveIds()` memakai `selected`), sehingga `OutletScope`, harga outlet, dan ketersediaan bekerja tanpa pengguna. `outlet_id` TIDAK pernah dibaca dari body.

Pembatas **bernama** (aturan 078: jangan `throttle:N,1` pada rute tanpa token): `self-service-read` (per IP), `self-service-submit` (per IP+meja, plus total per IP), `self-service-proof` (per IP). Angka awal: baca 120/mnt, kirim 5/mnt per IP+meja dan 20/jam per IP, unggah 10/mnt. **Angka awal ASUMSI**, disetel setelah uji beban ringan.

## D6 — Token QR

`SelfServiceQrToken` (satu kelas di `app/Support/`): `base64url(table_id.outlet_id.mode.nonce)` + HMAC-SHA256 dengan `APP_KEY`. Pemeriksaan: tanda tangan (perbandingan waktu-konstan), meja masih ada dan aktif, `outlet_id` cocok dengan milik meja, `mode` cocok dengan mode sistem, `nonce` cocok dengan `dining_tables.qr_nonce`. Mengganti id di URL → tanda tangan gagal (SC-005). "Buat ulang QR" memutar `qr_nonce` sehingga QR lama mati (sarana pencabutan bila QR bocor). Kolom `qr_nonce` diisi otomatis untuk semua meja lewat migrasi.

URL dasar QR: pengaturan baru `self_service_base_url` (kosong → memakai asal halaman staf bila bukan `localhost`/`127.0.0.1`/`::1`; selain itu staf diminta mengisi). FR-023.

## D7 — Pembuatan gambar QR di klien

Repo belum punya pustaka QR (`package.json`/`composer.json` tidak memuat "qr"). **Keputusan**: paket npm `qrcode`, diimpor dinamis di halaman Restaurant Setup saja (Konstitusi V — chunk terpisah, diperiksa di keluaran build). **Versi: unverified** — dipasang dan dikunci saat implementasi; jangan menebak nomor versi di rencana. Unduhan PNG/PDF massal memakai `utils/pdfCapture.js` yang sudah ada (CLAUDE.md: semua unduhan dokumen lewat sana); tata letak lembar QR didefinisikan satu kali di komponen sendiri.

## D8 — Menu publik

`SelfServiceMenuController` tipis: memakai pembaca yang sama dengan POS (`ProductCatalogQuery`, `OutletMenuService`, `MenuAvailability`, `ModifierResolver::present`) dengan konteks outlet dari token. **Daftar putih field** keluar: id, nama, kategori, foto, rentang harga, status habis, varian (id, nama, harga), grup modifier (aturan, opsi, selisih harga). Tidak keluar: `cost_price`, `current_stock`, `materials[].on_hand`, `home_outlet`. Kartu produk bersumber `modifier` tidak menampilkan porsi. Tanpa cache pada MVP (Konstitusi V: bila ditambah, kunci = mode + outlet, dengan waktu hitung). **Belum diukur**: SC-003 (3 detik) — diukur di quickstart.

## D9 — Bukti bayar dari pelanggan

Memakai ulang model `PaymentProof` dan disk privat (`PaymentProofController`: JPEG/PNG, maks 5 MB, nama acak, pemeriksaan MIME isi berkas). Rute unggah publik baru menyimpan dengan `uploaded_by = null` dan `captured_via`: kamera → `webcam`, galeri → `upload` (enum `webcam|upload` sudah ada; tidak perlu migrasi). Foto ponsel lebih besar dari 5 MB / HEIC: klien mengecilkan dan mengubah ke JPEG lewat kanvas sebelum unggah (kamera ponsel biasanya sudah menghasilkan JPEG). **Belum diverifikasi** di iOS Safari dan Android Chrome. Bukti yang tak pernah dikirim (yatim) dibersihkan perintah terjadwal `self-service:purge-orphan-proofs` (lebih dari 24 jam, `payment_id` kosong, tak tertaut ke entitas). Staf membuka foto lewat jalur berotorisasi (`GET /payment-proofs/{proof}/file` diperluas ke pemegang menu `self_service`); tidak ada URL publik.

## D10 — Idempotensi

Klien membuat UUID `local_ref` sekali per keranjang (disimpan bersama draf); kolom UNIQUE pada entitas. Kirim ulang → mengembalikan entitas pertama dengan token struk yang sama (pola `OrderService`/`TabService::open`).

## D11 — Harga, pajak, total

Dihitung saat kirim oleh `SelfServiceOrderService::submit()`: `MenuAvailability::assertOrderable`, `PriceService::applyOutletPrice`, `ModifierResolver::resolve`, lalu `TaxCalculator::compute` (satu-satunya rumus pajak; `PosSettings::effectiveTaxRate`). Tanpa diskon sama sekali (tidak ada field diskon di permintaan publik). Hasilnya disimpan sebagai potret (`lines`, `subtotal`, `tax_amount`, `tax_rate_snapshot`, `total_amount`) untuk struk dan pembanding saat dispatch.

## D12 — Data pelanggan

Data diri disimpan **di entitas** (nama, telepon, surel), bukan langsung ke `customers`: rute publik tidak boleh menulis atau mengenali data master (spec FR-020; mencegah enumerasi pelanggan). Saat dispatch, telepon dinormalisasi (angka saja, `08…` → `628…`) lalu dicocokkan dengan pelanggan yang ada; tidak ada → dibuat. `customers` tidak punya indeks unik telepon dan nomor lama tersimpan apa adanya, sehingga pencocokan hanya sebaik data lama (**ASUMSI**, risiko duplikat kecil diterima). Respons publik tidak pernah memuat data pelanggan.

## D13 — Dine-In / Take Away

Kolom `orders.service_type` sudah ada (`retail|dine_in|takeaway`). `TabService::open()` kini menurunkannya (meja → `dine_in`, tanpa meja → `takeaway`); `OrderService::create()` memakai bawaan `retail`.
- `POST /orders`: field baru `service_type` (`dine_in|takeaway`). POS web mengirimnya selalu (bawaan Dine-In).
- `POST /tabs`: field opsional `service_type`; tanpa itu perilaku turunan lama tetap (tab meja tetap Dine-In).
- Android: pilihan di editor/Counter, dikirim lewat `POST /tabs` (dan jalur penjualan langsung bila ada).
- Dampak: pesanan baru dari POS web tak lagi `retail`; bucket "retail" di `DashboardQueryRequest` kini hanya riwayat. Dicatat di CLAUDE.md. **Belum diperiksa**: apakah ada laporan yang mengelompokkan menurut `retail` untuk transaksi baru — diaudit di tugas.

## D14 — Gerbang menu staf

Kunci menu baru `self_service` (pola migrasi `add_purchase_orders_menu_key_to_default_roles`): diberikan ke owner, admin, dan kasir pada peran bawaan; peran khusus yang sudah ada tidak otomatis. UI disembunyikan penuh bagi yang tak punya (Konstitusi III); server menegakkan di `FormRequest::authorize()`.

## D15 — Tab Self-Service di web

`SalesView.vue` sudah punya saklar tampilan "Daftar order | Meja" yang tersimpan di URL (`?view=`). Tampilan ketiga `self-service` ditambahkan dengan lencana jumlah menunggu, mengikuti pola yang ada, hanya bagi pemegang menu `self_service`. Daftar memakai `usePaginatedList` dan filter status/meja. Detail = laci yang menampilkan pesanan, pembayaran, foto bukti, dan aksi Verifikasi / Tolak / Kirim ke dapur.

## D16 — Notifikasi Android

Fakta: Android sudah punya `audio/QueueAnnouncer`, `QueuePhrase`, `TtsSpeechEngine` (suara Indonesia, cadangan bahasa default) dan `HeartbeatWorker` (60 dtk, hanya di depan). Heartbeat terlalu lambat untuk SC-012 (10 dtk) dan `TtsSpeechEngine` memakai `QUEUE_FLUSH`, yang memotong ucapan sebelumnya.

**Keputusan**:
- `SelfServicePoller` baru (5 dtk, hanya saat aplikasi di depan, mundur eksponensial saat gagal) memanggil `GET /self-service/alerts?after_id=`; respons memuat pesanan baru (id, nama meja, nomor, total) dan `waiting_count`.
- `OrderCallQueue` baru: antrean FIFO di atas `SpeechEngine` yang sama (satu ucapan selesai baru yang berikutnya), tanpa mengubah perilaku `QueueAnnouncer` untuk nomor antrean.
- Kursor `after_id` disimpan per perangkat; pada buka-aplikasi pertama/sesudah offline kursor maju ke id terbaru **tanpa suara** dan hanya `waiting_count` yang ditampilkan (FR-040).
- Pemberitahuan visual = spanduk di `PosApp` yang bisa ditutup; preferensi suara `SelfServicePreference` (per perangkat, pola `SplitPreference`).
- Ucapan: `"Pesanan dari meja {nama meja}"`; tanpa data pelanggan (FR-039).
- Di belakang layar tak ada notifikasi (konsisten dengan desain heartbeat "tidak ada beban latar"). **ASUMSI**: dapat diterima karena tablet kasir berada di depan; jika pemilik ingin bunyi saat layar mati, butuh layanan latar depan terpisah (di luar cakupan).
- **Belum diverifikasi di perangkat**: suara Indonesia, `SC-012`, perilaku saat layar mati.

## D17 — Struk pelanggan

Halaman publik `/pesan/struk/:receiptToken`; token acak 40 karakter di entitas (bukan id). Data dari potret entitas + status; identitas toko memakai `StoreIdentity` (hanya blok yang boleh publik: nama, alamat, telepon outlet; tanpa surel internal bila tak dipakai di struk lain — sama dengan struk cetak). Dokumen didefinisikan satu kali (`components/selfservice/SelfServiceReceiptDocument.vue`) dan diunduh via `utils/pdfCapture.js` (gambar ditukar menurut `src`-nya sendiri, tidak menurut indeks — aturan 029). Struk membuka ulang dari token: status terbaru ditampilkan (FR-036).

## D18 — Nomor pesanan

`orders.order_number` dibuat saat order terbentuk (dispatch). Agar struk punya nomor sejak kirim tanpa membuat celah pada urutan resmi bila ditolak, entitas diberi **nomor mandiri** sendiri (mis. `SS-251103-007`, urut per outlet per hari kerja). Setelah dispatch, struk menampilkan juga nomor POS. *(Penyimpangan kecil dari FR-016 "penomoran yang sama"; dicatat sebagai asumsi tambahan.)*

## D19 — Restaurant Setup: filter, paginasi, QR massal

`GET /dining-tables` kini mengembalikan SEMUA meja tanpa paginasi (`DiningTableController::index`). Konsumen lain (lantai, tab) bergantung pada bentuk itu, maka paginasi **opt-in**: `page`/`per_page` + `q`, `area`, `is_active`, `outlet_id[]` (sudah ada, 071). Tanpa `page` bentuk lama tidak berubah. QR massal: `POST /dining-tables/qr-batch` menerima daftar id **atau** filter yang sama dan mengembalikan token (maks 200 meja), sehingga "pilih semua hasil filter" tidak bergantung pada halaman yang termuat (spec US7 skenario 3). Gambar QR dirender klien (D7).

## D20 — Log aktivitas

`ActivityLogger` di dalam transaksi yang sama: `self_service_submitted` (user null), `self_service_verified`, `self_service_rejected` (alasan), `self_service_dispatched`, `table_qr_generated`, `table_qr_rotated`. Tanpa nama/telepon/surel (FR-033).

## D21 — Pengujian

- Backend (MySQL, `tests/Feature/`): token QR (ubah id → ditolak, nonce diputar, mode salah), kirim (harga server, tanpa diskon, idempoten, lawan stok habis), verifikasi, tolak (tanpa stok/tiket), dispatch (tiket, stok, pembayaran terverifikasi, meja dengan tab terbuka, `total_changed`, `no_open_shift`, aksi bersamaan), publik tidak membocorkan HPP/stok/pelanggan, batas laju, paginasi meja, `service_type`.
- Web (Vitest, `qa-tests/`): keranjang + draf tahan muat ulang, validasi data diri, kartu menu, tab Self-Service, toggle Dine-In.
- Android (JUnit): `OrderCallQueue` berurutan, kursor tanpa putar ulang, preferensi suara, `TerminologyScanTest`.
- **Harus dibuktikan manusia** (Konstitusi II): peramban ponsel nyata (kamera, unduh gambar/PDF), tablet (suara). Dicatat di `evidence.md`.
- Tes backend di kontainer memakai flag `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test` + `CACHE_STORE=array SESSION_DRIVER=array` (CLAUDE.md).

## Pertanyaan terbuka untuk pemilik (tidak memblokir rencana)

1. D4: boleh kasir tanpa izin verifikasi QRIS memverifikasi lewat persetujuan penyelia, atau hanya pemegang izin?
2. D3: staf yang menekan Kirim ke dapur wajib punya shift terbuka di outlet itu — sesuai?
3. D18: nomor mandiri `SS-…` di struk sebelum dispatch, nomor POS menyusul — sesuai?
