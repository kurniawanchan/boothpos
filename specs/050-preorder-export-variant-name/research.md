# Research: Nama Varian di Ekspor Pre-order

## Decision 1 — Kolom terpisah `variant_names`, tepat sesudah `products`

- **Keputusan**: tambah kunci `variant_names` pada baris ekspor, disisipkan segera setelah `products`.
- **Alasan**: `PreorderExportImportService::import()` memecah `products` menjadi daftar SKU; menggabungkan nama ke sel itu merusak round-trip (SC-005 fitur 007). `PreorderImport` memakai `WithHeadingRow`, jadi impor membaca per NAMA kolom: kolom tambahan diabaikan (preseden: `created_at`/`updated_at`, tes `test_full_export_round_trips_dispatch_state_and_tolerates_the_read_only_columns`). Disisipkan di sebelah `products` karena tujuannya agar SKU dan namanya terbaca berdampingan.
- **Konsekuensi**: tes `test_export_carries_dispatch_columns_and_read_only_created_updated` mengunci urutan "HEADINGS dulu, lalu created_at/updated_at" dan harus diubah; tidak ada kode impor yang bergantung pada posisi.
- **Alternatif ditolak**: (a) `SKU (Nama)` dalam sel `products` — merusak impor; (b) kolom di ujung kanan seperti `created_at` — konsisten dengan preseden tetapi menjauhkan nama dari SKU-nya (kolom ke-18).

## Decision 2 — Sumber nama: varian TERKINI lewat `variant_id`, bukan snapshot

- **Keputusan**: baca `ProductVariant.variant_name` lewat relasi `PreorderItem::variant()` dengan `withTrashed()`.
- **Alasan**: `preorder_items.name_snapshot` berbentuk `"<produk> — <varian>"` dan tidak bisa dipecah dengan andal (nama produk boleh mengandung " — "). `variant_id` bersifat `restrictOnDelete`, jadi baris varian selalu ada (paling buruk soft-deleted → `withTrashed()` tetap menemukannya). Meminta "nama varian saja" (asumsi spec) sejalan dengan kolom ini.
- **Risiko yang diterima**: jika varian diganti nama setelah pesanan dibuat, ekspor menampilkan nama terbaru, sementara `products` (SKU) tetap SKU tercatat. Ini hanya label baca, bukan angka keuangan; snapshot nama varian tersendiri ditolak sebagai penambahan skema yang tak diminta.
- **Catatan DEMO**: snapshot SKU hasil klon LIVE→DEMO masih membawa SKU LIVE (lihat CLAUDE.md "Clone LIVE → DEMO"); nama diambil dari varian DEMO. Pada data klon, SKU dan nama bisa berasal dari "dunia" berbeda; dibiarkan, sesuai aturan snapshot-verbatim.

## Decision 3 — Pemisah ` | ` dan penjajaran posisi

- **Keputusan**: nama digabung dengan ` | ` (pipa berspasi), bukan `,`.
- **Alasan**: nama varian sering memuat koma ("Merah, L"); `,` akan membuat pembagian per posisi ambigu bagi pembaca (FR/edge case). Pipa jarang muncul dalam nama. Sel kosong tetap menempati posisinya (nama kosong → string kosong di antara pemisah) agar jumlah bagian selalu sama dengan jumlah SKU.
- **Batas diterima**: nama yang memuat " | " literal tetap ambigu; tidak dilindungi (tidak ada pemakai mesin untuk kolom ini).

## Decision 4 — Tanpa N+1

- **Keputusan**: `->with(['customer', 'event', 'items.variant' => fn ($q) => $q->withTrashed()])`.
- **Alasan**: Konstitusi V; relasi `variant` dipanggil sekali per pre-order di dalam `map`. Tes memakai pola penghitung query (pemanasan satu panggilan dulu, lihat catatan CLAUDE.md).

## Decision 5 — Sel berawalan `=` dipaksa jadi teks (di `GenericArrayExport`)

- **Masalah**: `GenericArrayExport` tidak punya pengikat nilai; PhpSpreadsheet mengubah string berawalan `=` menjadi RUMUS. Nama varian diketik pengguna, dan dalam sel gabungan nama pertama menentukan karakter pertama sel. FR-007/SC-004 mewajibkan teks.
- **Keputusan**: `GenericArrayExport` mengimplementasikan `WithCustomValueBinder`; hanya string yang diawali `=` dipaksa `TYPE_STRING`, selebihnya diserahkan ke `DefaultValueBinder`.
- **Mengapa sesempit itu**: PhpSpreadsheet hanya membuat rumus dari awalan `=` (awalan `+ - @` tidak dieksekusi pada sel xlsx bertipe string). Memaksa SEMUA string ke teks mengubah perilaku kolom numerik-berbentuk-teks pada ekspor laporan/master-data dan bisa memutus impor ulang; keputusan itu dipisahkan ke PR tersendiri (lihat catatan ekspor lain di PR #64).
- **Dampak sampingan (disengaja)**: semua pemanggil `GenericArrayExport` ikut terlindungi dari awalan `=`; tidak ada pemanggil yang sah mengirim rumus.
- **Tidak ditangani di sini**: `SheetArrayExport` dan `MultiSheetArrayExport` (tidak dipakai ekspor pre-order). Dicatat sebagai tindak lanjut.

## Decision 6 — Template dan OpenAPI

- **Template impor**: tidak berubah (`HEADINGS`/`template()` tidak memuat kolom baru); sudah dijaga tes "template punya persis kolom impor".
- **OpenAPI**: deskripsi `/preorders/export` menyebut kolom baru dan sifat hanya-bacanya, di commit yang sama (Konstitusi, Documentation & Change Discipline). Bentuk respons (berkas xlsx) tidak berubah.
