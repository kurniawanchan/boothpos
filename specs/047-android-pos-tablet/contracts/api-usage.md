**Classification**: INTERNAL

# Kontrak API yang DIPAKAI aplikasi (sudah ada) dan yang MENUNGGU

Rincian bidang lengkap: [`existing-api-inventory.md`](existing-api-inventory.md). Daftar belum-ada: [`../api-gap.md`](../api-gap.md). Base URL `/api/v1`, uang string 2 desimal, konvensi galat 401/403/409/422/423/503.

## Dipakai (per fitur)
| Fitur | Panggilan | Aturan klien |
|---|---|---|
| Masuk | `POST /auth/login` → token+user; `GET /auth/me`; `POST /auth/logout`; `GET /me/scope` | throttle 5/menit → tampilkan sisa waktu; simpan token terenkripsi |
| Shift | `GET /sessions/current` (404 = belum ada), `POST /sessions {opening_cash, outlet_id?}`, `POST /sessions/{id}/close {closing_cash}`, `GET /sessions/{id}/summary` | 409 `outlet_unassigned`/shift sudah terbuka; close 409 `open_tabs[]` ditampilkan |
| Denah | `GET /floor?version=` | polling 3 dtk saat terlihat; `unchanged` → tak render ulang |
| Menu | `GET /products?pos=1&with_variants=1&with_modifiers=1`, `GET /variants/lookup?q=&pos=1`, `GET /menu/availability?pos=1&since=` | etag/304 |
| Tab | `POST /tabs {session_id, table_id?, tab_label?, customer_id?, local_ref}`; `GET /tabs`, `GET /tabs/{id}`; `DELETE /tabs/{id}` (kosong) | 409 `table_occupied{existing_tab_id}` → tawarkan buka tab itu |
| Baris | `POST /tabs/{id}/items {variant_id, qty, note, modifier_option_ids[]}`; `PATCH`/`DELETE …/items/{item}` (hapus terkirim wajib `reason`) | TIDAK idempoten: antrean serial per tab |
| Kirim | `POST /tabs/{id}/send` → `{tab, tickets[]}` | 422 jika tak ada yang dikirim |
| Dapur | `GET /tickets/{id}`; `PATCH /ticket-items/{id}/status` (floor: `served`) | |
| Bayar | `POST /tabs/{id}/payments {client_ref, payments[{method,channel_id,amount,proof_token?}], discount_amount?}` | `client_ref` sekali per niat; replay aman; parsial boleh |
| Bukti | `POST /payment-proofs` (multipart) → `proof_token`; `GET /payment-channels` | opsional |
| Pisah (Stretch) | `POST /tabs/{id}/split {client_ref, items[], payments}` | 409 bila sudah ada pembayaran |
| Pindah/gabung (Stretch) | `POST /tabs/{id}/move`, `/merge` | |
| Struk | `GET /orders/{id}/receipt` | 409 untuk tab terbuka |
| Daftar order | `GET /tabs` (terbuka), `GET /orders?status=&session_id=&date_*` (selesai) | |
| Pengaturan | `GET /settings/features` | nama toko, zona waktu, footer struk |

## Menunggu API (UI ada, tombol/jalur "menunggu API" — `ApiCapabilities`)
PIN & roster (B1) · persetujuan atasan (B2) · kas masuk/keluar (B3) · X/Z & tutup buta (B4) · kas awal per pecahan (B5) · EDC (B6) · QRIS tertunda→verifikasi (B7) · pajak (B8) · diskon (B9) · void baris dengan status dapur/stok (B10) · batal order (B11) · nomor struk/antrean/cetak ulang (B12) · pra-bill & tamu (B13) · perangkat (B14) · idempotensi baris/send (B15) · sinkron offline (B16) · 86 dengan persetujuan (B17) · bagi rata (B18) · refund (B19) · pelaporan cetak (B20).
