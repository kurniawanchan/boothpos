# Research: Produk Sebagai Bahan Resep dengan Stok Bersama

**Klasifikasi**: INTERNAL | **Date**: 2026-11-07

Fakta di bawah dibaca langsung dari kode pada tanggal di atas. Yang tidak dibuktikan ditandai **ASSUMPTION** atau **belum diverifikasi**.

## Temuan dasar (fakta)

- Dua sistem stok terpisah: stok **varian** (bilangan bulat, `variant_outlet_stock`, ditulis HANYA `StockService::applyMovement`, buku besar `stock_movements`) dan stok **bahan baku** (desimal 3 tempat, `material_outlet_stock`, ditulis `MaterialStockService`, buku besar `material_stock_movements`).
- `recipe_lines.material_id` adalah FK wajib ke `materials`; resep tidak bisa merujuk varian hari ini (`RecipeLine`, migrasi `create_recipes_tables`).
- Bahan resep dipotong saat pesanan **selesai** lewat `ModifierMaterialConsumption::consumeOrder` (dipanggil di `OrderService::create`, `TabSettlementService::settle` dan `split`). Void/refund membaca buku besar (`sale − return` per baris pesanan), bukan resep (`restoreOrder`).
- Penjualan **langsung** memotong stok varian saat baris masuk tab (`TabService::addLine`) atau saat checkout ritel (`OrderService::create`). `assertCanFulfil` di kedua jalur memeriksa kebutuhan resep terhadap saldo bahan dikurangi **tahanan** tab terbuka lain (`MenuStockService::heldBy`).
- `StockService::applyMovement` mengunci baris varian lalu saldo (varian, outlet), menolak saldo < 0 lewat `ValidationException`, dan menulis buku besar + saldo + total `current_stock` dalam satu transaksi.
- `stock_movements.type` adalah **ENUM** MySQL (`purchase, sale, preorder_handover, adjustment, return, initial, transfer_out, transfer_in, transfer_cancel`); diperluas lewat `ALTER TABLE ... MODIFY` (preseden: `2026_12_30_000002`).
- `ProductVariant::tracksOwnStock()` sudah memutus stok varian untuk varian yang punya resep aktif (092). `RecipeDrivenVariants` adalah memo per permintaan yang dikunci hari bisnis + mode.
- Tablet Android hanya membaca `outlet_stock`/`track_stock` dari server (`MenuMapper.kt:65`); tidak ditemukan penghitungan stok di sisi klien pada pencarian ini.
- Pembatalan baris (`wasteLine`) dan refund di kode saat ini bekerja per pesanan/baris penuh; tidak ada refund sebagian per porsi.

## Keputusan

### D1 — Penanda di tingkat produk, rujukan resep di tingkat varian
**Keputusan**: `products.use_as_ingredient` (boolean, bawaan false) + `products.ingredient_unit` (string ≤ 20, opsional). Baris resep merujuk `variant_id`.
**Alasan**: stok disimpan per varian per outlet; penanda produk membuat semua variannya dapat dipilih (sejalan permintaan "Use as Ingredient di formulir Produk").
**Alternatif ditolak**: penanda per varian (formulir produk sudah tidak punya tempat natural; permintaan menyebut formulir produk); varian = unit "bahan baku" baru (menggandakan saldo, melanggar prinsip utama).

### D2 — `recipe_lines`: kolom `variant_id` + CHECK XOR, bukan tabel kedua
**Keputusan**: tambah `variant_id` nullable (FK `product_variants`, `restrictOnDelete`), ubah `material_id` jadi nullable, tambah `CHECK ((material_id IS NULL) <> (variant_id IS NULL))` lewat `DB::statement` (pola yang sudah dipakai proyek, MySQL 8 saja).
**Alasan**: satu urutan baris, satu blok, satu potret, satu penyajian. Tabel baris kedua menggandakan `display_order`, `block_id`, `RecipeService::writeBody`, `snapshot`, `presentLines`, dan sheet impor.
**Alternatif ditolak**: tabel `recipe_variant_lines` (duplikasi); membuat catatan `materials` bayangan yang menunjuk varian (melanggar "tanpa saldo terpisah" dan membuka jalur tulis kedua).

