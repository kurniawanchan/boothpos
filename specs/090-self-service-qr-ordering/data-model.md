# Data Model: Pesan Mandiri via QR Meja

**Klasifikasi**: INTERNAL

**Feature**: 090-self-service-qr-ordering | Nama kolom bersifat rencana; MySQL 8. Semua tabel baru memakai `HasDataMode` (`data_mode`) dan `BelongsToOutlet`; `outlet_id` tidak fillable dan diisi server dari token (Konstitusi IV). Tabel baru wajib didaftarkan di `OutletScopeRegistry`.

## Entitas baru: `self_service_orders`

Potret kiriman pelanggan; sumber struk pelanggan dan sumber pembuatan `orders` saat dispatch.

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `receipt_token` | char(40) UNIQUE | acak, untuk tautan struk pelanggan; bukan id |
| `local_ref` | char(36) UNIQUE | UUID klien, idempotensi |
| `reference_no` | varchar(20) | nomor mandiri, mis. `SS-251103-007`; unik per outlet per `business_date` |
| `business_date` | date | `StampsBusinessDay` saat kirim |
| `outlet_id` | FK outlets | dari token QR |
| `table_id` | FK dining_tables | dari token QR |
| `table_name_snapshot` | varchar | agar struk/suara tetap benar bila meja diubah namanya |
| `service_type` | enum(`dine_in`,`takeaway`) | pilihan pelanggan |
| `status` | enum(`awaiting_verification`,`verified`,`dispatched`,`rejected`) | lihat siklus |
| `lines` | json | potret baris: `variant_id`, `name`, `qty`, `unit_price`, `line_total`, `note`, `modifier_selections[{option_id,qty}]`, `modifiers[]` (nama + selisih harga untuk tampilan) |
| `subtotal`, `tax_amount`, `total_amount` | decimal(14,2) | dihitung server |
| `tax_rate_snapshot` | decimal(5,2) | tarif efektif saat kirim |
| `customer_name`, `customer_phone`, `customer_email` | varchar null | kosong = tamu |
| `payment_channel_id` | FK payment_channels | kanal QRIS yang ditampilkan |
| `proof_id` | FK payment_proofs | bukti yang dilampirkan |
| `verified_by`, `verified_at`, `verify_reference` | FK users null, datetime, varchar(100) | diisi saat verifikasi |
| `rejected_by`, `rejected_at`, `reject_reason` | FK users null, datetime, varchar(255) | |
| `order_id` | FK orders null | terisi saat dispatch |
| `dispatched_by`, `dispatched_at` | FK users null, datetime | |
| `created_at`, `updated_at` | timestamps | |

Indeks: `(outlet_id, status, id)` untuk tab dan polling Android; `(outlet_id, business_date, reference_no)` UNIQUE; `(table_id, status)`.

**Aturan validasi (saat kirim)**
- Meja aktif, outlet aktif, `mode` token = mode sistem, ada kanal QRIS aktif dengan gambar, `proof_id` valid dan belum terpakai.
- `lines`: 1–50 baris; `qty` 1–99; tiap baris lolos `MenuAvailability::assertOrderable` di outlet itu; modifier divalidasi `ModifierResolver`.
- Tidak ada field diskon di permintaan (ditolak 422 bila ada).
- Data diri: bila salah satu terisi → `name` wajib (1–100), `phone` wajib (format Indonesia, 8–15 digit), `email` opsional (format surel, ≤150). Mode tamu: ketiganya kosong.
- `local_ref` yang sama → kembalikan entitas pertama.

**Siklus status**
```
awaiting_verification ──verify──▶ verified ──dispatch──▶ dispatched
        │                            │
        └────────── reject ──────────┘──▶ rejected
```
- `verify`: referensi ≥ 4 karakter; pemegang izin verifikasi QRIS; mengisi `verified_*`.
- `dispatch`: hanya dari `verified`; butuh shift terbuka di outlet; semua efek di satu transaksi (lihat research D3); menghasilkan `order_id`.
- `reject`: alasan wajib; tidak ada efek stok/tiket/pembayaran.
- Transisi lain → 409.

## Perubahan pada tabel yang ada

### `orders`
| Kolom | Perubahan |
|---|---|
| `source` | baru: enum(`pos`,`self_service`) NOT NULL DEFAULT `pos`. **Penanda self-service permanen** (FR-015). Tidak fillable; hanya `SelfServiceOrderService` yang mengisinya (`forceFill`), mengikuti pola `guest_count`/`device_id` di `TabService::open`. |
| `self_service_order_id` | baru: FK null → `self_service_orders`; tautan balik |
| `service_type` | tidak berubah skema; kini diisi `dine_in`/`takeaway` dari POS web dan Android (D13) |

Indeks: `(source, business_date)` untuk filter daftar penjualan.

### `dining_tables`
| Kolom | Perubahan |
|---|---|
| `qr_nonce` | baru: char(16) NOT NULL; diisi acak untuk semua baris lama oleh migrasi; diputar saat "Buat ulang QR" (mencabut QR lama) |

### Pengaturan (`settings`, kunci baru)
- `self_service_base_url` — alamat dasar yang masuk ke QR (kosong → asal halaman staf bila bukan localhost). Dibaca `SelfServiceSettings` (satu pembaca, pola `PosSettings`).

### Peran bawaan
- Kunci menu `self_service` ditambahkan ke owner, admin, kasir lewat migrasi (pola `add_purchase_orders_menu_key_to_default_roles`).

## Entitas yang dipakai ulang tanpa perubahan skema
- `payment_proofs` — `uploaded_by` null, `captured_via` `webcam|upload`. Tertaut ke `payments` saat dispatch lewat `PaymentRecorder`.
- `payments` — satu baris `qr_ewallet` `verified` dibuat `PaymentRecorder` saat dispatch; `reference` = `verify_reference`.
- `customers` — dibuat/dicocokkan saat dispatch (research D12).
- `kitchen_tickets` / `kitchen_ticket_items` — dibuat `KitchenService::send` saat dispatch.

## Potret yang tidak berubah setelah ditulis
`lines`, `subtotal`, `tax_*`, `total_amount`, `reference_no`, `table_name_snapshot` tidak pernah ditulis ulang dari data master (Konstitusi IV, potret transaksi). Hanya `status` dan kolom `verified_*`/`rejected_*`/`dispatched_*`/`order_id` yang berubah.

## Retensi dan data pribadi
- Nama/telepon/surel di entitas adalah data pribadi (UU PDP): tidak masuk log aktivitas, tidak masuk respons publik selain pada struk milik pelanggan itu sendiri (lewat `receipt_token`), tidak masuk ekspor yang diserahkan ke pihak lain.
- Jangka retensi kiriman yang ditolak/lama **belum ditetapkan** — diusulkan: kosongkan kolom data diri pada entitas `rejected` setelah 30 hari. Keputusan pemilik; tidak dibangun di fitur ini kecuali disetujui.
