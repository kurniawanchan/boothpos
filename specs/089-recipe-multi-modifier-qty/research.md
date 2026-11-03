# Research: Resep Memotong Stok, Resep Ganda, Jumlah Pilihan Modifier

**Klasifikasi**: INTERNAL

Semua temuan di bawah berasal dari pembacaan kode saat ini (fakta) kecuali yang ditandai ASSUMPTION. Tidak ada NEEDS CLARIFICATION tersisa.

## D1 — Satu penerjemah, bukan jalur potong kedua

- **Decision**: perluas `ModifierMaterialConsumption::needFor()` agar juga menghasilkan kebutuhan dari resep aktif varian tiap baris. `consumeOrder`, `restoreOrder`, dan `wasteLine` tidak diubah selain itu.
- **Rationale**: kelas itu sudah dinyatakan "SATU-SATUNYA penerjemah baris pesanan → bahan" dan sudah dipanggil di tiga titik pesanan menjadi selesai, void/refund, dan buang. Jalur kedua melanggar Konstitusi I dan menggandakan aturan penguncian (material_id lalu order_item_id) serta idempotensi (cek buku besar `sale` per `order_item`).
- **Alternatives**: kelas `RecipeMaterialConsumption` terpisah — ditolak: dua pemanggil harus selalu dipanggil berpasangan, dan guard "sudah dipotong" tidak bisa dibagi.
- **Fakta yang membatasi**: guard idempotensi `consumeOrder` melihat ada/tidaknya `sale` per baris; karena resep dan pilihan dipotong dalam satu panggilan, guard tetap benar.

## D2 — Rumus potongan = rumus HPP

- **Decision**: per baris pesanan, kebutuhan bahan resep = `quantity ÷ (1 − susut%/100) ÷ Portions × qty baris`, dijumlahkan per bahan lintas semua resep aktif varian itu.
- **Rationale**: `RecipeCost::line()` memakai `quantity ÷ (1 − susut%/100)` dan `compute()` membagi dengan `yield`. Memakai rumus yang sama membuat stok terpotong sinkron dengan HPP; spec Edge Cases ("ditambah penyusutan") disempurnakan menjadi "dibagi (1 − susut)".
- **Pembulatan**: `round(…, 3)` per (bahan, baris), seperti `needFor` sekarang. Pergerakan ≤ 0 dibuang.
- **Alternatives**: `quantity × (1 + susut%)` — ditolak, tidak cocok dengan HPP.

## D3 — "Aktif" tanpa versi

- **Decision**: resep aktif varian = semua resep yang tertaut ke varian itu, `effective_from ≤ hari ini` (tanggal bisnis `BusinessDay::today()`), dan `archived_at IS NULL`. Tanggal berlaku dipertahankan sebagai isian (mulai berlaku), batas mundur 30 hari tetap.
- **Rationale**: spec Asumsi mempertahankan tanggal berlaku. Memakai `BusinessDay::today()` mengikuti aturan hari bisnis (042) agar tes dan produksi tidak berbeda antara 00:00–07:00 WIB; kode lama memakai `Carbon::today()` — RecipeService diseragamkan.
- **Alternatives**: hapus tanggal berlaku — ditolak: spec/Asumsi menyatakan tetap ada, dan hapus kolom memperbesar migrasi tanpa manfaat.

## D4 — Migrasi resep berversi (ASSUMPTION dari spec, dikonfirmasi di sini)

- **Decision**: satu migrasi data dalam transaksi:
  1. Untuk tiap varian, tentukan versi aktif lama (`effective_from ≤ hari ini`, terbaru; aturan `activeFor` lama).
  2. Baris `recipe_variants` yang BUKAN versi aktif sebuah varian dihapus dan isinya (resep, varian) ditulis ke log aktivitas `recipe_migrated`.
  3. Resep yang tersisa tanpa tautan varian diberi `archived_at = now()` (disimpan, baca-saja, tidak memotong stok).
  4. Resep masa depan (`effective_from > hari ini`) dibiarkan aktif-menunggu: tidak dihapus tautannya, hanya tidak memotong sebelum tanggalnya.
  5. Kolom `version` dan unique `uniq_recipes_product_version` dihapus; kolom `name` ditambahkan dan diisi "Resep" (+ " vN" untuk arsip agar bisa dibedakan).
