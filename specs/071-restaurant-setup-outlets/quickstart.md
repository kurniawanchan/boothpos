# Quickstart / uji manual: 071

**Klasifikasi**: INTERNAL

Prasyarat: dev stack berjalan; ≥ 2 outlet dengan meja dan stasiun; pengguna `owner`.

## Tes otomatis

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app \
  php artisan test --filter='RestaurantSetupOutlet|DiningTableMove|StationMove'
npm test
```

## Skenario manual

1. **Info + filter (US1)**: buka Restaurant Setup, tab Tables: kolom Outlet tampil, filter Outlet di atas tabel; pilih satu outlet → hanya meja outlet itu. Pindah ke tab Stations: filter yang sama berlaku. Pilih outlet di bilah atas → filter ikut; pilih "Semua outlet" di filter → semua tampil.
2. **Satu outlet**: kolom dan filter tidak tampil.
3. **Pindah meja (US2)**: Edit table → Outlet = outlet lain → Save → meja hilang dari outlet lama (bila terfilter) dan muncul di outlet baru. Pindahkan meja yang punya tab terbuka → ditolak dengan pesan.
4. **Pindah stasiun**: stasiun yang dipakai kategori/item → ditolak dengan alasan; stasiun kosong → berhasil. Nama kembar di tujuan → galat pada kolom nama.
5. **Riwayat**: pesanan lama di meja yang dipindah tetap tercatat di outlet semula; log aktivitas menampilkan "moved".

## Catatan garis dasar (T001, 2026-10-25, sebelum perubahan)

- Backend `DiningTablesTest|StationsTest|OutletScopeRegistryTest`: 29 lulus (156 assertion).
- Frontend `npm test`: 196 berkas, 1687 lulus, 2 dilewati.
- T002: cabang 071 sudah memuat 070 (PR #56 digabung ke `develop-warkop-wjm`), jadi `skipOutletHeader` sudah ada; tidak ada yang diambil dari cabang lain.

## Hasil verifikasi (2026-10-25)

- **Backend penuh**: 2064 lulus, 1 dilewati, 1 gagal — `ShiftReportParityTest` (`assertJsonMissingPath('expected_cash')`), gagal sejak fitur 065 dan tidak menyentuh berkas fitur ini; belum dibuktikan pada pohon bersih. `OutletScopeRegistryTest` hijau (tanpa kueri mentah baru).
- **Frontend penuh** (`npm test`): 200 berkas lulus; 1 kegagalan sekali jalan (`MaterialStockAdjustModal`, timeout 49 dtk saat beban mesin ±20) lulus bila dijalankan sendiri. `npm run build` sukses, tanpa heks mentah baru.
- **Data nyata LIVE (baca saja, via `OutletContext::narrowedIds`)**: Meja total 2 (WJM 2, WJD 0, jumlah 2 = "keduanya" 2); Stasiun total 4 (2 + 2 = 4). Pemindahan TIDAK dijalankan pada basis data dev; aturan, log, dan riwayat tak berubah (SC-004/SC-005) dibuktikan di basis data TES (`DiningTableMoveTest`, `StationMoveTest`, `OutletMoveGuardTest`).
- **Belum dilakukan — skenario manual 1–5 di browser (T030)**: peramban otomatis (chrome-devtools/playwright MCP) gagal tersambung pada sesi ini, jadi tata letak, lebar tablet, dan konsol belum dilihat langsung.
