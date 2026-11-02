# Quickstart: memverifikasi Laporan Bahan (088)

**Klasifikasi**: INTERNAL

Perintah tes (WAJIB dengan flag aman, satu proses tes per DB uji):

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=MaterialReport
docker compose exec -T node npx vitest run qa-tests/component/MaterialReportTab.test.js qa-tests/component/DashboardMaterialStats.test.js
```

## Skenario manual (peramban, DB dev, mode DEMO, outlet Warkop WJM Demo)

1. **Risiko (US1)**: jual Indomie Kuah rasa Ayam Bawang beberapa kali sampai saldo Ayam Bawang di WDM ≤ 3 dengan laju ±2/hari. Buka Laporan → Bahan: Ayam Bawang tampil "akan habis" (atau "minus"), hari tersisa sesuai, pilihan AYAM BAWANG dan menu Indomie Kuah tercantum.
2. **Outlet**: ganti navbar ke Warkop WJM 3 — angka berubah ke saldo/laju WJ3; "Semua outlet" menampilkan baris per outlet, tidak dijumlah.
3. **Saran & draf PO (US2)**: pilih Ayam Bawang (bervendor) dan satu bahan tanpa vendor → "Buat draf PO". Satu draf PO per vendor muncul di halaman PO berstatus Draf; bahan tanpa vendor dilaporkan ditolak; stok bahan TIDAK berubah sampai PO diterima. Dengan "Semua outlet" di navbar → ditolak "pilih satu outlet".
4. **Pemakaian (US4)**: catat penjualan, buang (batal "buang" di tab), koreksi −1, terima PO. Tab Pemakaian untuk hari ini menampilkan angka per jenis yang sama dengan Inventori → Stok → tab Bahan untuk hari dan outlet yang sama. Void satu pesanan → pemakaian bersih turun.
5. **Dashboard (US3)**: kartu bahan menampilkan jumlah minus/habis/akan habis dan menu terancam yang sama dengan ringkasan laporan; klik → laporan terbuka dengan saringan status itu. Masuk sebagai Kasir (menu dashboard tanpa reports) → hitungan tampil, rupiah tidak.
6. **Ekspor (US5)**: ekspor dengan saringan aktif; tiga sheet berisi baris yang sama dengan layar; sel yang diawali `=` tetap teks.
7. **Konsol peramban** bersih; tidak ada 4xx/5xx tak terduga.

Catat hasil dan tangkapan di `evidence.md`. Tablet Android tidak berubah pada fitur ini.
