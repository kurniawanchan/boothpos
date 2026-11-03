# Kontrak API: Pesan Mandiri via QR Meja

**Klasifikasi**: INTERNAL

Awalan semua rute: `/api/v1`. Uang selalu string `"12000.00"`. Kode status mengikuti konvensi proyek: `422` validasi, `409` konflik aturan bisnis, `403` peran, `404` tidak ditemukan, `429` dibatasi (renderer tunggal di `bootstrap/app.php`). `docs/openapi-pos-mvp.yaml` diperbarui pada commit yang sama dengan rute (Konstitusi).

## A. Rute publik (tanpa token) — pengecualian Konstitusi IV

Syarat bersama: lolos `EnsureInstallationIsActivated`; `{token}` = token QR (lihat research D6). Token tak sah/mode salah/meja nonaktif → respons seragam (tidak membedakan sebabnya ke luar kecuali pesan ramah) agar token tidak bisa ditebak.

| Metode & rute | Pembatas | Fungsi |
|---|---|---|
| `GET /self-service/{token}/context` | `self-service-read` | nama outlet, logo, nama meja, status layanan (`available` / alasan tidak tersedia), pajak (label, tarif efektif, tampil), kanal QRIS (`id`, `qr_image_url`) |
| `GET /self-service/{token}/menu` | `self-service-read` | kategori + produk + varian + grup modifier (daftar putih field, research D8); `?q=`, `?category_id=` |
| `POST /self-service/{token}/proofs` | `self-service-proof` | multipart `file` + `captured_via` (`webcam`/`upload`) → `{ proof_token }`; JPEG/PNG ≤ 5 MB |
| `POST /self-service/{token}/orders` | `self-service-submit` | kirim pesanan → `201 { receipt_token, reference_no, status, … }`; idempoten lewat `local_ref` |
| `GET /self-service/receipts/{receiptToken}` | `self-service-read` | data struk + status terbaru (potret + status) |

### `POST /self-service/{token}/orders` — permintaan
```json
{
  "local_ref": "uuid",
  "service_type": "dine_in",
  "proof_token": "uuid",
  "customer": { "name": "…", "phone": "…", "email": "…" },
  "items": [
    { "variant_id": 12, "qty": 2, "note": "tanpa gula",
      "modifier_selections": [{ "option_id": 5, "qty": 1 }] }
  ]
}
```
- `customer` dihilangkan = tamu. Ada `customer` → `name` dan `phone` wajib.
- Field harga, total, diskon, outlet, meja **tidak diterima**; mengirim `discount*`, `outlet_id`, atau `table_id` → 422.
- Respons 201/200 (replay): `{ receipt_token, reference_no, status: "awaiting_verification", service_type, table_name, subtotal, tax_amount, total_amount, created_at }`.

### Kode galat publik
| Status | `code` | Arti |
|---|---|---|
| 404 | `qr_invalid` | token rusak/dipalsukan/kedaluwarsa (pesan ramah: minta bantuan staf) |
| 409 | `table_unavailable` | meja/outlet nonaktif atau mode berbeda |
| 409 | `qris_unavailable` | tidak ada kanal QRIS aktif bergambar |
| 409 | `item_unavailable` | memuat `items: [{variant_id, reason}]`; keranjang tidak dihapus |
| 422 | (validasi) | data diri/bukti/qty tidak sah; `proof_token` tak valid atau terpakai |
| 429 | `throttled` | dibatasi |

### Struk publik — bentuk respons
`{ reference_no, pos_order_number|null, status, reject_reason|null, store{name,address,phone,logo_url}, table_name, service_type, lines[{name,qty,unit_price,line_total,note,modifiers[]}], subtotal, tax_label|null, tax_amount, total_amount, payment{method:"QRIS", status}, customer_name|null, created_at }`.
Tidak memuat: HPP, id internal selain yang perlu, data pelanggan lain, surel/telepon pelanggan (hanya nama). `status` ∈ `awaiting_verification|verified|dispatched|rejected`; `payment.status` ∈ `waiting_verification|verified|rejected`.

