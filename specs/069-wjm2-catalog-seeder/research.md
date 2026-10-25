# Research: Pengisian katalog Odoo Warkop WJM 2

**Klasifikasi**: CONFIDENTIAL — merujuk katalog nyata milik pelanggan.

Semua keputusan di bawah berangkat dari 068 (`specs/068-odoo-catalog-seeder/research.md`, D1–D14). Fitur ini **memperluas** pipeline 068 (alat bangkit → `catalog.json` → `WjmCatalogSeedService`) agar dapat menangani outlet kedua. Tidak ada pipeline kedua. Fakta kode yang dikutip sudah diperiksa di repo pada 2026-10-25.

## D1 — Satu pipeline, dua katalog

**Decision**: Katalog WJM 2 menjadi berkas kedua, `database/seeders/data/wjm2-odoo/catalog.json` (+ `photos/`). Berkas ini dibangkitkan oleh `build_catalog.py` yang sama dari `specs/069-wjm2-catalog-seeder/catalog-mapping.md`, lalu diisi oleh `WjmCatalogSeedService` yang sama lewat `app:seed-wjm-catalog --catalog=…`. Ditambah seeder tipis `Wjm2CatalogSeeder`.

**Rationale**: Konstitusi I (satu implementasi per concern). Persetujuan, pratinjau, transaksi per mode, jalan ulang, foto, harga, nama varian, dan stok awal sudah ada dan teruji di 068.

**Alternatives**: menyalin service menjadi `Wjm2CatalogSeedService` (ditolak: duplikasi, dan dua jalur tulis akan menyimpang); importer Odoo generik dengan UI (ditolak: di luar kebutuhan, PRD F15.9).

## D2 — Alat bangkit menerima ekspor Odoo berbahasa Indonesia

**Decision**: `build_catalog.py` mengenali kedua bahasa antarmuka Odoo:
- **Berkas** dicari menurut akhiran model (`(pos.category).xlsx`, `(product.attribute).xlsx`, `(product.template).xlsx`), bukan nama lengkap.
- **Kolom** lewat tabel alias: `Display Name`/`Nama Tampilan`, `Attribute`/`Atribut`, `Variants Creation Mode`/`Mode Pembuatan Varian`, `Name`/`Nama`, `Internal Reference`/`Referensi Internal`, `Sales Price`/`Harga Jual`, `# Product Variants`/`# Varian Produk`, `Image`/`Gambar 1024`, dan `Product Attributes`/`Atribut produk`.
- **Pesan foto terpotong** diterima dalam kedua bahasa ("The content of this cell is too long" / "Konten pada kolom ini terlalu panjang").
- **Kolom yang tidak ada** (BoM, `Is Kits`, `Values`) dianggap kosong, bukan galat.

**Rationale**: Ekspor WJM 2 memakai label Indonesia (source-analysis §1–§3). Tabel alias kecil lebih sederhana dan lebih aman daripada pembaca kedua.

**Alternatives**: meminta pengguna mengekspor ulang dalam bahasa Inggris (ditolak: beban di pengguna, dan ekspor berikutnya akan sama).

## D3 — Nilai atribut dari tabel pemetaan bila ekspor tidak memuatnya

**Decision**: Bagian baru `## Nilai atribut` di tabel pemetaan (`| Atribut | Nilai |`, nilai dipisah koma) menyediakan nilai untuk atribut yang tidak punya nilai di sumber. Di `catalog.json` setiap atribut mendapat `values_from: "odoo" | "pemetaan"`. Pertanggungjawaban sumber (FR-018) hanya menghitung nilai `odoo`; nilai `pemetaan` dilaporkan terpisah. Penanda `?` di kolom "Varian usulan" (mis. `JASJUS: ?, ?, ?, ?`) berarti nama belum diisi pemilik. Selama status DRAF, alat bangkit memberi nama sementara (`(belum diisi 1)`, …) dan mencantumkannya per item di ringkasan, sehingga pratinjau tetap bisa dijalankan. Bila status `Disetujui`, setiap `?` adalah galat dan tidak ada berkas yang ditulis. Karena itu katalog tidak dapat disetujui sebelum semua nama terisi (spec FR-003).

