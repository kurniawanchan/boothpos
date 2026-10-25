# Research: Filter outlet Produk, formulir tambah setara halaman item, angka kepala mengikuti filter

**Klasifikasi**: INTERNAL

Semua temuan di bawah berasal dari pembacaan kode saat ini (fakta); keputusan adalah usulan rencana. Tidak ada NEEDS CLARIFICATION tersisa.

## D1 — Filter Outlet vs header `X-Outlet-Id` bilah atas

- **Fakta**: `client.js` menambahkan `X-Outlet-Id` bila pengguna memilih outlet di bilah atas; `OutletContext::effectiveIds()` = `[selected]` bila ada. `ProductCatalogQuery::base()` memotong `outlet_id[]` dengan `effectiveIds()`. Jadi dengan bilah atas = "Warkop WJM 2", filter hanya bisa memilih WJM 2 — tak berguna.
- **Decision**: pada dua panggilan halaman Produk (`GET /products?tab=…` dan `GET /products/summary`) header TIDAK dikirim (opsi per-permintaan di klien); halaman mengirim `outlet_id[]` eksplisit. Filter terisi dari outlet bilah atas saat halaman dibuka, dan mengikuti bila bilah atas berganti (`watch` pada `outletStore.selected`). Opsi filter = `outletStore.outlets` (sudah hanya outlet yang diizinkan).
- **Rationale**: Konstitusi IV tetap terpenuhi — server tetap memotong dengan `permittedIds()`; header hanyalah kemudahan penyempitan. Perubahan server nol untuk filter.
- **Alternatives**: (a) opsi filter hanya outlet bilah atas — tak berguna; (b) filter mengubah pilihan bilah atas (`outletStore.select`) — store hanya satu outlet, tak bisa multi, dan berefek ke seluruh aplikasi; (c) meniadakan pengikatan ke bilah atas — melanggar harapan pengguna yang sudah memilih outlet.

## D2 — Satu definisi "himpunan tersaring"

- **Fakta**: `base($f)` sudah memuat seluruh filter daftar, tetapi juga eager-load berat; `summary()` dan `counts()` tidak menerima filter.
- **Decision**: pisahkan `base()` menjadi (1) `filtered($f)` — hanya `where`/`whereExists` (tab, q, category, status, outlet, availability, quality) tanpa `with`, dan (2) `base()` = `filtered()` + eager-load. `summary($f)` dan `counts($f)` memakai `filtered()`.
- **Rationale**: angka kepala = baris di bawahnya dengan konstruksi, bukan kebetulan (SC-002). Satu implementasi, bukan salinan filter.
- **Alternatives**: menyalin klausa filter ke `summary()` — duplikasi yang pasti menyimpang.

## D3 — Filter mana yang dipakai angka kepala

- **Decision**: `summary` memakai `outlet_id`, `category_id`, `status`, `q`. **Tidak** memakai `quality` dan `availability`, yang justru dipasang oleh klik pada kartu itu sendiri (kalau dipakai, mengklik "86 hari ini" akan membuat kartu "Kualitas data" jadi 0 dan kartu itu sendiri tak bisa dilepas dengan benar). Tab: `summary` selalu menu (bukan ritel), seperti sekarang.
- **Rationale**: kartu adalah ringkasan himpunan tersaring oleh filter pengguna, bukan oleh dirinya sendiri.
- **Konsekuensi**: dengan filter Status = Draft, kartu "Menu item aktif" menunjukkan 0 "dari N item" — benar menurut himpunan, dicatat di quickstart.
- Banner rilis (`unreleased`) tetap global: itu soal rilis menu organisasi, bukan item tersaring.

## D4 — "86 hari ini" dan "Kualitas data" per outlet

- **Fakta**: tanda 86 (`item_availability_marks`) punya `outlet_id` (NULL = global, berlaku di semua outlet); `availabilityFor()` sudah menerapkan aturan itu per outlet.
- **Decision**: hitungan 86 = produk dalam himpunan tersaring yang punya tanda aktif dengan `outlet_id` NULL atau termasuk outlet terpilih (tanpa filter outlet: semua tanda). Kualitas data = `qualityQuery()` dibatasi ke produk yang sama (`whereIn products.id` subkueri himpunan tersaring); kualitas sendiri tidak per outlet (stasiun, harga dasar, biaya adalah sifat item).
- **Hitungan tab (`counts`)**: hanya mengikuti `outlet_id` (tab menunjukkan total per jenis); bahan baku/modifier tidak berubah.

