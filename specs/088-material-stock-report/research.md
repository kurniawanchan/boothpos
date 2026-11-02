# Research: Laporan Bahan dan Statistik Stok di Dashboard (088)

**Klasifikasi**: INTERNAL · **Tanggal**: 2026-11-01

Semua keputusan di bawah berdasar kode yang dibaca di cabang `088-material-stock-report` (di atas 087 yang sudah digabung, PR #75). Tidak ada [NEEDS CLARIFICATION] tersisa: dua keputusan pemilik (ambang otomatis; draf PO per vendor) sudah ada di spec.

## D1. Satu sumber angka: buku besar bahan, tanpa tabel baru

- **Decision**: Semua angka laporan dan Dashboard diturunkan saat diminta dari `material_outlet_stock` (saldo) dan `material_stock_movements` (pergerakan), lewat SATU service baru `MaterialReport`. Tidak ada tabel ringkasan/pra-hitung, tidak ada migrasi.
- **Rationale**: FR-012/FR-015 menuntut laporan = buku besar = Dashboard. Satu service yang dipakai laporan, ekspor, dan Dashboard menghapus kemungkinan dua rumus berbeda (pelajaran `SalesFigures` 042). Volume kecil (ratusan bahan, beberapa outlet) sehingga agregasi `GROUP BY material_id, outlet_id, type` cukup cepat.
- **Alternatives considered**: tabel snapshot harian (cepat, tetapi butuh penjadwal yang tidak ada di instalasi lokal dan bisa basi); menghitung di klien (melanggar Konstitusi I/IV).

## D2. Laju pemakaian harian

- **Decision**: `laju = pemakaian_bersih / hari_jual`, dengan `pemakaian_bersih = −Σqty(sale) − Σqty(return) − Σqty(waste)` (sale/waste negatif, return positif) di jendela acuan (bawaan 7 hari tanggal bisnis terakhir, termasuk hari ini), dan `hari_jual` = jumlah `orders.business_date` berbeda dengan pesanan `completed` di outlet itu dalam jendela yang sama. `hari_jual = 0` → laju tidak diketahui.
- **Rationale**: Spec (Edge Cases) meminta hari tutup tidak menurunkan laju. `orders.business_date` sudah ada (042) dan sesuai zona/jam tutup buku toko; batas waktu pergerakan memakai `BusinessDay::bounds()` seperti `GET /materials/{id}/movements`. Waste ikut karena memang menghabiskan stok.
- **Alternatives considered**: hari kalender (menurunkan laju di outlet yang libur); rata-rata tertimbang/eksponensial (lebih rumit, belum diminta).

## D3. Status dan hari tersisa

- **Decision**: per bahan per outlet: `saldo < 0` → `negative`; `saldo = 0` → `out`; laju tidak diketahui atau 0 → `unused` (bukan berisiko selama saldo > 0); `hari_tersisa = saldo / laju`; `hari_tersisa < N` (bawaan 3) → `low`; selain itu `ok`. Bahan yang hanya dipakai resep/BOM: `unused` dengan penanda `usage_untracked = true` bila bahan itu ada di baris resep aktif atau BOM tetapi tidak di pemetaan modifier mana pun.
- **Rationale**: FR-003 (keputusan pemilik: otomatis). Penanda "pemakaian tidak tercatat" memenuhi Edge Case resep/BOM tanpa mengklaim bahan itu tidak terpakai.
- **Alternatives considered**: batas minimum manual (ditolak pemilik, Q1).

## D4. Menu dan pilihan yang terancam

- **Decision**: dari `modifier_option_materials` (pilihan aktif) → grup → produk yang grup EFEKTIF-nya memuat grup itu. Tambah satu pembaca balik di `ModifierResolver` (`productsUsingGroups(groupIds)`: tautan produk ∪ produk kategori yang tertaut − pengecualian 086, tautan produk menang) supaya aturan pewarisan tetap di satu tempat (CLAUDE.md 086: `inheritedGroups()` satu-satunya pembaca grup kategori). Porsi yang masih bisa dibuat per pilihan = rumus 087 (`floor(saldo / qty)`, minus = 0).
- **Rationale**: FR-004; memakai aturan dan rumus yang sudah teruji supaya angka porsi di laporan sama dengan popup POS.
- **Alternatives considered**: hanya menampilkan pilihan tanpa produk (kurang berguna bagi pemilik); query mentah terpisah (menduplikasi aturan pewarisan).

## D5. Nilai rupiah

- **Decision**: biaya per satuan = `RecipeService::unitCost(Material)` (harga vendor pilihan; bahan setengah jadi → biaya standar). Nilai = qty × biaya satuan saat ini. Bahan tanpa biaya → nilai `null`, ditandai "harga belum ada".
- **Rationale**: FR-019 — sama dengan biaya resep dan biaya bahan 087 di `cost_price`.
- **Alternatives considered**: harga rata-rata tertimbang dari PO (lebih akurat secara akuntansi, tetapi tidak dipakai di tempat lain → angka berbeda dari biaya menu).

## D6. Visibilitas biaya

- **Decision**: laporan Bahan hanya untuk menu `reports` (pemegangnya selalu melihat rupiah, sesuai aturan CLAUDE.md "cost and margin fields are added only for users with the `reports` menu"). Dashboard: blok rupiah hanya bila `canAccessMenu('reports')`; hitungan selalu tampil bagi pemegang `dashboard`.
- **Rationale**: FR-016 memakai aturan biaya yang sudah ada; tidak menambah izin baru.
- **Alternatives considered**: izin baru "lihat biaya bahan" (permintaan tidak memintanya).

## D7. Dashboard: endpoint terpisah, cache 30 dtk

- **Decision**: `GET /dashboard/materials?period=today|7d|30d`, `MaterialReport` dengan ambang bawaan (jendela 7, N=3). Cache 30 detik dengan kunci = mode data + `OutletFilter::cacheKey()` + periode + tanggal bisnis + zona + jam tutup (pola `DashboardService::summary`), dan menyertakan `computed_at`. Rupiah dibuang SESUDAH dibaca dari cache untuk pengguna tanpa `reports` (kunci cache tidak bergantung pengguna).
- **Rationale**: Konstitusi V (kunci cache memuat semua masukan, waktu hitung tertera); endpoint terpisah tidak mengubah bentuk `summary` yang dipakai banyak tes dan klien.
- **Alternatives considered**: menambah ke `summary` (kunci cache dan bentuknya berubah untuk semua).

## D8. Saran pembelian dan draf PO

- **Decision**: `saran = max(0, target × laju − saldo)` (saldo minus menambah saran), dibulatkan KE ATAS ke 3 desimal (bukan ke bilangan bulat, karena bahan boleh pecahan, mis. gram). Draf PO: `POST /reports/materials/purchase-drafts {lines[{material_id, qty}]}`. Outlet = outlet TUNGGAL yang berlaku (`OutletContext::singleId()`); tanpa outlet tunggal → 422 "pilih satu outlet". Baris dikelompokkan per vendor pilihan (`vendor_material_prices.is_preferred`), harga satuan = harga vendor itu; tiap kelompok dibuat lewat `PurchaseOrderService::create` (status draf; log aktivitas sudah di service). Baris tanpa vendor pilihan → dikembalikan di `refused[]` dengan alasan, tidak menggagalkan kelompok lain. Gerbang: `can('create', PurchaseOrder::class)` + menu `reports`.
- **Rationale**: Keputusan pemilik Q2 = B. Outlet dari server (Konstitusi IV, aturan 045), bukan dari body. Memakai service PO yang ada = satu jalur tulis PO.
- **Alternatives considered**: satu PO untuk semua vendor (PO per vendor adalah model yang ada); menulis PO langsung (melewati service).

## D9. Ekspor

- **Decision**: `GET /reports/materials/export` → satu workbook tiga sheet (Posisi & risiko, Saran pembelian, Pemakaian periode) memakai `SafeStringExport` (teks literal, bukan formula; 078) dan baris dari `MaterialReport` yang sama.
- **Rationale**: FR-018; SafeStringExport adalah pola ekspor laporan sejak 078.
- **Alternatives considered**: tiga endpoint ekspor terpisah (lebih banyak permukaan).

## D10. Preferensi per pengguna

- **Decision**: jendela acuan, N hari, dan target hari disimpan di `localStorage` per pengguna (dibungkus try/catch), dikirim sebagai parameter. Dashboard selalu memakai bawaan supaya angkanya stabil; klik dari Dashboard membuka laporan dengan bawaan itu.
- **Rationale**: preferensi tampilan per penonton, bukan data bisnis; tidak perlu tabel setelan.
- **Alternatives considered**: kolom setelan per pengguna di DB (berlebihan).

## D11. Pindah stok dan koreksi dalam laporan periode

- **Decision**: FR-009 menampilkan per jenis: penjualan bersih (sale + return), terbuang, koreksi + dan koreksi − terpisah (selisih opname), pembelian diterima, pindah masuk/keluar/batal. Hanya penjualan bersih dan terbuang yang masuk laju (D2).
- **Rationale**: Koreksi dan pindah bukan konsumsi; memasukkannya ke laju akan memalsukan perkiraan hari tersisa.