## B. Rute staf (butuh `auth:sanctum`, `ResolveOutletContext`; gerbang menu `self_service`)

| Metode & rute | Fungsi |
|---|---|
| `GET /self-service/orders` | daftar tab Self-Service; `status[]`, `table_id`, `q`, `page`, `per_page`; dipersempit `OutletContext`; `meta.counts` per status (lencana) |
| `GET /self-service/orders/{id}` | detail pesanan + pembayaran (+ `proof_id`) |
| `POST /self-service/orders/{id}/verify` | body `{ reference }` (≥ 4 karakter) → `verified` |
| `POST /self-service/orders/{id}/reject` | body `{ reason }` (wajib) → `rejected` |
| `POST /self-service/orders/{id}/dispatch` | → `dispatched`; respons memuat `order_id` dan tiket |
| `GET /self-service/alerts?after_id=` | untuk Android: `{ items:[{id, table_name, reference_no, total_amount, created_at}], waiting_count, last_id }`; hanya outlet perangkat; tanpa data pelanggan |
| `GET /payment-proofs/{proof}/file` | (sudah ada) diperluas: pemegang menu `self_service` boleh untuk bukti yang tertaut ke pesanan mandiri di outlet yang dapat dilihatnya |

### Kode galat staf
| Status | `code` | Arti |
|---|---|---|
| 403 | — | tanpa menu/izin |
| 404 | — | di luar outlet pengguna (tidak ditemukan) |
| 409 | `invalid_transition` | status tidak mengizinkan aksi (mis. kirim sebelum verifikasi, sudah dispatch, sudah ditolak) |
| 409 | `no_open_shift` | staf tidak punya shift terbuka di outlet pesanan |
| 409 | `total_changed` | harga/pajak berubah sejak kirim; sarankan Tolak |
| 409 | `item_unavailable` | item habis/dihentikan saat dispatch; memuat rincian |
| 422 | (validasi) | referensi < 4 karakter, alasan kosong |

Semua aksi mengunci baris (`lockForUpdate`) dan menulis `ActivityLogger` di transaksi yang sama.

## C. Perubahan pada rute yang ada

| Rute | Perubahan |
|---|---|
| `GET /dining-tables` | opt-in: `page`, `per_page` (maks 100), `q`, `area`, `is_active`, `outlet_id[]`; ada `page` → envelope `{data, meta:{current_page, per_page, total, last_page, areas[]}}`; tanpa `page` bentuk lama persis |
| `POST /dining-tables/qr-batch` | `{ ids[] }` **atau** `{ filter:{q,area,is_active,outlet_id[]} }`, maks 200 meja → `{ items:[{table_id, outlet_name, table_name, url}] }`; gerbang `dining_setup`; log `table_qr_generated` |
| `POST /dining-tables/{id}/qr/rotate` | memutar `qr_nonce` (mencabut QR lama) → `{ url }`; log `table_qr_rotated` |
| `GET /dining-tables` item | tambah `qr_ready: bool` (alamat dasar valid) — bukan token |
| `POST /orders` | field baru `service_type` (`dine_in\|takeaway`); tersimpan di `orders.service_type` |
| `POST /tabs` | field opsional `service_type`; tanpa itu: meja → `dine_in`, tanpa meja → `takeaway` (perilaku lama) |
| `GET /orders`, detail transaksi, struk, `GET /sales` | memuat `service_type` dan `source`; filter `source=self_service` |
| `GET /settings`, `PUT /settings` | kunci `self_service_base_url` (owner/admin; divalidasi: URL http/https, bukan localhost/loopback) |

## D. Pembatas bernama (`AppServiceProvider::boot`)

| Nama | Aturan awal (ASUMSI, disetel kemudian) |
|---|---|
| `self-service-read` | 120/mnt per IP |
| `self-service-submit` | 5/mnt per IP+meja **dan** 20/jam per IP |
| `self-service-proof` | 10/mnt per IP |

Tidak ada `throttle:N,1` baru pada rute tanpa token (aturan 078).
