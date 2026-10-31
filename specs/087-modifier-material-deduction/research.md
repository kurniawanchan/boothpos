# Research: Pilihan Modifier Memakai Bahan Baku

**Klasifikasi**: CONFIDENTIAL (struktur data dan kontrol internal)

**Feature**: [spec.md](./spec.md) · **Tanggal**: 2026-10-31 · Melanjutkan [assessment.md](./assessment.md); bila berbeda, dokumen ini yang berlaku.

Semua rujukan `file:baris` diperiksa di branch `087-modifier-material-deduction` (dari `develop-warkop-wjm` 61ef05b5).

---

## D1. Bentuk pemetaan: tabel baris komponen per pilihan

- **Decision**: tabel baru `modifier_option_materials (modifier_option_id, material_id, quantity DECIMAL(12,4), data_mode)`, unik `(modifier_option_id, material_id)`, CHECK `quantity > 0`.
- **Rationale**: satu pilihan bisa memakai >1 bahan (Extra Pedas); FK nyata; bentuknya sama dengan `recipe_lines`/`product_variant_bom_lines`, jadi jalur potong bisa dipakai resep kelak. Konstitusi I melarang abstraksi spekulatif, jadi tidak dibuat tabel generik.
- **Alternatives**: kolom `material_id` di `modifier_options` (gagal untuk >1 bahan); tabel polimorfik `owner_type` (tanpa integritas FK, memaksa migrasi resep yang berversi); pilihan sebagai varian tersembunyi (mengotori katalog). Lihat assessment §7.

## D2. Pemetaan disimpan lewat payload grup modifier yang sudah ada

- **Decision**: `options.*.materials` (opsional) di `POST/PUT /modifier-groups`. Ada kunci = penggantian penuh baris bahan pilihan itu; tanpa kunci = tidak berubah (pola 086 `excluded_group_ids`). Ditulis di `ModifierGroupService::syncOptions()` (`app/Services/ModifierGroupService.php:145-171`), dalam transaksi `create/update` yang sudah ada.
- **Rationale**: pilihan sudah disimpan sebagai bagian grup (`StoreModifierGroupRequest.php:25-29`); endpoint terpisah per pilihan akan memecah satu formulir menjadi dua penyimpanan yang bisa gagal sebagian. Klien lama (yang tidak mengirim `materials`) tidak menghapus pemetaan.
- **Gerbang**: tetap `canAccessMenu('dining_setup')` (`StoreModifierGroupRequest.php:12`). Tidak ada peran baru.
- **Pilihan dinonaktifkan** (tidak dikirim → `is_active=false`, `ModifierGroupService.php:170`): baris pemetaan dibiarkan; pilihan nonaktif tidak bisa dipesan, jadi tidak memotong apa pun.
- **Alternatives**: `PUT /modifier-options/{id}/materials` (dua penyimpanan untuk satu formulir); rilis menu `menu_change` (grup modifier tidak lewat rilis: `ChangeApplier` tidak menyentuh modifier; hanya tautan item 086 yang lewat).

## D3. Log aktivitas pemetaan (FR-006)

- **Decision**: `ModifierGroupService` menulis satu entri `modifier_materials_changed` per penyimpanan grup bila ada pilihan yang pemetaannya berubah, dengan `old_values`/`new_values` = `{option_id: [{material_id, quantity}]}` hanya untuk pilihan yang berubah. Ditulis di dalam transaksi yang sama.
- **Rationale**: konstitusi IV (log mutasi sensitif dalam transaksi). Fakta: `create/update` grup hari ini TIDAK menulis log (hanya `delete`, `ModifierGroupService.php:95`); 087 tidak menambah log untuk bidang lain grup supaya cakupan tidak melebar.

## D4. Kapan memotong: tiga titik "menjadi selesai"

- **Decision**: satu service baru `ModifierMaterialConsumption` dengan `consumeOrder(Order $order, User $user): array $warnings`, dipanggil dalam transaksi yang sudah ada tepat setelah baris pesanan final dan status `completed` diputuskan:
  1. `OrderService::checkout` setelah semua `order_items` dibuat (`OrderService.php:206-236`);
  2. `TabSettlementService::settle` di cabang `$completes` (`TabSettlementService.php:112-123`);
  3. `TabSettlementService::split` setelah baris pesanan baru dibuat (`TabSettlementService.php:199-224`).