## D5 — Harga per outlet saat membuat item

- **Fakta**: harga outlet kini hanya lewat `PUT /variants/{id}/outlet-prices/{outlet}` (`PriceService::setOutletPrice`), yang saat rilis AKTIF mengantre `menu_change`. Harga dasar saat membuat ditulis langsung (`source 'created'` dikenali sebagai langsung di `setBasePrice`), dan item baru berstatus draft saat rilis aktif.
- **Decision**: `POST /products` menerima `variants.*.outlet_prices` = daftar `{outlet_id, price}`; di dalam transaksi `store()` yang sudah ada, setelah varian dibuat, tiap baris ditulis dengan `PriceService::writeOutletPrice(..., source: 'created')` (langsung, sejajar dengan harga dasar saat dibuat). Item draft tidak terjual sampai terbit, jadi hasil amati sama dengan skenario 3 spec.
- **Rationale**: atomik (gagal → seluruh item batal, FR-009 dan edge case), satu jalur tulis harga, riwayat dan audit tertulis oleh `PriceService`. Memanggil PUT berurutan dari klien akan meninggalkan item setengah jadi bila satu panggilan gagal.
- **Alternatives**: PUT berurutan dari klien (tidak atomik; drawer sudah bertahap untuk gambar/stok, tetapi harga memengaruhi uang); memakai `setOutletPrice` (mengantre `menu_change` untuk item yang belum terbit — perubahan menunggu yang tak perlu).
- **Verifikasi saat implementasi**: pastikan `writeOutletPrice` menerima source `'created'` (kolom `price_histories.source` bebas/enum) dan ujilah dengan rilis aktif DAN mati.

## D6 — Tabel varian mode buat

- **Fakta**: `ProductVariantsTable` sudah dipakai kedua mode; kolom per-outlet hanya ada di `VariantsSection` (halaman item), bukan di tabel drawer.
- **Decision**: tambah kolom harga per outlet ke `ProductVariantsTable` bila `outletStore.multi`: sel kosong = mengikuti harga dasar; hanya outlet yang dicentang di bagian Ketersediaan yang punya sel aktif. Baris `row.outlet_prices` = peta `{outlet_id: harga}`; hanya nilai terisi dikirim. Status aktif per varian, nama, harga dasar, stok awal sudah ada — "setara" tercapai tanpa menyalin laci "Kelola varian".
- **Alternatives**: menyisipkan `VariantsSection` ke drawer — butuh item tersimpan (id varian), tak mungkin saat buat.

## D7 — Ketersediaan di formulir tambah

- **Fakta**: drawer sudah punya fieldset "Outlet aktif" (`outlet_ids`, multi saja) dan blok Status (`isCreate`); server menerima `outlet_ids` tetapi tidak menolak daftar kosong.
- **Decision**: gabungkan keduanya menjadi bagian Ketersediaan: satu baris per outlet (kotak centang + chip "Ada"/"Tidak dijual", memakai komponen `AvailabilityChips`), lalu pilihan status. Kuota/channel "—" seperti halaman item; penandaan 86 diganti keterangan. `StoreProductRequest`: `outlet_ids` yang ada tetapi kosong → 422 (`min:1`); tidak ada `outlet_ids` = perilaku lama (semua outlet). Untuk satu outlet: hanya status.
- **Risiko**: tes lama yang mengirim `outlet_ids: []` saat membuat — dicari dan disesuaikan di tahap tugas.

## D8 — Muat ulang angka kepala

- **Decision**: `loadSummary(params)` dipanggil dari `watch` yang sama dengan `loadList` untuk filter outlet/kategori/status/q (bukan quality/availability/tab/sort/halaman), dengan penjaga urutan respons (seperti `requestSeq` yang sudah ada) dan debounce pencarian yang sudah ada. Kartu menampilkan keadaan memuat dan tidak menahan angka lama tanpa tanda (FR-006).
- **Rationale**: dua panggilan paralel per perubahan filter (daftar + kepala), bukan satu panggilan berat.
