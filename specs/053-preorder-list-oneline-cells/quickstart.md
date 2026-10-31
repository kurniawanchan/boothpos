**Klasifikasi**: INTERNAL

# Quickstart / Verifikasi: Sel Satu Baris di Daftar Pre-order (053)

## Tes otomatis

```bash
npx vitest run --exclude '.claude/**' qa-tests/component/PreordersView.test.js qa-tests/component/BaseTooltip.test.js
npx vitest run --exclude '.claude/**'   # seluruh suite frontend
npm run build
```

Tidak ada perubahan backend → `php artisan test` tidak wajib untuk fitur ini (jalankan tetap aman di host; JANGAN di
container tanpa `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`).

## Pemeriksaan di peramban nyata (wajib, Konstitusi II)

Siapkan data: minimal satu pre-order Mail Order berstatus dispatch **Sedang dikirim** (punya tanggal invoice dan
pengiriman), satu **Invoice terkirim**, satu **Menunggu**, satu bernilai ≥ `Rp 10.000.000`, dan satu yang memunculkan
ikon bendera (Mail Order tanpa info pengiriman).

1. Buka **Pre-order** pada lebar jendela 1280 px, semua kolom default.
2. Nomor pre-order: semua satu baris; bendera + nomor sebaris; klik nomor → detail terbuka.
3. Total: `Rp` dan angka selalu sebaris.
4. Status dispatch: label + ikon ⓘ sebaris; tidak ada teks tanggal di bawahnya; baris "Menunggu" tanpa ikon.
5. Arahkan kursor ke ikon → tooltip dua baris (invoice, lalu pengiriman). Tekan Tab hingga ikon fokus → tooltip
   muncul; Escape → tertutup.
6. Buka tooltip di baris terakhir tabel → tooltip tampil utuh (berbalik ke atas bila perlu), tidak memunculkan scrollbar.
7. Tinggi baris dengan dan tanpa tanggal sama.
8. Persempit jendela → tabel menggulir horizontal, nomor/total tetap tidak patah.
9. Konsol peramban bersih dari error.
10. Panel detail: tanggal invoice/pengiriman tetap tampil sebagai teks (tidak berubah).