- **Rationale**: keputusan pemilik FR-015 = B. Ketiga titik sudah mengunci pesanan dan berjalan dalam `DB::transaction`, jadi FR-011 (selesai + potong atomik) terpenuhi tanpa transaksi baru. Split menyalin `modifiers` ke baris baru (`TabSettlementService.php:218`), jadi tidak perlu kasus khusus.
- **Stok produk tidak berubah**: tetap dipotong saat item masuk tab (`TabService.php:203`).
- **Alternatives**: observer `Order::updated` saat status berubah (tersembunyi, sulit diuji, dan checkout membuat order langsung `completed` tanpa event "berubah"); job antrean (melanggar atomik FR-011; aplikasi lokal tanpa worker antrean yang dijamin).

## D5. Idempotensi dan konkurensi (FR-013, FR-017)

- **Decision**:
  - Retry checkout ditangkap `local_ref` yang sudah ada sebelum transaksi menulis apa pun; retry settle/split ditangkap `submission_ref`/`client_ref` yang sudah ada. Service potong tidak pernah terpanggil dua kali untuk pesanan yang sama lewat jalur itu.
  - Sabuk pengaman di dalam service: bila sudah ada movement `sale` dengan `reference_type='order_item'` untuk salah satu baris pesanan, `consumeOrder` berhenti tanpa menulis (dan menulis log peringatan ke `Log::warning`, bukan exception, karena pesanan sah).
  - Kunci: kumpulkan kebutuhan `[material_id][order_item_id] => qty`, lalu tulis berurutan `material_id ASC, order_item_id ASC`. Kunci baris `materials` + `material_outlet_stock` diambil oleh `MaterialStockService::applyMovement()` (`MaterialStockService.php:45-50`) dan ditahan sampai commit, jadi urutan tetap mencegah deadlock antar dua pesanan.
- **Rationale**: tidak perlu kolom penanda baru; indeks `(reference_type, reference_id)` sudah ada (`2026_10_12_000004`).
- **Alternatives**: kolom `orders.materials_consumed_at` (lebih eksplisit, tetapi satu kolom lagi untuk hal yang sudah bisa dibuktikan dari buku besar). Dicatat sebagai opsi bila query penjaga terbukti lambat.

## D6. Saldo bahan boleh minus hanya untuk `sale` dan `waste` (FR-014, FR-014b)

- **Decision**:
  - Migrasi melepas `chk_material_outlet_stock_nonneg` (`2026_12_04_000001_create_outlet_stock.php:48`). `materials.current_stock` sudah decimal bertanda tanpa CHECK (`2026_10_12_000003:20`).
  - `MaterialStockService` mendapat konstanta `NEGATIVE_ALLOWED = ['sale', 'waste']`. Untuk jenis lain, aturan lama tetap (tolak bila < −0,0005 dan jepit ke 0, `MaterialStockService.php:56-62`). Untuk `sale`/`waste`, saldo minus disimpan apa adanya dan **tidak dijepit**.
  - `applyMovement` tetap satu-satunya penulis (konstitusi I); tidak ada parameter dari klien.
- **Rationale**: keputusan pemilik FR-014 = B; koreksi/transfer tetap ketat (FR-014b).
- **Risiko**: hasil `stock_after` di movement lama selalu ≥ 0; laporan yang berasumsi ≥ 0 harus diperiksa. Fakta: tidak ada laporan bahan yang membaca `stock_after` selain jejak transfer (`OutletScopeRegistry.php:46`).

## D7. Pengembalian membaca buku besar, bukan pemetaan (FR-019, FR-020)

- **Decision**: `ModifierMaterialConsumption::restoreOrder(Order, User, string $reason)` menghitung per `(order_item_id, material_id)`: `net = −Σ qty_change` atas movement `sale` dan `return` baris itu; bila `net > 0`, tulis `return +net`. Dipanggil di `OrderService::void` (`OrderService.php:270-283`) dan `RefundService::refund` (`RefundService.php:94-107`), di dalam transaksi dan kunci status yang sudah ada. `waste` tidak pernah masuk hitungan.
- **Rationale**: pemetaan bisa berubah setelah penjualan; buku besar adalah fakta. Dua kali void/refund sudah ditolak oleh status pesanan yang dikunci (`RefundService.php:41-46`, `OrderService.php:259`), dan `net` yang sudah 0 tidak menghasilkan baris kedua.

## D8. Bahan terbuang di tab terbuka (FR-018)

- **Decision**: `ModifierMaterialConsumption::wasteLine(OrderItem $line, User)` menulis movement `waste −need` per bahan (rujukan `order_item`, id baris). Dipanggil:
  - `TabService::removeLine` saat `stock_decision = 'waste'` (nilai sah `return|waste`, `CancelTabLineRequest.php:26`), **sebelum** `$line->delete()` (`TabService.php:337`);
  - `TabService::voidTab` (juga dipakai `cancelTab`) untuk baris yang stok produknya TIDAK dikembalikan (sudah dikirim dan item dapur bukan `new`, `TabService.php:407-412`).
