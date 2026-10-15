**Classification**: INTERNAL

# Bukti US3 — shift, kas, X-report, tutup buta (2026-10-15, sebagian)

## Yang diperiksa
| Pemeriksaan | Hasil |
|---|---|
| `./gradlew :app:compileDebugKotlin` | BUILD SUCCESSFUL (0 error) |
| `./gradlew :app:assembleDebug` | BUILD SUCCESSFUL, APK terpasang di emulator `wjm_tablet` |
| `./gradlew :core-network:test` | 27 test, 0 gagal (termasuk 8 baru di `ShiftDtosTest`: buka dengan pecahan tanpa `opening_cash`, hitungan buta sebagai pecahan, `edc_batch_total` dihilangkan bila kosong, X-report dengan jam dan metode, `z_report` tersimpan, pengaturan berupa angka, kas masuk/keluar) |
| Server (backend, dari sesi ini sebelumnya) | `SessionOpeningDenominationsTest`, `CashMovementTest`, `ShiftCloseBlindCountTest` (10 skenario tangan + QRIS tertunda + X-report), `ShiftReportParityTest`: 35 lulus pada uji terarah; `OpenApiCoverageTest` lulus |

## Yang diubah di aplikasi
- Layar Shift (hub), X-report, Tutup shift, Hasil tutup, dan Perangkat & sinkron tidak lagi memakai angka contoh. Nilai "Shift 1", "Versi 17", "Selisih 4 dtk", "Batas Rp 3.000.000", "TID 0091", "Sabtu 07:00–15:00", "31 Okt", dan "Refund tunai Rp 0" dihapus.
- Angka shift dari `GET /sessions/{id}/x-report` dan daftar kas dari `GET /sessions/{id}/cash-movements`; keadaan memuat/galat (Coba lagi) bila gagal, bukan angka lama.
- Buka shift mengirim `opening_denominations`; hitungan buta tutup mengirim `counted_by_denomination` + `edc_batch_total`; kas masuk/keluar mengirim `Idempotency-Key` per niat (dilepas hanya pada jawaban pasti).
- Seharusnya (expected cash) tidak tampil di X-report dan layar tutup; hanya di Hasil tutup setelah server menjawab.
- Batas ambang dan toleransi dibaca dari `GET /settings/features` (angka); bila belum terbaca, layar memakai teks umum, tidak angka tebakan.

## Pemeriksaan emulator (T048) — BELUM LULUS (sesi 2026-10-15, lanjutan)

Lingkungan: emulator `wjm_tablet`, APK debug terbaru, terminal T01 (perangkat 1), outlet 4 (Warkop WJM 3). Keadaan DB dibaca read-only lewat tinker (tanpa tulis langsung). Tidak ada uji backend yang dijalankan.

Catatan lingkungan: `ModeGate` kadang membaca `live` karena cache file `setting:system_mode` ditulis oleh proses uji
(sudah dibersihkan dan dijalankan ulang setelah uji selesai). Saat uji berjalan, outlet demo tidak terlihat dan semua
panggilan ber-`X-Outlet-Id` gagal 403.

