# Evidence — fitur 083 (Pengaturan Pajak)

Dikumpulkan 2026-10-29 pada sesi implementasi. Hanya yang benar-benar dijalankan dicatat sebagai terbukti.

## Garis dasar (T001, sebelum perubahan)

- Backend: `PosSettingsTest`, `SettingsFeaturesPosTest`, `ReceiptStoreIdentityTest` — 11 lulus (64 assertion).
- Frontend: `npm test -- posTotals` dijalankan; tidak ada kegagalan.

## Terbukti (dijalankan)

| Area | Perintah | Hasil |
|---|---|---|
| Backend fitur ini | `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=TaxSettingTest` | 15 lulus (84 assertion): bawaan tampil aktif, simpan tarif/label/toggle, 6 nilai tak valid → 422 dan nilai lama utuh, batas 0/100/30 karakter/11.5 diterima, kasir 403, log aktivitas lama/baru, pajak tersembunyi tetap ditagih dan tersimpan (700 / 7.700), struk mengikuti toggle tanpa mengubah total, ganti tarif tidak menulis ulang order lama, order tanpa pajak tetap tanpa pajak |
| Frontend fitur ini | `npx vitest run` pada 5 berkas baru (`taxDisplay`, `TaxSection`, `PosTaxLines`, `ReceiptModalTax`, `SalesDetailTax`) | 29 lulus |
| Frontend penuh | `npm test` | 215 berkas, 1808 lulus, 2 dilewati (dilewati sudah ada sebelumnya) |
| Build | `npm run build` | berhasil (43 dtk); bagian Pajak masuk chunk `SettingsView` (48 kB, impor statis seperti bagian lain) |
| Android unit (modul `core-network`) | `./gradlew --offline :core-network:testDebugUnitTest --tests '*FeaturesDtoTaxTest' --tests '*ReceiptDtoTest'` | BUILD SUCCESSFUL |
| Android unit (modul `app`) | `./gradlew --offline :app:testDebugUnitTest --tests '*DocumentsTest' '*ReceiptMapperTest' '*PosStoreRefreshTest' '*DocumentsOrderNoteTest' '*StrukIdentityTest'` | 47 tes lulus, 0 gagal (ReceiptMapper 5, DocumentsOrderNote 10, Documents 19, StrukIdentity 8, PosStoreRefresh 5); kode utama (EditorScreen, Sheets, Documents) ikut dikompilasi |
| OpenAPI | parse YAML setelah edit | valid |

Catatan: `adb` tidak dipakai; Gradle dan JDK ada di mesin ini sehingga tes unit Android BISA dijalankan (koreksi atas anggapan awal di tasks.md T034 bahwa Android tidak bisa diuji sama sekali; yang tidak bisa hanya perangkat).

## Backend suite penuh (T037)

`php artisan test` penuh (perintah aman dengan `-e`, 4052 dtk): **2261 lulus, 1 gagal, 1 dilewati** (18810 assertion). Satu kegagalan: `ShiftReportParityTest > the summary keeps every existing key and value`. Catatan 2026-10-26 sudah mencatat tes ini gagal sebelum pekerjaan ini; saya TIDAK menjalankannya ulang di cabang bersih, jadi "sudah ada sebelumnya" bersandar pada catatan itu, belum dibuktikan di sesi ini. Tidak ada tes pajak yang gagal.

Suite Android penuh (`:app:testDebugUnitTest` tanpa filter) tidak dijalankan; hanya kelas yang disentuh (lihat tabel). T037 karena itu tetap terbuka.

## BELUM terbukti (jangan dianggap selesai)

- **Pemeriksaan browser nyata (T014, T019, T026, T038)**: tidak dilakukan. Server MCP browser (Playwright, Chrome DevTools) gagal tersambung di sesi ini, dan tidak ada cara lain yang saya pakai untuk menjalankan UI. Konstitusi II mewajibkan pemeriksaan ini sebelum fitur dinyatakan selesai. Langkahnya ada di `quickstart.md` (langkah 1–8). `public/build` sudah dibangun ulang sehingga bisa langsung dicoba di http://localhost:8010 (Vite dev di :5180).
- **Android di perangkat/emulator**: tidak dilakukan (tidak ada perangkat). Yang belum teramati: tampilan baris pajak di layar Editor, lembar struk, hasil cetak ke printer termal, dan penyegaran pengaturan di tablet.
- **Tablet lama**: berperilaku seperti sebelumnya (mencetak baris pajak) karena tidak mengenal `tax_show`; tidak diuji, hanya disimpulkan dari kode.
- Tidak ada pemeriksaan DEMO vs LIVE di runtime; keputusan "satu nilai bersama" didasarkan pada pembacaan kode (`tax_*` satu kunci, `Setting` tidak dipisah per mode).

## Perbedaan dari rencana

- T029: cadangan 10% pada `Order.tax` dihapus dan diganti `serverTax ?: 0` (bukan "tarif dari server"). `OrderMapper` selalu mengisi `serverTax` dari server, sehingga cadangan itu tidak terjangkau pada data server; cadangan tarif dari `TaxDisplay` ditiadakan agar `Order` tidak bergantung pada status tampilan.
- T030: tidak ada perubahan kode; pola `tax = try { b.taxDisplay() } catch (_: ApiError) { tax }` di `PosStore.loadMenu()` sudah mempertahankan nilai terakhir saat galat, diverifikasi dengan membaca kode (bukan ditambah tes khusus kegagalan).
- Tes web digabung ke 5 berkas (bukan 7 yang disebut tasks.md); isi yang diminta T017/T024 tercakup, kasus total-tidak-berubah-menurut-toggle ada di `taxDisplay.test.js` alih-alih `posTotals.test.js`.
- Nilai boolean disimpan sebagai `1`/`0` oleh `normalizeValueForStorage`; tes log aktivitas memeriksa maknanya, bukan string `true`/`false`.
