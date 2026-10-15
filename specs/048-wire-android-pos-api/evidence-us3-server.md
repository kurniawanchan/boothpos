**Classification**: INTERNAL

# Bukti US3 sisi server — kas, shift, X-report, tutup buta (T041–T046)

Tanggal: 2026-10-15. Lingkungan: backend dev Docker (app :8010, MySQL :3308, DB `warkop_wjm`), uji di `warkop_wjm_test`.

## Yang dibangun
- Migrasi `2026_12_22_000001`: `cash_movements`, `session_opening_denominations`, kolom hitungan buta di `cashier_sessions` (`counted_by_denomination`, `counted_total`, `edc_batch_total`, `variance`, `z_report`, `device_id`). Diterapkan ke DB dev.
- Kas awal per pecahan (`opening_denominations[]`), jumlah diturunkan server; `opening_cash` lama tetap diterima.
- `ShiftReportService`: satu rumus kas seharusnya untuk ringkasan, X-report, dan Z-report.
- `CashMovementService` + `CashMovementController`: kas masuk/keluar, alasan per arah, ambang kas keluar → 409 `override_required`, kunci baris sesi, catatan aktivitas dalam transaksi yang sama.
- `GET /sessions/{id}/x-report` (tidak tersimpan), `close` dengan hitungan buta (`pending_payments`, `override_required` di atas toleransi, `z_report`, `edc_batch_total`).
- Dua perbaikan dari peninjauan T048: `close` menerima `Idempotency-Key`; pembayaran tab di shift tertutup → 409 `shift_required`.

## Keputusan yang perlu dikonfirmasi pemilik produk
1. **Blind count hanya untuk protokol `counted_by_denomination`.** Penutupan bentuk lama (`closing_cash`) tidak memeriksa QRIS tertunda atau toleransi, karena SPA web belum punya jalur persetujuan untuk 409 itu. Bila pemilik ingin aturan yang sama untuk semua klien, perlu jalur persetujuan (US4) lebih dulu.
2. **QRIS tertunda** = `payments.method = qr_ewallet` dengan `verification = pending` (nilai enum yang ada; QRIS disimpan sebagai `qr_ewallet`).
3. **Kas seharusnya tidak tampil ke kasir sebelum hitungan dikirim.** `summary` dan X-report tidak lagi memuat `expected_cash`; ia hanya ada di respons tutup dan Z-report. Ini dibalik dari rancangan awal yang membocorkannya.
4. **Persetujuan belum ada** (S3/US4): kas keluar di atas ambang dan selisih di atas toleransi ditolak 409 dengan `code`, bukan disetujui.

## Verifikasi
- Uji fokus US3 dan tab: `ShiftRequiredTest`, `ShiftCloseBlindCountTest` (termasuk 10 skenario hitungan tangan dan tes kebocoran kas seharusnya, serta tes retry close), `ShiftReportParityTest`, `CashMovementTest` (9), `SessionOpeningDenominationsTest` (10), `OpenApiCoverageTest`, dan `IdempotencyKeyTest` — lulus.
- Suite penuh pada kondisi akhir server, dijalankan tanpa proses lain: **1678 lulus, 1 dilewati, 0 gagal** (`full-test-final2.log`).

## Insiden dan koreksi (dicatat, bukan disembunyikan)
1. **Tes memakai cache file yang sama dengan API dev.** Di container `CACHE_STORE=file` (dari `.env` lewat env_file), dan `phpunit.xml` tidak memaksanya ke `array`. Akibatnya setting dari satu tes (mis. `system_mode`) bocor ke API dev dan ke tes lain. Dikoreksi: `phpunit.xml` memakai `force="true"` untuk `CACHE_STORE` dan `SESSION_DRIVER`; cache dev dikosongkan (`cache:clear`, hanya cache, bukan data).
2. **Lima kegagalan suite penuh dan dua kegagalan berikutnya** muncul saat ada proses tes lain yang berjalan bersamaan terhadap `warkop_wjm_test` (terlihat dari `migrations doesn't exist` dan `table already exists`). Tidak dapat direproduksi pada suite penuh yang berjalan sendirian. Penyebab langsung tidak dibuktikan lebih jauh.
3. **Tulis di data dev di luar rencana.** Agen Android (sesi T048) menutup shift 5 (outlet 4, Rina, kas Rp 150.000, selisih 0) dan membatalkan tab 2014 dengan alasan "Residu uji US3 (dev)". Keduanya residu uji sendiri, tanpa izin tertulis. Tidak ada data produksi terpengaruh.
4. **PIN dev Rina berubah** dari nilai seed (482916) sebelum sesi ini; sebabnya tidak diketahui. Dikembalikan ke nilai seed langsung di DB dev (hash).

## Belum diverifikasi
- Dua kas keluar bersamaan terhadap tutup shift: kunci baris ada, tetapi belum ada tes konkurensi.
- Kas di shift multi-outlet (outlet induk berbeda dengan outlet penjualan): belum diuji.
- EDC (`edc_batch_total` hanya disimpan, belum dicocokkan; EDC = US4).
- Verifikasi di emulator Android: lihat `evidence-us3.md` (sesi terpisah).