- **Rationale**: dengan potong-saat-selesai, baris yang dimasak lalu dibuang tidak akan pernah memotong bahan bila tidak dicatat di sini (ASUMSI di spec, menunggu konfirmasi pemilik). `waste` untuk baris belum dimasak tetap dicatat bila kasir memilihnya (keputusan kasir dihormati, sama dengan stok produk).
- **Rujukan baris yang dihapus**: movement merujuk `order_item.id` yang lalu dihapus. Ini sama dengan preseden stok produk (`returnStock` sebelum `delete`, `TabService.php:326-337`), dan baris log aktivitas `line_cancelled` menyimpan nama/jumlahnya. Tidak menambah kolom `order_id`.

## D9. Biaya bahan pilihan ke HPP baris (FR-023)

- **Decision**: saat `consumeOrder`, untuk tiap baris: `cost_price += round(Σ_material (qty_per_porsi × unitCost), 2)` (biaya per satuan baris), lalu service itu sendiri menulis ulang HANYA `orders.total_cost = Σ cost_price × qty` (rumus yang sama dengan `TabService::recalculate`, `TabService.php:702`). Tidak memanggil `recalculate` utuh: checkout menulis `total_cost` sebelum baris ada (`OrderService.php:203`) dan punya alur diskonnya sendiri, jadi menghitung ulang subtotal/pajak di sana berisiko mengubah angka yang sudah ditagih.
  - `unitCost` = `RecipeService::unitCost()` (`RecipeService.php:180-192`): harga vendor acuan, `standard_cost` untuk setengah jadi, `null` → 0. Satu sumber, tidak diduplikasi.
  - Bahan nonaktif yang dilewati saat potong juga tidak dihitung biayanya.
- **Rationale**: `order_items.cost_price` decimal(14,2) per satuan (`2026_10_04_000003:45`) adalah snapshot yang tidak pernah berubah setelah pesanan selesai (`RecipeService.php:28`), sehingga konstitusi IV (snapshot finansial) terpenuhi. Refund tidak mengubah biaya (sama dengan hari ini).
- **Risiko**: pembulatan per satuan ke 2 desimal. Untuk 0,5 g garam bernilai Rp 0,4 → Rp 0,00; diterima (biaya bahan murah tidak material untuk margin).

## D10. Bahan nonaktif / terhapus

- **Decision**: (a) validasi simpan pemetaan menolak bahan nonaktif, terhapus, atau beda mode; (b) saat potong, bahan nonaktif/terhapus dilewati; (c) `DELETE /materials/{id}` menolak 409 bila dipakai pemetaan pilihan **aktif** (pola penjaga yang sama dengan BOM, `MaterialController.php:106-110`), karena bahan memakai soft delete sehingga FK RESTRICT tidak pernah terpicu; (d) daftar grup modifier menandai baris pemetaan yang bahannya nonaktif.
- **Rationale**: FR-003, kasus tepi "bahan dinonaktifkan".

## D11. "Dipakai oleh" dan riwayat bahan

- **Decision**:
  - `GET /materials/{id}` (sudah ada, kebijakan `view`) menambah `used_by_modifier_options: [{option_id, option, group_id, group, quantity, option_active}]`, dimuat hanya di `show` (daftar tetap ramping, konstitusi V).
  - `GET /materials/{material}/movements` BARU (P3, US5): paginasi amplop standar, saring `type[]`, `outlet_id[]`, `from`/`to` (tanggal); dibatasi `OutletFilter` dan gerbang yang sama dengan `adjustStock`. Rujukan `order_item` diterjemahkan ke `{order_id, order_number}` dalam satu query (bukan per baris).
- **Rationale**: hari ini tidak ada pembaca riwayat bahan (fakta: `MaterialStockMovement` hanya dipakai `MaterialStockService` dan `StockTransferController`). `OutletScopeRegistry` entri `material_stock_movements` (`OutletScopeRegistry.php:46`) diperbarui teksnya.

## D12. Peringatan ke kasir