- **Rationale**: tanpa langkah 2, resep yang "tergantikan" untuk satu varian tetapi aktif untuk varian lain akan ikut memotong untuk varian pertama (pemotongan ganda). Log aktivitas menyimpan jejak pasangan yang dilepas.
- **Alternatives**: biarkan semua versi aktif — ditolak (pemotongan ganda). Hapus versi lama — ditolak (kehilangan riwayat HPP).
- **`down()`**: tidak mengembalikan versi (dicatat di migrasi sebagai satu arah dengan alasannya); data dev dipulihkan dari cadangan bila perlu.

## D5 — Jumlah pilihan, harga, dan "tiap pilihan = 1 porsi"

- **Decision**:
  - `modifier_options` mendapat `min_qty` (tinyint unsigned, default 1) dan `max_qty` (tinyint unsigned null, default 1; null = tanpa batas atas, dibatasi 50 oleh validasi). CHECK `min_qty ≥ 1` dan `max_qty IS NULL OR max_qty ≥ min_qty`.
  - Min/maks berlaku SAAT dipilih; jumlah 0 = tidak dipilih. Aturan grup (`min_select`/`max_select`, wajib) tetap menghitung banyaknya PILIHAN berbeda.
  - Harga: kontribusi satu pilihan = `price_delta × qty`. Grup `portion_per_option`: tiap SATUAN jumlah di atas yang pertama dalam grup menambah satu harga dasar (SOTO ×2 = dua porsi). Pilihan pertama menurut urutan opsi grup membawa `qty − 1` harga dasar ekstra, pilihan berikutnya `qty`.
  - Snapshot `order_items.modifiers[]` menambah `qty` (int). `price_delta` snapshot = KONTRIBUSI TOTAL pilihan itu, sehingga `unit_price = dasar + Σ price_delta` tetap benar bagi semua pembaca. Baris lama tanpa `qty` dibaca sebagai 1.
- **Rationale**: invarian lama (`unit_price = dasar + Σ price_delta`) dipakai struk, ubah-baris tab (`TabService` mengurangi `Σ price_delta` lama untuk mendapat dasar), dan laporan. Menyimpan kontribusi total mempertahankannya tanpa menyentuh pembaca. Porsi per satuan konsisten dengan potongan bahan yang sudah ×qty (dua bungkus terpotong ⇒ dua porsi ditagih).
- **Alternatives**: porsi per pilihan berbeda (qty tidak menambah porsi) — ditolak: membuat SOTO ×2 memotong dua bungkus tetapi menagih satu.
- **Batas**: jumlah baris tab × qty > 9999 bahan tidak relevan; validasi `qty` 1..50 per pilihan.

## D6 — Bentuk permintaan pesanan

- **Decision**: tambah `modifier_selections: [{option_id:int, qty:int}]` pada `POST /orders` (per item), `POST /tabs/{id}/lines`, dan `PUT /tabs/{id}/lines/{line}`. `modifier_option_ids` tetap diterima (tiap id = qty 1). Mengirim KEDUANYA → 422 pada `modifier_selections`. Id kembar di `modifier_selections` → 422.
- **Rationale**: kompatibel mundur dengan APK/web lama; hanya satu bentuk internal (`array<int,int>` id→qty) yang masuk `ModifierResolver::resolve()` lewat satu normalizer, sehingga tiga pemanggil tidak menulis ulang aturan.
- **Alternatives**: id berulang sebagai jumlah (`[5,5]`) — ditolak: ambigu dengan duplikat tak sengaja dan menyulitkan validasi min/maks.

## D7 — Bug galat "bahan yang sama dua kali"

