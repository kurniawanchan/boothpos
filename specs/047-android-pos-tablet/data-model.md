**Classification**: INTERNAL

# Model data klien — 047 (online-first)

Model berikut adalah model **UI/domain klien**; sumber kebenaran tetap server. Uang = `Money` (BigDecimal, 2 desimal, dari string API). Tidak ada tabel lokal pada fase ini (DataStore saja).

| Entitas | Bidang utama | Sumber API | Catatan |
|---|---|---|---|
| `Session` (sesi masuk) | token (terenkripsi), `user{id,name,username,role,menuKeys,permissions}`, `outletId`, `lastActiveAt` | `POST /auth/login`, `GET /auth/me`, `GET /me/scope` | kunci idle 5 menit; logout hapus token |
| `Outlet` | id, code, name | `GET /me/scope` | `X-Outlet-Id` dikirim bila bukan "semua" |
| `Shift` | id, outletId, openedAt, openingCash, status, notes | `GET /sessions/current`, `POST /sessions`, `/sessions/{id}/summary` | tutup: 409 `open_tabs[]` |
| `Area` / `Table` | id, name, area, seats, `state` (free/occupied), `tab?{id,total,paid,readyCount,unsentCount}` | `GET /floor` | `version` untuk polling; status "Bill tercetak"/"Perangkat lain" = menunggu B13/B14 |
| `Category` / `MenuItem` | id, name, price (outlet), imageUrl, categoryId, variants, modifierGroups, soldOut | `GET /products?pos=1&with_variants=1&with_modifiers=1`, `GET /menu/availability` | habis = dari ketersediaan |
| `ModifierGroup`/`Option` | selectionType, isRequired, minSelect, maxSelect, options{id,name,priceDelta} | idem | aturan min/maks ditegakkan di klien (UX) dan server |
| `Tab` (pesanan) | id, number, type (dine-in/takeaway), table?, label?, status, lines[], payments[], subtotal, discount, total, paid, balance | `POST/GET /tabs`, `/tabs/{id}` | total dari server |
| `TabLine` | id, variantId, name, qty, unitPrice, modifiers[], note, sent(bool), prepStatus, station | `/tabs/{id}/items` | terkirim = tak bisa diubah, hanya batal+alasan |
| `Ticket` | id, round, station, items[]{status}, waitingSeconds | `POST /tabs/{id}/send`, `GET /tickets/{id}` | bahan cetak KOT |
| `Tender` | method (cash/bank_transfer/qr_ewallet), channelId?, amount, proofToken?, clientRef | `POST /tabs/{id}/payments` | EDC/QRIS-tertunda menunggu B6/B7 |
| `PaymentChannel` | id, type, provider, accountMasked | `GET /payment-channels` | |
| `Receipt` | orderNumber, store header, lines+modifiers, totals, payments, change, cashier, table, footer | `GET /orders/{id}/receipt` | 409 untuk tab terbuka |
| `PrintJob` | id, printerId, kind (KOT/PREBILL/RECEIPT/…), payload, attempts, state | lokal | antrean cetak, coba ulang 3× |
| `ApiCapabilities` | flag per B-item: pinLogin, approvals, cashMovements, xzReports, edc, qrisPending, tax, … | konstanta (semua `false` fase ini) | UI membaca flag → tampil "menunggu API" |

## Transisi penting
- **Tab**: `open` → (bayar ≥ total) `completed`; `open` → `voided` (butuh izin). Baris: baru → terkirim (send) → (preparing/ready/served dari dapur) → batal (butuh alasan).
- **Print job**: `queued` → `printing` → `done`; gagal → coba ulang hingga 3× → `queued` (tertahan, penanda) → retry/cancel manual.
- **Sesi klien**: `LoggedOut` → `Active` → `Locked` (idle) → `Active` (verifikasi ulang) | `LoggedOut`.
