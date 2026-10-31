---

description: "Task list for 087 — pilihan modifier memakai bahan baku (potong stok otomatis saat pesanan selesai)"
---

# Tasks: Pilihan Modifier Memakai Bahan Baku

**Klasifikasi**: CONFIDENTIAL (merujuk struktur data dan kontrol internal)

**Input**: `specs/087-modifier-material-deduction/` — plan.md, spec.md, research.md (D1–D15), data-model.md, contracts/api.md, quickstart.md, assessment.md

**Tests**: Disertakan. Konstitusi II mewajibkan tes server (`tests/Feature/`, MySQL nyata) dan tes web (`qa-tests/`) untuk setiap perubahan, plus pemeriksaan peramban nyata untuk layar yang disentuh.

**Organization**: Per user story. US1 (pemetaan) + US2 (potong saat selesai) + US3 (pengembalian & buang) = MVP; ketiganya P1 dan harus dirilis bersama (US2 tanpa US3 membuat stok melenceng pada void/refund). US4 (minus, P2) dan US5 (riwayat, P3) menambah di atasnya.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: bisa paralel (file berbeda, tanpa ketergantungan pada tugas yang belum selesai)
- Perintah tes di kontainer WAJIB: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=…` (flag `-e` SEBELUM nama layanan). Tanpa flag itu tes pernah menghapus DB dev. Migrasi di DB dev (`warkop_wjm`) memerlukan persetujuan pemilik; tes memakai DB uji.
- Komentar kode, teks UI, pesan galat berbahasa Indonesia (UI juga `en.json`). Komentar menjelaskan MENGAPA dan merujuk `087` + keputusan `research.md Dn`.
- Satu-satunya penulis stok bahan: `MaterialStockService::applyMovement()`. Satu-satunya penerjemah pesanan → bahan: `ModifierMaterialConsumption`. Jangan menulis `material_stock_movements`/`material_outlet_stock` di tempat lain.

## Path Conventions

Server `app/`, `database/migrations/`, `lang/id/`, `routes/api.php`, `tests/Feature/`; web `resources/js/`, `qa-tests/unit/`; kontrak `docs/openapi-pos-mvp.yaml`.

---

## Phase 1: Setup

- [X] T001 Buat kerangka `specs/087-modifier-material-deduction/evidence.md` dengan bagian: Verifikasi awal (U1, U2), Konfirmasi pemilik (U3), Hasil suite backend, Hasil Vitest, Peramban (Grup modifier, Bahan, POS ritel, Tab), Tablet, Konkurensi manual; semuanya berstatus "belum dijalankan"
- [X] T002 Verifikasi research U2: jalankan `grep -n "modifier" app/Support/MasterDataSheets.php`; catat di `specs/087-modifier-material-deduction/evidence.md` apakah grup modifier termasuk lembar master data. Bila TERMASUK, hentikan dan tambahkan tugas ekspor/impor `materials` per pilihan sebelum melanjutkan (FR-007)
- [X] T003 Verifikasi research U1: temukan pembaca respons `POST /tabs/{id}/payments` dan `POST /tabs/{id}/split` di `android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt` (dan parser yang dipakainya); catat di `evidence.md` apakah bidang JSON tingkat atas yang tidak dikenal (`material_warnings`) diabaikan atau menimbulkan galat. Bila galat, tambahkan tugas perbaikan parser Android di Phase 8 sebelum rilis
- [X] T004 Catat di `evidence.md` status konfirmasi pemilik untuk ASUMSI FR-018 (bahan terbuang dicatat) dan FR-023 (biaya bahan masuk HPP). Bila pemilik menolak salah satunya, hapus tugas terkait sebelum Phase 3: FR-018 → T036, T038, T040 (dan bagian `waste` di T006/T010); FR-023 → langkah (5) di T028 dan asersi biaya di T025

---

## Phase 2: Foundational (memblokir semua user story)

- [X] T005 Pastikan awalan tanggal migrasi masih terbaru (`ls database/migrations | tail -3`; terakhir yang diketahui `2026_12_29_000001`). Buat `database/migrations/2026_12_30_000001_create_modifier_option_materials.php` sesuai `data-model.md`: `modifier_option_id` FK `modifier_options` cascadeOnDelete, `material_id` FK `materials` restrictOnDelete, `quantity DECIMAL(12,4)`, `data_mode ENUM('demo','live') DEFAULT 'live'` dengan indeks `idx_modifier_option_materials_data_mode`, timestamps, UNIQUE `uniq_modifier_option_material`, INDEX `material_id`, lalu `DB::statement` CHECK `chk_modifier_option_materials_qty_pos (quantity > 0)`; `down()` menghapus tabel
- [X] T006 Buat `database/migrations/2026_12_30_000002_add_consumption_types_to_material_movements.php`: `ALTER TABLE material_stock_movements MODIFY type ENUM('purchase','adjustment','transfer_out','transfer_in','transfer_cancel','sale','return','waste') NOT NULL`; `down()` melempar `RuntimeException` berbahasa Indonesia bila ada baris bertipe `sale|return|waste`, lalu mengembalikan enum lama (daftar persis di `2026_12_04_000001_create_outlet_stock.php` baris terakhir `up()`)
- [X] T007 Buat `database/migrations/2026_12_30_000003_allow_negative_material_outlet_stock.php`: lepas `chk_material_outlet_stock_nonneg` (periksa dulu lewat `information_schema.TABLE_CONSTRAINTS` supaya idempoten, pola migrasi 045); `down()` menolak bila ada `material_outlet_stock.current_stock < 0`, lalu memasang kembali CHECK. Komentar: invarian pindah ke `MaterialStockService` (research D6)
- [X] T008 [P] Buat model `app/Models/ModifierOptionMaterial.php` (`HasDataMode`, `HasFactory`; fillable `modifier_option_id`, `material_id`, `quantity`; cast `quantity` `decimal:4`; `material()` BelongsTo `withTrashed()`; `option()` BelongsTo `ModifierOption`) dan factory `database/factories/ModifierOptionMaterialFactory.php`
- [X] T009 [P] Tambahkan `materials(): HasMany` (ke `ModifierOptionMaterial`) pada `app/Models/ModifierOption.php` dan `modifierUsages(): HasMany` pada `app/Models/Material.php`
- [X] T010 Ubah `app/Services/MaterialStockService.php`: tambahkan `public const NEGATIVE_ALLOWED = ['sale', 'waste'];`; untuk tipe di daftar itu jangan menolak dan jangan menjepit `stockAfter` ke 0 (simpan minus apa adanya); untuk tipe lain perilaku lama tetap persis (tolak < −0,0005, jepit 0). Perbarui docblock kelas (tipe baru, minus terbatas, rujukan `087 research D6`)
- [X] T011 Tes regresi fondasi di `tests/Feature/MaterialStockNegativeGuardTest.php`: `adjustment` dan `transfer_out` yang menghasilkan minus tetap 422; `sale`/`waste` menghasilkan saldo minus pada `material_outlet_stock` dan `materials.current_stock`, `stock_after` negatif di movement; `purchase` setelah minus (−1 + 10 = 9). Jalankan juga `MaterialStockPerOutletTest`, `MaterialTest`, `MasterDataImportVendorMaterialTest`, dan tes transfer/PO yang ada (`--filter='Material|StockTransfer|PurchaseOrder'`) dan catat hasilnya di `evidence.md`
- [X] T012 Buat kerangka `app/Services/ModifierMaterialConsumption.php` dengan konstruktor (`MaterialStockService`, `RecipeService`) dan tiga metode publik bertanda tangan sesuai `data-model.md`: `consumeOrder(Order $order, User $user): array`, `restoreOrder(Order $order, User $user): void`, `wasteLine(OrderItem $line, User $user): void`, plus helper privat `needFor(Collection $items): array` yang mengembalikan `[material_id => [order_item_id => qty]]` (memuat pemetaan untuk SEMUA `option_id` di `order_items.modifiers` dalam SATU query, hanya bahan aktif dan tidak terhapus pada mode berjalan; `round(qty_mapping × line.qty, 3)`, dijumlahkan per bahan per baris). Docblock kelas: satu-satunya penerjemah pesanan → bahan, dipanggil hanya dari titik selesai/void/refund/buang (research D4, D7, D8)
- [X] T013 Buat `app/Support/MaterialWarningBag.php` (didaftarkan `scoped` di `app/Providers/AppServiceProvider.php` supaya per-permintaan): `push(array $warning)`, `all(): array`, `isEmpty()`. Komentar: membawa peringatan bahan minus dari service ke controller tanpa mengubah tipe kembalian service yang sudah ada (research D12)
- [X] T014 Tambahkan pesan Indonesia di `lang/id/vendors_materials.php` (dan padanan `lang/en/` bila ada): `material_delete_used_by_modifier` (dengan `:usage`), `option_material_inactive`, `option_material_not_found`, `option_material_duplicate`, `material_negative_warning` (`:material`, `:before`, `:required`, `:unit`)

**Checkpoint**: migrasi berjalan di DB uji, minus terbatas teruji, kerangka service ada; belum ada perilaku pesanan yang berubah.

---

## Phase 3: User Story 1 - Atur bahan yang dipakai sebuah pilihan modifier (Priority: P1) 🎯 MVP

**Goal**: pengelola memetakan 0..n bahan + jumlah ke tiap pilihan modifier lewat formulir grup modifier; bahan menampilkan "dipakai oleh".

**Independent Test**: simpan grup Topping dengan Telur → Telur ×1 dan Bakso → Bakso ×3; buka lagi, pemetaan sama; `GET /materials/{telur}` menampilkan Topping → Telur; hapus bahan Telur ditolak 409.

### Tests for User Story 1

- [X] T015 [P] [US1] Tulis `tests/Feature/ModifierOptionMaterialMappingTest.php`: (a) `POST /modifier-groups` dengan `options[].materials` menyimpan baris; (b) `PUT` dengan `materials: []` mengosongkan pilihan itu; (c) `PUT` tanpa kunci `materials` tidak mengubah pemetaan (klien lama); (d) 422 untuk `quantity` 0/negatif/bukan angka, `material_id` duplikat dalam satu pilihan, bahan nonaktif, bahan terhapus, bahan mode DEMO saat LIVE — dengan kunci galat `options.{i}.materials.{j}.*`; (e) satu log `modifier_materials_changed` per simpan yang mengubah pemetaan, tanpa log bila tidak berubah, dan tidak ada log bila transaksi gagal; (f) respons `options[].materials[]` berbentuk sesuai `contracts/api.md` §1 (quantity 4 desimal, `material_active`); (g) `GET /materials/{id}` memuat `used_by_modifier_options`, `GET /materials` tidak; (h) `DELETE /materials/{id}` 409 bila dipakai pilihan aktif, 204 bila hanya dipakai pilihan nonaktif; (i) peran tanpa menu `dining_setup` 403
- [X] T016 [P] [US1] Tulis `qa-tests/unit/modifierMaterials.test.js` untuk komponen `OptionMaterialsEditor.vue`: tambah/hapus baris, satuan bahan tampil, jumlah pecahan diterima, payload hanya menyertakan `materials` untuk pilihan yang barisnya diubah, penanda "nonaktif" untuk bahan nonaktif

### Implementation for User Story 1

- [X] T017 [US1] Di `app/Http/Requests/StoreModifierGroupRequest.php` tambahkan aturan `options.*.materials` (`sometimes|array|max:20`), `options.*.materials.*.material_id` (`required|integer|distinct`), `options.*.materials.*.quantity` (`required|numeric|gt:0|max:99999999.9999`) dengan pesan Indonesia; `UpdateModifierGroupRequest` mewarisinya
- [X] T018 [US1] Di `app/Services/ModifierGroupService.php` perluas `syncOptions()`: setelah tiap pilihan disimpan/dibuat, bila baris permintaan memuat kunci `materials`, panggil `syncOptionMaterials(ModifierOption $option, array $rows): ?array` yang (1) memvalidasi bahan lewat `Material::query()` (global scope mode) — ada, tidak terhapus, `is_active` — kalau tidak lempar `InputRejectedException` berkunci `options.{i}.materials.{j}.material_id`; (2) mengganti penuh baris `modifier_option_materials` pilihan itu; (3) mengembalikan `['old' => [...], 'new' => [...]]` bila berubah, `null` bila sama. Kumpulkan perubahan semua pilihan; bila ada, tulis SATU `ActivityLogger::log(action: 'modifier_materials_changed', entityType: 'ModifierGroup', ...)` di transaksi yang sama (research D3). `create()`/`update()` perlu menerima `User` atau `userId` untuk log: tambahkan parameter opsional dan teruskan dari `ModifierGroupController` (`$request->user()->id`)
- [X] T019 [US1] Di `app/Services/ModifierGroupService.php::loaded()` muat `options.materials.material` (withTrashed); di `app/Http/Resources/ModifierGroupResource.php` tambahkan `materials` pada tiap opsi: `material_id`, `code`, `name`, `unit`, `quantity` (`number_format(..., 4, '.', '')`), `material_active` (`is_active && !trashed()`). Periksa `ModifierGroupController::index` juga eager-load `options.materials.material` (konstitusi V, tanpa N+1)
- [X] T020 [US1] Di `app/Http/Controllers/Api/MaterialController.php`: `show()` memuat `modifierUsages.option.group` (hanya di show); `app/Http/Resources/MaterialResource.php` menambah `used_by_modifier_options` (`whenLoaded`) berbentuk `contracts/api.md` §2; `destroy()` menolak 409 `__('vendors_materials.material_delete_used_by_modifier', ['usage' => 'Grup → Pilihan'])` bila ada pemetaan dengan pilihan `is_active = true` (letakkan setelah penjaga BOM, pola yang sama)
- [X] T021 [P] [US1] Buat `resources/js/components/restaurant/OptionMaterialsEditor.vue`: daftar baris (pilih bahan aktif lewat `listMaterials({ is_active: 1, per_page: 100, search })` dengan `BaseSelect`/pencarian, jumlah `BaseInput type=number step=0.001 min>0`, satuan bahan tampil di samping jumlah, hapus baris), emit `update:modelValue`; hanya token Tailwind, teks lewat `t('dining_setup.option_materials_*')`
- [X] T022 [US1] Di `resources/js/components/restaurant/ModifierGroupsAdmin.vue`: tambahkan `materials` ke `blankOption()` dan ke pemetaan saat membuka grup (`openEdit`, baris ±81), tampilkan `OptionMaterialsEditor` per pilihan (bisa dilipat, ringkasan "2 bahan"), dan di `save()` (baris ±97) sertakan `materials: [{material_id, quantity}]` HANYA untuk pilihan yang barisnya diubah atau pilihan baru (bandingkan dengan salinan awal); kolom tabel `options` menampilkan ikon bahan bila ada pemetaan
- [X] T023 [P] [US1] Tambahkan kunci terjemahan `dining_setup.option_materials_*` (judul, tambah bahan, jumlah, satuan, nonaktif, ringkasan jumlah bahan) dan `materials.used_by_*` di `resources/js/locales/id.json` dan `resources/js/locales/en.json`
- [X] T024 [US1] Jalankan T015 dan T016 sampai hijau; catat hasil di `evidence.md`

**Checkpoint**: pemetaan bisa diatur dan terlihat; penjualan belum memotong apa pun.

---

## Phase 4: User Story 2 - Penjualan memotong stok bahan saat pesanan selesai (Priority: P1) 🎯 MVP

**Goal**: saat checkout ritel, tab lunas, atau split, bahan dari pilihan yang dipesan dipotong dari saldo outlet transaksi (satu saldo per bahan), biaya bahan masuk `cost_price`, peringatan minus dikirim ke kasir.

**Independent Test**: stok outlet A Indomie Soto 50, Telur 100; checkout ritel 2× Indomie Kuah (SOTO, Sedang, Telur) → 46/96 dengan 4 movement `sale` berujuk `order_item`; tab: tambah/kurangi/ganti tanpa movement, lunas → potong isi akhir; outlet B tak berubah.

### Tests for User Story 2

- [X] T025 [P] [US2] Tulis `tests/Feature/ModifierMaterialConsumptionTest.php` (checkout ritel `POST /orders`): potong Σ(qty × porsi) per bahan; satu movement per (baris, bahan) walau dua pilihan memetakan bahan sama; dua menu berbeda dengan Telur memotong satu saldo; pilihan tanpa pemetaan = 0 movement; bahan nonaktif dilewati; outlet = `order.outlet_id` (outlet lain tak berubah); DEMO tidak menyentuh LIVE; varian `track_stock=false` tetap memotong bahan; pecahan (0,5 × 3 = 1,500); `order_items.cost_price` bertambah Σ(qty × `RecipeService::unitCost`) dan `orders.total_cost` = Σ cost × qty; `total_amount`/`tax_amount` tidak berubah dibanding tanpa fitur; pemetaan diubah setelah jual tidak mengubah movement lama
- [X] T026 [P] [US2] Tulis `tests/Feature/TabMaterialConsumptionTest.php`: `POST /tabs/{id}/items`, `PATCH .../items/{item}` (qty, `modifier_option_ids`), `DELETE` dengan `stock_decision=return`, `move`, `merge` = 0 movement bahan; settle sebagian (`POST /tabs/{id}/payments` belum lunas) = 0; settle lunas = potong isi akhir sekali; split = pesanan baru `completed` memotong barisnya sendiri dan sisa tab memotong saat lunas (total = isi awal, tanpa ganda, termasuk split yang memecah jumlah satu baris); biaya bahan tidak tergandakan pada baris hasil split
- [X] T027 [P] [US2] Tulis `tests/Feature/MaterialConsumptionIdempotencyTest.php`: retry `POST /orders` dengan `local_ref` sama = satu set movement; retry split dengan `client_ref` sama (respons 200 `replayed`) = tanpa movement tambahan; retry settle dengan `submission_ref` sama = tanpa movement tambahan; memanggil `consumeOrder` dua kali langsung pada pesanan yang sama = movement tetap satu set (penjaga D5); urutan movement yang ditulis `material_id ASC, order_item_id ASC`

### Implementation for User Story 2

- [X] T028 [US2] Implementasikan `ModifierMaterialConsumption::consumeOrder()` di `app/Services/ModifierMaterialConsumption.php`: (1) penjaga — bila sudah ada `MaterialStockMovement` `type='sale'`, `reference_type='order_item'`, `reference_id IN (id baris)`, `Log::warning` lalu kembalikan `[]`; (2) `needFor()`; (3) iterasi `ksort` per `material_id`, lalu `ksort` per `order_item_id`, panggil `MaterialStockService::applyMovement($material, (int) $order->outlet_id, 'sale', -$qty, 'order_item', $lineId, $user->id)`; (4) bila `stock_after < 0`, susun peringatan (`material_id`, `material`, `unit`, `outlet_id`, `stock_before`, `required`, `stock_after` sebagai string 3 desimal) dan `push` ke `MaterialWarningBag` (satu peringatan per bahan, `required` = total pesanan, `stock_before` = saldo sebelum movement pertama bahan itu); (5) biaya: per baris `cost_price += round(Σ_mat (qty_per_porsi × (RecipeService::unitCost($mat) ?? 0)), 2)` lalu tulis ulang HANYA `orders.total_cost = round(Σ cost_price × qty, 2)` (research D9 — jangan memanggil `TabService::recalculate`); kembalikan daftar peringatan
- [X] T029 [US2] Di `app/Services/OrderService.php` (checkout, setelah loop pembuatan `order_items` dan sebelum pencatatan pembayaran, ±baris 236), panggil `app(ModifierMaterialConsumption::class)->consumeOrder($order, $cashier)` di dalam transaksi yang sama; komentar `087 research D4`
- [X] T030 [US2] Di `app/Services/TabSettlementService.php::settle` panggil `consumeOrder($order, $user)` HANYA di cabang `if ($completes)` (±baris 112-123), setelah status/receipt diisi dan di transaksi yang sama; di `split()` panggil `consumeOrder($paid, $user)` setelah semua baris `$paid` dibuat dan `recalculate($paid)` (±baris 226), sebelum pembayaran split dicatat. Jangan memanggil pada jalur replay (`replayed`)
- [X] T031 [US2] Sisipkan `material_warnings` di respons: `app/Http/Controllers/Api/OrderController.php::store` (respons `OrderResource`, ±baris 110) dan `app/Http/Controllers/Api/TabPaymentController.php` (`store` untuk settle dan `split`): bila `app(MaterialWarningBag::class)` tidak kosong, tambahkan bidang tingkat atas `material_warnings` (gunakan `->additional([...])` untuk resource, atau gabungkan ke array untuk respons array). Tanpa peringatan, bentuk respons persis seperti sebelumnya
- [X] T032 [P] [US2] Di `resources/js/views/PosView.vue` (setelah `createOrder` berhasil, ±baris 241) dan `resources/js/views/TabView.vue` (setelah `payTab`/`splitTab` berhasil), tampilkan setiap `material_warnings[]` sebagai toast peringatan (`useToastStore`, varian warning, teks `t('pos.material_negative_warning', {...})`); bukan galat, tidak menghalangi alur cetak/selesai. Tambahkan kunci `pos.material_negative_warning` di `resources/js/locales/id.json` dan `en.json`
- [X] T033 [P] [US2] Tulis `qa-tests/unit/materialWarnings.test.js`: helper format peringatan (mis. `utils/materialWarnings.js` — buat bila perlu) menghasilkan teks "Telur: stok 1 butir, dibutuhkan 2, kini −1" dan tidak memunculkan toast bila array kosong/tidak ada
- [X] T034 [US2] Jalankan T025–T027 dan T033 sampai hijau; jalankan juga tes pesanan/tab yang sudah ada (`--filter='Order|Tab|Refund|Split|Settle'`) untuk regresi; catat di `evidence.md`

**Checkpoint**: penjualan memotong bahan dengan benar; void/refund BELUM mengembalikan (jangan dirilis sebelum Phase 5).

---

## Phase 5: User Story 3 - Pembatalan, buang, void, refund (Priority: P1) 🎯 MVP

**Goal**: void/refund pesanan selesai mengembalikan potongan bersih dari buku besar; batal "buang" di tab terbuka dan void tab mencatat `waste`.

**Independent Test**: refund pesanan dari US2 → saldo kembali tepat; batal baris dimasak dengan `waste` → movement `waste`, baris tidak terpotong lagi saat lunas; `return` → tanpa movement; refund kedua ditolak tanpa movement.

### Tests for User Story 3

- [X] T035 [P] [US3] Tulis `tests/Feature/MaterialRestoreTest.php`: `POST /orders/{id}/void` dan `POST /orders/{id}/refund` menulis `return` = net per (baris, bahan); pemetaan diubah/dihapus setelah jual tidak memengaruhi jumlah kembali; bahan dinonaktifkan setelah jual tetap dikembalikan; refund/void kedua ditolak dan tidak ada movement tambahan; pesanan tanpa bahan berpemetaan = 0 movement; `waste` tidak pernah dikembalikan; `cost_price` tidak berubah oleh refund
- [X] T036 [P] [US3] Tulis `tests/Feature/MaterialWasteTest.php`: `DELETE /tabs/{id}/items/{item}` dengan `stock_decision=waste` menulis `waste` per bahan (rujukan `order_item` = id baris yang lalu dihapus) di transaksi yang sama dengan log `line_cancelled`; `stock_decision=return` = 0 movement bahan; persetujuan (`OverrideGate`) ditolak → tidak ada movement; `POST /tabs/{id}/void` dan `/cancel` menulis `waste` hanya untuk baris yang sudah dikirim dan item dapurnya bukan `new`; baris `waste` lalu tab dilunasi → baris itu tidak dipotong `sale`; `waste` boleh membuat saldo minus

### Implementation for User Story 3

- [X] T037 [US3] Implementasikan `ModifierMaterialConsumption::restoreOrder()` di `app/Services/ModifierMaterialConsumption.php`: satu query agregat `SUM(qty_change)` per (`reference_id`, `material_id`) atas movement `type IN ('sale','return')`, `reference_type='order_item'`, `reference_id IN (id baris pesanan)`; untuk tiap `net = −sum > 0.0005`, `applyMovement(Material::withTrashed()->withoutGlobalScopes()->find(...)` — hormati mode pesanan —, `(int) $order->outlet_id`, `'return'`, `+net`, `'order_item'`, lineId, userId)` berurutan `material_id ASC, order_item_id ASC`. Bila agregat memakai query mentah, daftarkan di `app/Support/OutletScopeRegistry.php`
- [X] T038 [US3] Implementasikan `ModifierMaterialConsumption::wasteLine()`: `needFor(collect([$line]))`, lalu `applyMovement(..., 'waste', -qty, 'order_item', $line->id, ...)` berurutan `material_id ASC`; outlet = outlet pesanan baris (`$line->order->outlet_id`). Tanpa peringatan kasir (pembatalan, bukan penjualan)
- [X] T039 [US3] Panggil `restoreOrder` di `app/Services/OrderService.php::void` (di dalam `DB::transaction`, setelah loop `return` stok produk, ±baris 283) dan `app/Services/RefundService.php::refund` (setelah loop stok produk, ±baris 107), keduanya setelah penjaga status yang sudah ada
- [X] T040 [US3] Panggil `wasteLine` di `app/Services/TabService.php::removeLine` bila `$stockDecision === 'waste'`, SETELAH `OverrideGate::requireFor` dan SEBELUM `$line->delete()` (±baris 323-337); di `voidTab` (±baris 407-412) panggil `wasteLine($line, $user)` untuk baris yang TIDAK masuk cabang `returnStock`. Komentar: dengan potong-saat-selesai, baris dimasak lalu dibuang tidak akan pernah memotong bahan tanpa ini (`087 research D8`)
- [X] T041 [US3] Jalankan T035–T036 sampai hijau, ditambah `--filter='Refund|Void|TabCancel|Override'` untuk regresi; catat di `evidence.md`

**Checkpoint**: MVP lengkap (US1–US3). Bisa diuji ujung-ke-ujung di peramban.

---

## Phase 6: User Story 4 - Stok bahan tidak cukup (Priority: P2)

**Goal**: pesanan tidak pernah ditolak karena bahan; saldo minus terlihat di daftar bahan dan bisa disaring.

**Independent Test**: Telur outlet A = 1, checkout Indomie Kuah (Telur) ×2 → sukses, toast peringatan, Telur −1, saringan "Minus" menampilkan Telur.

- [X] T042 [P] [US4] Tulis `tests/Feature/MaterialNegativeStockTest.php`: checkout dan settle dengan bahan kurang sukses (201/200), `material_warnings` berisi bahan itu dengan `stock_before`/`required`/`stock_after`; tanpa kekurangan, kunci `material_warnings` TIDAK ada di respons; `GET /materials?stock_status=negative` hanya mengembalikan bahan bersaldo < 0 pada outlet yang berlaku (`OutletFilter`, outlet lain tidak bocor); `outlet_stock[].on_hand` negatif terformat `"-1.000"`
- [X] T043 [US4] Di `app/Http/Controllers/Api/MaterialController.php::index` tambahkan saringan `stock_status=negative` (`whereHas('outletStock', fn ($q) => OutletFilter::apply($q, 'outlet_id')->where('current_stock', '<', 0))`); validasi nilai `in:negative`
- [X] T044 [P] [US4] Di `resources/js/components/masterData/MaterialsTable.vue` tampilkan pil "Minus" (`StatusPill` varian bahaya, token warna yang ada) untuk saldo outlet < 0 dan tambahkan saringan "Hanya yang minus" di `FilterBar` yang mengirim `stock_status=negative`; kunci terjemahan di `resources/js/locales/id.json`/`en.json`
- [X] T045 [P] [US4] Tulis `qa-tests/unit/materialsNegative.test.js`: pil Minus muncul untuk `on_hand` negatif dan tidak untuk 0; saringan mengirim parameter yang benar
- [X] T046 [US4] Jalankan T042 dan T045 sampai hijau; catat di `evidence.md`

---

## Phase 7: User Story 5 - Riwayat pemakaian bahan (Priority: P3)

**Goal**: pemilik melihat pergerakan bahan per jenis/outlet/tanggal dengan nomor pesanan, dan daftar pilihan yang memakai bahan.

**Independent Test**: setelah penjualan + refund, riwayat Telur dengan saringan `sale,return` menampilkan dua baris dengan nomor pesanan; bersih = potong − kembali.

- [X] T047 [P] [US5] Tulis `tests/Feature/MaterialMovementsEndpointTest.php`: `GET /materials/{id}/movements` amplop paginasi standar; saring `type[]`, `outlet_id[]`, `from`/`to` (tanggal bisnis toko); outlet di luar milik pengguna tidak terlihat; `reference.order_number` terisi untuk baris yang ada dan `null` untuk baris yang dihapus (`waste`); jumlah query tetap (tanpa N+1) untuk 25 baris; peran tanpa izin penyesuaian stok bahan 403
- [X] T048 [US5] Tambahkan rute `Route::get('/materials/{material}/movements', [MaterialController::class, 'movements'])` di `routes/api.php` (dekat `adjustments`, ±baris 241) dan metode `movements()` di `app/Http/Controllers/Api/MaterialController.php`: gerbang sama dengan `adjustStock`, `OutletFilter::apply` pada `outlet_id`, saringan, `orderByDesc('id')`, paginasi; terjemahkan rujukan `order_item` ke `{order_id, order_number}` dengan satu query `order_items JOIN orders` untuk id di halaman itu; eager-load `user:id,name` dan outlet. Perbarui teks entri `material_stock_movements` di `app/Support/OutletScopeRegistry.php` (kini dibaca endpoint riwayat lewat `OutletFilter`)
- [X] T049 [P] [US5] Tambahkan `listMaterialMovements(id, params)` di `resources/js/api/materials.js` dan buat `resources/js/components/masterData/MaterialUsageModal.vue` dengan dua tab: "Dipakai oleh" (dari `getMaterial(id).used_by_modifier_options`) dan "Riwayat" (tabel paginasi `usePaginatedList`, saringan jenis + tanggal, kolom jenis/jumlah/saldo sesudah/nomor pesanan/pengguna/waktu); buka dari aksi baris baru di `resources/js/components/masterData/MaterialsTable.vue`; terjemahan di `id.json`/`en.json`
- [X] T050 [US5] Jalankan T047 sampai hijau; catat di `evidence.md`

---

## Phase 8: Polish & Cross-Cutting

- [X] T051 Perbarui `docs/openapi-pos-mvp.yaml` sesuai `contracts/api.md`: skema opsi grup modifier (`materials`), `MaterialResource.used_by_modifier_options`, parameter `stock_status`, 409 hapus bahan, rute `GET /materials/{material}/movements`, bidang opsional `material_warnings` pada `POST /orders`, `POST /tabs/{order}/payments`, `POST /tabs/{order}/split`, enum tipe pergerakan bahan
- [X] T052 [P] Tambahkan catatan bertanggal 2026-10-31 di `CLAUDE.md` bagian "Domain rules" (butir baru "Bahan dari pilihan modifier (087)": satu penerjemah `ModifierMaterialConsumption`, potong saat selesai, minus hanya `sale`/`waste`, pengembalian dari buku besar, `material_warnings`, jangan menulis movement bahan di tempat lain) dan catatan bertanggal di `docs/PRD-POS-Event-Multivendor.md` §10.2 (bahan kini dipotong oleh penjualan lewat pilihan modifier; resep/BOM menu tetap hanya HPP)
- [X] T053 Bila T003 menemukan parser Android tidak toleran: perbaiki parser di `android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt` agar mengabaikan bidang tak dikenal, tambah tes unit Kotlin, dan catat bahwa APK lama perlu diganti sebelum server dirilis. Bila toleran: tandai selesai dengan rujukan bukti di `evidence.md`
- [X] T054 Jalankan suite backend penuh dengan flag aman dan `npm test`; catat jumlah lulus/gagal di `evidence.md`. Kegagalan yang sudah ada sebelumnya (mis. `ShiftReportParityTest`) dibuktikan dengan menjalankannya di `develop-warkop-wjm` sebelum ditandai "bukan dari 087"
- [ ] T055 `npm run build`, lalu verifikasi peramban nyata sesuai `quickstart.md` langkah 1–7 dan 9 (Grup modifier, Bahan, POS ritel, Tab, refund, konkurensi dua tab peramban) dengan konsol diperiksa; catat hasil dan tangkapan di `evidence.md`. Langkah 8 (tablet) dicatat "belum diverifikasi di perangkat" bila tablet tidak tersedia
- [X] T056 Tinjauan akhir: `git diff` memastikan tidak ada `MaterialStockMovement::create`/`material_outlet_stock` ditulis di luar `MaterialStockService`, tidak ada hex literal di komponen Vue, semua komentar baru berbahasa Indonesia, `OutletScopeRegistryTest` lulus

---

## Phase 9: User Story 6 - Sumber stok per produk: varian atau modifier (Priority: P1)

*(Ditambahkan 2026-10-31. Keputusan pemilik: FR-025 = gabungan, FR-031 = tanpa angka stok / tidak pernah habis. Lihat research D16–D21.)*

**Goal**: produk bersumber `modifier` tidak pernah menggerakkan stok varian di modul mana pun; bahan modifier tetap dipotong untuk semua produk; tampilan POS/Android tanpa stok.

**Independent Test**: Indomie Kuah = `modifier`, Teh Botol = `variant`. Jual/tab/split/void/refund/transfer/koreksi: Indomie Kuah 0 pergerakan stok varian, bahan bergerak; Teh Botol seperti hari ini plus bahan modifier-nya. `GET /products?pos=1` mengirim `track_stock: false` untuk varian Indomie Kuah.

### Verifikasi awal

- [X] T057 [US6] Periksa dan catat di `evidence.md`: (a) apakah halaman Produk/formulir dan POS memakai `ProductVariantResource` yang sama (research D19); (b) apakah `PUT /products/{id}` lewat alur rilis menu `menu_change` (`app/Services/Release/ChangeApplier.php`, `ProductLifecycleService`) (research D21); (c) daftar semua pemanggil `applyMovementIfTracked`/`applyMovement` varian (`grep -rn "applyMovement" app/Services app/Http`) — perbarui tabel D20 bila ada titik yang terlewat

### Tests for User Story 6

- [X] T058 [P] [US6] Tulis `tests/Feature/ProductStockSourceTest.php`: bawaan `variant` untuk produk lama dan baru; `PUT /products/{id}` `stock_source` tersimpan + log `stock_source_changed` dalam transaksi; 422 nilai lain; `warnings` `variant_stock_remaining` dan `no_material_mappings`; checkout/tab/split produk `modifier` = 0 `stock_movements` varian dan bahan tetap terpotong; produk `variant` = stok varian bergerak DAN bahan modifier terpotong (FR-025); ubah sumber saat tab terbuka lalu kurangi/hapus/void/refund = pengembalian mengikuti snapshot `order_items.stock_source` (tidak ada stok fantom, tidak ada yang terlewat); `GET /products?pos=1&with_variants=1` dan `GET /variants/lookup` mengirim `track_stock` efektif, payload pengelolaan mengirim mentah + `stock_tracked`
- [X] T059 [P] [US6] Tulis `tests/Feature/StockSourceModulesTest.php` (SC-009, satu uji per modul FR-030): `POST /stock/adjustments` 409 `stock_source_modifier`; transfer baris varian produk `modifier` 422; `GET /stock/low`, `GET /stock/balances`, `GET /reports/stock-by-outlet` (dan ekspornya), perhatian dasbor tidak memuat varian produk `modifier`; impor master data `products` dengan kolom `stock_source` (kosong = tidak berubah, nilai salah = galat baris, all-or-nothing); ekspor memuat kolom itu; `WjmCatalogSeedService` mengisi `variant`
- [X] T060 [P] [US6] Tulis `qa-tests/unit/productStockSource.test.js`: formulir produk mengirim `stock_source`; varian produk `modifier` tampil "—" di daftar Produk dan tidak pernah "habis" di kartu Tab/POS (`isUnavailable`)

### Implementation for User Story 6

- [X] T061 [US6] Buat migrasi `database/migrations/2026_12_30_000004_add_stock_source_to_products_and_order_items.php`: `products.stock_source ENUM('variant','modifier') NOT NULL DEFAULT 'variant'`; `order_items.stock_source ENUM('variant','modifier') NULL`; `down()` menolak bila ada produk `modifier`. Jangan menjalankannya di DB dev tanpa persetujuan pemilik
- [X] T062 [US6] Di `app/Models/Product.php` tambahkan `stock_source` (fillable, konstanta `STOCK_VARIANT`/`STOCK_MODIFIER`); di `app/Models/ProductVariant.php` tambahkan `tracksOwnStock(): bool` dan `scopeTracksOwnStock(Builder)` (join/whereHas `products.stock_source = 'variant'` + `track_stock = 1`) dengan komentar `087 research D17`; tambahkan `stock_source` ke fillable/cast `OrderItem`
- [X] T063 [US6] Di `app/Services/StockService.php`: `applyMovementIfTracked` memakai `tracksOwnStock()` (muat `product` bila belum); tambahkan `applyLineMovementIfTracked(OrderItem $line, ProductVariant $variant, string $type, int $qtyChange, ...)` yang melewati baris ber-`stock_source = 'modifier'` dan, untuk NULL (baris lama), memakai `track_stock` seperti sebelumnya
- [X] T064 [US6] Isi snapshot `order_items.stock_source` di semua pembuat baris: `OrderService::create`, `TabService::addLine`, klon baris di `TabSettlementService::split` (salin dari baris asal); alihkan semua jalur PENGEMBALIAN/kurangi stok varian ke `applyLineMovementIfTracked`: `OrderService::void`, `RefundService::refund`, `TabService::returnStock`, `TabService::updateLine` (delta qty, dua arah memakai snapshot baris), `voidTab` (lewat `returnStock`)
- [X] T065 [US6] Modul lain (research D20): `StockController::adjust` → 409 `stock_source_modifier` dengan pesan Indonesia di `lang/id/stock.php` (+`en`); `StockTransferService.php:211` → `tracksOwnStock()`; `StockController::lowStock`/`balances`, `ReportController::stockByOutlet`, `AttentionService::lowStock` → kecualikan lewat `scopeTracksOwnStock` atau syarat SQL yang sama (daftarkan kueri mentah baru di `OutletScopeRegistry` bila perlu)
- [X] T066 [US6] API produk: `StoreProductRequest` (+ request ubah) menerima `stock_source` (`sometimes|in:variant,modifier`); jalur ubah produk menulis log `stock_source_changed` dalam transaksi yang sama dan mengembalikan `warnings` aditif (FR-027, FR-029; lewat payload rilis bila T057b menemukan rilis menu); resource produk mengirim `stock_source`; `ProductVariantResource` mengirim `track_stock` efektif pada payload POS (`pos=1`, `/variants/lookup`) dan mentah + `stock_tracked` di payload pengelolaan (sesuai temuan T057a)
- [X] T067 [US6] Master data dan katalog: tambahkan kolom `stock_source` ke lembar `products` di `app/Support/MasterDataSheets.php` (satu daftar untuk ekspor, templat, impor); `MasterDataImportService` memvalidasi dan menulis (kosong = tidak berubah); `WjmCatalogSeedService` mengisi `stock_source = 'variant'` secara eksplisit (atribut di luar `$fillable` diisi eksplisit, pola 068)
- [ ] T068 [P] [US6] UI: `resources/js/views/products/ProductFormDrawer.vue` pilihan "Sumber stok: Varian / Modifier" dengan teks bantuan dan tampilan `warnings` sebagai toast peringatan; daftar Produk dan `ProductVariantsTable.vue` menampilkan "—" + pil "Stok dari modifier" untuk produk `modifier`; kunci terjemahan di `id.json`/`en.json`. Pastikan `TabView.vue:121` dan kartu POS memakai `track_stock` efektif dari payload POS (tanpa logika sumber di klien)
- [X] T069 [US6] OpenAPI (`docs/openapi-pos-mvp.yaml`) sesuai `contracts/api.md` §6; catatan bertanggal US6 di butir CLAUDE.md "Bahan dari pilihan modifier (087)" dan catatan PRD 087
- [X] T070 [US6] Jalankan T058–T060 sampai hijau, lalu suite backend penuh dan `npm test` dengan flag aman; kegagalan yang diklaim sudah ada dibuktikan di commit dasar; catat di `evidence.md`
- [ ] T071 [US6] Setelah persetujuan pemilik: jalankan migrasi T061 di DB dev (cadangan dulu), uji asap hanya-baca `GET /products?pos=1` untuk produk `modifier`, dan verifikasi peramban/tablet sesuai quickstart (atau catat "belum diverifikasi")

**Checkpoint**: produk bersumber modifier konsisten di semua modul; FR-030 punya uji per modul.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: tanpa ketergantungan. T002–T004 bisa mengubah cakupan; selesaikan sebelum Phase 3.
- **Foundational (Phase 2)**: setelah Setup; MEMBLOKIR semua user story.
- **US1 (Phase 3)**: setelah Foundational.
- **US2 (Phase 4)**: setelah Foundational; tes US2 butuh pemetaan, jadi praktiknya setelah T017–T019 (US1 backend). UI US1 (T021–T023) bisa paralel dengan US2.
- **US3 (Phase 5)**: setelah US2 (mengembalikan apa yang US2 potong; `wasteLine` memakai `needFor` dari T012).
- **US4 (Phase 6)**: setelah T010 dan T028–T031; independen dari US3.
- **US5 (Phase 7)**: setelah Foundational; datanya baru bermakna setelah US2/US3.
- **Polish (Phase 8)**: setelah story yang akan dirilis selesai.
- **US6 (Phase 9)**: setelah US2+US3 (memakai jalur jual/kembali yang sama); T057 lebih dulu karena temuannya menentukan T066. US6 P1 → dirilis bersama MVP.

### User Story Dependencies

```
Setup → Foundational ─┬─> US1 (backend T017–T020) ─┬─> US2 ─┬─> US3 ──┐
                      │                            │        └─> US4 ──┼─> Polish
                      │   US1 UI (T021–T023) ──────┘                  │
                      └─> US5 (setelah US2/US3 untuk data) ───────────┘
```

### Within Each User Story

- Tes ditulis lebih dulu dan gagal sebelum implementasi.
- Service sebelum controller; controller sebelum UI.
- Setiap story ditutup dengan tugas "jalankan sampai hijau" yang mencatat bukti.

### Parallel Opportunities

- Phase 2: T008 ∥ T009 (setelah T005); T013 ∥ T014.
- US1: T015 ∥ T016 ∥ T021 ∥ T023.
- US2: T025 ∥ T026 ∥ T027 (tes), lalu T032 ∥ T033 setelah T031.
- US3: T035 ∥ T036.
- US4: T042 ∥ T044 ∥ T045.
- US5: T047 ∥ T049 (setelah kontrak `contracts/api.md` §3).
- Polish: T052 ∥ T051.

---

## Parallel Example: User Story 2

```bash
# Tulis ketiga berkas tes bersamaan (berkas berbeda):
Task: "T025 tests/Feature/ModifierMaterialConsumptionTest.php"
Task: "T026 tests/Feature/TabMaterialConsumptionTest.php"
Task: "T027 tests/Feature/MaterialConsumptionIdempotencyTest.php"

# Setelah T031 (respons membawa material_warnings):
Task: "T032 toast di PosView.vue dan TabView.vue"
Task: "T033 qa-tests/unit/materialWarnings.test.js"
```

---

## Implementation Strategy

### MVP (US1 + US2 + US3, dirilis bersama)

1. Phase 1 → keputusan U1/U2/U3 tercatat.
2. Phase 2 → migrasi + minus terbatas + kerangka service.
3. Phase 3 (US1) → pemetaan tersimpan dan terlihat.
4. Phase 4 (US2) → potong saat selesai. **JANGAN rilis di titik ini**: void/refund belum mengembalikan bahan.
5. Phase 5 (US3) → pengembalian dan buang. **STOP & VALIDATE**: quickstart langkah 1–7 di peramban.
6. Rilis MVP.

### Incremental

7. US4 → saringan minus (nilai operasional langsung untuk stok opname).
8. US5 → riwayat dan "dipakai oleh" penuh.
9. Polish → OpenAPI, catatan bertanggal, suite penuh, verifikasi akhir.

### Catatan

- Commit per tugas atau kelompok logis, pesan berbahasa Indonesia, diakhiri baris atribusi.
- Push dan PR hanya dengan izin eksplisit pengembang per kali.
- Bila satu tugas menemukan fakta yang bertentangan dengan `research.md`, perbarui `research.md` lebih dulu, lalu lanjutkan.
