INTERNAL

# Rekaman jawaban backend sungguhan (tes kontrak) — feature/orders

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
| `orders_list_200` | #43 | GET /customer/orders | 200 |
| `order_detail_courier_awaiting_200` | #44 | GET /customer/orders/{nomor} (kurir, menunggu konfirmasi) | 200 |
| `order_detail_pickup_cancelled_200` | #69 | GET /customer/orders/{nomor} (ambil di toko, dibatalkan) | 200 |
| `tracking_courier_awaiting_200` | #45 | GET /customer/orders/{nomor}/tracking (sebelum dikirim) | 200 |
| `tracking_courier_shipped_200` | #58 | GET /customer/orders/{nomor}/tracking (sudah dikirim) | 200 |
| `order_detail_other_account_404` | #46 | GET /customer/orders/{nomor} milik akun lain | 404 |
| `tracking_other_account_404` | #47 | GET …/tracking milik akun lain | 404 |
| `order_unknown_number_404` | #48 | GET /customer/orders/CO-0000000000 | 404 |
| `unauthenticated_401` | #22 | GET /customer/me tanpa token (envelope 401 yang sama untuk semua rute /customer/*) | 401 |

Tidak direkam untuk endpoint pelanggan: status confirmed/packing/ready_for_pickup/delivered/picked_up dan detail yang berisi `shipment`. Kosakata status dari rekaman sisi staf (#52-#68) diperiksa sebagai string saja.