### Skenario S2 — hasil per langkah
| Langkah | Hasil di layar | Server / DB (read-only) | Status |
|---|---|---|---|
| Buka shift 1×100.000 + 1×50.000 | Total Rp 150.000 | `POST /sessions` 201; sesi 7 `opening_cash` 150000.00, outlet 4, device 1; pecahan 100000×1, 50000×1 | LULUS |
| Penjualan 1: Kopi Tubruk Rp 8.000, tunai uang pas | Lunas, kembalian Rp 0, struk T01-000003 | `POST /tabs/2015/payments` 200; order 2015 completed, paid 8000, change 0 | LULUS |
| Penjualan 2: Kopi Susu Rp 12.000, diterima Rp 20.000 | Lunas, kembalian Rp 8.000, struk T01-000004, antrean A-005 | `POST /tabs/2016/payments` 200; order 2016 completed, paid 20000, change 8000 | LULUS |
| Kas keluar `petty_cash` Rp 20.000 | Tercatat, "Kas keluar … dicatat" | `POST /sessions/7/cash-movements` 201; baris 1: out, petty_cash, 20000.00 | LULUS |
| Kas keluar Rp 250.000 (di atas ambang Rp 200.000) | Peringatan sebelum simpan; toast galat | `POST` 409 (`override_required`); tidak ada baris baru (tetap 1) | LULUS (pesan toast berbahasa Inggris, lihat catatan) |
| X-report: modal, struk, penjualan, kembalian | 150.000 · 2 · 20.000 · 8.000 | Cocok dengan DB untuk keempat angka tersebut | LULUS sebagian |
| X-report: tunai diterima | **Rp 0** | DB: pembayaran tunai 2012 dan 2013 `session_id` NULL → `cash_received` = 0 | **GAGAL** (defek server) |
| Hub shift: tunai bersih | **−Rp 8.000** (seharusnya Rp 20.000) | Turunan dari `cash_received` = 0 | **GAGAL** (defek server) |
| Tutup buta, hitungan Rp 150.000 | Dialog konfirmasi benar; ditolak: "Close the shift only after every open tab…" | `POST /sessions/7/close` 409 `open_tabs`; sesi masih open, tanpa `z_report` | **GAGAL** (lihat catatan 2) |
| Bayar tanpa shift terbuka → `shift_required` | Belum diuji | Tidak bisa dicapai dari aplikasi tanpa tab terbuka di sesi tertutup; penutupan terblokir karena tab 2017 | **BELUM DIUJI** |

Angka yang seharusnya (tangan, dari DB): kas diterima tunai 28.000; kembalian 8.000; kas keluar 20.000; kas seharusnya
= 150.000 + 28.000 − 8.000 − 20.000 = **150.000**. Server saat ini menghitung **122.000** (karena 28.000 tak
ter-attribusi), sehingga hitungan benar Rp 150.000 akan ditolak `override_required` (selisih +28.000).

### Catatan dan defek yang ditemukan
1. **Defek server (blocker):** pembayaran tab (`TabSettlementService::settle`) menyimpan `payments.session_id` = NULL.
   Akibatnya tunai tidak masuk ke shift (X-report, hub, kas seharusnya). Perbaikan yang diperlukan di backend (bukan di sini).
2. **Tab 2017 terbuka dari login:** setiap login kasir membuka counter kosong otomatis (`newCounter()` di `PosStore`).
   Tab terbuka itu memblokir tutup shift (409 `open_tabs`). Perlu keputusan produk: buka counter saat diminta, bukan saat login.
   Tidak diubah di sesi ini.
3. **Server mengirim `hour` sebagai angka (23), kontrak menyebut string ("09").** Klien sudah menerima keduanya
   (`HourlyTotalDto.hour` = JsonElement, uji `jam x-report boleh berupa angka`). Server perlu konsisten.
4. **Pesan server berbahasa Inggris** untuk pengguna dengan bahasa `en` (toast kas keluar, `open_tabs`). Layar kasir
   seharusnya Bahasa Indonesia.
5. **Klien:** panel "Komposisi pembayaran" menghasilkan "−800000%" saat total negatif (belum diklem). Header tetap memuat
   "Shift 1 sejak …" dan jam yang tidak cocok dengan jam perangkat (teks contoh belum diganti).
6. **Dev DB, dicatat:** sesi 7 (Rina) masih terbuka, dengan dua penjualan, satu kas keluar, dan tab 2017 terbuka. Menutup
   sesi atau membatalkan tab 2017 memerlukan izin.

### Belum diverifikasi
- Tutup buta yang berhasil, `z_report`, dan selisih yang tersimpan (tertahan oleh catatan 1 dan 2).
- `shift_required` pada pembayaran tanpa shift (lihat tabel).
- Replay tutup dengan `Idempotency-Key` (tidak pernah sampai ke tahap tutup yang berhasil).
- Logcat `AndroidRuntime` untuk `com.warkop.pos` (tidak diperiksa setelah instalasi terakhir).
- Pengecekan layar 8"/13" dan telepon.
