# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

BoothPOS — a POS system for event-based multi-artist merchandise booths, sold as a **one-time license installed locally per store**. Laravel API + Vue SPA both run on one machine and are reached over `localhost`; there is no cloud tier, no separate frontend server, and no multi-tenancy.

> **Dated note — 2026-10-11 (constitution 2.0.0, feature 045):** one organisation may now run **one or more outlets** (seller records are renamed outlets), on one machine **or** one shared server. Still one server and one database per organisation, and still no multi-tenancy across customers. A single-outlet install is unchanged. See `specs/045-outlets-per-outlet-menu/`. "Production" means a shopkeeper's laptop at an event venue — as of `016-docker-store-deployment` (dated note, 2026-09-05), that laptop MAY run BoothPOS via Docker instead of a native install; both are real, supported production paths, not just feature 015's dev-only tooling. See that feature's plan for the details.

Docs in `docs/` are the spec source of truth: `PRD-POS-Event-Multivendor.md`, `openapi-pos-mvp.yaml`, `schema-pos-mvp.sql`, `wbs-pos-mvp.md`, `uml-pos-mvp.md`. `docs/RUNBOOK.md` is the operational command reference; `README.md` carries the narrative history and the full list of bugs found during execution.

## Commands

```bash
# Backend
php artisan test                          # full suite (native/host only — see warning below)
php artisan test --filter=PreorderTest    # one file
php artisan test --filter=test_arrived_status_increases_stock   # one test
php artisan migrate && php artisan db:seed
php artisan serve                         # :8000

# Frontend
npm test                                  # Vitest, 44 tests, no backend needed (APIs are vi.mock'd)
npm run test:watch
npm run build                             # → public/build, required before Laravel can serve the SPA
npm run dev                               # Vite :5173, proxies /api → :8000

# Backup / restore (WBS 9.2)
php artisan app:backup
php artisan app:restore <path/database.sql> [--force]
```

> **Dated note — 2026-10-16 (feature 048, T066).** Perintah tes di dalam kontainer WAJIB menyertakan `-e CACHE_STORE=array -e SESSION_DRIVER=array`. Tanpanya tes menulis setting bawaan (`system_mode = live`) ke cache file yang juga dibaca API dev, sehingga mode dan outlet dev berubah diam-diam. `tests/TestCase.php` menolak berjalan tanpa flag ini.

## Non-negotiable environment constraints

- **Dated note — 2026-10-11 (constitution 2.0.0):** the premise "one machine per store" is extended to one organisation with one or more outlets, on one machine or one shared server (one server, one database per organisation). The bullets below still apply unchanged.
- **MySQL 8 is required; SQLite hard-fails.** `create_orders_and_payments_tables` and `create_preorders_tables` use raw `DB::statement('ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)')`, which SQLite cannot execute. `phpunit.xml` deliberately does *not* pin `DB_CONNECTION` so that `.env.testing` decides — don't "helpfully" add a sqlite default to it.
- **`.env.testing` must exist and point at a separate database** (`warkop_wjm_test`). It is gitignored. The suite runs `RefreshDatabase`, so pointing it at the app DB destroys real data.
- **Running `php artisan test` INSIDE the `app` Docker container is destructive unless you pass explicit `-e` overrides — this has actually wiped the dev database twice (020-customer-data-import-export, and again 2026-09-27).** `docker-compose.yml`'s `app` service uses `env_file: .env`, which injects `DB_DATABASE=warkop_wjm` (the real dev DB) as a real OS-level environment variable inside the container. phpdotenv never overwrites an environment variable that's already set, so `.env.testing` — and even `-e APP_ENV=testing` alone — silently do **nothing**; `RefreshDatabase` then drops and rebuilds `warkop_wjm` itself. The ONLY safe way to run tests inside the container:
  ```bash
  docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array
  ```
  `tests/TestCase.php::guardAgainstWrongDatabase()` is a hard technical backstop that refuses to run any test at all unless the resolved database name contains `test` — but treat that as a last-resort safety net, not permission to skip the `-e` flags above. If data does go missing, recover with `php artisan db:seed` + `php artisan db:seed --class=SakanaFridgeDemoSeeder` (docs/RUNBOOK.md §Mode C has the full recovery note).
