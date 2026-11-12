INTERNAL

# Rekaman jawaban backend sungguhan (tes kontrak) — feature/auth

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
| `register_201 (+_request)` | #15 | POST /customer/auth/register | 201 |
| `login_200 (+_request)` | #17 | POST /customer/auth/login | 200 |
| `register_email_taken_422_registration_failed` | #16 | POST /customer/auth/register (e-mail sama) | 422 |
| `login_wrong_password_401_invalid_credentials` | #18 | POST /customer/auth/login | 401 |
| `login_unknown_email_401_invalid_credentials` | #19 | POST /customer/auth/login | 401 |
| `login_rate_limited_429_too_many_requests` | #73 (percobaan ke-6) | POST /customer/auth/login, header `retry-after: 56` | 429 |
