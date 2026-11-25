INTERNAL

# Paritas Formulir Android vs Web (094 / T071)

**Cara periksa (2026-11-25):** perbandingan TINGKAT KODE antara komponen web (dikutip dengan berkas:baris oleh agen pembaca) dan kode Android (`android-pos/manager/`). Aturan klien Android dikunci tes unit (`:manager:testDebugUnitTest`, 58 lulus). **Belum** dibandingkan di perangkat/peramban: kolom "Perangkat" kosong sampai T072.

Legenda: ✅ sama · ≈ sama hasilnya, cara berbeda (alasan di catatan) · ✗ berbeda/belum ada.

## Kategori (`CategoriesView.vue` + `Store/UpdateCategoryRequest`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| code 2 huruf, alpha, unik | input + `toUpperCase()` saat kirim | hanya huruf, maks 2, huruf besar saat mengetik (`CategoryForm.cleanCode`) | ✅ | |
| code hanya saat buat, terkunci saat ubah | ✅ | ✅ (`toBody` tanpa `code` saat ubah) | ✅ | |
| name ≤ 100 wajib | ✅ | ✅ | ✅ | |
| induk tanpa diri sendiri; siklus ditolak server | ✅ | ✅ | ✅ | |
| display_order kosong → 0 | `Number(x) \|\| 0` | `toIntOrNull() ?: 0` | ✅ | |
| station dari stasiun aktif | ✅ | ✅ | ✅ | |
| foto jpeg/png ≤ 5 MB setelah simpan; gagal foto tidak membatalkan | ✅ (web cek `image/*`) | ✅ (cek jpeg/png = aturan server) | ≈ lebih ketat, sama dengan server | |
| nonaktifkan + 409 ditampilkan | toast global | pesan di formulir (`apiUnit`) | ✅ | |

## Produk (`ProductFormDrawer.vue` + `Store/UpdateProductRequest`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| name ≤ 150, home_outlet, category wajib | ✅ | ✅ (`ProductForm.localErrors`) | ✅ | |
| outlet_ids ≥ 1 saat buat; dikirim hanya bila multi | ✅ | ✅ | ✅ | |
| segmen 3 huruf, hanya saat buat | ✅ | ✅ | ✅ | |
| short_name ≤ 24 (trim, kosong → null) | ✅ | ✅ | ✅ | |
| tax_category 3 nilai tetap | ✅ | ✅ | ✅ | |
| prep_minutes 0–600, kosong → null | ✅ | ✅ (batas atas ditegakkan server) | ≈ | |
| stock_source variant/modifier | ✅ | ✅ | ✅ | |
| ingredient_unit hanya bila use_as_ingredient, ≤ 20 | ✅ | ✅ | ✅ | |
| Draft/Aktif hanya saat ubah | ✅ (dan hanya bila rilis menu mati) | ✅ (selalu tampil saat ubah) | ≈ server menentukan bila rilis aktif | |
| varian: nama kosong → "Standard"; wajib bila > 1 varian | ✅ | ✅ | ✅ | |
| harga `toMoneyString` ("x.00") | ✅ | ✅ (`ProductForm.money`) | ✅ | |
| cost_price dikirim saat ubah hanya bila boleh melihat biaya; terkunci bila BOM lengkap | ✅ | ✅ | ✅ | |
| alasan stok wajib hanya saat ubah + stok berubah; dilewati bila sumber modifier | ✅ | ✅ | ✅ | |
| stok awal saat buat beralasan "Stok awal item baru" | ✅ | ✅ | ✅ | |
| urutan simpan produk → varian → foto → foto varian → stok | ✅ | ✅ | ✅ | |
| gagal setelah produk tercipta | toast, laci tertutup, tanpa ulang | isian tetap; Simpan ulang hanya langkah gagal/berubah | ≈ disengaja (T066) | |
| varian dinonaktifkan saat buat | `PUT /products/{id}/variants` sekaligus | `PUT /variants/{id}` per baris | ≈ | |
| tautan modifier di formulir buat | ✅ | ✗ — ditautkan dari detail setelah tersimpan | ✗ | |
| `outlet_prices` di POST produk | tidak dikirim web | tidak dikirim | ✅ | |

