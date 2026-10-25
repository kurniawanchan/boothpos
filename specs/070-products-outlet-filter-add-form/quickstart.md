# Quickstart / uji manual: 070

**Klasifikasi**: INTERNAL

Prasyarat: dev stack berjalan (`app` :8010, Vite :5180), basis data berisi ≥ 2 outlet dengan menu berbeda (mis. WJM 2 dan WJM 3), pengguna `owner`.

## Tes otomatis

```bash
# backend (kontainer, flag WAJIB — lihat CLAUDE.md)
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app \
  php artisan test --filter='ProductCatalogOutletFilter|ProductSummaryFilter|ProductStoreOutletPrices'
npm test
```

## Skenario manual

1. **Filter outlet (US1)**: buka `/products` dengan bilah atas "Semua outlet" → filter Outlet tampil di samping Kategori/Status. Pilih satu outlet → daftar, "N items", dan angka tab ikut berubah. Segarkan halaman → pilihan bertahan (`?outlet=…`). Reset → kembali.
2. **Bilah atas**: pilih "Warkop WJM 2" di bilah atas → filter terisi WJM 2; di filter pilih WJM 3 → daftar menampilkan WJM 3 (header tidak menyempitkan).
3. **Angka kepala (US2)**: catat tiga kartu tanpa filter; pilih satu outlet → "Menu item aktif" = jumlah item aktif outlet itu "dari N item"; tandai satu item 86 di outlet lain → "86 hari ini" tidak berubah. Klik kartu "86 hari ini" → kartu lain tidak ikut jadi 0 (D3). Filter Status = Draft → "Menu item aktif" 0 (diharapkan, D3).
4. **Tambah item (US3)**: "+ Menu item" → tabel varian punya kolom harga per outlet; isi 17.000 untuk WJM 2 saja, simpan; halaman item menampilkan 17.000 di kolom WJM 2 dan harga dasar di outlet lain. Ulangi dengan rilis menu aktif: item draft, harga outlet tersimpan.
5. **Ketersediaan (US4)**: di bagian Ketersediaan, lepas centang WJM 3, simpan → halaman item menampilkan ketersediaan hanya WJM 2. Lepas semua centang → pesan galat, tidak tersimpan. Satu outlet saja: hanya Status.
6. **Tanpa regresi (SC-006)**: bandingkan hitungan item dan harga sebelum/sesudah pemasangan tanpa filter.

## Catatan garis dasar (T001, 2026-10-25, sebelum perubahan)

- Backend `ProductCatalogTest|ProductsOutletsListTest|ProductDetailOutletsTest|ProductOutletsApiTest`: 29 lulus (129 assertion).
- Frontend `npm test`: 193 berkas, 1664 lulus, 2 dilewati.

## Hasil verifikasi (2026-10-25)

- **Backend penuh**: 2022 lulus, 1 dilewati, 1 gagal — `ShiftReportParityTest` (`assertJsonMissingPath('expected_cash')`), gagal sejak fitur 065 dan tidak menyentuh berkas fitur ini; belum dibuktikan pada pohon bersih.
- **Frontend penuh** (`npm test`): semua lulus; `npm run build` sukses, tanpa heks mentah baru.
- **Data nyata LIVE (baca saja, via `ProductCatalogQuery`)** — daftar, kartu "Menu item aktif", dan hitungan tab menu sama untuk setiap filter: tanpa filter 209; WJM 3 (id 9) 79; WJM 2 (id 10) 130; keduanya 209. Tanpa filter identik dengan tangkapan layar (209 dari 209; Kualitas data 1 "no_price"). Dengan WJM 2 kartu Kualitas data beralih ke jenis berikutnya (`no_cost`, 130) karena item tanpa harga milik WJM 3 — sesuai desain D4.
- **Kinerja (SC-005)**: `summary` 21–104 ms, `counts` 3–8 ms pada ±209 item.
- **Belum dilakukan — skenario manual 1–6 di browser (T035)**: peramban otomatis (chrome-devtools/playwright MCP) gagal tersambung pada sesi ini, jadi tampilan (tata letak, lebar tablet/ponsel, konsol) belum dilihat langsung.
