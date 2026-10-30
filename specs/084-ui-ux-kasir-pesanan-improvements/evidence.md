# Bukti pelaksanaan — 084

Label: INTERNAL. Ditulis 2026-10-30. Hanya yang DIJALANKAN di sesi ini yang ditulis "lulus"; sisanya ditandai **belum dibuktikan**.

## 1. Terbukti (dijalankan, hasil nyata)

| Area | Perintah | Hasil |
|------|----------|-------|
| Backend, fitur baru | `php artisan test --filter='QrisAutoVerifyTest\|PaymentEdcQrisTest\|OverrideQrisVerifyTest\|StandardVariantNameTest\|OrderModifierTest\|…Modifier…'` (di kontainer, DB `warkop_wjm_test`) | 58 lulus |
| Backend, pindah meja | `--filter='TabMoveClosedTest\|TabSplitMergeMoveTest'` | 30 lulus |
| Backend, daftar per shift + laporan | `--filter='ClosedOrdersSessionScopeTest\|SalesTransactionsTest\|ReportTest'` | 49 lulus |
| Backend, tab/order/pembayaran/shift (lebar) | `--filter='Tab\|Order\|Payment\|Shift\|Modifier\|Product\|Qris\|Receipt\|CashierSession\|Session\|Variant\|Stock'` | lihat bagian 4 (hasil akhir) |
| Web | `npm test` (Vitest) | lihat bagian 4 |
| Android JVM | `./gradlew --offline :app:testDebugUnitTest` | **444 lulus, 0 gagal** |

Yang dibuktikan per butir permintaan:

- **Modifier di POS web**: server menghitung harga dari id opsi (klien tak bisa menetapkan harga), snapshot tersimpan, grup wajib kosong/opsi asing → 422, diskon + modifier tetap konsisten (`OrderModifierTest`); keranjang web memisahkan baris per pilihan (`posCartModifiers.test.js`); struk web menampilkan modifier (`ReceiptModalModifiers.test.js`).
- **Struk Android memuat modifier**: sebelumnya TIDAK (temuan: `ReceiptLine` tak punya modifier; hanya KOT/pra-bill). Diperbaiki dari JSON server → mapper → struk → byte (`DocumentsModifiersTest`).
- **"Standard" tersembunyi**: aturan di server untuk order baru (`StandardVariantNameTest`, 6 kasus termasuk sibling nonaktif dan alur tab); dua tes lama yang mengunci "— Standard" diperbarui.
- **QRIS langsung terverifikasi** di jalur tab; baris warisan `pending` tetap menahan tutup shift dan bisa diverifikasi (`QrisAutoVerifyTest`); tes lama yang mengandalkan QRIS `pending` mengembalikan baris ke keadaan warisan.
- **Ubah meja pesanan selesai**: hanya `table_id` berubah, meja terisi boleh, log `table_changed`, meja nonaktif ditolak, void tetap ditolak, peran tanpa `floor` 403 (`TabMoveClosedTest`).
- **Daftar Selesai per shift** (`GET /orders?session_id=`) dan pengosongan saat tutup shift (`ClosedOrdersSessionScopeTest`, `PosStoreKasirFlowTest`).
- **Layar awal Kasir, tab kosong dibuang sebelum tutup shift/kunci** (`PosStoreKasirFlowTest`).
- **Kursor isian uang**: pemetaan offset digit↔tampilan berformat ribuan (`CountInputOffsetTest`, 8 kasus).
- **Karakter struk**: transliterasi tanpa `?` (`EscPosTranslitTest`, 9 kasus; 2 tes lama yang mengunci `?` diperbarui).
- **X/Z-report**: dokumen terbentuk dengan istilah baku dan angka server (`ShiftDocumentsTest`).
- **Status Koneksi**: nama empat keadaan + indikator progres hanya saat menyambung (`PrinterStatusTextTest`).
- **Istilah baku + urutan menu + jam + hamburger (preferensi)**: `TerminologyScanTest` (pemindai string literal), `NavAndClockTest`.
- **Rincian pesanan Selesai lengkap + pilihan meja**: `OrderDetailRowsTest`.
- `docs/openapi-pos-mvp.yaml` valid YAML dan memuat semua perubahan kontrak.

