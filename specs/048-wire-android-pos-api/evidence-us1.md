**Classification**: INTERNAL

# Bukti US1 — masuk PIN dengan staf dan menu asli (2026-10-15)

Lingkungan: backend dev Docker (`warkop_wjm`, app :8010, MySQL :3308) diseed (`WarkopPosStaffSeeder`, `WarkopMenuDemoSeeder`); emulator `wjm_tablet` (Android 35, 2360×1480) ke `10.0.2.2:8010`; APK debug.

## Yang diverifikasi (log `PosApi`, metode+path+status saja)
| Langkah | Hasil |
|---|---|
| Penyiapan terminal: alamat `10.0.2.2:8010` + login admin | `POST /auth/login → 200`, `GET /me/scope → 200`, daftar 4 outlet tampil |
| Daftarkan terminal pada "Warkop WJM 3" | `POST /devices/pairings → 201` (kode **T01** dibuat server), `GET /staff/roster → 200` |
| Layar PIN | 5 staf dari server (Andi/Budi/Dewi/Rina/Sari) dengan peran masing-masing; panel kiri memuat "Warkop WJM 3 · Terminal T01" |
| PIN salah (Andi, `000111`) | `POST /auth/pin-sessions → 401`; layar "PIN salah. Sisa 4 percobaan." (angka dari server); pilihan staf tidak hilang setelah roster dimuat ulang |
| PIN benar (Rina) | `POST /auth/pin-sessions → 200` → `GET /sessions/current → 404` → layar **Buka shift** (Kasir tanpa shift) |
| Muat menu + ketersediaan | `GET /products → 200`, `GET /menu/availability → 200` lalu `304` tiap ±5 dtk (etag) |
| Buka shift (Rp 150.000 dari pecahan) | `POST /sessions → 201`; header "Shift 1 sejak 13:23" dari server |
| Editor | 32 item menu Warkop dari server, kategori Kopi/Tambahan/Camilan/Non-kopi/Makanan, kartu bermodifier berlabel "Pilihan", harga dari server |

`adb logcat -s AndroidRuntime:E` untuk paket aplikasi: **kosong** pada seluruh alur.

## Bukti uji otomatis
`php artisan test --filter='PinLoginTest|IdempotencyKeyTest|DevicePairingTest|PosSettingsTest|OpenApiCoverageTest'` lulus; uji JVM `MoneyTest` (4), `ApiErrorMapperTest` (11), `LogRedactionTest` (2) lulus.

## Belum dibuktikan
- Perubahan harga/habis di back office muncul ≤ 10 dtk (SC-008): jalur poll terbukti (304/200 tiap ±5 dtk) tetapi perubahan nyata belum diuji end-to-end.
- Kunci 5-salah dari sisi aplikasi (server terbukti oleh `PinLoginTest`; aplikasi baru diuji 1 kali salah).
- Order di editor masih LOKAL (belum `POST /tabs`) — itu bagian US2 (T034–T040).
- Sesi PIN kedaluwarsa/perpanjang di aplikasi (T020 menyediakan jalur `refreshSession`, belum dipanggil terjadwal).