## Harga outlet (`VariantOutletPriceController`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| kosong = ikut harga dasar (`price: null`) | ✅ | ✅ | ✅ | |
| catatan ≤ 255 | web tidak mengirim `note` | dikirim bila diisi | ≈ server menerima | |
| hasil pending/persetujuan dilaporkan | ✅ | ✅ | ✅ | |

## Tautan modifier (`ModifierLinksDrawer.vue` + `ModifierLinkService`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| bawaan tambah grup (`rowFromGroup`) | ✅ | ✅ | ✅ | |
| Wajib → min naik ke 1 | ✅ | ✅ | ✅ | |
| min ≥ 0 bulat; max kosong atau ≥ 1 dan ≥ min | ✅ | ✅ | ✅ | |
| pilihan tunggal (C1) di kolom max | ✅ | ✅ | ✅ | |
| ≤ 30 tautan | server saja | klien + server | ≈ | |
| display_order mulai 1; max kosong → null | ✅ | ✅ | ✅ | |
| `excluded_group_ids` hanya bila berubah; `[]` = pulihkan semua | ✅ | ✅ | ✅ | |
| lepas/pulihkan grup warisan | ✅ | ✅ | ✅ | |
| `applied:false` → "menunggu rilis menu" | ✅ | ✅ | ✅ | |
| label tombol "Ajukan perubahan" saat rilis aktif | ✅ | ✗ selalu "Simpan modifier" | ✗ kecil | |

## Resep (`RecipeDrawer.vue` + `RecipeService::validate`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| nama ≤ 100 diisi nama produk | ✅ | ✅ | ✅ | |
| porsi > 0, bawaan 1 | ✅ | ✅ | ✅ | |
| berlaku ≤ 30 hari ke belakang; tanggal lama tak diubah dibebaskan | ✅ | ✅ | ✅ | |
| catatan ≤ 500 | server saja | klien + server | ≈ | |
| tabel dasar per varian aktif tanpa BOM lengkap | ✅ | ✅ | ✅ | |
| (varian, pilihan) unik; ≥ 1 tabel; ≥ 1 bahan per tabel | ✅ | ✅ | ✅ | |
| susut 0–99,99 (bahan); produk-bahan susut 0 dan bulat per porsi | ✅ | ✅ | ✅ | |
| tulis hanya dengan `menu.recipe.edit`, tombol disembunyikan | ✅ | ✅ | ✅ | |
| pemilih bahan dikelompokkan per kind | ✅ | ✗ cari bahan/produk tanpa kelompok kind | ✗ kecil | |
| pilih tanggal dengan kalender | ✅ | ✗ ketik YYYY-MM-DD | ✗ kecil | |
| biaya porsi/HPP dari server | ✅ | daftar resep saja | ≈ | |

## Vendor (`VendorsView.vue` + `Store/UpdateVendorRequest`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| code alpha_dash ≤ 20, huruf besar, permanen | `toUpperCase()` saat kirim | disaring + huruf besar saat mengetik; tidak dikirim saat ubah | ≈ server mengabaikan `code` saat ubah | |
| name ≤ 150, telepon ≤ 30, surel valid ≤ 100 | ✅ | ✅ | ✅ | |
| hapus 409 bila masih punya harga bahan | toast | pesan di formulir | ✅ | |

## Bahan (`MaterialsTable.vue` + `Store/UpdateMaterialRequest`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| satuan dari 16 preset + "Lainnya…" (≤ 20) | ✅ | ✅ (`MaterialUnits`) | ✅ | |
| kind 3 nilai; `standard_cost` hanya untuk setengah jadi | ✅ | ✅ | ✅ | |
| saldo = outlet terpilih atau 0, tidak pernah total | ✅ | ✅ (`MaterialBalance`) | ✅ | |
| koreksi: selisih ≠ 0 dalam ±1e9, alasan ≤ 255 | ±1e9 hanya server | klien + server | ≈ | |
| koreksi: outlet | dipilih bila multi | selalu outlet terpilih | ≈ | |
| harga vendor: vendor hanya saat buat, harga 0..999999999999,99, preferred | ✅ | ✅ | ✅ | |
| saldo di panel detail | baris daftar | `GET /materials/{id}` tidak memuat `outlet_stock`, jadi saldo tidak tampil di panel detail | ✗ kecil | |

