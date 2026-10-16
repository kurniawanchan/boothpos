**Classification**: INTERNAL

# Peta API: yang sudah ada vs yang belum ada (untuk app Android POS tablet)

Sumber: pembacaan kode (`routes/api.php`, controller, FormRequest, service) pada cabang 047 (= 046), inventaris lengkap di sesi kerja; berkas desain `design/spec` dan `design/screens`. `docs/openapi-pos-mvp.yaml` TIDAK lengkap (tak memuat `/tabs`, `/floor`, `/tickets`, `/ticket-items`, `/stations`, `/dining-tables`, `/modifier-groups`; masih menyebut `/artists`) — ikut dibenahi di fase rencana.

## A. SUDAH ADA — dipakai apa adanya
| Kebutuhan desain | Endpoint yang ada | Catatan |
|---|---|---|
| Login | `POST /auth/login` (username+kata sandi), `GET /auth/me`, logout | Token Sanctum tak kedaluwarsa. Bukan PIN (lihat B1) |
| Pilih outlet | header `X-Outlet-Id`, `GET /me/scope` | |
| Buka/tutup shift, ringkasan | `GET /sessions/current`, `POST /sessions`, `POST /sessions/{id}/close`, `GET /sessions/{id}/summary` | Kas awal tunggal/per outlet, bukan per pecahan |
| Meja (area, status) | `GET /floor?version=` (polling ±3 dtk, `unchanged`) | |
| Menu + modifier | `GET /products?pos=1&with_variants=1&with_modifiers=1`, `GET /variants/lookup` | Aturan wajib/min/maks modifier tersedia |
| Ketersediaan/86 (baca) | `GET /menu/availability?pos=1` (etag, 304) | |
| Order meja / bawa pulang | `POST /tabs` (`local_ref` idempoten), `GET /tabs`, `GET /tabs/{id}` | |
| Baris: tambah/ubah/hapus | `POST/PATCH/DELETE /tabs/{id}/items` | Hapus baris terkirim = batal dengan `reason` |
| Pindah/gabung meja | `POST /tabs/{id}/move`, `/merge` | (Stretch di desain) |
| Kirim ke dapur (KOT) | `POST /tabs/{id}/send` → tiket per stasiun | Data cetak dibangun di klien |
| Status tiket | `GET /tickets/{id}`, `PATCH /ticket-items/{id}/status` (floor: hanya `served`) | |
| Bayar | `POST /tabs/{id}/payments` (`client_ref`; tunai/transfer/QRIS e-wallet; parsial) | |
| Pisah tagihan per item | `POST /tabs/{id}/split` | Stretch |
| Batal seluruh tab | `POST /tabs/{id}/void` (owner/admin + alasan) | Belum pakai persetujuan supervisor |
| Bukti & verifikasi non-tunai | `POST /payment-proofs`, `POST /orders/{id}/payments/{id}/verify` | Dasar untuk verifikasi QRIS |
| Struk | `GET /orders/{id}/receipt` | |
| Daftar order | `GET /orders`, `GET /tabs` | |
| Pelanggan | `GET/POST /customers` | |
| Pengaturan toko | `GET /settings/features`, `GET /payment-channels` | |

