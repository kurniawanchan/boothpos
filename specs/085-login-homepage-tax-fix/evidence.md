# Evidence: 085 — Beranda login dua kolom & paritas pajak

**Klasifikasi**: INTERNAL

Setiap bagian dicatat apa adanya.

## Reproduksi pajak

### Server (T003) — dijalankan 2026-10-30, hasil: TIDAK tereproduksi
- Tes sementara (alur nyata `PUT /settings` lalu tambah baris tab) pada tarif 100, 5, 2,5: lulus, pajak = tarif. Tes sementara dihapus; versi permanen ada di `tests/Feature/TaxParityTest.php`.
- DB dev (baca saja): `tax_rate=100`, `tax_label=PPN`, `tax_show=0`. Lima order terakhir: `tax_rate_snapshot=100.00`, `tax_amount=17000.00` atas subtotal 17000, total 34000.
- Kesimpulan: server memakai tarif dari Pengaturan. Dengan `tax_show=0` baris pajak tidak tampil di layar mana pun walau total sudah memuat 100%.

### Web (T004) — BELUM dijalankan
Alat peramban (Playwright, Chrome DevTools) gagal tersambung di sesi ini, jadi pemeriksaan peramban nyata tidak dilakukan. Yang ada hanya tes komponen Vitest (bukan pengganti peramban nyata, Konstitusi II).

### Tablet (T005) — BELUM dijalankan (butuh perangkat dan pemilik)
Perlu dicatat pemilik: nomor build di layar Koneksi (sel "Versi aplikasi", baru), tarif yang terlihat di keranjang/struk, dan nilai `tax_show`.

## Temuan implementasi

- H2 (pratinjau web basi) TERBUKTI MUNGKIN: `AppShell.vue` memanggil `settings.load()` sekali saat shell dibuka, jadi POS yang dibiarkan terbuka memakai tarif lama setelah diubah di tempat lain. Diperbaiki (T010), diuji `PosViewTaxRefresh.test.js`.
- H-mode GUGUR: pajak satu nilai untuk DEMO dan LIVE (083 research D8); CLAUDE.md "dibagi" berarti "dipakai bersama". Kata itu diperjelas.
- H3 (cache) tidak terbukti dan kecil kemungkinannya: `Setting` menghapus kunci cache di event `saved` pada penyimpanan yang sama. Catatan jujur: tes `test_a_rate_changed_by_another_process…` memakai cache `array`, jadi TIDAK membuktikan perilaku cache `file` di dev.
- H1 (APK lama) tidak bisa diperiksa dari sini. Sel "Versi aplikasi" + penanda build (sha git + tanggal) ditambahkan agar pemilik bisa memeriksanya. Catatan: `versionCode` tetap 1, jadi hanya penanda build yang membedakan APK.
- Tidak ada kode pajak server/web/Android yang diubah; semua kasus fixture lulus pada kode yang ada.

## Hasil suite (sejauh ini)

| Suite | Hasil |
|-------|-------|
| `TaxParityTest` (PHPUnit, DB uji) | 17 lulus, 87 asersi |
| `TaxNoHardcodedRateTest` | 2 lulus, 14 asersi |
| `taxParity.test.js` (Vitest) | 16 lulus |
| `PosViewTaxRefresh` + `PosView` + `PosTaxLines` (Vitest) | 22 lulus |
| `TaxParityTest` (Android JVM) | 6 lulus |
| `AppVersionTextTest` (Android JVM) | 2 lulus |
| Suite backend penuh (`php artisan test`, DB uji, flag `-e` benar) | 2305 lulus, 1 dilewati, **1 gagal**: `ShiftReportParityTest > the summary keeps every exi…` (`assertJsonMissingPath('expected_cash')`), 19017 asersi, 3673 dtk |
| `npm test` penuh | 219 berkas, 1839 lulus, 2 dilewati |
| `npm run build` | berhasil |
| Suite Android JVM penuh | 466 lulus, 0 gagal, 0 dilewati |

Kegagalan `ShiftReportParityTest`: fitur ini TIDAK mengubah satu pun berkas di `app/`, `routes/`, `database/`, atau `config/` (`git status` untuk jalur itu kosong), jadi kegagalan itu ada di HEAD `9faac52d` tanpa perubahan 085. Itu bukti bahwa 085 tidak menyebabkannya; penyebab aslinya belum diselidiki dan tidak diperbaiki di sini.

## Pemeriksaan peramban — belum dijalankan

## Pemeriksaan perangkat — belum diverifikasi di perangkat
