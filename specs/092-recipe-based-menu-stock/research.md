# Research: Stok Menu Berbasis Resep

**Klasifikasi**: INTERNAL

Semua temuan di bawah dari pembacaan kode di sesi ini (bukan asumsi), kecuali yang diberi label **ASUMSI**. Tidak ada `NEEDS CLARIFICATION` tersisa.

## Temuan penjelajahan (fakta)

| # | Temuan | Sumber |
|---|---|---|
| F1 | Bahan resep sudah dipotong saat pesanan MENJADI SELESAI lewat satu penerjemah, `ModifierMaterialConsumption::consumeOrder` (dipanggil dari `OrderService::create`, `TabSettlementService::settle/split`). Tab terbuka TIDAK menyentuh bahan. | `app/Services/ModifierMaterialConsumption.php` |
| F2 | Pergerakan `sale` bahan boleh minus (`MaterialStockService::NEGATIVE_ALLOWED`); tidak ada pemeriksaan kapasitas sebelum pesanan. | `MaterialStockService.php:32,66` |
| F3 | Stok VARIAN dipotong saat baris masuk (`OrderService::create`, `TabService::addLine/updateLine`) lewat `StockService::applyLineMovementIfTracked`, hanya bila `ProductVariant::tracksOwnStock()` (track_stock DAN `stock_source = variant`). Snapshot `order_items.stock_source` menentukan pengembalian. | `StockService.php:46-100`, `ProductVariant.php:99` |
| F4 | Rumus kebutuhan per porsi resep: `quantity ÷ (1 − susut%) ÷ yield(Portions) × (jumlah pilihan, untuk blok bermodifier)`. Bahan nonaktif/terhapus dilewati. | `ModifierMaterialConsumption::addRecipeNeed` |
| F5 | Resep aktif: `Recipe::active()` (tidak arsip, `effective_from` ≤ `BusinessDay::today()`); pembaca tunggalnya `RecipeService::activeRecipesFor*`; `isRecipeDriven()` sudah ada tetapi satu kueri per varian. | `RecipeService.php:240-280` |
| F6 | Payload POS web DAN Android sudah memakai dua bidang per varian: `track_stock` (efektif) dan `outlet_stock` (saldo outlet pemesanan). Web `stockOf(v) = track_stock === false ? null : outlet_stock ?? current_stock`; Android `stock = if (trackStock) (outletStock ?: currentStock) else null`. | `posProductCards.js:19-30`, `MenuMapper.kt:65`, `ProductVariantResource.php:31,51`, `ProductController.php:619-622` |
| F7 | `OutletPriceBook` sudah memuat saldo varian dan saldo bahan outlet pemesanan dengan SATU kueri per permintaan. | `OutletPriceBook.php:32-57` |
| F8 | `GET /menu/availability` hanya membawa tanda 86/diskontinu/versi menu, bukan angka stok. | `MenuAvailabilityController.php` |
| F9 | Galat bisnis 409 dilempar lewat kelas pengecualian khusus (`ShiftRuleException`, `BomRuleException`, `TabConflictException`) yang dirender di `bootstrap/app.php`; `ValidationException` menjadi 422. | `app/Exceptions/*` |
| F10 | Keputusan 087 FR-031: kartu bersumber `modifier` menampilkan porsi tetapi TIDAK PERNAH memblokir. Fitur ini membalik itu HANYA untuk kebutuhan yang berasal dari RESEP (keputusan pemilik 2026-11-06). | CLAUDE.md, spec FR-013 |

## Keputusan

### D1 — Satu penghitung kapasitas di server, klien tidak menghitung

- **Keputusan**: kelas baru `App\Services\MenuStockService` satu-satunya yang menghitung "stok menu beresep" (batch per daftar varian + outlet). Web dan Android hanya membaca hasilnya lewat bidang payload yang SUDAH mereka pakai.
- **Alasan**: FR-015, Konstitusi I dan IV (server satu-satunya sumber angka). Mengisi `outlet_stock` dengan kapasitas dan mengirim `track_stock: true` (F6) membuat kartu POS web, daftar tab, batas qty keranjang, DAN APK lama langsung benar tanpa perubahan klien.
- **Alternatif ditolak**: klien menghitung dari bahan (menggandakan rumus di Kotlin dan JS, risiko angka beda); endpoint stok terpisah (satu round-trip lagi per layar dan dua sumber angka).