- **Decision**: respons checkout (`POST /orders`), settle (`POST /tabs/{order}/payments`), dan split (`POST /tabs/{order}/split`) menambah bidang tingkat atas opsional `material_warnings: [{material_id, material, unit, outlet_id, stock_before, required, stock_after}]`, hanya bila ada bahan yang menjadi minus. Web POS menampilkannya sebagai toast peringatan (bukan galat). Tidak ada perubahan status code.
- **Rationale**: aditif; APK lama mengabaikan bidang yang tidak dikenal. **Belum terverifikasi**: cara parser Android membaca respons settle/split (`android-pos/.../data/PosBackend.kt`); `ignoreUnknownKeys` hanya terlihat di modul print. Tugas verifikasi wajib sebelum rilis. Menampilkan peringatan di Android = P3, perlu verifikasi perangkat (konstitusi II).

## D13. Tanda minus di daftar bahan (FR-014a)

- **Decision**: `GET /materials` menerima `stock_status=negative` (saldo outlet yang berlaku < 0, lewat `OutletFilter`); `outlet_stock[].on_hand` sudah ada (`MaterialResource.php:25-27`) dan kini bisa negatif. `MaterialsTable.vue` menampilkan pil "Minus" dan saringan.
- **Rationale**: laporan stok rendah hanya untuk varian (`StockController::lowStock`, `routes/api.php:212`); bahan tidak punya ambang stok rendah. Spec FR-014a disesuaikan.

## D14. DEMO/LIVE dan outlet

- **Decision**: `ModifierOptionMaterial` memakai `HasDataMode` (aturan 003). Potong dari `order.outlet_id` (outlet transaksi). Tidak ada query mentah baru; bila plan pelaksanaan butuh satu (penjaga D5 atau terjemahan rujukan D11), daftarkan di `OutletScopeRegistry`.

## D15. Ekspor/impor master data

- **Decision**: di luar cakupan. Fakta: `MasterDataSheets` tidak memuat grup modifier (grup tidak ada di lembar master data), sesuai Asumsi spec. ASUMSI: diverifikasi saat tugas pertama dengan grep `MasterDataSheets`.

## Ringkasan yang belum terverifikasi

| # | Hal | Kapan diverifikasi |
|---|---|---|
| U1 | Parser Android untuk respons settle/split menoleransi `material_warnings` | tugas uji Android sebelum rilis |
| U2 | `MasterDataSheets` tidak memuat grup modifier | tugas pertama |
| U3 | Persetujuan pemilik atas ASUMSI FR-018 (bahan terbuang) dan FR-023 (biaya bahan ke HPP) | sebelum `/speckit-implement` |

---

# Tambahan US6 — sumber stok per produk (2026-10-31)

Keputusan pemilik: FR-025 = **gabungan** (bahan modifier dipotong untuk SEMUA produk; sumber stok hanya menentukan apakah stok VARIAN bergerak), FR-031 = **seperti menu masak** (produk bersumber Modifier tanpa angka stok, tidak pernah "habis").

## D16. Kolom `products.stock_source`

- **Decision**: `ENUM('variant','modifier') NOT NULL DEFAULT 'variant'` pada `products`. Migrasi mengisi semua produk lama dengan `variant` (bawaan), jadi tidak ada perilaku yang berubah sampai pemilik mengubah produk.
- **Rationale**: sumber stok adalah sifat produk (Indomie Kuah), bukan sifat varian; per-varian `track_stock` tetap ada dan tetap berlaku di dalam `variant`.
- **Alternatives**: memakai `track_stock = false` di semua varian sebagai "Modifier" (tidak membedakan menu masak tanpa stok dari menu yang stoknya di bahan; dan bergantung pada konsistensi semua varian).

## D17. Satu aturan: `ProductVariant::tracksOwnStock()`

- **Decision**: `tracksOwnStock(): bool = $this->track_stock && $this->product->stock_source === 'variant'`. `StockService::applyMovementIfTracked()` memakai aturan ini sebagai pengganti `! $variant->track_stock` (`StockService.php:55`), jadi SEMUA jalur jual yang sudah memanggilnya (`OrderService::create`, `TabService::addLine`, `updateLine` qty naik) otomatis berhenti memotong stok varian untuk produk `modifier`. Kueri mentah laporan memakai syarat yang sama: `product_variants.track_stock = 1 AND products.stock_source = 'variant'` lewat satu scope/konstanta (`ProductVariant::scopeTracksOwnStock`).
- **Rationale**: konstitusi I — satu implementasi; `applyMovementIfTracked` sudah menjadi gerbang semua jalur pesanan.

## D18. Snapshot per baris: `order_items.stock_source` (FR-028)

