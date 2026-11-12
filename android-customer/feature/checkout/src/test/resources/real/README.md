INTERNAL

# Rekaman jawaban backend sungguhan (tes kontrak) — feature/checkout

Sumber: pasangan permintaan/jawaban yang direkam dari server Laravel lokal yang sudah dibangun
(koleksi Bruno `bruno/12-CustomerCommerce`, putaran 1, 2026-11-12). Badan (`*.json`) adalah JSON
verbatim dari server; `*.meta` memuat status HTTP (baris pertama) dan header jawaban yang direkam
(`nama: nilai`). `*_request.json` adalah badan PERMINTAAN yang diterima backend, dipakai untuk
membuktikan bahwa aplikasi mengirim bentuk yang sama.

Data uji sintetis (akun/pesanan buatan koleksi Bruno). Atas kebijakan data pribadi, NILAI e-mail
dan telepon disamarkan (`b***@example.test`, `0800****0011`); tidak ada yang lain diubah. Token dan
kata sandi pada rekaman sudah berupa penanda `<redacted>`. Bagi parser nilai-nilai itu hanya string
buram, jadi samaran tidak mengubah apa yang dibuktikan.

Dipakai lewat `RealPayloads` (`:core:data` testFixtures) oleh `*Real*ContractTest` di modul ini:
tes menyajikan badan + status + header ini lewat MockWebServer ke `Retrofit` + converter +
`NetworkJson` milik :core:network + repository yang dipakai aplikasi.

| Berkas | Rekaman | Permintaan | Status |
|---|---|---|---|
| `quote_courier_200` | #34 | POST /customer/orders/quote | 200 |
| `quote_zero_stock_409_cart_invalid` | #35 | POST /customer/orders/quote (varian stok nol) | 409 |
| `order_create_courier_201 (+_request)` | #36 | POST /customer/orders + Idempotency-Key | 201 |
| `order_create_replay_200` | #37 | POST /customer/orders, kunci sama (putar ulang) | 200 |
| `order_create_pickup_201` | #41 | POST /customer/orders (ambil di toko) | 201 |
| `order_create_key_reused_409_idempotency_key_reused` | #38 | POST /customer/orders, kunci sama isi beda | 409 |
| `order_create_no_key_422_idempotency_key_required` | #39 | POST /customer/orders tanpa kunci | 422 |
| `order_create_foreign_address_422_validation_failed` | #40 | POST /customer/orders dengan alamat akun lain | 422 |

Tidak pernah direkam: 409 `price_changed` dan 503 `customer_commerce_disabled`. Lihat `../derived/README.md`.
