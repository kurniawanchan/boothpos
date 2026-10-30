# Quickstart & verifikasi — 084

Label: INTERNAL.

## Backend (di dalam kontainer — flag WAJIB, lihat CLAUDE.md)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app \
  php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter='OrderModifier|TabMove|QrisAutoVerify|SessionClosedList'
```

Jangan menjalankan `php artisan test` tanpa `-e` di dalam kontainer (menghapus DB dev).

## Frontend web

```bash
npm test          # qa-tests/: keranjang modifier, kunci baris, validasi opsi
npm run build     # periksa chunk; ModifierPickerModal dimuat dinamis bila hanya dipakai PosView
```

Verifikasi browser (Konstitusi II): login `kasir01` (`password123`), buka Kasir web → pilih menu bermodifier → pilih opsi → bayar → struk/Detail Penjualan memuat modifier; cek konsol tanpa galat.

## Android (JVM, tanpa perangkat)

```bash
cd android-pos && ./gradlew :app:testDebugUnitTest
```

Uji murni yang ditambahkan: `VariantLabel`, transliterasi `EscPos`, pemetaan offset `CountInput`, `Documents.xReport/zReport`, `PrinterStatus`, pemindai istilah lama.

## Butuh perangkat (BELUM diverifikasi di sesi perencanaan ini)

- Tablet: hamburger, jam berdetak, kursor isian uang, pemilih Meja, alur QRIS tanpa verifikasi.
- Printer TM-58V/58 mm: struk dengan karakter non-ASCII, modifier, X-report, Z-report, indikator "Menyambung".
Catat hasilnya di `specs/084-ui-ux-kasir-pesanan-improvements/evidence.md`; jangan menandai selesai tanpa itu.
