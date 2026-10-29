# Research: Pengaturan Pajak (083)

Semua temuan di bawah dibaca langsung dari kode pada 2026-10-29. Tidak ada yang dijalankan.

## Temuan kode yang membentuk rencana

- **Perhitungan sudah benar dan terpusat.** `TaxCalculator::compute()` memakai `PosSettings::taxRate()`, dibulatkan per order (HALF_UP), dan dipakai `OrderService` dan `TabService`. Hasil disimpan di `orders.tax_amount` dan `orders.tax_rate_snapshot`. Order lama dengan tarif 0 tersimpan 0.
- **Tarif dan label sudah ada di `GET /settings/features`** (`tax_rate`, `tax_label`), tetapi tidak ada UI pengubahnya dan tidak ada validasinya di `UpdateSettingsRequest` (kunci bebas, nilai ngawur jatuh ke 0 di `PosSettings::taxRate()` tanpa pemberitahuan).
- **Web:** baris pajak hanya di `PosCartPanel.vue` dan `PosPaymentModal.vue`, digerbang `taxRate > 0`. `ReceiptModal.vue` menampilkan Subtotal, Diskon, Total saja, padahal `GET /orders/{id}/receipt` sudah mengirim `tax_amount`, `tax_rate`, `tax_label`. `OpenOrderDetail.vue` (daftar `totals`) dan `TransactionItemsModal.vue` tidak memuat pajak; yang kedua bahkan menghitung rincian dari baris item ("bukan dari total tersimpan"), sehingga dengan pajak rincian tidak menjumlah ke total.
- **Android:** `EditorScreen.kt` menampilkan baris pajak dari `TaxDisplay`; `Sheets.kt` dan `print/Documents.kt` selalu mencetak baris pajak struk dengan label bawaan "PPN". `PosModels.kt` punya `TAX_RATE = 0.10` yang dipakai sebagai perkiraan lokal bila server belum memberi angka (`serverTax ?: base * 0.10`). `RemoteBackend.taxDisplay()` mengembalikan null bila label kosong, sehingga baris pajak lenyap walau tarif ada.
- **DEMO/LIVE:** `store_name` dipisah per mode lewat dua kunci, tetapi `tax_rate`/`tax_label` hanya satu kunci. Tidak ada tanda bahwa `Setting` dipisah per mode untuk kunci ini (tidak ada referensi `data_mode` di `app/Models/Setting.php`).

## Keputusan

### D1 — Kunci setting baru `tax_show`, bukan menimpa `tax_rate`
- **Keputusan**: tambah `tax_show` (boolean). Tarif tetap menentukan total; `tax_show` hanya menentukan tampilan.
- **Alasan**: FR-006 meminta pajak tetap ditagih saat disembunyikan. Memakai tarif 0 sebagai "sembunyi" akan menghilangkan pajak dari total.
- **Alternatif**: menyembunyikan lewat tarif 0 (ditolak, mengubah total); kolom per-order "tampilkan pajak" (ditolak, spesifikasi meminta tampilan mengikuti toggle saat ini, bukan snapshot).

### D2 — Bawaan `tax_show` = aktif
- **Alasan**: instalasi yang sudah memakai tarif (mis. 10%) tetap menampilkannya setelah pembaruan (FR-005, SC-006). Instalasi tanpa pajak (tarif 0) tak berubah karena baris pajak tidak tampil pada tarif 0.

### D3 — Simpan lewat `PUT /settings` yang ada, tambah validasi di closure `UpdateSettingsRequest`
- **Keputusan**: tiga kunci disimpan sekaligus (`tax_rate` decimal 0–100 maks 2 desimal; `tax_label` string maks 30; `tax_show` boolean) dengan pola closure yang sudah dipakai kunci lain.
- **Alasan**: otorisasi (`SettingPolicy`) dan log aktivitas dalam transaksi sudah ada di jalur ini (FR-002, FR-014). Tidak perlu endpoint baru.
- **Alternatif**: endpoint khusus `PUT /settings/tax` (ditolak: menggandakan jalur tulis dan log).