## B. BELUM ADA — harus dibuat di backend (diurutkan menurut pengaruh pada R1)
| # | Kebutuhan | Dari desain | Bentuk usulan | Prioritas |
|---|---|---|---|---|
| ✅ B1 | Daftar staf + login **PIN 6 digit** per outlet, kunci 5 salah = 15 mnt, ganti PIN | S02, S27 | `GET /sync/snapshots/staff` (atau `/staff/roster`), `POST /auth/pin-sessions`, `POST /auth/pin-changes`, `POST /auth/token-refresh`, kedaluwarsa token | **SELESAI 2026-10-15** (kecuali `Bagian GET /sync/snapshots/staff`, ditunda bersama B16): `GET /staff/roster`, `POST /auth/pin-sessions`, `POST /auth/pin-changes`, `POST /auth/token-refresh`, `POST /users/{user}/pin-reset`; token PIN 12 jam; kunci 5 salah = 15 mnt |
| ✅ B2 | **Persetujuan atasan terikat satu aksi** (void, batal, 86, QRIS verify, cetak ulang, diskon di atas batas, kas keluar besar, selisih) + jejak | S10, S09, S19, S21 | `POST /overrides {action, payload_hash, approver_user_id, pin}`; kunci izin per aksi; peran Waiter/Supervisor/Outlet Manager sebagai peran kustom + `PermissionCatalog` | **R1 – blokir** |
| ✅ B3 | **Kas masuk/keluar** shift (alasan, ambang Rp 200.000) | S18, S19 | `POST/GET /sessions/{id}/cash-movements` | R1 |
| ✅ B4 | **Laporan X / Z**, tutup shift buta (hitung per pecahan, total batch EDC, selisih + toleransi Rp 5.000 + persetujuan) | S20–S22 | `GET /sessions/{id}/x-report`; perluas `POST /sessions/{id}/close` (`counted_by_denomination`, `edc_batch_total`, `variance_approval`); hasil + Z-report | R1 |
| ✅ B5 | Kas awal **per pecahan** | S03 | perluas `POST /sessions` (`opening_denominations[]`) | R1 |
| ✅ B6 | **Metode EDC** dengan kode persetujuan 6–12 alfanumerik + deteksi bayar ganda 2 mnt | S12 | tambah `method: edc`, `approval_code` pada `POST …/payments` | R1 |
| ✅ B7 | **QRIS statis: catat tertunda → verifikasi** dengan referensi; tertunda memblokir tender non-tunai lain dan penutupan shift | S13, S21 | status `pending` + `POST …/payments/{id}/verify {reference}` pada tab; aturan blokir di server | R1 |
| ✅ B8 | **Pajak eksklusif** (tarif tenant, setelah diskon) pada total tab/struk | S06, S11, S15 | setting `tax_rate`; hitung di `TabResource`/struk | R1 |
| ✅ B9 | **Diskon** order/baris dengan alasan + batas per peran + persetujuan | S08, S10 | `POST /tabs/{id}/discounts`, batas di izin peran | Stretch (desain) |
| ✅ B10 | **Void baris terkirim**: alasan + status dapur + keputusan stok (sisa/wastage), data slip BATAL | S09 | perluas `DELETE …/items/{item}` (`reason_code`, `kitchen_status`, `stock_decision`) | R1 |
| ✅ B11 | **Batal order** (alasan, blok bila ada pembayaran) dengan persetujuan | S08 | `POST /tabs/{id}/cancel` | R1 |
| ✅ B12 | **Nomor struk per terminal** (`T01-000124`), **nomor antrean** konter (`A-024`), penghitung **cetak ulang** ("SALINAN n", salinan pertama gratis) | S14, S18 | field pada order/struk; `POST /orders/{id}/reprints` | R1 |
| ✅ B13 | **Pra-bill** tercetak (status meja "Bill tercetak"), **jumlah tamu** (pax 1–99) per tab | S05, S08 | `POST /tabs/{id}/prebill`; `guest_count` pada `POST /tabs`; tambahkan ke payload `GET /floor` | R1 |
| ✅ B14 | **Perangkat**: pairing, heartbeat (versi app, selisih jam), kunci meja oleh perangkat lain | S01, S23 | `POST /devices/pairings`, `POST /devices/heartbeat`; `device_id` pada tab | R1 |
| B15 | **Idempotensi** untuk tambah/ubah baris & `send` (kini bisa dobel bila diulang) | arsitektur | `client_ref` / `Idempotency-Key` pada `POST …/items`, `PATCH …/items`, `POST …/send` | **SELESAI 2026-10-15**: header `Idempotency-Key` (middleware `idempotent`, tabel `idempotency_keys`, replay `Idempotency-Replayed: true`, 422 bila kunci dipakai permintaan lain, 409 bila masih berjalan) |
| B16 | **Sinkron offline**: `POST /sync/batches` (≤50 event, `ack_seq`, status accepted/rejected/quarantined/deferred), snapshot menu & staf | S23, S28 | sesuai Technical Architecture §3.5 | **Ditunda** (lihat asumsi online-first) |
| ✅ B17 | **86 dengan persetujuan** (kasir butuh atasan) | S29 | perluas `/products/{id}/sold-out` agar menerima `override_id` | R1 |
| ✅ B18 | **Bagi rata** (2–5 orang) & bagian per orang | S09, S11 | `POST /tabs/{id}/split {mode: equal, parts}` | Stretch |
| ✅ B19 | **Refund penuh** (hanya online) | S20 | `POST /orders/{id}/refund` (void seluruh order yang kini hanya owner/admin) | Stretch |
| B20 | Pelaporan **antrean cetak** / status printer ke server | S25 | event `print.reported` | Ditunda |
| ✅ B21 | Dokumentasi: OpenAPI mencakup `/tabs`, `/floor`, `/tickets`, `/stations`, `/dining-tables`, `/modifier-groups` | — | perbarui `docs/openapi-pos-mvp.yaml` | R1 |