### D3 — Penerjemah tunggal diperluas, penulis stok tetap `StockService`
**Keputusan**: `ModifierMaterialConsumption::addRecipeNeed` juga mengisi `variantNeed[variant_id][order_item_id] = int`. `consumeOrder` menulis lewat `StockService::applyMovement(type: 'recipe_sale')`; `restoreOrder` membaca `stock_movements` bertipe `recipe_sale`/`recipe_return` (dikelompokkan variant_id + reference_id) lalu menulis `recipe_return`; `wasteLine` menulis `recipe_waste`. `reference_type = 'order_item'`, `reference_id` = id baris menu penyebab (seperti buku besar bahan).
**Alasan**: Konstitusi I (satu penerjemah, satu penulis per saldo); void/refund tetap dari buku besar sehingga resep yang diubah sesudah jual tidak memengaruhi pemulihan (FR-024/025).
**Alternatif ditolak**: memakai tipe `sale`/`return` yang sudah ada — `reference_id` untuk `sale` bermakna id ORDER di sebagian jalur (lihat docblock `StockMovementReferences`) dan id BARIS di jalur lain, sehingga void penjualan langsung akan salah mengambil/ menimpa; tipe terpisah menghilangkan tabrakan dan memberi sumber audit yang jelas (FR-026).

### D4 — Tipe pergerakan baru: `recipe_sale`, `recipe_return`, `recipe_waste`
**Keputusan**: perluas ENUM `stock_movements.type` (migrasi `ALTER TABLE ... MODIFY`, `down()` menolak bila baris bertipe baru ada, seperti `2026_12_30_000002`).
**Dampak**: label dan warna di `utils/stockMovements.js` + locale; `StockMovementReferences` butuh cabang baru (D9); laporan yang menjumlah `type='sale'` tidak menghitung pemakaian resep sebagai "penjualan produk itu" (benar: itu bukan penjualan). Perlu audit semua pembaca `type` (tugas T-audit).

### D5 — Tahanan varian dan penjualan langsung produk bahan
**Keputusan**: `MenuStockService` menghitung `heldVariants(outletId)` = kebutuhan varian-bahan dari baris tab `open` di outlet itu (rumus sama dengan pemotongan). Dipakai:
(a) `assertCanFulfil`: kebutuhan varian pesanan ini ≤ saldo varian − tahanan pesanan LAIN;
(b) `forVariants`/stok menu: bahan varian ikut jadi penghambat (saldo − tahanan);
(c) **penjualan langsung** varian yang dirujuk resep aktif: `StockService::applyMovement` untuk tipe `sale` bernilai negatif menolak bila `saldo sesudah < tahanan semua pesanan terbuka` (termasuk pesanan yang sama, karena kebutuhan resepnya juga dijanjikan);
(d) `outlet_stock` varian bahan di payload POS = saldo − tahanan.
**Alasan**: tanpa (c), kasir menjual telur langsung habis-habisan sementara tab terbuka sudah menahan telur untuk Indomie Kuah; saat tab diselesaikan pemotongan gagal (409) atau saldo minus. FR-020.
**Penghindar biaya**: pemeriksaan (c) hanya untuk varian dalam `IngredientVariants` (himpunan varian yang dirujuk resep tidak-arsip, dimuat sekali per permintaan, dikunci hari bisnis + mode). Produk biasa tidak menambah kueri.
**Galat**: kekurangan karena tahanan → 409 `insufficient_stock_reserved` (renderer yang sama dengan `RecipeStockException`); kekurangan murni (tanpa tahanan) tetap `ValidationException` lama agar klien lama tidak berubah.
**Alternatif ditolak**: tabel reservasi (086/092 sudah menolaknya: harus dibersihkan di setiap jalur ubah/hapus baris, rawan basi).

### D6 — Aturan kunci dan keserentakan
**Keputusan**: urutan kunci global: (1) `materials` naik id → (2) `material_outlet_stock` → (3) `product_variants` naik id → (4) `variant_outlet_stock`. Semua jalur yang menjaga tahanan berjalan di `MenuStockService::transaction()` (READ COMMITTED bila terluar). Jalur yang kini memotong stok langsung SEBELUM penjaga (`OrderService::create`) diurutkan ulang: sisipkan semua baris → penjaga (mengunci) → baru pergerakan. `MenuStockService::transaction` memakai percobaan ulang saat deadlock (`DB::transaction($work, 3)`).
**Alasan**: tambahan kunci varian pada penjaga membuka kemungkinan deadlock antara penjualan langsung produk bahan dan penjaga resep; urutan tunggal menutupnya. `RecipeMenuStockLockOrderTest` diperluas.
**Catatan jujur**: urutan material↔varian saat ini sudah tidak konsisten di dua jalur (create vs tab) tetapi himpunannya terpisah; fitur ini membuatnya bertemu, jadi urutan harus ditegakkan, bukan diandalkan.