### D2 — Satu rumus kebutuhan per porsi, diekstrak, dipakai dua kali

- **Keputusan**: ekstrak rumus F4 ke satu fungsi (`RecipeCost::perPortionNeed(line, yield, times)`); `ModifierMaterialConsumption::addRecipeNeed` dan `MenuStockService` memanggilnya.
- **Alasan**: FR-002 mewajibkan angka tampil == angka terpotong. Dua salinan rumus pasti menyimpang suatu hari (pola bug di riwayat repo).
- **Alternatif ditolak**: membuat `OrderItem` palsu qty 1 untuk memanggil `needFor` (rapuh, mencampur sumber pilihan modifier).

### D3 — Kapasitas = min lantai((saldo − tertahan) ÷ kebutuhan per porsi)

- **Keputusan**: per varian, jumlahkan kebutuhan per porsi bahan yang sama lintas semua resep aktif dan blok DASAR (blok tanpa pilihan); kapasitas bahan = `floor((saldo_outlet − tertahan + 0,0005) ÷ kebutuhan)`, dijepit ≥ 0; stok menu = minimum atas semua bahan; bahan penghambat = yang mencapai minimum (seri → bahan dengan id terkecil, deterministik).
- **Alasan**: contoh spec (20/10/10/10 → 10). Toleransi 0,0005 sama dengan toleransi buku besar bahan (tiga desimal) agar 0,3 ÷ 0,1 tidak jadi 2 karena pecahan biner.
- **Kasus tepi**: resep aktif tetapi semua bahannya nonaktif/terhapus → tidak ada pembatas: stok menu `null` (tanpa angka, tanpa batas qty), varian tetap dianggap beresep (tidak memotong stok varian). Kebutuhan 0 diabaikan. Saldo minus → kapasitas 0. Tanpa baris saldo → 0.

### D4 — Tab terbuka MENAHAN bahan (temuan inti)

- **Masalah**: bahan baru dipotong saat pesanan selesai (F1), tetapi pemeriksaan kapasitas terjadi saat baris masuk tab. Dua tab terbuka masing-masing menambah 6 porsi dari stok 10 akan sama-sama lolos jika yang dibandingkan hanya saldo, lalu tab kedua gagal/minus saat bayar.
- **Keputusan**: `tertahan` = jumlah kebutuhan RESEP seluruh baris pada pesanan berstatus `open` di outlet yang sama (selain pesanan yang sedang diperiksa). Angka yang ditampilkan di kartu juga memakai `saldo − tertahan`, jadi stok kartu turun begitu baris masuk tab (perilaku setara stok varian yang dipotong saat baris masuk).
- **Alasan**: mempertahankan keputusan 087 (bahan dipotong saat selesai, riwayat bahan bersih) sekaligus memenuhi FR-014 (tidak oversell). Tidak ada tabel reservasi baru: tertahan dihitung dari baris tab yang sudah ada, sehingga hapus/ubah/batal baris otomatis melepaskan.
- **Alternatif ditolak**: memotong bahan saat baris masuk (membalik 087 FR-015, mengacaukan void, buang, dan pembalikan buku besar); tabel reservasi terpisah (state kedua yang bisa basi).

### D5 — Penjaga kapasitas, atomik, di bawah kunci baris saldo