**Status 2026-10-16 (fitur 048):** butir yang bertanda ✅ sudah ada di kode; rincian commit:
- **B1** — f8fa41f (PIN login, roster), 01b8a14 (US1 terverifikasi)
- **B2** — 7a9979e (server), cb09cce, 3f4804c (Android)
- **B3** — 8f51d03 (server), 5abb664 (Android)
- **B4** — 8f51d03 (X-report, tutup buta), 5abb664
- **B5** — 8f51d03 (server), 5abb664
- **B6** — 7a9979e (server), 3f4804c, cab4107 (Android)
- **B7** — 7a9979e (server), 3f4804c, cab4107
- **B8** — 85735f8 (S1), febd1a2 (ritel dan laporan), 8af9c30 (web)
- **B9** — 08c8f55 (server), 638dc49 (Android)
- **B10** — 7a9979e (server), 3f4804c (Android)
- **B11** — 7a9979e (server), 3f4804c (Android)
- **B12** — 85735f8 (nomor struk dan antrean), 08c8f55 (salinan), db8913f (tanggal struk)
- **B13** — 85735f8 (pra-bill, guest_count), 638dc49 (Android)
- **B14** — f6a196f (pemasangan), 70529c4 (heartbeat), 3fbcbab (Android). Kunci meja oleh perangkat lain BELUM diverifikasi di slice ini
- **B17** — 7a9979e (server), 3f4804c (Android)
- **B18** — 08c8f55 (server), 638dc49 (Android)
- **B19** — 08c8f55 (refund), 35db271 (netting), 638dc49 (Android)
- **B21** — 98846659 (rute restoran), 85735f8 (S1)
- B15, B16, B20: lihat `specs/048-wire-android-pos-api/` (B15 selesai di f8fa41f; B16 dan B20 tidak dicakup dan tetap terbuka).

## C. Ketidaksesuaian model yang perlu keputusan
- Desain = **local-first** (outbox, DB lokal terenkripsi, sinkron). API sekarang = **online-first** (server menghitung total & stok saat tambah baris). R1 aplikasi ini diasumsikan online-first; offline penuh (B16) menyusul.
- Desain memakai peran Kasir/Waiter/Supervisor/Manajer Outlet dengan batas diskon dan izin per aksi; backend punya peran kustom + `PermissionCatalog` (owner/admin/cashier/inventory bawaan). Peran dan izin baru perlu dikonfigurasi (B2).
- Pengesahan perangkat desain (token perangkat + token pengguna 15 mnt/12 jam) vs token Sanctum tunggal tanpa kedaluwarsa.