## PO (`PurchaseOrdersView.vue` + `Store/UpdatePurchaseOrderRequest`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| vendor wajib; outlet dari body | outlet dipilih di formulir | outlet = outlet terpilih (T082) | ≈ | |
| baris: bahan wajib (material), deskripsi ≤ 255 (jasa), qty ≥ 0,001, harga ≥ 0 | ✅ | ✅ | ✅ | |
| baris terkunci setelah draft (`items` tidak dikirim) | ✅ | ✅ | ✅ | |
| transisi menurut graf model; 409 ditampilkan | ✅ | ✅ + muat ulang | ✅ | |
| alasan batal opsional ≤ 255 | `window.prompt` | dialog | ✅ | |
| pembayaran hanya received/paid | ✅ | ✅ | ✅ | |
| metode bayar Transfer/QRIS | ditawarkan tetapi selalu 422 | hanya Tunai | ≈ disengaja (lihat evidence) | |
| cetak faktur PDF | ✅ | ✗ (D12 — ditunda) | ✗ | |

## Meja / Stasiun / Grup modifier / Attach (`TablesAdmin`, `StationsAdmin`, `ModifierGroupsAdmin`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| daftar tanpa X-Outlet-Id + `outlet_id[]` | ✅ | ✅ (`@NoOutletHeader`), outlet terpilih saja | ≈ | |
| meja: nama ≤ 60 dipangkas, area ≤ 60, kursi 1–99 (bawaan 4) | ✅ | ✅ | ✅ | |
| `outlet_id` dikirim hanya bila berubah; 409 `OutletMoveGuard` ditampilkan | ✅ | ✅ + peringatan "memindahkan" | ✅ | |
| QR: gambar dibuat klien dari `url`; buat ulang dengan konfirmasi | ✅ gambar + cetak + PNG | alamat + Bagikan; buat ulang dengan konfirmasi; tanpa gambar | ✗ (tanpa pustaka QR) | |
| QR massal | ✅ | ✗ (⏸ research) | ✗ | |
| stasiun: nama ≤ 60, urutan bawaan = jumlah baris | ✅ | ✅ | ✅ | |
| grup: single → tanpa min/max; multiple → `max_select` null eksplisit bila kosong | ✅ | ✅ (`toJson`) | ✅ | |
| opsi: min 1..50, maks kosong/1..50, maks ≥ min; opsi yang dibuang dinonaktifkan server | ✅ | ✅ | ✅ | |
| `materials` per opsi dikirim hanya bila berubah (4 desimal); bahan kembar ditolak per opsi | ✅ | ✅ | ✅ | |
| Attach: penggantian penuh, dua daftar selalu dikirim; kosong = "Tidak ada"; ringkasan ≥ 2 | ✅ | ✅ | ✅ | |

## Dapur & Bar (`KitchenView.vue`)

| Aturan | Web | Android | Status | Perangkat |
|---|---|---|---|---|
| polling `?version=`, `unchanged` mempertahankan tiket | 3 dtk + jitter | 5 dtk, hanya saat layar tampil (T088) | ≈ | |
| maju satu langkah; new→preparing→ready `kitchen`, served `kitchen`/`floor` | ✅ | ✅ | ✅ | |
| terlambat ≥ 15 menit + ada item baru/dimasak | ✅ | ✅ | ✅ | |
| catatan order | tidak ditampilkan | ditampilkan (T088) | ≈ disengaja | |
| cetak tiket | ✅ | ✗ | ✗ | |

## Koreksi stok & Transfer — lihat `evidence.md` (T060–T061); aturan dikunci `StockFormsTest`.

## Perbedaan yang perlu keputusan
1. Tautan modifier tidak bisa dipasang di formulir BUAT produk (harus dari detail setelah tersimpan).
2. Label tombol tautan saat rilis menu aktif, kalender tanggal resep, pengelompokan bahan — kosmetik, belum dikerjakan.
