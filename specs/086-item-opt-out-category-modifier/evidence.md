# Evidence: 086 — Item melepas grup modifier warisan kategori

**Klasifikasi**: INTERNAL

Setiap bagian dicatat apa adanya. Status awal semuanya "belum dijalankan".

## Pembaca grup kategori yang ditemukan (T026) — dijalankan 2026-10-30

Pencarian `category.modifierGroups`, `category->modifierGroups`, `modifier_group_category`, `->categories()` di `app/`, `resources/js/`, `android-pos/app/src/main`, `database/seeders`:

| Tempat | Jenis | Keputusan |
|--------|-------|-----------|
| `ModifierResolver::effectiveGroups` / `inheritedGroups` | pembaca | SATU-SATUNYA pembaca; memakai pengecualian |
| `ProductDetailService::links()` | pembaca | memakai `inheritedGroups()` |
| `ProductCatalogQuery` (nama grup di daftar) | pembaca | memakai `inheritedGroups()` |
| `ProductController` (2 eager-load `with_modifiers`), `ProductCatalogQuery::base()`, `ProductDetailService` (eager-load) | pemuat relasi | ditambah `excludedModifierGroups` (tanpa N+1) |
| `ModifierGroupService::setAttachments` / `delete` | penulis | tidak diubah; baris pengecualian ikut terhapus lewat cascade FK saat grup dihapus |
| `Category.php`, `ModifierGroup.php` | definisi relasi | tidak relevan |
| `WjmCatalog*` | penghitung kategori / pemetaan stasiun | tidak relevan |
| Android `MenuMapper` | membaca `modifier_groups` efektif dari server | tidak berubah (server sudah menyaring) |
| Ekspor/impor Excel master data | tidak memuat kolom modifier | tidak relevan |

Tidak ditemukan pembaca keempat.

## Hasil suite (sejauh ini)

| Suite | Hasil |
|-------|-------|
| `ModifierExclusionTest` (PHPUnit, DB uji, flag `-e` benar) | 20 lulus, 117 asersi |
| Tes modifier/pesanan/detail yang sudah ada (`ModifierLinkTest`, `ModifierResolverTest`, `ModifierGroupsTest`, `ProductDetailTest`, `OrderModifierTest`, `TabModifiersTest`) | 72 lulus (dijalankan bersama tes baru sebelum tes US2/US3 ditambah) |
| `npm test` penuh | 222 berkas, 1857 lulus, 2 dilewati |
| `npm run build` | berhasil |
| Suite backend penuh | percobaan 1 TERPUTUS (exit 137): kontainer Docker dimulai ulang pada 21:05 WIB setelah 456 tes, tanpa kegagalan sebelum terputus. Percobaan 2 saya HENTIKAN sendiri (±21:59 WIB) karena menjenuhkan VM Docker bersama dan membuat web dev tidak memuat. KEDUANYA bukan hasil: suite backend penuh BELUM selesai dijalankan untuk 086 dan harus diulang saat mesin lengang (T027 tetap terbuka) |

Temuan saat menulis tes (semuanya galat tes, bukan galat fitur): helper `save()` memakai operator `+` array (kunci kiri menang, menimpa `links`) dan kunci respons lookup adalah `variant_id` bukan `id`; menghapus grup langsung gagal karena FK `RESTRICT` pada opsi, jalur benar `ModifierGroupService::delete`. Satu galat nyata ditangkap tes lama: argumen ketiga `undefined` pada `saveModifierLinks` mengubah arity panggilan, diperbaiki dengan spread bersyarat.

## Pemeriksaan peramban (tiga peran) — belum dijalankan

## POS web — belum dijalankan

## Perangkat — belum diverifikasi di perangkat

## Peta persyaratan → bukti (T031, disusun 2026-10-30)

| FR | Bukti | Status |
|----|-------|--------|
| FR-001 melepas grup warisan untuk satu item | `ModifierExclusionTest::test_excluding_a_category_group_removes_it_from_that_item_in_every_reader`; `InheritedModifierList.test.js`; tes drawer/form | tes lulus |
| FR-002 hanya item itu | `…other_items_in_the_same_category_and_the_category_itself_are_unchanged` | tes lulus |
| FR-003 tidak ditanyakan di POS web/tablet, tanpa aturan/harga | server: `…removes_it…in_every_reader`, `…an_option_of_an_excluded_group_is_refused…` (422). POS web dan tablet NYATA: belum dicoba | server lulus; POS web/tablet BELUM diverifikasi |
| FR-004 grup dilepas tetap terlihat, terpisah | `…detail_page_lists_a_released_group…`; `InheritedModifierList.test.js` | tes lulus; tampilan peramban BELUM diverifikasi |
| FR-005 pulihkan | `…an_empty_list_restores_everything…`, `…a_subset_restores…`; tes drawer/form restore | tes lulus |
| FR-006 laci dan formulir sama | `ModifierExclusionSurfaces.test.js` (2 tes) | tes lulus |
| FR-007 tersembunyi untuk peran tanpa izin + 403 | server: `…a_role_without_permission_is_refused…`; UI: `InheritedModifierList.test.js` (tanpa tombol bila tidak `editable`). Catatan: `editable` dipasang `true` di kedua permukaan karena permukaan itu sendiri hanya dibuka pemegang izin; gerbang nyata per peran di peramban BELUM diverifikasi | sebagian |
| FR-008 grup baru kategori tetap berlaku | `…a_group_added_to_the_category_later…` | tes lulus |
| FR-009 mengikuti rilis menu | `…with_the_menu_release_on…`, `…queued_change_without_the_exclusion_key…` | tes lulus |
| FR-010 log aktivitas satu transaksi | `…one_audit_row_records_the_old_and_new_exclusions…` | tes lulus |
| FR-011 pesanan/tab/struk tidak berubah | `…a_tab_line_that_already_carries_the_group_is_not_rewritten` | tes lulus |
| FR-012 tautan item lama tetap bekerja | `…products_own_link…wins…`, `…without_the_key…`, 72 tes modifier lama | tes lulus |
| FR-013 daftar efektif sama di semua pembaca | `…removes_it…in_every_reader` (daftar POS, lookup, detail); audit pembaca di bagian T026 | tes lulus |

## Insiden: web dev tidak memuat (2026-10-30 ±21:54–22:10 WIB)

- Gejala (laporan pemilik): web tidak memuat.
- Fakta yang diperiksa: `localhost:8010/` 200; API sehat (401 JSON tanpa token; 500 yang saya lihat hanya dari `curl` tanpa header JSON, bukan dari SPA); halaman memuat aset dari Vite `:5180` (`public/hot`); Vite menjawab `@vite/client` tetapi banyak modul kecil menggantung 25–100+ dtk. Beban host 28; proses VM Docker 200–280% CPU.
- Penyebab yang terbukti: VM Docker jenuh. Dua penyumbang: (1) suite backend penuh saya sendiri (sudah dihentikan), (2) kontainer proyek LAIN `talenta-full-binary-db-1` ±109% CPU, 2 GB (tidak saya sentuh). AVG dan Edge juga memakai CPU host. Kode 086 tidak ditemukan bermasalah: modul baru (`InheritedModifierList.vue`, `modifierExclusion.js`, dst.) dikompilasi Vite dengan 200.
- Tindakan saya: menghentikan hanya proses `php artisan test` milik saya; me-restart HANYA kontainer `warkop_wjm-node-1` (Vite dev, tanpa data). Setelah itu modul kunci menjawab 200 (pemuatan dingin pertama lambat).
- Belum terbukti: bahwa halaman kini memuat penuh di peramban pemilik (tidak ada alat peramban di sesi ini).
