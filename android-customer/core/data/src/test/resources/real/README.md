INTERNAL

# Rekaman jawaban backend sungguhan (tes kontrak) — core/data

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
| `storefront_config_200` | #3 | GET /storefront/config | 200 |
| `address_list_200` | #28 | GET /customer/addresses | 200 |
| `address_create_201 (+_request)` | #27 | POST /customer/addresses | 201 |
| `address_create_notes_null_201` | #30 | POST /customer/addresses | 201 |
| `address_update_other_owner_404` | #31 | PUT /customer/addresses/2 (milik akun lain) | 404 |
| `address_delete_other_owner_404` | #32 | DELETE /customer/addresses/2 (milik akun lain) | 404 |
| `logout_204 (hanya .meta)` | #70 | POST /customer/auth/logout | 204 |

Tidak pernah direkam: PUT alamat milik sendiri (200) dan DELETE alamat milik sendiri (204). Tes memakai badan #27 dan header 204 #70 dan menyebutnya di komentar (bentuknya sama menurut `AddressController` PHP).