**Rationale**: Klarifikasi 2026-10-25 Q2 (pemilik mengisi tabel). Asal-usul nilai tetap terlihat, sehingga laporan tidak mengklaim nilai itu berasal dari Odoo.

**Alternatives**: membaca `pos.order` (ditolak oleh pengguna; berisi data pelanggan); nama generik "Varian 1..n" (ditolak: tidak berguna bagi kasir).

## D4 — Kategori: pakai bersama menurut nama, dipetakan oleh pemilik

**Decision**: Judul bagian tabel pemetaan boleh mengganti nama kategori sumber: `### COFFEE → Coffee (Bar)`. Kategori yang namanya sudah ada di mode itu dipakai apa adanya. Nama, urutan, dan stasiunnya **tidak diubah** (sama dengan 068 FR-015). Usulan memetakan sembilan kategori setara ke nama WJM 3 (COFFEE → Coffee, NON-COFFEE → Non Coffee, …). Kategori yang benar-benar baru (MAKANAN, MIE, LAIN LAIN, SNACK RINGAN, bila masih dipakai) dibuat dengan stasiun WJM 2. Kategori sumber tanpa item ditulis `### NASI (Dapur) — tanpa item` dan masuk daftar dikecualikan dengan alasan "tanpa item di ekspor".

**Rationale**: Kategori berlaku per mode, bukan per outlet (tabel `categories` tidak punya `outlet_id`, dan `code` unik se-DB). Memakai bersama nama yang setara mencegah tab kembar "Coffee"/"COFFEE".

**Alternatives**: kategori khusus WJM 2 untuk semua (ditolak: 13 tab tambahan yang kembar nama di mode yang sama); mengubah kategori WJM 3 agar netral (ditolak: menyentuh WJM 3, FR-006).

## D5 — Tiket dapur: stasiun diset per item

**Decision**: Setiap item baru mendapat `products.station_id` = stasiun outlet tujuan sesuai peran Bar/Dapur kategorinya di tabel pemetaan. Ini juga berlaku untuk katalog WJM 3 pada pengisian berikutnya, untuk item baru saja.

**Rationale**: `KitchenService::stationFor()` mencoba stasiun produk lebih dulu, baru stasiun kategori, dan hanya stasiun aktif (`app/Services/KitchenService.php:155`). Kategori bersama menunjuk ke stasiun WJM 3. Dengan stasiun per item, tiket WJM 2 masuk ke stasiun WJM 2 tanpa mengubah kategori WJM 3 (FR-006, FR-008, SC-006).

**Alternatives**: kategori terpisah per outlet (lihat D4); mengubah aturan perutean dapur (ditolak: di luar cakupan dan berisiko untuk semua outlet).

## D6 — Stasiun: dikenali menurut peran, bukan nama tetap

**Decision**: Katalog membawa `stations: {"Bar": [nama…], "Dapur": [nama…]}` dari baris pemetaan `**Stasiun**: Bar = <nama>[, alias…]; Dapur = <nama>[, alias…]`. Nama pertama dipakai saat membuat. Di outlet tujuan, setiap peran dicocokkan ke stasiun pertama yang namanya ada di daftar itu. Bila outlet belum punya stasiun sama sekali, kedua stasiun dibuat. Bila outlet sudah punya stasiun tetapi sebuah peran tidak cocok, mode itu **ditolak** dengan pesan yang menyebut stasiun yang ada.

**Rationale**: Di DB dev, stasiun WJM 3 LIVE kini bernama "Dapur Minuman"/"Dapur Makanan" (id 12/13, dibuat ulang pengguna). Pencocokan nama tetap di 068 (`ensureStations`, `STATIONS = ['Bar','Dapur']`) akan membuat stasiun "Bar"/"Dapur" ganda saat jalan ulang. Menolak lebih aman daripada menebak.

