# Research: Sinkronisasi Stok Riil dengan Transaksi

## Decision 1 — Bentuk: perintah artisan, bukan layar

- **Keputusan**: `php artisan app:sync-stock-from-transactions` — dry-run bawaan, `--apply --approve=<hash> --by=<username>` untuk menulis.
- **Alasan**: ini koreksi sekali pakai atas data yang sudah salah, oleh pemilik/pengembang; preseden langsung `app:clone-live-to-demo` (simulasi bawaan, `--force`, konfirmasi, cadangan). Layar baru berarti route, OpenAPI, i18n dua bahasa, uji browser — berlebihan untuk alat sekali pakai.
- **Ditolak**: layar di Stok (terlalu besar), endpoint API tanpa UI (permukaan serangan baru tanpa pemakai).

## Decision 2 — Sumber kebenaran: buku besar `stock_movements`, bukan tabel transaksi

- **Keputusan**: kuantitas yang dikurangkan dihitung dari `stock_movements`: `sale` (−), `preorder_handover` (−), dikurangi `return` (+, hasil void). Pengurang bersih = penjualan + serah-terima − retur.
- **Alasan**: buku besar sudah memuat tepat apa yang MENGURANGI stok (setiap jalur lewat `applyMovement`); void sudah menjadi `return`; pre-order yang belum diserahkan/dibatalkan memang tidak punya pergerakan (Q1 = A terpenuhi otomatis, sesuai invarian CLAUDE.md "membuat pre-order tidak menyentuh stok"). Menghitung ulang dari `order_items`/`preorder_items` akan menduplikasi aturan status dan bisa berbeda dari yang benar-benar terjadi.
- **Cakupan data mode**: seluruh kueri lewat Eloquent di dalam `ModeGate::runAs('live', …)` sehingga `DataModeScope` menyaring LIVE; `DB::table` TIDAK dipakai (akan melewati scope — lihat CLAUDE.md).

## Decision 3 — Titik acuan ("pembaruan stok riil") per varian

- **Keputusan**: pergerakan `adjustment` TERAKHIR varian itu (id terbesar) yang BUKAN koreksi fitur ini (`reference_type <> 'stock_sync'`). Jendela transaksi yang dikoreksi = pergerakan dengan `id` di antara acuan sebelumnya (pembaruan manual sebelumnya, atau awal buku besar) dan acuan ini.
- **Alasan**: impor stok Excel (`MasterDataImportService`) dan penyesuaian manual keduanya menulis `adjustment`; stok awal varian baru juga `adjustment`. Memakai `id`, bukan `created_at`, karena `created_at` hanya presisi detik dan dua pergerakan dalam detik yang sama tidak terurut; `id` auto-increment dan buku besar append-only, jadi urutannya pasti.
- **Batas yang diterima**: (a) Varian yang diimpor dengan nilai SAMA dengan stok saat itu tidak menulis pergerakan (aturan CLAUDE.md "baris yang cocok tidak menulis movement") → tidak ada acuan → dilewati, status "tidak ada pembaruan manual". (b) Varian dengan ≥ 2 pembaruan manual dikoreksi hanya untuk jendela terakhir dan diberi catatan di Excel — pemilik meninjau. (c) **ASSUMPTION (tidak terverifikasi dengan data nyata)**: tidak ada database LIVE pemilik di mesin ini (kontainer MySQL tidak berjalan), jadi aturan acuan belum diuji terhadap data asli; tinjauan Excel (kolom waktu/ID acuan, daftar sumber) adalah pemeriksaannya.

## Decision 4 — Operand koreksi: stok SEKARANG, bukan angka yang diketik

- **Keputusan**: stok seharusnya = max(0, `current_stock` − pengurang bersih); selisih = seharusnya − sekarang (≤ 0); kekurangan = max(0, pengurang − sekarang).
- **Alasan**: transaksi SESUDAH acuan sudah mengubah `current_stock`; menimpanya dengan (angka diketik − pengurang) akan membuang transaksi itu. Contoh spec (50 − 8 = 42) tetap berlaku bila tak ada transaksi susulan.
- **Pembatasan ke 0 (Q2 = B)**: `StockService` menolak stok negatif; selisih dipotong sehingga hasil ≥ 0. Kekurangan tidak dibuang: kolom Excel `kekurangan` + disebut di `reason` pergerakan. Bila stok sekarang sudah 0 dan ada kekurangan, TIDAK ada pergerakan (`applyMovement` menolak qty nol) — hanya tampil di Excel.

