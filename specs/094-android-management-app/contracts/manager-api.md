# Kontrak API: Aplikasi Android Manajemen (094)

**Klasifikasi**: CONFIDENTIAL (memuat rincian kontrol akses)

Semua path di bawah `/api/v1`. Bagian A = perubahan server (wajib masuk `docs/openapi-pos-mvp.yaml` di commit yang sama). Bagian B = endpoint yang sudah ada dan dipakai ulang tanpa perubahan bentuk. Bagian C = celah validasi yang ditutup.

---

## A. Perubahan server

### A1. `POST /auth/manager-sessions` (BARU, tanpa token, pembatas bernama)

Pembatas: jatah percobaan login yang SAMA dengan `POST /auth/login` (per akun+IP 5/mnt, per akun 30 kegagalan/mnt; satu service `CredentialLogin`). Tidak memakai `throttle:N,1` (CLAUDE.md 078).

Request:
```json
{ "username": "string, required, max:50", "password": "string, required", "outlet_id": "integer, required" }
```

Respons:

| Kode | Kapan | Badan |
|---|---|---|
| 200 | Kredensial benar, peran memegang `manager_app_enabled`, outlet diizinkan | `{ "token": "…", "user": <bentuk sama dengan GET /auth/me> }` |
| 401 | Username/kata sandi salah (pesan seragam, tanpa enumerasi) atau akun nonaktif | `{ "message": "…" }` |
| 403 | Peran tidak memegang penanda | `{ "message": "Akun ini tidak punya akses ke aplikasi manajemen", "code": "manager_app_denied" }` |
| 403 | `outlet_id` di luar outlet yang diizinkan pengguna (penugasan atau `all_outlets`) | `{ "message": "…", "code": "outlet_not_permitted" }` |
| 422 | Bentuk tidak valid / outlet tidak ada atau nonaktif | galat per field |
| 429 | Jatah habis | renderer 429 bersama (`code: throttled`, lama tunggu) |

Aturan:
- Pemeriksaan peran dan outlet dilakukan SETELAH kredensial benar, sehingga respons 403 tidak membocorkan keberadaan akun bagi penebak.
- Token Sanctum dibuat bernama `manager-app`. Tidak ada token yang dibuat pada 401/403.
- `last_login_at` diperbarui seperti login web.
- Log aktivitas: tidak ada entri baru untuk login (sama dengan web). ASUMSI: login web tidak dicatat di log aktivitas; dipastikan saat implementasi.

### A2. Middleware `EnsureManagerAppAccess` (BARU)

Dipasang di grup rute `auth:sanctum` (setelah `EnsureUserIsActive`). Bila `currentAccessToken()->name === 'manager-app'` dan `user->role->manager_app_enabled` bukan true → hapus token itu, balas 403 `{code: "manager_app_denied"}`. Token bernama lain tidak disentuh. JANGAN memakai panggilan `$middleware->alias()` kedua (menimpa peta, CLAUDE.md 090).

### A3. Peran: kolom `manager_app_enabled`

- `GET /roles`, `GET /roles/{id}`: tiap peran membawa `manager_app_enabled: boolean`.
- `POST /roles`: `manager_app_enabled: sometimes|boolean` (bawaan `false`).
- `PUT /roles/{id}`: `manager_app_enabled: sometimes|boolean`.
- Otorisasi tidak berubah (`RolePolicy`, menu `roles`).
- Log aktivitas perubahan peran memuat nilai sebelum/sesudah penanda.
- `GET /auth/me`: TIDAK diubah (aplikasi tidak butuh penanda; server menegakkannya).

### A4. Tutup celah validasi klien-saja (lihat C)

Perubahan aturan, bukan bentuk; tetap dicatat di OpenAPI bila deskripsi aturan berubah.

---

## B. Endpoint yang dipakai ulang (tanpa perubahan bentuk)

Header tiap permintaan bertoken: `Authorization: Bearer <token>`, `Accept: application/json`, `X-Outlet-Id: <outlet terpilih>` kecuali dicatat "tanpa header" (pola `skipOutletHeader` web; kirim `outlet_id[]=<outlet terpilih>` di query).

### B0. Sesi & sambungan
| Metode | Path | Catatan |
|---|---|---|
| GET | `/pos-setup/branding` | uji sambungan + mode (tanpa token) |
| GET | `/server-address` | opsional: alamat pilihan admin (tanpa token) |
| GET | `/pos-setup/outlets` | pilih outlet sebelum login (tanpa token) |
| GET | `/pos-setup/outlets/{outlet}/branding` | nama/logo/mode outlet (tanpa token) |
| GET | `/auth/me` | `menu_keys`, `permissions` → menu yang tampil |
| GET | `/settings/features` | nama/logo toko outlet terpilih, mode (pola 077) |
| POST | `/auth/logout` | hapus token |

### B1. Dashboard (menu `dashboard`)
`GET /dashboard/summary`, `/dashboard/attention`, `/dashboard/shifts`, `/dashboard/orders`, `/dashboard/materials` (rupiah hanya bila `reports`; filter `period`). Ekspor (`/dashboard/export`, `/orders/export`) TIDAK di aplikasi.

### B2. Dapur & Bar (menu `kitchen`)
`GET /stations`, `GET /stations/{id}/tickets?version=` (polling 5 dtk saat tampil), `GET /tickets/{id}`, `PATCH /ticket-items/{id}/status` `{status: preparing|ready|served}`. Transisi hanya maju new→preparing→ready→served; new→preparing→ready butuh `kitchen`, ready→served `kitchen` atau `floor`. Tombol yang tidak boleh disembunyikan (Konstitusi III).

