# Data model: Pengisian katalog Odoo Warkop WJM 2

**Klasifikasi**: CONFIDENTIAL — merujuk katalog nyata milik pelanggan.

**Tanpa migrasi.** Fitur ini mengubah format berkas katalog (bersama 068) dan cara pengisian memakai tabel yang sudah ada. Rujukan keputusan: research D1–D14.

## 1. Tabel pemetaan (`catalog-mapping.md`), tambahan terhadap 068

| Unsur | Bentuk | Dipakai untuk |
|---|---|---|
| `**Outlet**: <nama> · **Kode usulan**: WJ2, WD2 · **Buat outlet**: DEMO bila belum ada` | satu baris | `outlet` di katalog (D8). Pilihan "Buat outlet": `mode kosong` (068) atau `DEMO bila belum ada` |
| `**Tarik item lain dari menu outlet**: LIVE ya, DEMO ya` | satu baris | `withdraw_others` (D9) |
| `**Stasiun**: Bar = <nama>[, alias…]; Dapur = <nama>[, alias…]` | satu baris, opsional; bawaan `Bar = Bar; Dapur = Dapur` | `stations` (D6) |
| `**Stok awal**: 100` | sudah ada (068) | `initial_stock` (D11) |
| `### <KATEGORI SUMBER> → <Nama tujuan> (Bar\|Dapur)` | judul bagian; `→ <Nama tujuan>` opsional | penggantian nama kategori (D4) |
| `### <KATEGORI SUMBER> (Dapur) — tanpa item` | judul bagian tanpa tabel | kategori dikecualikan (spec FR-002) |
| `## Nilai atribut` — `\| Atribut \| Nilai \|` (nilai dipisah koma) | bagian baru | nilai atribut yang tidak ada di ekspor (D3) |
| `## Nama item khusus` — `\| Item sumber \| Nama \|` | bagian baru | penggantian nama item (D12) |
| `?` di kolom "Varian usulan" (mis. `JASJUS: ?, ?, ?, ?`) | penanda | nama yang belum diisi pemilik: nama sementara saat DRAF, galat saat Disetujui (FR-003) |

Bagian `## Harga varian khusus`, `## Nama varian khusus`, `## Foto pengganti`, dan `## Grup pilihan` tetap seperti 068.

## 2. Berkas katalog (`catalog.json`), perubahan format

`format` naik dari `1` ke `2`. `WjmCatalog` hanya menerima `2`, dan katalog WJM 3 dibangkitkan ulang. Kunci akar (semuanya wajib):

| Kunci | Tipe | Aturan validasi |
|---|---|---|
| `format` | `2` | |
| `outlet` | `{name, codes, create_when}` | **menggantikan `outlet_name`**. `name` 1–100 karakter. `codes`: 1–5 kode `^[A-Z0-9]{3}$`. `create_when` ∈ `mode_empty`, `demo_missing` |
| `withdraw_others` | `{live: bool, demo: bool}` | |
| `stations` | `{Bar: [nama…], Dapur: [nama…]}` | tiap daftar 1–5 nama, tidak kosong; satu nama tidak boleh dipakai dua peran |
| `initial_stock` | bilangan bulat ≥ 0 atau `null` | sudah ada (068) |
| `source`, `approval`, `categories`, `option_groups`, `items` | | seperti 068 |
| `attributes[]` | + `values_from` ∈ `odoo`, `pemetaan` | pertanggungjawaban `source.rows.attribute_values` hanya menghitung nilai `odoo` |
| `excluded[]` | + `source: "categories"` | kategori sumber tanpa item, beserta alasannya |

Setiap `categories[]` membawa `source_name` (nama di Odoo) dan `name` (nama tujuan), sehingga pertanggungjawaban `source.rows.categories` = kategori yang dipakai + kategori yang dikecualikan.