## 2. BELUM dibuktikan (butuh perangkat / browser)

Tidak ada tablet, printer, atau browser otomasi yang terhubung di sesi ini (adb absen; MCP browser gagal tersambung). Tugas berikut TIDAK boleh dianggap selesai sampai diperiksa:

- **T028, T038, T045, T051, T061, T067** (periksa di tablet + printer TM-58V):
  - kursor benar saat mengetik/menyunting/menghapus/menempel di form tutup shift (logikanya teruji, perilaku `VisualTransformation` di perangkat belum);
  - hamburger menampilkan/menyembunyikan sidebar; jam navbar dan jam layar PIN berdetak; nama pengguna di chip;
  - layar awal Kasir setelah login, pemilih Meja, perubahan meja tampil di layar Meja;
  - alur QRIS tanpa langkah verifikasi di layar;
  - struk dengan karakter non-ASCII, modifier, dan tanpa "Standard" TERCETAK benar; X-report dan Z-report tercetak;
  - status printer berubah Menyambung → Tersambung/Gagal tersambung dengan indikator progres (transisi `PrinterService` memakai Bluetooth, tak teruji di JVM);
  - tata letak Pembayaran: "Terima tunai" di kolom pertama; ikon dan label metode sebaris (SEMUA ubin metode dibuat sebaris, bukan hanya EDC).
- **T070** verifikasi browser nyata alur Kasir web dengan modifier (Konstitusi II) — belum dilakukan; hanya uji komponen/unit + uji API.
- **T069** suite backend PENUH (±2000 tes, >1 jam) tidak dijalankan; hanya himpunan lebar di bagian 1/4.
- **T001** garis awal sebelum perubahan tidak ditangkap; tak ada pembanding "sebelum".

## 3. Temuan yang butuh keputusan pemilik

1. **EDC tidak muncul di laporan shift.** `PaymentRecorder` mencatat semua non-tunai `pending`; `ShiftReportService` hanya menghitung `verified`. Maka EDC (`method = edc`) tidak masuk "by_method" X/Z-report; baris yang dulu berlabel "Transfer" membaca `bank_transfer` (yang tablet tak bisa catat). Kini baris berlabel **EDC** dan membaca `edc` — tetap 0 selama EDC `pending`. Pilihan: (a) EDC juga langsung terverifikasi (seperti QRIS), (b) laporan menghitung EDC walau `pending`, (c) biarkan. Tidak diubah karena menyangkut kontrol keuangan.
2. **QRIS otomatis terverifikasi** melepas kontrol verifikasi (dicatat di PRD dan CLAUDE.md). Hanya jalur tab/tablet; `POST /orders` (web) tidak diubah.
3. **Layar awal Kasir membuka tab counter kosong** di server (nomor antrean terpakai tiap login bila tak ada tab kosong). Tab kosong dibuang saat kunci/keluar dan sebelum tutup shift; bila aplikasi dimatikan paksa, tab kosong bisa tertinggal dan memblokir tutup shift sampai dibuang (tombol batal tab ada).
4. **Draf POS web tidak menyimpan modifier**: menyimpan draf saat keranjang bermodifier ditolak dengan pesan (bukan membuang diam-diam).
5. **Hamburger hanya di layar lebar**; layar sempit memakai bilah bawah tanpa sidebar.
6. Istilah "Transfer" pada web (`method_transfer`, "Transfer bank") TIDAK diubah: itu metode `bank_transfer`, bukan EDC.
7. `CLAUDE.md` memuat perintah tes kontainer dengan `-e` SETELAH `php artisan test`; yang benar `docker compose exec -e … app php artisan test …` (flag sebelum nama layanan). Versi di CLAUDE.md gagal dengan "Unknown option -e" (tidak merusak data).

8. **Teks dari server tidak ikut diganti istilahnya.** Pesan galat/validasi yang dikirim server ke tablet (mis. pada `lang/`) masih bisa memuat "order"/"tab"; pemindai istilah hanya memeriksa teks yang ditulis di aplikasi Android. Perlu telaah terpisah bila pemilik menginginkannya.

## 4. Hasil akhir suite lebar

(diisi di bawah setelah dijalankan)