**Alternatives**: cocokkan menurut urutan (ditolak: urutan juga berubah, 1/2 dan bukan 0/1); selalu buat (perilaku 068 sekarang, ditolak karena menggandakan stasiun).

## D7 — Identitas item dinilai per outlet

**Decision**: "Item sudah ada" = item **milik outlet tujuan** (`home_outlet_id`) dengan nama + kategori yang sama, termasuk yang dihapus lunak. Item outlet lain tidak pernah cocok.

**Rationale**: FR-007. Pencocokan 068 sekarang lintas outlet (`createItems`, `Product::withTrashed()…keyBy(name+category)`), sehingga "BASRENG" WJM 2 bisa dianggap sama dengan "Basreng" WJM 3 di kategori bersama (kolasi `utf8mb4_unicode_ci` tidak membedakan huruf besar-kecil). Untuk katalog WJM 3 tidak ada perubahan perilaku, karena semua itemnya milik outlet WJM 3.

**Alternatives**: menolak bila nama bentrok lintas outlet (ditolak: nama sama di dua outlet adalah hal wajar).

## D8 — Resolusi outlet tujuan dikendalikan katalog

**Decision**: Katalog membawa `outlet: {name, codes, create_when}`:
- `name`: nama outlet yang dicari (tanpa membedakan huruf besar-kecil).
- `codes`: kode yang diusulkan bila outlet harus dibuat, mis. `["WJ2", "WD2"]`, lalu `WJA…WJ9` seperti 068.
- `create_when`: `"mode_empty"` (068: buat hanya bila mode belum punya outlet; jatuh ke satu-satunya outlet aktif diizinkan) atau `"demo_missing"` (WJM 2: LIVE harus sudah ada, DEMO dibuat bila belum ada; **tanpa** jatuh ke satu-satunya outlet aktif).

`outlet_name` di 068 diganti `outlet.name`; katalog WJM 3 dibangkitkan ulang dengan `create_when: "mode_empty"` dan `codes: ["WJ3","WJM"]` (konstanta `OUTLET_CODES` pindah ke katalog).

**Rationale**: DEMO saat ini hanya punya satu outlet aktif, WJ3. Aturan 068 "satu-satunya outlet aktif dipakai" akan **mengisi katalog WJM 2 ke outlet WJM 3 DEMO**, pelanggaran langsung terhadap FR-006. FR-012 juga melarang pembuatan outlet di LIVE.

**Alternatives**: mewajibkan `--outlet` setiap kali (ditolak: mudah terlupa, dan `db:seed --class` tidak punya opsi itu).

## D9 — Menarik item lain dari menu outlet tujuan, di LIVE juga

**Decision**: Katalog membawa `withdraw_others: {"live": bool, "demo": bool}`. WJM 2: keduanya `true`. WJM 3: `live: false, demo: true` (perilaku 068 tetap). Penarikan memakai `OutletMenuService::setCarried` yang sudah dipakai `withdrawOtherItems`, hanya untuk outlet tujuan. Item yang ditarik tetap ada dan tetap dijual di outlet pemiliknya.

**Rationale**: Klarifikasi Q3 / FR-019. Penanda "dijual" untuk outlet WJM 2 adalah satu-satunya baris yang menyangkut item WJM 3 dan boleh ditulis.

**Alternatives**: menghapus baris penanda (ditolak: tanpa baris berarti "dijual", aturan bawaan `OutletMenuService::carriedOutletIds`).

## D10 — Daftar kategori POS hanya untuk outlet shift