- **Keputusan**: `MenuStockService::assertCanFulfil(Order $order, Collection $lines)` dipanggil di `TabService::addLine`, `updateLine` (hanya saat qty naik), `OrderService::create`, dan sebelum `consumeOrder` di `TabSettlementService::settle/split`. Ia (1) mengunci baris `material_outlet_stock` bahan yang terlibat berurutan `material_id` (insertOrIgnore bila belum ada, pola `MaterialStockService`), (2) menghitung kebutuhan seluruh baris pesanan ini + tertahan pesanan lain, (3) bila melebihi saldo → melempar `RecipeStockException` (409, kode `insufficient_recipe_stock`, bahan penghambat) dan seluruh transaksi digulung.
- **Alasan**: urutan kunci sama dengan `MaterialStockService` mencegah deadlock (087 D5); dua terminal serentak diserialkan pada kunci yang sama, yang kedua melihat baris yang pertama sebagai tertahan. Memeriksa lagi saat selesai menangkap saldo yang turun lewat koreksi/transfer di antara waktu tambah dan bayar.
- **Koreksi saat implementasi (isolasi transaksi)**: rencana awal ("kunci lalu baca") belum cukup. Pada REPEATABLE READ (bawaan MySQL) transaksi yang menunggu kunci tetap memakai snapshot LAMA dan tidak melihat baris tab yang baru di-commit pendahulunya, sehingga dua terminal sama-sama lolos. Perbaikan: `MenuStockService::transaction()` menyetel `READ COMMITTED` untuk transaksi terluar (`SET TRANSACTION` hanya sah sebelum transaksi dimulai; dilewati bila sudah ada transaksi), dipasang di semua titik masuk. Alternatif ditolak: `lockForUpdate` pada baris `order_items`/`orders` (urutan kunci terbalik terhadap `settle` yang menahan baris pesanan lalu menunggu kunci bahan, jadi berisiko deadlock) dan kolom `reserved` di saldo bahan (state kedua yang harus dirawat di setiap titik perubahan baris).
- **Cakupan**: hanya kebutuhan yang berasal dari RESEP (basis dan blok bermodifier milik resep). Bahan dari pilihan modifier (087) tetap peringatan, bukan penolakan (F10), supaya perubahan tidak meluas melebihi keputusan pemilik.

### D6 — Menu beresep lepas dari stok varian otomatis (keputusan Q1 = A)

- **Keputusan**: `ProductVariant::tracksOwnStock()` mengembalikan `false` untuk varian yang punya resep aktif. Pembaca "apakah varian beresep" memakai satu pencarian set id yang di-memo per permintaan (`RecipeService::recipeDrivenVariantIds()`, dua kueri sekali per permintaan), bukan `isRecipeDriven()` per varian. `scopeTracksOwnStock`/`scopeStockFromVariant` mendapat syarat SQL setara (`whereNotIn` id beresep). Memo dibuang saat `RecipeService` menulis.
- **Dampak yang sudah terkunci oleh aturan lama**: `sourceForNewLine` menyimpan `order_items.stock_source = 'modifier'` untuk baris baru menu beresep, sehingga pengembalian stok varian (`applyLineMovementIfTracked`) tidak pernah menyentuh stok varian; baris tab lama bersnapshot `variant` tetap mengembalikan ke varian dengan benar.
- **Konsekuensi yang harus dinyatakan ke pemilik**: laporan stok varian dan koreksi stok varian untuk varian beresep berperilaku seperti sumber `modifier` hari ini (dikecualikan/ditolak 409 `stock_source_modifier`); angka stok varian lama dibiarkan.
- **Alternatif ditolak**: menjadikan `products.stock_source` otomatis `modifier` saat resep dibuat (menimpa pilihan manual dan menyimpan state kedua yang harus dipulihkan saat resep diarsipkan; spec edge case meminta kembali ke varian otomatis).

### D7 — Payload: bidang lama berisi angka efektif + dua bidang baru tambahan

- **Keputusan**: untuk varian beresep di mode POS, `track_stock = true`, `outlet_stock = stok menu` (atau `null` bila tanpa pembatas, dengan `track_stock = false`), ditambah `stock_basis` (`recipe`|`variant`) dan `stock_limiting` (`{material_id, name, unit, on_hand, per_portion}` atau null). Payload pengelolaan (bukan `pos=1`) tidak berubah kecuali `stock_tracked` memakai aturan D6.
- **Alasan**: F6. Bidang baru bersifat tambahan; APK lama (`ignoreUnknownKeys`) tetap benar dan server tetap menolak lewat D5 (spec Story 4 skenario 3).
- **Alternatif ditolak**: bidang baru wajib dibaca klien (APK lama salah menampilkan stok varian).