## Decision 5 — Idempoten lewat penanda di buku besar

- **Keputusan**: koreksi ditulis sebagai `adjustment` dengan `reference_type='stock_sync'` dan `reference_id` = id pergerakan acuan. Perencana melewati varian yang sudah punya pergerakan `stock_sync` untuk acuan itu (status "sudah disinkronkan"), dan acuan dipilih dengan mengabaikan `stock_sync`.
- **Alasan**: tanpa penanda, koreksi itu sendiri akan menjadi "pembaruan terakhir" dan jendelanya akan menghitung ulang transaksi antara pembaruan asli dan koreksi → pengurangan ganda (FR-010/SC-004).

## Decision 6 — Persetujuan = kode rencana (hash), bukan sekadar "ya"

- **Keputusan**: dry-run mencetak dan menyimpan di Excel `plan_hash` = sha256 dari baris rencana yang berdampak (variant_id, acuan, stok sekarang, pengurang, selisih). `--apply` wajib `--approve=<hash>`; dihitung ulang, bila beda → tolak, tulis Excel baru, tidak ada perubahan (FR-009). Tambahan: konfirmasi interaktif (non-interaktif butuh `--yes`), `--by=<username>` (harus owner/admin/inventory lewat `canAccessAnyMenu`), dan cadangan pengaman `BackupService::create()` sebelum menulis (preseden clone command).
- **Alasan**: persetujuan terikat pada ANGKA yang ditinjau, bukan pada niat umum; transaksi baru di antara dry-run dan apply otomatis membatalkan persetujuan lama.
- **Ditolak**: satu sesi interaktif "dry-run lalu tanya ya/tidak" (tidak memberi waktu meninjau Excel; tidak tahan terhadap perubahan data); menyimpan rencana di tabel (skema baru tanpa kebutuhan).

## Decision 7 — Penulisan: satu transaksi, jalur tunggal

- **Keputusan**: semua koreksi dalam satu `DB::transaction` yang memanggil `StockService::applyMovement(adjustment, delta, reference_type 'stock_sync', reference_id acuan, reason, user)`. `applyMovement` sudah mengunci baris varian, menolak negatif, dan menulis log `stock_adjusted` (F13.4) di transaksi yang sama. Dijalankan di `ModeGate::runAs('live')` supaya pergerakan baru berstempel `live`.
- **Alasan**: Konstitusi I/IV (satu jalur tulis, audit di transaksi yang sama); semua-atau-tidak-sama-sekali (FR-008).

## Decision 8 — Excel: berkas privat, tanpa PII, dan guard rumus

- **Keputusan**: `Excel::store(..., 'local')` ke `stock-sync/` (privat, bukan disk publik); tiga lembar: `Ringkasan` (hitungan per status, kode rencana, mode, waktu, pelaksana), `Varian` (penjual, produk, varian, SKU, stok sekarang, waktu & id acuan, waktu acuan sebelumnya, terjual, retur, serah-terima pre-order, pengurang bersih, stok seharusnya, selisih, kekurangan, status, catatan), `Sumber` (per varian: jenis, nomor transaksi lewat `StockMovementReferences`, kuantitas, waktu — agar SC-003 bisa diperiksa). Tidak ada kolom pelanggan.
- **Guard rumus**: nama penjual/produk/varian diketik pengguna. `SheetArrayExport` belum punya guard `=`; kelas ini dipakai di sini sehingga guard ditambahkan (binder yang sama dengan `GenericArrayExport`, fitur 050 Decision 5). **Verifikasi pertama saat implementasi**: apakah binder pada objek sheet berlaku di dalam `MultiSheetArrayExport` (tes membaca ulang xlsx); bila tidak, pasang binder pada pembungkus multi-sheet.

## Decision 9 — Cakupan

- Hanya LIVE (spec FR-012). Varian soft-deleted dilaporkan "dilewati". Bahan baku (`material_stock_movements`) di luar cakupan. `purchase` (kedatangan pre-order) tidak dikurangkan/ditambahkan: stok riil yang diketik dianggap sudah memuat barang yang datang (ASSUMPTION; terlihat di Excel lewat daftar sumber hanya untuk jenis yang dikurangkan).
