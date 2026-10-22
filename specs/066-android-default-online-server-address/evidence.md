# Evidence — 066 (2026-10-22)

| Cek | Hasil |
|---|---|
| Android `ServerDiscoveryTest` + `PosStoreConnectionTest` (`./gradlew --offline :app:testDebugUnitTest`) | BUILD SUCCESSFUL; `ServerDiscoveryTest` 15 tes, 0 gagal (6 baru untuk 066) |
| Android `:app:assembleDebug` | BUILD SUCCESSFUL |
| PHP `--filter=ServerAddress` di kontainer (flag `-e` sebelum `app`) | 24 lulus (119 assertion). Sempat 1 gagal: tes lama `owner saves both…` memakai exact JSON tanpa `default_online`; asertionnya diperbarui, bukan perilakunya |
| Vitest `ServerAddressSection`, `serverAddress`, `localeKeys` | 10 lulus |

## Keamanan (T020)
- `GET /server-address` publik tidak memuat `default_online` dan `url` tetap `null` untuk online kosong (`test_the_public_answer_never_carries_the_built_in_address`).
- `GET/PUT /settings/server-address` tetap lewat `authorize('viewAny'/'update', Setting)`; kasir 403 (tes lama masih hijau).
- Baris audit `server_address_updated` dibuat dari `ServerAddress::current()` sebelum `default_online` ditambahkan, jadi bawaan tidak masuk log. Body `PUT` yang memuat `default_online` diabaikan dan tidak tersimpan (`assertDatabaseMissing`).

## Temuan
- Aturan simpan 054 menolak `selected=online` dengan kolom online kosong, sehingga skenario P1 tidak bisa disimpan. Dilonggarkan hanya bila ada bawaan (T022/T023).
- Awalnya perintah tes PHP memakai `-e` setelah nama layanan (`Unknown option "-e"`); urutan yang benar `-e … app php artisan test`.

## Belum diuji
- Penyiapan terminal di tablet/emulator sungguhan (quickstart #4) — tidak ada perangkat; tidak dinyatakan lulus.
- Baseline sebelum perubahan (T001) tidak dijalankan terpisah; tes terkait hijau setelah perubahan.
- Suite backend penuh tidak dijalankan (hanya filter ServerAddress).