- **Decision**: kolom `ENUM('variant','modifier') NULL`. Diisi saat baris dibuat: `variant` bila stok varian benar-benar bergerak untuk baris itu (`tracksOwnStock()` saat itu), selain itu `modifier`. NULL untuk baris lama = perilaku lama (dianggap `variant`, pengembalian tetap memakai `track_stock` seperti sebelumnya).
- Jalur PENGEMBALIAN stok varian (`OrderService::void`, `RefundService::refund`, `TabService::returnStock`, `updateLine` qty turun, `voidTab`) memakai snapshot baris, bukan pengaturan produk saat ini: `StockService::applyLineMovementIfTracked(OrderItem $line, ...)` (baru, satu tempat) melewati baris ber-snapshot `modifier`. Split (klon baris sebagian) dan merge menyalin/membawa snapshot.
- **Rationale**: tanpa snapshot, mengubah sumber saat tab terbuka membuat void/refund mengembalikan stok yang tidak pernah dipotong (atau sebaliknya). Bahan modifier TIDAK butuh snapshot: pengembalian bahan sudah membaca buku besar (D7).

## D19. Tampilan "tanpa stok" untuk produk `modifier` tanpa perubahan klien (FR-031)

- **Koreksi 2026-10-31 (bug dilaporkan pemilik):** klaim "POS web sudah memperlakukan `track_stock: false`" SALAH — hanya `TabView.vue:121` yang melakukannya; POS ritel (`posProductCards.js`, `PosView.vue`, `ProductVariantPickerModal.vue`, `PosCartPanel.vue`) mengabaikan `track_stock` sejak 028, jadi item bersumber modifier tampil "Stok 0 / Habis" dan tak bisa dijual. Diperbaiki dengan satu aturan klien `stockOf`/`isOutOfStock` di `posProductCards.js`. Server sudah benar.
- **Decision**: payload POS (`GET /products?pos=1`, `GET /variants/lookup`) mengirim `track_stock` EFEKTIF = `tracksOwnStock()`. Web (`TabView.vue:121`, POS) dan Android (`MenuMapper.kt:63`: `stock = if (v.trackStock) ... else null`) sudah memperlakukan `track_stock: false` sebagai "tanpa angka, tidak pernah habis". APK lama ikut benar tanpa rilis baru.
- Payload pengelolaan (halaman Produk, formulir) tetap mengirim `track_stock` MENTAH ditambah `stock_source` dan `stock_tracked` (efektif), supaya formulir tidak menyimpan balik nilai efektif sebagai nilai mentah.
- **Belum terverifikasi**: apakah halaman Produk dan POS memakai resource yang sama (`ProductVariantResource`); tugas pertama US6 memeriksanya. Bila sama, nilai efektif hanya dipakai saat `pos=1`.

## D20. Modul lain (FR-030)

| Modul | Perlakuan untuk produk `modifier` | Titik kode |
|---|---|---|
| Koreksi stok varian | 409 `stock_source_modifier` (varian itu tidak punya stok) | `StockController::adjust` (`applyMovement` langsung, tanpa cek pelacakan) |
| Transfer stok | ditolak seperti varian tak dilacak (422 per baris) | `StockTransferService.php:211` → `tracksOwnStock()` |
| Stok rendah, saldo per outlet, laporan stok per outlet, perhatian dasbor | dikecualikan | `StockController::lowStock`/`balances`, `ReportController::stockByOutlet`, `AttentionService::lowStock` |
| Halaman Produk | kolom stok "—", pil "Stok dari modifier" | daftar/detail produk |
| Formulir produk | pilihan "Sumber stok"; peringatan saldo varian tersisa & tanpa pemetaan bahan (FR-027, FR-029) | `ProductFormDrawer.vue`, `StoreProductRequest`/update |
| Ekspor/impor master data | kolom `stock_source` di lembar `products` (kosong = tidak berubah, nilai di luar enum = galat baris) | `MasterDataSheets`, `MasterDataImportService` |
| Katalog WJM | eksplisit `stock_source = 'variant'` (bawaan) — katalog tidak disunting tangan | `WjmCatalogSeedService` |
| Android | tanpa perubahan kode (D19) | — |

## D21. Mengubah sumber stok (FR-027, FR-029)

- **Decision**: `PUT /products/{id}` menerima `stock_source`. Perubahan ditulis di `ProductLifecycleService`/jalur ubah produk yang sudah ada dalam transaksinya, dengan log `stock_source_changed`. Respons membawa `warnings` aditif: `variant_stock_remaining` (Σ saldo varian ≠ 0 saat menjadi `modifier`) dan `no_material_mappings` (tidak ada pilihan efektif yang berpemetaan). Saldo varian dibiarkan.
- **ASUMSI**: pengubahan produk tidak lewat alur rilis menu `menu_change`; diverifikasi di tugas US6 pertama (bila lewat rilis, `stock_source` ikut payload rilis, pola 086).