- **Fakta**: `StoreModifierGroupRequest` memakai `options.*.materials.*.material_id` + `distinct`. `distinct` dengan dua wildcard membandingkan SEMUA nilai lintas pilihan, bukan per pilihan. Tabel `modifier_option_materials` sudah benar (unique `modifier_option_id + material_id`).
- **Decision**: hapus `distinct` dari aturan dan periksa kembar di dalam satu pilihan pada `withValidator()` memakai kunci galat `options.{i}.materials.{j}.material_id`, sehingga galat menunjuk pilihan yang bermasalah saja. Editor web juga menandai kembar per pilihan sebelum kirim (UX); server tetap penentu.
- Ditulis sebagai komentar `BUG YANG DITEMUKAN & DIPERBAIKI` di kode.

## D8 — Tanpa N+1 saat menyelesaikan pesanan

- **Decision**: `needFor()` memuat resep aktif untuk semua `variant_id` baris pesanan dengan satu kueri (`recipe_variants` join `recipes` + `lines.material`) lalu mengelompokkan di memori. Biaya satuan bahan memakai `RecipeService::unitCost` hanya untuk bahan modifier (biaya resep sudah di `cost_price`, lihat D9).

## D9 — Tidak ada penghitungan biaya ganda

- **Fakta**: `variant.cost_price` disinkronkan dari resep dan disalin ke `order_items.cost_price` saat pesanan dibuat; `addMaterialCost` (087) menambah hanya biaya bahan PILIHAN.
- **Decision**: potongan stok resep TIDAK menulis biaya baris. Biaya varian ber-resep = jumlah biaya porsi SEMUA resep aktif (D10); `RecipeService::cost()` per resep tidak berubah.

## D10 — `cost_price` dengan banyak resep

- **Decision**: `cost_price` varian = Σ biaya porsi semua resep aktifnya. Bila SALAH SATU resep tak lengkap (bahan tanpa harga), penulisan `cost_price` dilewati dan dilaporkan sebagai `incomplete`, sama dengan aturan satu resep. `applyDue`, `recalculate`, dan `writeCost` dihitung ulang per varian (bukan per resep). `isRecipeDriven()` = ada resep aktif (mengunci edit tangan `cost_price`, tidak berubah). `cost_applied_at` dipertahankan untuk tahu resep mana yang tanggalnya baru tiba.
- **Log**: `cost_price_synced` menyebut jumlah resep, bukan "vN".

## D11 — Laporan Bahan (088) terpengaruh

- **Fakta**: `MaterialReport::untrackedMaterials` (baris 451–464) menandai bahan yang ada di resep/BOM tetapi tidak di pemetaan modifier sebagai "pemakaian tidak tercatat" karena resep/BOM tidak memotong stok.
- **Decision**: bahan yang berada di resep AKTIF tidak lagi "tak tercatat"; hanya bahan yang HANYA ada di BOM yang tetap. Laju pemakaian (−sale − return − waste) otomatis ikut karena potongan resep bertipe `sale`. Sesuaikan teks `usage_untracked` dan tesnya.

## D12 — Antarmuka dan klien

- **Web Resep**: `RecipeSection` menampilkan daftar resep (nama, Portions, varian, biaya, tombol Ubah/Hapus/Tambah). `RecipeDrawer` membuat/mengubah SATU resep; banner diganti ("Stok bahan dipotong saat pesanan selesai"); label `rc_yield` → "Portions"/"Porsi"; `rc_yield_unit` tidak lagi dirender.
- **Web kasir**: `ModifierPickerModal` mendapat stepper jumlah untuk pilihan yang `max_qty ≠ 1`; pilihan `max_qty = 1` tetap pilih/lepas. `modifierRules.js` mencerminkan harga/porsi server (hanya pratinjau).
- **Android**: `ModOption` menambah `minQty/maxQty`; `Sheets.kt` stepper; `ModifierPricing` menghitung harga/porsi per satuan; `AddLineRequestDto` mengirim `modifier_selections` bila ada jumlah >1 atau grup memintanya (selain itu tetap `modifier_option_ids` agar server lama/APK baru + server lama tetap berfungsi); struk dan KOT mencetak "×N". Tidak ada layar resep di tablet (verifikasi: tidak ada kata "recipe" di `android-pos/app/src/main`).
- **Istilah tablet**: teks baru memakai istilah baku yang dijaga `TerminologyScanTest` (mis. "Jumlah", bukan "Qty").