### D7 — Bulat saja untuk bahan produk (menyelesaikan asumsi pecahan)
**Keputusan**: baris bahan produk wajib `shrinkage_pct = 0` dan `quantity ÷ yield` bilangan bulat ≥ 1 (divalidasi di `RecipeService::validate`; pilihan modifier berjumlah bulat sehingga `times` tidak merusaknya). Saat jalan, kebutuhan dibulatkan dengan peringatan log bila selisih > 1e-6 (pertahanan, bukan jalur normal).
**Alasan**: stok varian adalah bilangan bulat; tanpa aturan ini 0,5 telur per porsi harus di-ceil per pesanan (stok hilang) atau dibawa sebagai sisa pecahan (buku besar baru). Preseden: BOM varian sudah "bilangan bulat ≥ 1" (`WholeBomQuantity`, 036).
**Alternatif ditolak**: ceil per pesanan (kehilangan stok akumulatif, melanggar "tanpa stok hilang/ganda"); sisa pecahan per (varian, outlet) (tabel/buku besar baru, kompleks tanpa permintaan).
**Dampak ke pengguna**: resep "1 telur untuk 2 porsi" ditolak dengan pesan jelas; solusinya memakai bahan baku (desimal) untuk bahan yang memang pecahan.

### D8 — Larangan rekursi dan konsistensi dua arah
**Keputusan** (semua 422 dengan pesan konflik yang menyebut penyebab):
1. Menandai produk sebagai bahan ditolak bila: tidak melacak stok, bersumber stok `modifier`, atau salah satu variannya punya resep **tidak-arsip** (termasuk berlaku-masa-depan).
2. Menambah baris varian ke resep ditolak bila varian itu: berasal dari produk bukan-bahan (untuk baris BARU; baris lama yang sudah ada boleh tetap), tidak melacak stok, bersumber `modifier`, sedang beresep, atau milik produk resep itu sendiri.
3. Membuat/mengubah resep untuk produk yang bertanda bahan ditolak.
4. Mengubah `track_stock` → false atau `stock_source` → `modifier` pada produk yang variannya dirujuk resep tidak-arsip → 409 (jika dibiarkan, pemakaian resep berhenti memotong tanpa suara).
5. Nonaktifkan/hapus varian atau produk yang dirujuk resep tidak-arsip → 409 menyebut resep (FR-011). Aturan hapus-permanen berbasis riwayat (`VariantDeletionService`) tetap.
**Alasan**: menu beresep tidak punya saldo sendiri (092) sehingga rekursi tak bermakna; larangan menutup siklus tanpa deteksi graf.
**Catatan**: bahan baku lama yang nonaktif dilewati diam-diam saat potong (perilaku 087 yang ada); untuk varian itu DITOLAK di langkah 5 supaya tidak ada pelewatan diam-diam pada jalur baru.

### D9 — Jejak dan tautan riwayat
**Keputusan**: `StockMovementReferences` mendapat cabang untuk `recipe_*`: `reference_id` = id baris pesanan → nomor pesanan + nama menu (`name_snapshot`); hasil `{type:'order', id, number, menu}`. Penjaga lama ("pesanan harus memuat varian pergerakan") TIDAK berlaku untuk `recipe_*` karena varian pergerakan adalah bahan, bukan menu; penggantinya: baris pesanan harus ada dan `order_items.id` valid.
**Alasan**: FR-026/027 (menu dan pesanan penyebab dapat ditelusuri).