- On this dev machine MySQL runs in the `warkop_wjm-mysql-1` Docker container, exposed to the host at `127.0.0.1:3308` (container-internal port stays `3306` — that's what `mysql`/`DB_HOST=mysql` inside the `app` container resolves to). **Do not `brew install mysql-client`** — that was done once and deliberately reverted. If a CLI tool like `mysqldump` is needed, proxy through `docker exec`, and keep that shim out of committed code.
- **This checkout is a SEPARATE copy from `/Users/chan/www/boothpos` (dated note, 2026-10-03; restaurant variant, feature 028).** It must never share ports, containers, volumes or databases with it. Compose project `warkop_wjm` (pinned via `COMPOSE_PROJECT_NAME` in `.env`); host ports **MySQL `3308`, app `8010`, Vite `5180`** (boothpos keeps `3307`/`8000`/`5174`); databases **`warkop_wjm`** (dev) and **`warkop_wjm_test`** (tests); volume `warkop_wjm_mysql_data`. Other CLAUDE.md passages below that still say `boothpos`, `:8000` or `:3307` describe the original project's history, not this copy. Never run a command against a `boothpos-*` container or the `boothpos`/`boothpos_test` databases from here.
- **Migration filename date prefixes are load-bearing** for FK order. Never rename or reorder them. `payments.preorder_id` is intentionally created *without* a constraint in `orders_and_payments`, then constrained later in `preorders_tables` via `Schema::table()`, because `preorders` doesn't exist yet at that point.

## Architecture

**Business logic lives in `app/Services/`, not controllers.** `OrderService`, `PreorderService`, `StockService`, `SettlementService`, `PaymentRecorder`, `ActivityLogger`, `ProductCodeGenerator`. Controllers validate, delegate, and shape responses. Prices, totals, and stock deltas are always computed server-side — client-supplied amounts are never trusted.

**Authorization is split across three mechanisms.** Before concluding an endpoint is unguarded, check all three:
1. `FormRequest::authorize()` (17 request classes) — e.g. `StockAdjustmentRequest` gates stock adjustments via `canManageMasterData()`
2. Inline `$request->user()->isOwnerOrAdmin()` in 6 controllers (`ReportController`, `OrderController`, `CashierSessionController`, `PaymentProofController`, `PaymentChannelController`, `ActivityLogController`)
3. Policies in `app/Policies/` (Artist, Category, Customer, Event, Product, Setting)

Roles are `owner`, `admin`, `cashier`, `inventory`, with two helpers on `User`: `isOwnerOrAdmin()` and `canManageMasterData()` (owner/admin/inventory). Some object-level checks are ownership-based rather than role-based (a cashier may close/summarize only their own session).

**Pro vs Master licensing** is one setting, `multi_artist_enabled`. All the logic is in `app/Support/LicenseGate.php` and enforced in `ArtistPolicy::create` — not in controllers, and not in the frontend, where the check is cosmetic only. `Setting::get()` caches with model-event invalidation; note the deliberate `filter_var(..., FILTER_VALIDATE_BOOLEAN)` rather than a `(bool)` cast, because `(bool)"false"` is `true` in PHP.

**Stock movement invariants** (`stock_movements.type`: `purchase`, `sale`, `preorder_handover`, `adjustment`, `return`, `initial`). The preorder lifecycle is the easiest thing in this codebase to get backwards:
- Creating a preorder does **not** touch stock (goods don't physically exist yet)
- `arrived` → `purchase` movement, stock **increases**
- `handed_over` → `preorder_handover` movement, stock **decreases** (blocked with 409 unless fully paid)

Stock rows are append-only history; `current_stock` is maintained alongside them by `StockService::applyMovement()`, which is the only sanctioned write path — including for the bulk Excel import.

**`GET /reports/artist-settlements` lists every *active* artist, not only those with sales.** The settlement rows themselves still come only from `GROUP BY order_items.artist_id`, so zero-earning artists are left-joined in at report time with `id: null` and all money `"0.00"`. Use `artist_id` (always present) as the UI row key, not `id`. Inactive/soft-deleted artists appear only if they hold a settlement row for that event.

**`GET /products` omits `variants` unless `?with_variants=1`.** Opt-in on purpose: the product management screen doesn't render variants, the POS does.

**Payment proofs are uploaded before the payment exists.** `POST /payment-proofs` stores the file on the private `local` disk (`storage/app/private/payment-proofs`, never public) and returns a `proof_token`; the subsequent `POST /orders` or preorder payment call carries that token and links the record. Files are served only through an authorizing endpoint.

**Order idempotency** uses a client-generated `local_ref` UUID on `POST /orders`.

**Activity log (F13.4)** writes only through `ActivityLogger`, and always *inside* the same transaction as the mutation it records, so a rolled-back delete never leaves a log claiming it happened.

## API conventions

- **Status codes are meaningful and the frontend depends on them**: `422` shape/validation errors, `409` business-rule conflicts (insufficient stock, session already open, invalid status transition, delete guards), `403` role/ownership denial.
- **Money is returned as a string**, always `number_format((float) $x, 2, '.', '')`.
- **Pagination envelope** is hand-rolled in several controllers: `{"data": [...], "meta": {current_page, per_page, total, last_page}}`.
- **Two response-shaping styles coexist**: `JsonResource` classes in `app/Http/Resources/`, and hand-rolled private `present()`/array builders in `PreorderController` and `ReportController`. The `present()` style guards relations with `relationLoaded()` — if a caller forgets to eager-load, the field silently vanishes from the response rather than erroring. That exact pattern caused a real bug (customer/payments/shipment missing from every preorder response); when touching these, verify the service's `load()`/`fresh()` call includes what `present()` reads.
- `docs/openapi-pos-mvp.yaml` must move in the same commit as any route/response change (PRD §9.5).

## Frontend

Vue 3 SPA (`resources/js/`: `api/`, `stores/` (Pinia), `router/`, `composables/`, `components/`, `views/`), built by Vite into `public/build`, served by Laravel through a catch-all in `routes/web.php` that excludes `/api`. Axios always calls relative `/api/v1/...` — same origin, no base URL config.

- **Tailwind v4, CSS-first.** There is no `tailwind.config.js`; design tokens are `@theme` CSS variables in `resources/css/app.css`. Components use token classes — **no raw hex literals**.
- Plus Jakarta Sans + Phosphor Icons (duotone). This product deliberately does **not** use Mekari Pixel: it ships to external customers, so no internal design-system dependency belongs in it.
- **Frontend tests live in `qa-tests/`; backend tests in `tests/Feature/`.**
- One central error handler maps the 422/409/403/401 convention above; `usePaginatedList` handles the pagination envelope. Reuse them rather than re-implementing per screen.
- Login posts a **`username`**, not an email.
- **Bentuk kartu POS dan item keranjang (`buildProductCards`, `buildSearchCards`, `toCartItem`) hidup di `utils/posProductCards.js`.** Jangan membuat kartu dengan `map` inline di view: kartu hasil pencarian pernah dibuat begitu, memilih sebagian field saja, dan `image_url` yang sudah dikirim `GET /variants/lookup` terbuang sehingga kartu dan baris keranjang tanpa foto (feature 030).
- **Semua unduhan dokumen (invoice, payment invoice, surat jalan, struk, PO, invoice billing) lewat `utils/pdfCapture.js`.** Gambar ditukar ke data URL di klon html2canvas berdasarkan `src`-nya sendiri, **JANGAN PERNAH berdasarkan indeks**: argumen pertama `onclone` adalah klon SELURUH halaman, jadi gambar lain di halaman (avatar, thumbnail) menggeser indeks dan QR pembayaran tampil di slot logo (feature 029). Tes regresinya memakai gambar umpan (`qa-tests/unit/pdfCapture.test.js`). Dokumen invoice dan payment invoice pre-order didefinisikan SEKALI di `components/preorder/PreorderInvoiceDocument.vue` / `PreorderPaymentDocument.vue` — dipakai modalnya DAN unduh massal (`PreordersView.vue::mountBulkDocument`); jangan buat tata letak kedua (`utils/invoiceDocument.js` kini hanya berisi surat jalan).

## Scope discipline

PRD §10.2/§10.3 explicitly **cut** these from MVP — do not build them even if scaffolding hints at them: purchase management (PO to vendors), full production/manufacturing scheduling, flash sale, QR/barcode scanning, granular custom roles, artist self-service portal, printed/PDF catalog, and Excel import *of sales transactions* (PRD F15.9).

**Excel import of master data was un-cut on 2026-09-01** at the product owner's explicit request and is now built (see "Master-data Excel export/import" below). PRD §10.2, §7.15 and README carry dated notes rather than rewritten history — don't "restore" the old scope cut when you read those.

**Vendor/material/BOM tracking was added post-MVP on 2026-09-01** at the product owner's explicit request — see "Vendor, material, and BOM tracking" below. This is a genuinely new capability, not a resurrection of the PRD §10.2 "vendor management" or "materials/production" cuts: it's deliberately narrower than either (no purchase orders, no production scheduling) and doesn't map to any existing F-number. PRD §10.2 carries a dated note rather than rewritten history.

Some mockup elements in `docs/UI-mockups/BoothPOS.dc.html` have no backend and are intentionally left unwired: the receipt PDF button and the Settings "Cadangkan sekarang" button (`app:backup` is CLI-only). The generic "Ekspor .xlsx" buttons on master-data tables now DO have a backend (`GET /exports/{entity}`) but are not wired up in the SPA yet. The mockup's embedded JS also contains fabricated demo data and discount rules — none of it is real logic.

## Domain rules (still in force)

Full history and reasoning for each rule: `docs/claude-md-history.md`. Feature numbers below point there.

- **Master-data Excel import** (`MasterDataImportService`, `app/Support/MasterDataSheets.php`): all-or-nothing, one transaction; a blank cell means "leave unchanged"; stock column is absolute and applied via `StockService`; the Pro/Master quota is re-checked inside the import. Sheet and column names live in one place, shared by export, template and import. Don't fork that list.
- **Stock writes**: `StockService::applyMovement()` is the only writer of stock. Since 045 it takes a required `outletId` (a source-scan test enforces it). `current_stock` is a cached total; `variant_outlet_stock` / `material_outlet_stock` hold the per-outlet balances.
- **BOM writes** (034, 036): `VariantBomService` is the only BOM writer. Never write `product_variant_bom_lines`, or a complete variant's `cost_price`, anywhere else. BOM quantity is a whole number ≥ 1 (`WholeBomQuantity`). A complete BOM syncs `cost_price` and locks it.
- **Payments** (028, 031, 032, 046): `PaymentRecorder::record()` is the only creator of `payments` rows; `PaymentService` wraps it. Never `Payment::create()` elsewhere. Overpayment is refused. Payments are orders-only since 046. Shift cash follows `payments.session_id`.
- **Pricing and availability** (043): `PriceService` is the only writer of `sell_price`. `MenuAvailability` is the only "can this be ordered" rule.
- **DEMO/LIVE** (003): a new business or transactional model must use the `HasDataMode` trait. Raw `DB::table()` queries must filter `data_mode` explicitly. A DB-wide UNIQUE value must be checked across both modes (`withoutGlobalScope`).
- **Outlets** (045): `home_outlet_id` = the item's owner; `outlet_id` = where it is sold or operated. `outlet_id` is never fillable and never read from the request. It is stamped server-side. Scope is `OutletContext`, `OutletScope` and `OutletFilter`. Every raw query over scoped tables must be listed in `OutletScopeRegistry`, or `OutletScopeRegistryTest` fails.
- **Halaman Produk per outlet** (070, catatan bertanggal 2026-10-25): filter Outlet halaman memakai `outlet_id[]`, dan klien MENGIRIM daftar serta `GET /products/summary` tanpa header `X-Outlet-Id` (`skipOutletHeader`), karena header hanya menyempitkan dan membuat filter tak bisa memilih outlet lain; server tetap memotong dengan `permittedIds()`. Daftar, hitungan tab, dan angka kepala dihitung dari SATU `ProductCatalogQuery::filtered()`; angka kepala TIDAK memakai `quality`/`availability` (dipasang oleh klik kartunya sendiri). `POST /products` menerima `variants.*.outlet_prices` (ditulis `PriceService::writeOutletPrice`, source `created`, dalam transaksi `store()`, hanya bila berbeda dari harga dasar) tetapi UI tambah item TIDAK mengirimnya (revisi 2026-10-25: tabel varian buat = tata letak ubah, tanpa kolom outlet, tanpa bagian Ketersediaan); `outlet_ids` kosong ditolak 422 saat membuat. Status Draft/Aktif ada di formulir UBAH.
- **Meja dan stasiun pindah outlet** (071, catatan bertanggal 2026-10-25): `PUT /dining-tables/{id}` dan `PUT /stations/{id}` menerima `outlet_id` (pindah outlet). Aturan "boleh dipindah" DAN penulisannya hanya di `OutletMoveGuard` (meja: tidak ada order `open`; stasiun: tidak dipakai kategori/item menu dan tidak ada tiket dengan item new/preparing/ready); riwayat order/tiket tidak ikut pindah; log aktivitas `moved`. Daftar Restaurant Setup menerima `outlet_id[]` (lewat `OutletContext::narrowedOrNull`) dan diminta TANPA header `X-Outlet-Id` (`skipOutletHeader`, pola 070); filter-nya hidup di `DiningSetupView` (`useOutletPageFilter`).
- **Business day** (042): `business_date` is stamped by `StampsBusinessDay` from `BusinessDay`. Tests that need "today" use `BusinessDay::today()`, not `now()`, because WIB and UTC differ between 00:00 and 07:00.
- **Sales and reports**: the Sales page is POS-only (pre-orders are not listed). Cost and margin fields are added only for users with the `reports` menu. Figures come from `SalesFigures` (042) or `SalesTransactionsService` (list and export share one source). Since 046, reports are by business-date range and sales-only. Don't reintroduce pre-order revenue into any figure.
- **Events and pre-orders are removed** (046): do not add code that reads `events`, `preorders`, `shipments` or `payments.preorder_id`. They are inert history, kept in the database.
- **Shifts** (065): `ShiftSource` is the only place that finds "the open shift for this user". The app and the web each have their own open shift per user.
- **Terminals** (064): a terminal is identified by its name only. There is no code. **Android setup without login** (073, dated note 2026-10-26): the tablet no longer signs in as owner/admin. `POST /pos-setup/outlets/{outlet}/terminals`, `GET /pos-setup/outlets[/{outlet}/terminals]`, `GET /staff/roster` and `POST /auth/pin-sessions` need NO token (a recorded Constitution IV exception: the outlet comes from the path, only names leave these routes). `DeviceService::findOrRegister` is the Android create path (a same-name active terminal is returned, not refused); `pin-sessions` requires an active `device_id` of that outlet; the roster excludes owner/admin (`isOwnerOrAdmin`); the PIN lock is 5 minutes and is shared with approver PINs. Do not re-add an owner/admin login to the Android setup. **Setting PINs from the web** (074, dated note 2026-10-26): owner/admin (menu `settings`; the Users menu alone is not enough) set a user's PIN with `PUT /users/{user}/pin` (`PinAuthService::adminSet`, the only writer — it clears the lock, deletes the user's `pos-pin` tokens and logs `pin_set`/`pin_replaced` without the PIN) and get a random one from `POST /pin-suggestions` (`PinAuthService::suggest`, not stored). `UserResource` exposes only `has_pin` and `pin_locked`; `GET /users?has_pin=` filters. The PIN must never appear in a response (other than the one-time suggestion), log or activity entry, and the weak-PIN rule lives only in `PinAuthService::isWeak`.
- **Documents and PDFs**: every document download goes through `utils/pdfCapture.js`. Images are swapped by their own `src`, never by index (029). Pre-order invoice documents are defined once, in `components/preorder/`.
- **Restore** (backup, 2026-10-01): a restore brings back `license_activations` on purpose. Don't "fix" that. Tests must never call the real restore; use `FakeDatabaseDumper`.
- **Android POS** (`android-pos/`, 047–066): a separate app, released with the server for 064 and 065. `android/` is the old WebView shell. Don't touch it.
  - **Grid Counter per produk** (075, catatan bertanggal 2026-10-26): `MenuMapper` mengelompokkan varian menjadi `ProductEntry` (satu kartu per produk, lencana "N varian", rentang harga, stok total); unit order, habis (86) dan grup opsi tetap per varian (`MenuCatalog.products`), dan pencarian tetap daftar varian. Daftar varian = `Sheet.VariantPicker` (menyimpan id entri, bukan salinan). Foto: URL server SELALU lewat `ServerUrl.resolve` (host `APP_URL` bisa `localhost`) dan digambar `ProductPhoto` lewat `MenuImageLoader` (cache dibatasi byte; tarik-untuk-segarkan mengosongkannya); gagal/kosong = inisial. Status habis hanya dari himpunan ketersediaan server, angka stok hanya tampilan.
- **Android: mode, identitas toko, catatan order** (077, catatan bertanggal 2026-10-27): outlet dan terminal berbeda per mode DEMO/LIVE, jadi tablet menyimpan pilihan terminal **per mode** (`TokenVault.terminalKey`; kunci LIVE tanpa akhiran, DEMO `_demo`) dan membaca mode dari `GET /pos-setup/branding` (tanpa token, pengecualian Konstitusi IV: hanya mode, nama toko, logo) sebelum login dan dari `GET /settings/features` sesudahnya. `StoreBranding` adalah satu-satunya tempat yang memilih nama/logo toko (dipakai `features` dan `branding`). Pergantian mode saat sesi berjalan memunculkan dialog yang tak bisa ditutup dan memblokir tulis order (`PosStore.modeChanged`); 422 `device_id` yang disebabkan pergantian mode BUKAN "terminal dicabut" (`isModeChange` memastikan dulu). `PUT /tabs/{order}/note` (`TabService::setNote`) satu-satunya penulis `orders.notes` dari tablet; log aktivitas mencatat perubahan, bukan isi catatan. Roster tanpa token kini membawa `photo_url` (melebarkan temuan 3 di `specs/073-.../security-findings.md`; keputusan pemilik tertunda). Catatan: Counter berdampingan memakai pembatas yang bisa digeser (`SplitPreference`, per perangkat); layar Android belum diverifikasi di perangkat (lihat `specs/077-.../evidence.md`).
- **Throttle, layar PIN, foto, catatan di dokumen** (078, catatan bertanggal 2026-10-27): pembatas numerik `throttle:N,1` TANPA token memakai kunci `domain|ip` tanpa rute/batas, jadi semua rute tanpa token dari satu IP berbagi SATU hitungan (polling layar PIN menghabiskan jatah 5/menit login web). Karena itu rute tanpa token memakai pembatas BERNAMA (`tablet-read` 300/mnt, `tablet-write` 10, `pin-login` 20 per orang+outlet+IP, `login-flood` 60; didefinisikan di `AppServiceProvider::boot`), dan login web dihitung PER AKUN hanya untuk percobaan GAGAL di `AuthController::login` (5/mnt, kunci `login|username|ip`, dibersihkan saat sukses). SATU renderer 429 di `bootstrap/app.php` (Indonesia + lama tunggu, `code: throttled`). Jangan menambah `throttle:N,1` baru pada rute tanpa token. Layar PIN: `pinLayoutFor` (≥960 dp tiga kolom, 720–959 dua, bawahnya bertumpuk) dan `staffRows` (satu baris per orang; terpilih = baris diperbesar, tanpa blok pengguna terpilih terpisah). Probe PIN membaca branding tiap putaran ke-3. Foto: `UserAvatar` di semua avatar pengguna (termasuk picker penyetuju — `GET /overrides/approvers` kini ada `photo_url`); `PosStore.refreshUser()` membaca ulang `GET /auth/me`. Catatan order tampil di struk (`notes` di receipt, `Catatan order:` di bawah item), KOT (`order_note` di `TicketResource`, dibaca langsung dari order, tanpa salinan; `CATATAN ORDER:` di atas item), dan transaksi (`notes` + kolom ekspor `order_note`). Perbedaan waktu `Hash::check` untuk username tak dikenal belum diperbaiki (lihat `specs/078-.../evidence.md`).
- **Katalog WJM dari Odoo** (068, catatan bertanggal 2026-10-24): `WjmCatalogSeedService` adalah satu-satunya pengisi katalog WJM, dipanggil `app:seed-wjm-catalog` atau `WjmCatalogSeeder` (tidak pernah dari `DatabaseSeeder`). Sumbernya `database/seeders/data/wjm-odoo/catalog.json` + `photos/`, dibangkitkan oleh `specs/068-odoo-catalog-seeder/tools/build_catalog.py` dari `catalog-mapping.md` yang disetujui pemilik. Jangan menyunting `catalog.json` dengan tangan, dan jangan menyalin ekspor XLSX mentah (memuat nama staf) ke repo. Katalog berstatus draf menolak pengisian sungguhan. Service ini dipanggil baik *guarded* (perintah) maupun *unguarded* (`db:seed`), jadi atribut di luar `$fillable` (mis. `Station.outlet_id`) diisi eksplisit. **Katalog kedua WJM 2** (069, catatan bertanggal 2026-10-25): katalog memakai format 2 dan **membawa kebijakan per outlet** (`outlet`, `stations`, `withdraw_others`, `initial_stock`), jadi jangan menambah cabang kode per pelanggan. `WjmCatalogSeedService` menilai "item sudah ada" hanya di dalam outlet tujuan, men-set `products.station_id` tiap item baru (kategori boleh dipakai bersama dua outlet), mengenali stasiun menurut peran Bar/Dapur lewat daftar nama, menolak outlet tujuan yang tidak dikenal (katalog `demo_missing` tidak pernah jatuh ke outlet lain), dan membukukan saldo nol varian baru di outlet tujuan, bukan di outlet bawaan. Data outlet WJM 3 tidak boleh berubah selain penanda "dijual di WJM 2"; dibuktikan lewat tes potret. `GET /pos/categories` menyembunyikan kategori yang punya item tetapi seluruhnya dijual di outlet lain, dengan aturan `OutletMenuService::notCarriedProductIds` yang sama dengan `GET /products?pos=1`. **Halaman tambahan ekspor** (catatan bertanggal 2026-10-25): ekspor produk yang terbagi `… page N.xlsx` ikut dibaca `build_catalog.py`; halaman tanpa kolom jumlah varian memakai jumlah dari tabel pemetaan (dicatat di `variant_count_override`). Penarikan menu `withdraw_others` di LIVE tidak pernah menyentuh item milik outlet tujuan (item buatan pemilik); hanya item outlet lain.

- **Identitas toko di kerangka** (072, catatan bertanggal 2026-10-25): judul tab = `buildDocumentTitle(storeName, appName)` ("{toko} – {aplikasi}", sekali bila sama) dan favicon = logo toko, keduanya dipasang `composables/useBranding.js` dari `AppShell` (pra-login memakai bawaan `app.blade.php`; dikembalikan saat kerangka dilepas). Logo datang dari `GET /settings/features` → `store_logo_url` (terbuka semua peran; `GET /settings` hanya owner/admin). Halaman yang harus pas satu layar (POS) memakai `meta.fillHeight` pada rutenya: `AppShell` setinggi layar (`h-screen`) dan `main` jadi kolom flex; JANGAN memakai `h-full` pada akar halaman itu — judul bawaan membuatnya melampaui `main`.

## Conventions

- **Code comments, docs, commit messages, and UI copy are in Indonesian.** Comments explain *why*, often citing the PRD clause or the bug that motivated the code; several carry a `BUG YANG DITEMUKAN & DIPERBAIKI` header. Match this style.
- Seeded dev accounts (`php artisan db:seed`): `owner`, `admin`, `kasir01`, `kasir02`, `inventory` — all `password123`, local only.
- A remote `origin` (github.com/kurniawanchan/boothpos) exists (dated note 2026-10-11; the earlier "no remote" line was outdated), but **pushing and opening PRs need the developer's explicit per-instance authorization** (constitution; the harness may also deny them) — never push on your own initiative.

<!-- SPECKIT START -->
Active feature plan: `specs/078-android-pin-3col-photos-notes-fixes/plan.md`
(branch `078-android-pin-3col-photos-notes-fixes`, dibuat dari `077-android-mode-branding-counter-notes` yang belum di-commit) — perbaikan "Too Many Attempts" (throttle bernama per bucket + login per akun hanya untuk kegagalan, pesan Indonesia), layar PIN Android 3 kolom tanpa nama ganda, foto pengguna di semua avatar (picker penyetuju + header segar), catatan order di struk, KOT, dan transaksi (server: `notes` di receipt, `order_note` di TicketResource, kolom ekspor). Lihat research.md R1–R5.

Rencana sebelumnya: `specs/077-android-mode-branding-counter-notes/plan.md`.
<!-- SPECKIT END -->