Nilai untuk katalog WJM 3 yang dibangkitkan ulang: `outlet = {name: "Warkop WJM 3", codes: ["WJ3","WJM"], create_when: "mode_empty"}`, `withdraw_others = {live: false, demo: true}`, `stations = {Bar: ["Bar","Dapur Minuman"], Dapur: ["Dapur","Dapur Makanan"]}`. Isi lain tidak berubah; pembangkitan ulang dibandingkan dengan diff.

Usulan untuk katalog WJM 2: `outlet = {name: "Warkop WJM 2", codes: ["WJ2","WD2"], create_when: "demo_missing"}`, `withdraw_others = {live: true, demo: true}`, `stations = {Bar: ["Bar"], Dapur: ["Dapur"]}` (pemilik dapat menggantinya), `initial_stock = 100`.

## 3. Dampak ke basis data per mode yang diisi

| Tabel | Baris yang ditulis | Tidak pernah ditulis |
|---|---|---|
| `outlets` | DEMO: satu outlet "Warkop WJM 2" bila belum ada | LIVE: tidak ada; outlet WJM 3 |
| `stations` | stasiun peran Bar/Dapur milik outlet WJM 2 bila outlet itu belum punya stasiun | stasiun WJM 3 |
| `categories` | kategori tujuan yang belum ada, dengan `station_id` = stasiun WJM 2 | kategori yang sudah ada (dipakai apa adanya) |
| `products` | item baru: `home_outlet_id` = WJM 2, `station_id` = stasiun WJM 2 sesuai peran (D5) | item outlet lain |
| `product_variants` | varian baru: `track_stock` = `initial_stock !== null` | varian outlet lain |
| `price_history` | baris `created` lewat hook `ProductVariant::created` | |
| `outlet_item_settings` | item baru: dijual hanya di WJM 2 (`setCarried`). Item lain: `is_carried = false` **hanya untuk `outlet_id` = WJM 2** (D9) | baris untuk outlet WJM 3 |
| `variant_outlet_stock`, `stock_movements` | +`initial_stock` per varian baru di WJM 2 lewat `StockService` | saldo dan gerakan outlet lain |
| `modifier_groups`, `modifier_options`, `modifier_group_product` | grup katalog (nama pembeda bila bentrok) dan tautannya ke item baru | grup yang sudah ada |
| `activity_logs` | satu `catalog_seeded` per mode yang berhasil | |
| disk `public/products` | foto baru | foto item lain |

## 4. Aturan pengisian yang berubah (berlaku untuk kedua katalog)

| Aturan | 068 | Sesudah 069 |
|---|---|---|
| Outlet tujuan | nama katalog → satu-satunya outlet aktif → buat bila mode kosong | nama katalog → (`mode_empty`: satu-satunya outlet aktif, buat bila mode kosong) / (`demo_missing`: LIVE ditolak bila tidak ada; DEMO dibuat bila tidak ada) |
| Kode outlet baru | konstanta `WJ3, WJM`, lalu `WJA…WJ9` | `outlet.codes`, lalu `WJA…WJ9` |
| Stasiun | cari nama `Bar`/`Dapur`, buat bila tidak ada | cocokkan peran menurut daftar nama. Buat hanya bila outlet belum punya stasiun; selain itu peran yang tak cocok → mode ditolak |
| Item "sudah ada" | nama + kategori, lintas outlet | nama + kategori, **hanya milik outlet tujuan** |
| `products.station_id` | kosong (ikut kategori) | stasiun outlet tujuan sesuai peran |
| Penarikan item lain | DEMO saja | menurut `withdraw_others` |

## 5. Status persetujuan

Sama dengan 068: tabel pemetaan berstatus `DRAF` → `catalog.json` `approval.status = draft` → pratinjau boleh, pengisian sungguhan ditolak. Tambahan: `?` di nama varian diganti nama sementara selama DRAF (pratinjau tetap jalan), tetapi menjadi galat begitu status `Disetujui`. Dengan begitu katalog WJM 2 tidak pernah ada dalam keadaan "disetujui tetapi tidak lengkap".
