# Data Model: Hubungkan Aplikasi Android POS ke Data Server

**Classification**: INTERNAL — struktur data baru/diubah. Yang sudah ada tidak diulang (lihat `schema-pos-mvp.sql`, fitur 028/042/045/046).

## Tabel & kolom baru (server)

### `devices` (B14, irisan S1)
| Kolom | Catatan |
|---|---|
| id, outlet_id (FK, diturunkan server), code (mis. `T01`, unik per outlet), name | `outlet_id` TIDAK dari body (Konstitusi IV) |
| app_version, os_info, last_seen_at, clock_skew_seconds | diisi heartbeat (S4) |
| paired_by (FK users), paired_at, revoked_at | pairing oleh owner/admin |
| data_mode | `HasDataMode` (daftar ke `OutletScopeRegistry` bila perlu) |

Aturan: kode unik per outlet; perangkat dicabut → token terkait dicabut; satu pengguna boleh memakai banyak perangkat.

### `terminal_counters` (B12, S1)
`device_id` (PK/FK), `receipt_seq` (uint). Dikunci per baris saat mengalokasikan nomor struk. Antrean: `outlet_queue_counters (outlet_id, business_date, queue_seq)`.

### Kolom baru pada `orders` (S1)
| Kolom | Arti |
|---|---|
| `tax_amount` decimal(15,2) default 0 | snapshot pajak; total = subtotal − diskon + pajak |
| `tax_rate_snapshot` decimal(5,2) | tarif saat order — laporan lama tak berubah |
| `receipt_no` string(20) null, unik lintas mode | diisi saat lunas |
| `queue_no` string(8) null | order counter saja |
| `guest_count` tinyint unsigned null (1–99) | B13 |
| `prebill_at` timestamp null, `prebill_count` tinyint | B13; status meja "Bill tercetak" |
| `device_id` FK null | perangkat pembuka (kunci meja lintas perangkat) |

### `overrides` (B2, S3)
| Kolom | Catatan |
|---|---|
| id, outlet_id, action (enum: void_line, cancel_order, discount, cash_out, reprint, qris_verify, refund, availability, variance) | |
| subject_hash char(64) | hash kanonik aksi+parameter |
| requested_by, approved_by (FK users), reason | `approved_by ≠ requested_by` ditegakkan di service dan CHECK bila memungkinkan |
| issued_at, expires_at (issued+120 dtk), consumed_at null, consumed_by_ref | sekali pakai |
Indeks: (subject_hash, consumed_at). Audit ke `activity_logs` di transaksi yang sama.

### `cash_movements` (B3, S2)
`id, session_id (FK), outlet_id, direction (in/out), reason_code, reason_note, amount decimal(15,2) >0, override_id null, created_by, created_at, data_mode`. Ambang persetujuan dari pengaturan (`cash_out_approval_threshold`, default 200000).

### `session_opening_denominations` (B5, S2)
`session_id, denomination int, qty int` — jumlah = `cashier_sessions.opening_cash` (tetap ada; dijaga konsisten oleh service, kolom lama menjadi jumlah turunan).

### Kolom baru pada `cashier_sessions` (B4, S2)
`counted_by_denomination` JSON, `counted_total` decimal, `edc_batch_total` decimal null, `variance` decimal, `variance_override_id` null, `z_report` JSON (salinan angka saat tutup), `device_id` null.

### Pengaturan baru (tabel `settings`, kunci)
`tax_rate`, `tax_label`, `cash_out_approval_threshold`, `variance_tolerance` (default 5000), `edc_duplicate_window_seconds` (default 120), `reprint_free_copies` (default 1), `offline_cash_exposure_limit` (informasi). Dibaca lewat helper tunggal seperti `AppName`/`LicenseGate`.

### Pembayaran (B6/B7, S3)
- `payments.method` bertambah nilai `edc`; kolom `approval_code` string(12) null (huruf besar/angka). QRIS memakai nilai yang ada (`qr_ewallet`) dengan `verification=pending` + kolom `reference` yang sudah ada.
- `payments.business_date`, `session_id` (sudah ada) tidak berubah.

### Peran & izin (B2)
Tidak ada tabel baru. Peran kustom **Waiter**, **Supervisor**, **Outlet Manager** (nama peran default di seeder) + kunci izin baru di `PermissionCatalog`: `order.void`, `order.cancel`, `order.discount.<tier>` (batas % per peran disimpan di `roles.permissions` JSON `discount_limit_pct`), `cash.out.approve`, `order.reprint.approve`, `payment.qris.verify`, `order.refund`, `menu.availability.set`, `shift.variance.approve`. Aturan: **izin menentukan siapa yang boleh menyetujui**, bukan siapa yang boleh meminta.

## Entitas sisi aplikasi (model domain)

| Entitas | Sumber | Catatan |
|---|---|---|
| `TerminalConfig` | penyiapan | `serverUrl, outletId, outletName, deviceCode, deviceId` |
| `Credentials` (di `TokenVault`) | PIN/penyiapan | `adminToken, sessionToken, sessionExpiresAt` — terenkripsi |
| `StaffUser` | `GET /staff/roster` | `id, name, role, hasPin, locked` (tidak ada username/PIN) |
| `MenuCatalog` | `GET /products?pos=1&with_variants=1&with_modifiers=1` | kategori, produk, varian, grup modifier + aturan; `image_url` |
| `Availability` | `GET /menu/availability?pos=1` | etag, daftar id habis |
| `FloorSnapshot` | `GET /floor` | area, meja, order terbuka ringkas, `version` |
| `Order` / `OrderLine` | `TabResource` | angka uang berupa string desimal → `Money` (Long rupiah) di klien; tidak ada perhitungan uang otoritatif di klien |
| `Tender` | `payments[]` | `method, amount, verification, reference, approvalCode` |
| `ShiftSummary` / `XReport` | S2 endpoint | seluruhnya dari server |

Klien menghitung total **hanya untuk tampilan sementara** (mis. saat baris diedit sebelum jawaban server); angka yang tersimpan selalu dari server dan menimpa angka sementara.

## Transisi status penting
- **Order**: `open → completed` (lunas) | `open → voided/cancelled`; pra-bill menambah `prebill_at` tanpa mengubah status; pembagian per item memindah baris ke order baru (tidak ada pembayaran).
- **Pembayaran QRIS**: `pending → verified` | `pending → rejected(failed)`; satu arah, tak ada undo (fitur 032).
- **Override**: `issued → consumed` | `issued → expired`.
- **Shift**: `open → closed`; tutup mensyaratkan: online, tak ada QRIS tertunda, hitungan terkirim, dan persetujuan bila |selisih| > toleransi.