### D4 — `tax_show` dibaca hanya lewat `PosSettings::taxShow()`
- **Alasan**: pola AppName/LicenseGate; mencegah bawaan yang menyimpang. Nilai disaring dengan `filter_var(..., FILTER_VALIDATE_BOOLEAN)` (bukan cast `(bool)`, sesuai CLAUDE.md).

### D5 — Gerbang tampil di klien, angka tetap dari server
- **Keputusan**: server mengirim `tax_show` pada `features` dan struk; web dan Android menyembunyikan BARIS pajak. Field `tax_amount`, `tax_rate`, `total_amount` tetap dikirim apa adanya.
- **Alasan**: tablet lama yang belum diperbarui tetap mengurai respons tanpa galat. Total selalu dari server sehingga tidak ada selisih (FR-009, FR-011).
- **Risiko**: tablet yang belum diperbarui akan tetap mencetak baris pajak walau toggle mati. Dicatat di quickstart dan catatan rilis; perbaikan penuh butuh APK baru (di luar kendali server).

### D6 — Detail Penjualan memakai angka tersimpan di order
- **Keputusan**: `TransactionItemsModal` dan `OpenOrderDetail` menampilkan `tax_amount` dan `tax_rate` (snapshot) dari order, label dari `tax_label` saat ini; `TransactionItemsModal` memakai `total_amount` tersimpan untuk total dan baris pajak untuk menutup selisih.
- **Alasan**: FR-010 dan SC-004: mengubah tarif tidak mengubah order lama. Baris tampil hanya bila `tax_amount > 0` dan `tax_show` aktif (order lama tarif 0 tidak menampilkan baris).
- **Catatan label**: label adalah pengaturan saat ini, bukan snapshot; order lama akan tampil dengan label baru. Diterima (label bukan angka keuangan); dicatat di Asumsi spesifikasi bila perlu dikonfirmasi.

### D7 — Android: tarif dari server, hapus 10% hardcode
- **Keputusan**: `TaxDisplay` ditambah `show`; `taxDisplay()` tidak lagi null saat label kosong (label bawaan "Pajak"); perkiraan lokal memakai `ratePercent` dari server dan 0 bila belum pernah menerima. Nilai terakhir disimpan di `PosStore` (sudah ada: `tax = try { ... } catch { tax }`).
- **Alasan**: FR-012, FR-013. Perkiraan 10% yang di-hardcode salah bila tarif bukan 10%.
- **Alternatif**: menyimpan ke penyimpanan permanen agar bertahan setelah aplikasi dimulai ulang offline (DITUNDA: bila tablet belum pernah sinkron, perilakunya tarif 0 sesuai FR-013; ditinjau pada tahap tugas bila perlu).

### D8 — DEMO/LIVE: satu nilai bersama
- **Keputusan**: `tax_*` tetap satu kunci bersama kedua mode, tidak dipisah seperti `store_name`.
- **Alasan**: konsisten dengan kunci `PosSettings` lain dan menghindari dua set tarif yang bisa berbeda diam-diam. Spesifikasi (Asumsi) meminta planning mengonfirmasi; dikonfirmasi dari kode: tidak ada pemisahan mode untuk kunci ini.
- **Konsekuensi**: mengubah tarif di mode DEMO juga mengubahnya di LIVE. Bagian Pengaturan perlu teks bantu yang menyebutnya.

### D9 — Ekspor penjualan tidak berubah
- **Alasan**: `tax_total` dan `grand_total` di `SalesFigures` adalah angka laporan; toggle hanya tampilan (Asumsi spesifikasi).

## Pertanyaan terbuka (tidak memblokir)

- Apakah baris pajak tersembunyi juga harus hilang dari ekspor Excel? Rencana: tidak (D9). Konfirmasi lewat `/speckit-clarify` bila pemilik ingin lain.
- Perangkat Android: verifikasi di perangkat belum mungkin (tanpa `adb`); tugas verifikasi Android ditandai belum terbukti sampai ada tablet.