### B3. Pengaturan Restoran (menu `dining_setup`)
| Metode | Path | Catatan |
|---|---|---|
| GET/POST/PUT/DELETE | `/dining-tables[/{id}]` | daftar tanpa header, `outlet_id[]`; PUT kirim `outlet_id` HANYA bila berubah (pindah outlet, `OutletMoveGuard`) |
| POST | `/dining-tables/{id}/qr/rotate` | buat ulang QR |
| GET/POST/PUT/DELETE | `/stations[/{id}]` | idem pindah outlet |
| GET/POST/PUT/DELETE | `/modifier-groups[/{id}]` | termasuk `options[].materials`, `min_qty/max_qty`, `portion_per_option` |
| PUT | `/modifier-groups/{id}/attachments` | dialog Attach (091) |

Ditunda: `POST /dining-tables/qr-batch` (dokumen cetak massal).

### B4. Kategori (menu `categories`)
CRUD `/categories`; `POST /categories/{id}/image` (multipart `image`, jpeg/png ≤ 5 MB). `code` 2 huruf, dikirim huruf besar, tidak bisa diubah.

### B5. Produk (menu `products`)
| Metode | Path |
|---|---|
| GET | `/products` (tanpa header, `outlet_id[]`; `with_variants=1` di detail), `/products/summary`, `/products/{id}`, `/products/{id}/price-history` |
| POST/PUT/DELETE | `/products[/{id}]` |
| POST/PUT | `/products/{id}/variants` (satu / batch), `PUT /variants/{id}`, `DELETE /variants/{id}/permanent` |
| GET/PUT | `/variants/{id}/outlet-prices`, `/variants/{id}/outlet-prices/{outlet}` |
| POST | `/products/{id}/image`, `/variants/{id}/image` |
| PUT | `/products/{id}/modifier-links` (`excluded_group_ids` hanya bila berubah, 086) |
| GET/POST/DELETE | `/products/{id}/recipes`, `GET/PUT/DELETE /recipes/{id}`, `GET /products/{id}/recipe-modifier-options`, `GET /recipe-ingredients` (tulis butuh izin `menu.recipe.edit`) |
| POST/DELETE | `/products/{id}/sold-out`; `POST /products/{id}/discontinue|reactivate` |
| POST | `/stock/adjustments` (langkah terakhir simpan produk bila stok diubah; D15) |

Tidak di aplikasi rilis ini: harga massal (`/price-changes`), rilis menu (`/menu/releases`), BOM varian (`/variants/{id}/bom*`). ASUMSI: ketiganya fitur lanjutan web yang jarang dipakai di lapangan; dikonfirmasi pemilik saat tinjauan rencana.

### B6. Stok (menu `stock`)
`GET /stock/balances`, `/stock/movements`, `/stock/low`; `POST /stock/adjustments` `{reason, outlet_id, items[{variant_id, qty_change}]}`; pindah stok `GET|POST /stock/transfers`, `GET /stock/transfers/{id}`, `POST .../receive`, `POST .../cancel`, `GET /stock/in-transit` (izin `stock.transfer.dispatch|receive|cancel`).

### B7. Vendor (menu `vendors`)
CRUD `/vendors`. `code` alpha_dash ≤ 20, huruf besar, tidak bisa diubah.

### B8. Bahan (menu `materials`)
CRUD `/materials`; `POST /materials/{id}/adjustments`; `GET /materials/{id}/movements`, `GET /materials/movements`; harga vendor `POST /materials/{id}/vendor-prices`, `PUT|DELETE /vendor-prices/{id}`. Saldo = outlet terpilih, tanpa jatuh ke total (`materialStockText`, 087). `standard_cost` hanya dikirim bila `kind = semi_finished`; satuan dari daftar preset `utils/materialUnits.js` + "lainnya".

### B9. Purchase Order (menu `purchase_orders`)
CRUD `/purchase-orders`; `PATCH /purchase-orders/{id}/status` `{status: ordered|received|paid|cancelled, cancel_reason}`; `POST /purchase-orders/{id}/payments`; `GET /purchase-orders/{id}/invoice` (JSON; aplikasi menampilkan + bagikan ringkasan teks, tanpa PDF — research D12). Transisi: draft→ordered|cancelled, ordered→received|cancelled, received→paid.

---

## C. Celah validasi yang ditutup di server (research D16)

| # | Aturan | Letak web hari ini | Perubahan server | Tes |
|---|---|---|---|---|
| C1 | Grup modifier pilihan tunggal: `min_select ≤ 1`, `max_select = 1` | `modifierLinkRows.js:34-50` | DITAMBAH di `ModifierLinkService::validate` (422 `links.N.max_select`, `products.modifier_single_invalid`) | `ModifierLinkSingleSelectTest` |
| C2 | Resep ≥ 1 blok, tiap blok ≥ 1 baris | `RecipeDrawer.vue:350,363` | SUDAH di `RecipeService::validate` (tanpa perubahan) | `RecipeApiTest::test_the_blocks_shape_needs_at_least_one_block_and_one_line_per_block` |
| C3 | Pindah stok varian: jumlah bulat | `NewTransferDrawer.vue:76` | SUDAH di `StockTransferService::normalise` (tanpa perubahan) | `StockTransferTest::test_a_product_line_must_be_a_whole_quantity_but_a_material_line_may_be_fractional` |

Pesan galat berbahasa Indonesia dan memakai kunci field yang sama dengan payload web, sehingga web dan aplikasi menampilkannya di field yang sama.