### D10 — Biaya (HPP)
**Keputusan**: biaya per satuan bahan produk = `cost_price` varian (>0 dianggap diketahui; 0 = tidak diketahui). `RecipeService::cost/variantCost/presentLines` memakainya; tidak ada penambahan ke `order_items.cost_price` baris (biaya resep dasar sudah di `cost_price` varian menu — aturan 089 D9). Sinkron otomatis saat `cost_price` bahan produk berubah **tidak** dibangun; tombol "Hitung ulang" yang ada tetap jalur manual.
**Alasan**: cakupan; spesifikasi tidak meminta HPP baru. Ini ASSUMPTION yang dicatat.

### D11 — Impor/ekspor Excel tidak boleh menghapus baris varian
**Keputusan**: sheet `recipes` hanya memuat baris bahan baku (nama bahan). Ekspor MENGABAIKAN baris varian; impor yang menimpa resep di tempat MEMPERTAHANKAN baris varian yang ada (preseden: blok bermodifier dipertahankan — 089 lanjutan). Lembar baru untuk produk bahan di luar cakupan.
**Alasan**: tanpa ini impor ulang file yang diekspor diam-diam menghapus bahan produk dari resep (kehilangan data). **Belum diverifikasi** terhadap `ImportsRecipesAndModifiers`; tugas pertama sisi impor adalah tes yang membuktikan perilakunya SEBELUM mengubah.

### D12 — Pilihan bahan di laci resep
**Keputusan**: endpoint baca baru `GET /recipe-ingredients` mengembalikan varian produk bahan (nama produk + varian, SKU, label satuan, tersedia di outlet terpilih = saldo − tahanan, status). Mengecualikan: produk resep itu sendiri (`exclude_product_id`), nonaktif, tak melacak stok, beresep, bersumber `modifier`. `RecipeDrawer.vue` menampilkan grup "Produk" bersama grup bahan baku yang ada; muatan baris memakai `variant_id` dan menyembunyikan kolom susut untuk baris produk. Baris lama yang produknya sudah tidak bertanda ditampilkan dengan penanda "tidak lagi tersedia untuk resep baru" (opsi tetap di-render seperti `orphanOption`).
**Alasan**: FR-002, FR-004, FR-007.

### D13 — Android
**Keputusan**: tidak ada perubahan kode fungsional; tablet membaca `outlet_stock`/`track_stock` yang sudah dihitung server. Pekerjaan Android = (a) tes kontrak payload (`outlet_stock` varian bahan = saldo − tahanan), (b) periksa bahwa tidak ada pengurangan stok lokal pada keranjang (pencarian awal tidak menemukannya; **belum diverifikasi** di `PosStore`), (c) verifikasi perangkat (kolom "Belum diverifikasi" di `evidence.md`).
**Offline**: pencarian awal menemukan "offline" hanya sebagai status koneksi di UI; tidak ditemukan antrean tulis offline di lapisan data (**belum dipastikan**). Karena pemotongan terjadi di transaksi server yang sama dengan penyelesaian pesanan dan sudah idempoten lewat `local_ref`/`client_ref`/`submission_ref`, pemotongan varian-bahan mewarisi idempotensi itu; sabuk pengaman buku besar diperluas ke `stock_movements` (cek `recipe_sale` per baris) supaya pesanan yang hanya berbahan varian pun terlindungi.

### D14 — Pesan mandiri QR dan layar publik
**Keputusan**: `SelfServiceOrderService::assertQuantitiesAvailable` sudah memakai `forVariants`; dengan D5(b) otomatis memperhitungkan bahan varian. Menu publik menandai `sold_out` memakai angka yang sama; produk bahan yang dijual langsung memakai `outlet_stock` dikurangi tahanan. Respons publik tidak membocorkan nama bahan (aturan 092 tetap).

## Risiko dan hal yang belum terverifikasi

1. Keserentakan nyata di MySQL (dua koneksi, READ COMMITTED, deadlock retry): hanya bisa dibuktikan lewat tes dua proses; hasil di `evidence.md`.
2. Pembaca lain atas `stock_movements.type` (laporan, ekspor, `LowStockHistory`) belum diaudit satu per satu; tugas audit wajib sebelum migrasi ENUM dianggap aman.
3. Perilaku impor resep terhadap baris varian (D11).
4. `PosStore` Android: ada/tidaknya pengurangan stok lokal.
5. Konsumen lama `stock_limiting.material_id` (web/Android): untuk bahan varian field itu null dan `variant_id` terisi; perlu diperiksa bahwa tidak ada pembaca yang mengasumsikan non-null.