**Decision** (disempurnakan saat implementasi, lihat contracts/pos-categories.md): `GET /pos/categories` menyembunyikan kategori yang punya item tetapi seluruhnya **tidak dijual di outlet pemanggil**; kategori yang sama sekali belum punya item tetap tampil (keputusan 055). Rumusan awal: hanya kategori yang punya ≥1 item aktif yang **dijual di outlet pemanggil**. Aturannya sama persis dengan `GET /products?pos=1` (`ProductController.php:71`): outlet dari `OutletContext::orderingId()` (outlet shift, atau satu-satunya outlet), lalu item yang `is_carried = false` di outlet itu tidak dihitung. Aturan "dijual di outlet ini" diangkat ke satu tempat yang dipakai kedua endpoint, bukan disalin. `product_count` dihitung dengan cara yang sama. Bila `orderingId()` null, perilakunya tetap seperti sekarang. `docs/openapi-pos-mvp.yaml` diperbarui di commit yang sama.

**Rationale**: Android menampilkan **semua** kategori dari server, termasuk yang kosong (`MenuMapper.toCatalog`). Tanpa ini, tablet WJM 3 akan mendapat tab kosong WJM 2 (mis. MIE), dan tablet WJM 2 tab kosong WJM 3 (Indomie, Nasi, Signature Coffee, …). Itu perubahan yang terasa di WJM 3 (US2) dan melanggar SC-008 di WJM 2.

**Alternatives**: menyaring di Android (ditolak: butuh rilis APK, dan logika outlet adalah urusan server); membiarkan tab kosong (ditolak: lihat di atas).

**Catatan cakupan**: ini satu-satunya perubahan di luar pengisian. Perlu persetujuan pengguna di tinjauan rencana.

## D11 — Stok awal 100

**Decision**: Pakai `initial_stock` dari 068 (catatan bertanggal 2026-10-25): baris `**Stok awal**: 100`, pelacakan stok menyala, satu gerakan `initial` per varian baru di outlet WJM 2 lewat `StockService::applyMovement(outletId: …)`. Jalan ulang tidak menambah stok.

**Rationale**: Permintaan pengguna. Mekanismenya sudah teruji (`test_an_initial_stock_tracks_every_new_variant_and_stocks_it_once_at_the_target_outlet`).

## D12 — Perapian nama dan nama item

**Decision**: Spasi berlebih di nama item dan atribut dirapikan (`"MARTABAK TELUR  KORNET MINI"` → satu spasi; `"Mentai "` → `"Mentai"`). Perapian dicatat di bagian "dirapikan" laporan alat bangkit. Nama tetap huruf kapital seperti sumber kecuali tabel pemetaan memakai `## Nama item khusus` (pola yang sama dengan `## Nama varian khusus`).

**Rationale**: Kecocokan jalan ulang memerlukan nama yang stabil. Pemilik mungkin ingin menyamakan gaya dengan WJM 3 tanpa harus menyunting sumber.

## D13 — Bukti "WJM 3 tidak tersentuh"

**Decision**: Dua lapis.
1. **Tes fitur**: isi katalog uji "outlet A", ambil potret semua baris milik A (produk, varian, harga, riwayat harga, stok per outlet, gerakan stok, penanda menu di outlet A, kategori, stasiun A, grup pilihan), lalu isi katalog uji "outlet B" di kedua mode. Potret A harus identik; hanya penanda "dijual di B" untuk item A yang boleh bertambah.
2. **DB dev**: skrip potret (hash per tabel atas baris milik WJM 3) sebelum dan sesudah pengisian, dicatat di `evidence.md`.

**Rationale**: SC-002 harus dibuktikan, bukan diasumsikan (Konstitusi II).

## D14 — Dokumentasi dan cakupan

**Decision**:
- `docs/RUNBOOK.md` §14 mendapat bagian WJM 2.
- `CLAUDE.md` mendapat catatan bertanggal tentang katalog kedua dan aturan per outlet (D5–D9).
- OpenAPI berubah hanya untuk D10.
- Tanpa migrasi: semua kolom yang dipakai (`products.station_id`, `outlet_item_settings`) sudah ada.
- Spec 068 mendapat catatan bertanggal untuk perubahan perilaku D6 (stasiun) dan D7 (identitas per outlet).
