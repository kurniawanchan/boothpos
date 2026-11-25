# Quickstart: Aplikasi Android Manajemen (094)

**Klasifikasi**: INTERNAL

## Prasyarat

- Server dev checkout ini berjalan (Docker, compose project `warkop_wjm`, app di host `:8010`, MySQL `3308`). Jangan pakai port/kontainer `boothpos`.
- Migrasi 094 dijalankan di DB dev: `docker compose exec app php artisan migrate`.
- Android Studio / JDK 17, SDK 34; emulator ponsel (≈6", mis. Pixel 8) dan tablet (≈10", mis. Pixel Tablet).

## Server

```bash
# Tes server — WAJIB flag -e (CLAUDE.md); tanpa ini DB dev terhapus.
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app \
  php artisan test --filter=ManagerSession
```

Satu proses tes per DB tes: jangan menjalankan tes terfilter selagi seluruh suite berjalan.

Uji cepat login manajer (akun seed lokal, kata sandi dev bawaan dari CLAUDE.md, bukan rahasia produksi):

```bash
curl -s -X POST http://127.0.0.1:8010/api/v1/auth/manager-sessions \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"<kata sandi seed>","outlet_id":1}'
# 200 untuk admin; 403 manager_app_denied untuk kasir01
```

## Android

```bash
cd android-pos
./gradlew :manager:assembleDebug          # APK aplikasi manajemen
./gradlew :manager:testDebugUnitTest :core-network:test
./gradlew :app:testDebugUnitTest          # aplikasi kasir tetap hijau (ApiClient generik)
adb install -r manager/build/outputs/apk/debug/manager-debug.apk
```

Di emulator: Pengaturan → profil Lokal `http://10.0.2.2:8010` → Uji sambungan → pilih outlet → login `admin`.

## Skenario verifikasi (catat di `evidence.md`)

1. Login admin → Dashboard; angka sama dengan Dashboard web outlet yang sama.
2. Login kasir01 → ditolak "Akun ini tidak punya akses ke aplikasi manajemen".
3. Web → Peran → matikan penanda peran Admin saat admin login di aplikasi → aksi berikutnya kembali ke login.
4. Buat produk 2 varian + harga outlet + foto di ponsel → web menampilkan field identik.
5. Koreksi stok dan bahan dengan alasan → riwayat web mencatatnya.
6. PO: buat draf → ordered → received → saldo bahan bertambah di web.
7. Dapur & Bar: ubah tiket preparing → ready; web ikut berubah ≤ 5 dtk.
8. Pengaturan Restoran: tambah meja, putar QR, coba pindah meja yang punya order terbuka → 409 ditampilkan.
9. Ulangi 4 di tablet lanskap (daftar-detail berdampingan) dan putar layar di tengah isian.
10. Matikan Wi-Fi di tengah simpan → tidak ada data ganda; pita "Luring" tampil.
