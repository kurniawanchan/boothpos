# Research: 086 — Item melepas grup modifier warisan kategori

**Klasifikasi**: INTERNAL · **Tanggal**: 2026-10-30

Semua temuan berasal dari pembacaan kode di sesi ini. Tidak ada kode yang dijalankan di tahap rencana.

## Temuan (fakta)

| # | Temuan | Bukti |
|---|--------|-------|
| F1 | Grup efektif item = grup tautan item ∪ grup kategori, tanpa jalan keluar. `unique('id')` hanya memilih tautan item bila grup sama. | `ModifierResolver::effectiveGroups` (baris 25–55) |
| F2 | Ada TIGA pembaca grup kategori, bukan satu: (a) `ModifierResolver::effectiveGroups` (dipakai daftar produk POS `with_modifiers`, `GET /variants/lookup`, dan validasi pesanan lewat `resolve()`), (b) `ProductDetailService::links()` (halaman Produk), (c) `ProductCatalogQuery` baris 297 (nama grup di daftar halaman Produk, `merge()` + `unique('id')`). | grep `category.modifierGroups` |
| F3 | `resolve()` memvalidasi opsi pesanan terhadap `effectiveGroups`. Jadi bila grup dikecualikan di sana, opsi grup itu ditolak untuk item itu secara otomatis. | `ModifierResolver::resolve` baris 90–99 |
| F4 | Kedua permukaan UI (laci `ModifierLinksDrawer.vue` dan formulir `ProductFormDrawer.vue`) menyimpan lewat endpoint SAMA `PUT /products/{id}/modifier-links` (`saveModifierLinks`). Keduanya sudah menampilkan baris `source: 'category'` hanya-baca dan sengaja tidak mengirimnya. | `api/products.js:105`, kedua komponen |
| F5 | `ModifierLinkService::replace` menggantikan SELURUH tautan lewat `sync()`; dengan rilis aktif diantrekan sebagai `menu_change` (`modifier_link`) dan diterapkan `ModifierLinkApplier` lewat `applyLinks()`. | `ModifierLinkService`, `ModifierLinkApplier` |
| F6 | Tabel `modifier_group_product` adalah tautan item (kunci komposit, kini membawa kolom aturan). Banyak pembaca memakai `Product::modifierGroups()` sebagai "tautan item", termasuk penjaga hapus grup dan `setAttachments`. | migrasi `…_000004`, `…_000007`, `Product.php:135` |
| F7 | Pengambilan produk dengan `with_modifiers` memuat relasi lewat eager-load di `ProductController` baris 79 dan 529. | grep |
| F8 | `docs/openapi-pos-mvp.yaml` mendokumentasikan `PUT /products/{id}/modifier-links` (baris 3967) dan harus ikut berubah bila bentuk permintaan/respons berubah (Konstitusi). | grep |

## Keputusan

- **D1 — Tabel baru `modifier_group_product_exclusions`** (`product_id`, `modifier_group_id`, kunci komposit, `ON DELETE CASCADE` kedua sisi), bukan kolom `is_excluded` di `modifier_group_product`. *Alasan*: F6 — menambah baris "dikecualikan" ke tabel tautan akan membuat semua pembaca `Product::modifierGroups()` menganggapnya tautan sungguhan (penjaga hapus, lampiran, daftar), dan itu cara termudah memperkenalkan galat diam-diam. Tabel terpisah hanya dibaca oleh satu helper. *Alternatif ditolak*: kolom di pivot; kolom JSON di `products`.
- **D2 — Satu helper "grup warisan"**: `ModifierResolver::inheritedGroups(Product): Collection` = grup kategori dikurangi grup yang dikecualikan item itu. Dipanggil oleh ketiga pembaca di F2. Tautan item sendiri TIDAK terpengaruh pengecualian (tautan item menang, spec Edge Case). *Alasan*: Konstitusi I (satu implementasi per konsep) dan F2 (tiga pembaca).
- **D3 — Pengecualian diterapkan di server, bukan di klien.** Klien (POS web, Android) membaca grup efektif dari server (`present()`), jadi tidak ada perubahan Android. Validasi pesanan (F3) otomatis menolak opsi grup yang dikecualikan.
- **D4 — Perluas endpoint yang ada, tanpa rute baru**: `PUT /products/{id}/modifier-links` menerima `excluded_group_ids` OPSIONAL. Tidak ada kunci = pengecualian tidak berubah (klien lama dan tes lama tetap benar); ada kunci (termasuk `[]`) = penggantian penuh. *Alasan*: F5 — satu transaksi, satu jalur rilis, satu catatan audit; dan klien lama yang tak mengenal kunci ini tidak menghapus pengecualian diam-diam.
- **D5 — Rilis menu**: payload `menu_change` membawa `excluded_group_ids` hanya bila dikirim; `ModifierLinkApplier` meneruskannya ke `applyLinks()` yang sama.
- **D6 — Respons halaman Produk**: `modifier_links` mewakili grup EFEKTIF (baris kategori yang dikecualikan tidak ada di sana). Kunci baru `modifier_excluded` memuat `{group_id, name, options}` untuk grup yang masih diwarisi kategori tetapi dikecualikan item ini, agar UI bisa memulihkannya. Pengecualian yang grupnya sudah tidak ada di kategori tidak ditampilkan tetapi tetap tersimpan (spec Edge Case; tidak ada galat).
- **D7 — UI**: satu komponen bersama `InheritedModifierList.vue` untuk laci dan formulir (FR-006: perilaku sama). Menampilkan baris warisan dengan tombol "Lepas", dan bagian "Dilepas untuk item ini" dengan "Pulihkan". Keadaan pengecualian ikut dalam deteksi perubahan formulir dan dikirim bersama tautan. Tombol hanya ada bila pengguna boleh mengubah produk (`ProductPolicy::update`, sama dengan endpoint); peran lain tidak melihatnya (Konstitusi III).
- **D8 — Audit**: log `modifier_links_updated` yang ada diperluas dengan nilai lama/baru pengecualian bila dikirim, di transaksi yang sama. Tanpa isi sensitif.
- **D9 — Eager load (Konstitusi V)**: tambahkan relasi `excludedModifierGroups:id` ke eager-load di `ProductController` (baris 79, 529) dan `ProductCatalogQuery` (baris 153) supaya tidak ada N+1.
- **D10 — Impor Excel master data**: tidak diubah. Tidak ditemukan kolom modifier di impor yang perlu pengecualian (diperiksa di tahap tugas).
- **D11 — Baris kosong "Select…"**: perilaku tombol silang pada tabel Modifier tidak diubah (di luar cakupan).

## Risiko dan yang belum diverifikasi

- Tampilan peramban dan tablet belum dicoba; mengikuti pola 070/085, bukti dicatat di `evidence.md`.
- Belum diperiksa apakah ada pembaca keempat grup kategori di kode Android atau di ekspor (pencarian server saja). Diperiksa lagi di tahap tugas.
- Penafsiran permintaan (fitur, bukan galat) adalah ASSUMPTION di spec; bila salah, rencana ini perlu diganti.
