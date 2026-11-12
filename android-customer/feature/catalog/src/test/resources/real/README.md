INTERNAL

# Rekaman jawaban backend sungguhan (tes kontrak) — feature/catalog

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
| `categories_200` | #13 | GET /storefront/categories | 200 |
| `products_list_200` | #10 | GET /storefront/products?q=…&per_page=500 (server memotong ke 50) | 200 |
| `product_detail_200` | #11 | GET /storefront/products/{slug} | 200 |
| `product_not_published_404` | #12 | GET /storefront/products/{slug} (belum terbit) | 404 |
| `products_unknown_sort_422_validation_failed` | #14 | GET /storefront/products?sort=acak | 422 |

Tidak pernah direkam: 503 `customer_commerce_disabled`. Lihat `../derived/README.md`.