### D8 — Penyegaran angka di klien

- **Keputusan**: tidak menambah polling baru. Stok menu sudah ikut respons daftar menu yang dimuat klien; klien memuat ulang daftar setelah penjualan berhasil dan pada penyegaran manual yang ada. **Dapat diverifikasi** saat implementasi: apakah web (`PosView`/`TabView`) dan Android (`PosStore`) benar-benar memuat ulang menu setelah checkout/settle; bila tidak, tambahkan pemuatan ulang di titik itu (bukan polling).
- **Alasan**: FR-017 tanpa beban polling tambahan di tablet. **ASUMSI**: pemuatan ulang pasca transaksi belum ada di semua jalur; dipastikan saat tugas, bukan diklaim di sini.

### D9 — Performa (FR-018)

- **Keputusan**: `MenuStockService::forVariants(array $variantIds, int $outletId)` memuat dalam jumlah kueri TETAP per permintaan: resep aktif + blok + baris + bahan (eager, `activeRecipesForVariants`), saldo bahan outlet (sekali, dipakai ulang dari pola `OutletPriceBook`), dan baris tab terbuka outlet itu beserta resepnya (sekali). Hasil untuk satu respons daftar disimpan di atribut permintaan (`prime()`), yang hidup tepat satu permintaan; TIDAK ada cache lintas permintaan. Himpunan "varian beresep" (dua kueri) di-memo per permintaan dan dikunci hari bisnis + mode data (DEMO/LIVE).
- **Alasan**: Konstitusi V: angka ini harus selalu benar (bergantung saldo dan tab terbuka yang berubah tiap detik); cache butuh kunci mode+outlet+tab dan bisa basi tepat di titik oversell. Pilihan ini sengaja dan didokumentasikan.
- **Pengujian**: tes menghitung jumlah kueri untuk 5 dan 50 menu beresep dan memastikan sama.

### D10 — Void, refund, batal

- **Keputusan**: tidak ada perubahan pada `restoreOrder` (membaca buku besar `sale − return`), `wasteLine`, dan jalur batal tab. Baris tab terbuka yang dibatalkan melepas tahanan otomatis (D4). Pesanan yang sudah selesai: tahanan sudah menjadi pergerakan nyata, tidak dihitung dua kali karena `tertahan` hanya membaca pesanan `open`.
- **Alasan**: FR-010 sudah dipenuhi oleh mekanisme yang ada; mengubahnya mengambil risiko tanpa manfaat.

### D11 — Mode, outlet, keamanan

- DEMO/LIVE: semua pembacaan lewat model ber-`HasDataMode` (Eloquent); tidak ada `DB::table()` baru. Bila perlu kueri mentah, daftarkan di `OutletScopeRegistry` dan filter `data_mode` eksplisit.
- Outlet pemesanan selalu dari server (`OutletContext::orderingId()` / outlet pesanan), tidak pernah dari body (Konstitusi IV).
- Tidak ada rute publik baru. Payload pesan mandiri (090) tidak membawa nama bahan: bidang `stock_limiting` DIHILANGKAN di respons publik (hanya angka dan `available`), karena nama bahan adalah detail internal.

### D12 — Konkurensi: apa yang bisa dan tidak bisa dibuktikan

- Tes PHPUnit berjalan satu proses dan dibungkus transaksi (jadi pengalihan isolasi READ COMMITTED dilewati di tes); mereka membuktikan LOGIKA tahanan (dua tab berurutan, kebutuhan bahan bersama lintas baris) dan bahwa kunci baris diambil (`lockForUpdate` dalam urutan id). Uji dua terminal sungguhan dilakukan manual lewat `quickstart.md` (dua permintaan paralel ke DB dev). **Belum terbukti oleh otomasi**: keserentakan MySQL sebenarnya.
